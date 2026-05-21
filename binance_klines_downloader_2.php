<?php
// =====================================================
// BINANCE KLINE DOWNLOADER - VERSION COMPLÈTE SMART
// 1m only + Aggregation + Quote Volume + Variations
// =====================================================

echo "🚀 Binance Smart Kline Downloader (Complet) started...\n\n";

// === Configuration ===
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
            $_ENV[trim($key)] = trim($value);
        }
    }
}

$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) require_once $configFile;

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = 'gecko_data_2';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "✅ Connexion à la base de données OK\n";

// === Timeframes ===
$timeframes = [
    '1m'  => ['table' => 'ohlcv_data_1m',   'minutes' => 1],
    '5m'  => ['table' => 'ohlcv_data_5m',   'minutes' => 5],
    '15m' => ['table' => 'ohlcv_data_15m',  'minutes' => 15],
    '30m' => ['table' => 'ohlcv_data_30m',  'minutes' => 30],
    '1h'  => ['table' => 'ohlcv_data_1h',   'minutes' => 60],
    '4h'  => ['table' => 'ohlcv_data_4h',   'minutes' => 240],
    '1d'  => ['table' => 'ohlcv_data_1d',   'minutes' => 1440],
    '1w'  => ['table' => 'ohlcv_data_1w',   'minutes' => 10080],
    '1M'  => ['table' => 'ohlcv_data_1M',   'minutes' => 43200],
];

// === CRÉATION / MISE À JOUR DES TABLES ===
echo "🔧 Création / Mise à jour des tables...\n";

foreach ($timeframes as $tf) {
    $table = $tf['table'];

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `$table` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `symbol_id` INT NOT NULL,
            `open_time` DATETIME NOT NULL,
            `open_time_timestamp` BIGINT NOT NULL,
            `open_price` DECIMAL(18,8) NOT NULL,
            `high_price` DECIMAL(18,8) NOT NULL,
            `low_price` DECIMAL(18,8) NOT NULL,
            `close_price` DECIMAL(18,8) NOT NULL,
            `volume` DECIMAL(18,4) NOT NULL,
            `quote_volume` DECIMAL(18,4) NOT NULL DEFAULT 0,
            `previous_close_price_variation` DECIMAL(18,6) NULL,
            `previous_volume_variation` DECIMAL(18,6) NULL,
            `kline_completed` TINYINT(1) DEFAULT 1,
            `close_time_timestamp` BIGINT NOT NULL,
            `close_time` DATETIME NOT NULL,
            `row_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_candle` (`symbol_id`, `open_time_timestamp`),
            INDEX `idx_symbol_time` (`symbol_id`, `open_time_timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('quote_volume', $columns)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `quote_volume` DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER `volume`");
        echo "   → $table : quote_volume ajoutée\n";
    }
    if (!in_array('previous_close_price_variation', $columns)) {
        $pdo->exec("ALTER TABLE `$table` 
                    ADD COLUMN `previous_close_price_variation` DECIMAL(18,6) NULL AFTER `quote_volume`,
                    ADD COLUMN `previous_volume_variation` DECIMAL(18,6) NULL AFTER `previous_close_price_variation`");
        echo "   → $table : colonnes variations ajoutées\n";
    }
}
echo "✅ Toutes les tables sont prêtes\n\n";

// === Symbols ===
$symbols = $pdo->query("SELECT id, symbol FROM trading_symbols ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (empty($symbols)) {
    $symbols = [
        ['id' => 0, 'symbol' => 'BTCUSDT'],
        ['id' => 0, 'symbol' => 'ETHUSDT'],
        ['id' => 0, 'symbol' => 'SOLUSDT']
    ];
}

// ======================= FONCTIONS =======================

function fetch1mKlines($symbol, $startTime, $limit = 1000) {
    $url = "https://api.binance.com/api/v3/klines?symbol=$symbol&interval=1m&limit=$limit&startTime=$startTime";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

// ======================= TRAITEMENT =======================

foreach ($symbols as $s) {
    $symbol_id = $s['id'];
    $symbol    = $s['symbol'];

    echo "\n🔄 Traitement de $symbol...\n";

    // ==================== 1. Récupération 1m ====================
    $last = $pdo->prepare("SELECT MAX(open_time_timestamp) FROM ohlcv_data_1m WHERE symbol_id = ?");
    $last->execute([$symbol_id]);
    $start = ($last->fetchColumn() ?: (strtotime("-36 months") * 1000)) + 1;
    $now = round(microtime(true) * 1000);

    echo "   → Téléchargement 1m... ";

    $batch = 0;
    while ($start < $now) {
        $klines = fetch1mKlines($symbol, $start);
        if (empty($klines)) break;

        $pdo->beginTransaction();
        foreach ($klines as $k) {
            $open_ts  = (int)$k[0];
            $close_ts = (int)$k[6];

            // Récupération de la bougie précédente (prix + volume)
            $prev = $pdo->prepare("SELECT close_price, volume FROM ohlcv_data_1m 
                                   WHERE symbol_id = ? AND open_time_timestamp < ? 
                                   ORDER BY open_time_timestamp DESC LIMIT 1");
            $prev->execute([$symbol_id, $open_ts]);
            $prevData = $prev->fetch(PDO::FETCH_ASSOC);

            $prevClose  = $prevData['close_price'] ?? null;
            $prevVolume = $prevData['volume'] ?? null;

            $priceVar = $prevClose  ? round(((float)$k[4] / (float)$prevClose), 6) : null;
            $volVar   = $prevVolume && (float)$prevVolume > 0 
                        ? round(((float)$k[5] / (float)$prevVolume), 6) 
                        : null;

            $stmt = $pdo->prepare("
                INSERT INTO ohlcv_data_1m 
                (symbol_id, open_time, open_time_timestamp, open_price, high_price, low_price, close_price,
                 volume, quote_volume, previous_close_price_variation, previous_volume_variation,
                 close_time_timestamp, close_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    high_price = GREATEST(high_price, VALUES(high_price)),
                    low_price  = LEAST(low_price, VALUES(low_price)),
                    close_price = VALUES(close_price),
                    volume = VALUES(volume),
                    quote_volume = VALUES(quote_volume)
            ");

            $stmt->execute([
                $symbol_id,
                date('Y-m-d H:i:s', $open_ts/1000), $open_ts,
                $k[1], $k[2], $k[3], $k[4],
                $k[5], $k[7],
                $priceVar,
                $volVar,                    // ← Maintenant calculé !
                $close_ts,
                date('Y-m-d H:i:s', $close_ts/1000)
            ]);
        }
        $pdo->commit();

        $start = $close_ts + 1;
        $batch++;
        if ($batch % 20 == 0) echo ".";
    }
    echo " terminé ($batch batches)\n";

    // ==================== 2. Agrégation (autres timeframes) ====================
    echo "   → Agrégation vers les autres timeframes...\n";

    foreach ($timeframes as $tfName => $tf) {
        if ($tfName === '1m') continue;

        $table = $tf['table'];
        $ms = $tf['minutes'] * 60000;

        $lastTarget = $pdo->prepare("SELECT MAX(open_time_timestamp) FROM `$table` WHERE symbol_id = ?");
        $lastTarget->execute([$symbol_id]);
        $from = $lastTarget->fetchColumn() ?: 0;

        $sql = "
            INSERT INTO `$table` 
            (symbol_id, open_time, open_time_timestamp, open_price, high_price, low_price, close_price,
             volume, quote_volume, close_time_timestamp, close_time)
            SELECT 
                symbol_id,
                MIN(open_time),
                MIN(open_time_timestamp),
                MIN(CASE WHEN rn = 1 THEN open_price END),
                MAX(high_price),
                MIN(low_price),
                MAX(CASE WHEN rn = total THEN close_price END),
                SUM(volume),
                SUM(quote_volume),
                MAX(close_time_timestamp),
                MAX(close_time)
            FROM (
                SELECT *,
                       FLOOR(open_time_timestamp / ?) as bucket,
                       ROW_NUMBER() OVER (PARTITION BY FLOOR(open_time_timestamp / ?) ORDER BY open_time_timestamp) as rn,
                       COUNT(*) OVER (PARTITION BY FLOOR(open_time_timestamp / ?)) as total
                FROM ohlcv_data_1m 
                WHERE symbol_id = ? AND open_time_timestamp > ?
            ) sub
            GROUP BY bucket
            ON DUPLICATE KEY UPDATE 
                high_price = GREATEST(high_price, VALUES(high_price)),
                low_price  = LEAST(low_price, VALUES(low_price)),
                close_price = VALUES(close_price),
                volume = VALUES(volume),
                quote_volume = VALUES(quote_volume)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ms, $ms, $ms, $symbol_id, $from]);

        // Mise à jour des variations
        $pdo->exec("
            UPDATE `$table` t
            JOIN (
                SELECT 
                    id,
                    close_price / NULLIF(LAG(close_price) OVER (PARTITION BY symbol_id ORDER BY open_time_timestamp), 0) as p_var,
                    volume      / NULLIF(LAG(volume)      OVER (PARTITION BY symbol_id ORDER BY open_time_timestamp), 0) as v_var
                FROM `$table`
                WHERE symbol_id = $symbol_id
            ) prev ON t.id = prev.id
            SET t.previous_close_price_variation = prev.p_var,
                t.previous_volume_variation = prev.v_var
            WHERE t.previous_close_price_variation IS NULL
        ");

        echo "     ✓ $tfName → OK\n";
    }
}

echo "\n🎉 === TOUT EST TERMINÉ AVEC SUCCÈS ===\n";
echo "previous_volume_variation est maintenant calculé aussi sur les 1m !\n";
?>
