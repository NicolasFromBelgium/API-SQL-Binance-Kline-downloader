<?php
// =====================================================
// BINANCE KLINE UPDATER - CRON (toutes les minutes)
// Idempotent + kline_completed intelligent
// =====================================================

$scriptStart = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] 🚀 Binance Updater started...\n";


// === Config ===
if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$k, $v] = explode('=', $line, 2);
            putenv(trim($k) . '=' . trim($v));
        }
    }
}

$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) require_once $configFile;

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = 'gecko_data_2';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? 'Hellogo13364';

$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Timeframes
$timeframes = [
    '5m' => 5, '15m' => 15, '30m' => 30, '1h' => 60,
    '4h' => 240, '1d' => 1440, '1w' => 10080, '1M' => 43200
];

// Symbols
$symbols = $pdo->query("SELECT id, symbol FROM trading_symbols ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) 
         ?: [['id'=>0, 'symbol'=>'BTCUSDT']];

// ======================= FONCTIONS =======================

function fetch1mKlines($symbol, $startTime) {
    $url = "https://api.binance.com/api/v3/klines?symbol=$symbol&interval=1m&limit=1000&startTime=$startTime";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?: [];
}

// ======================= MAIN =======================

foreach ($symbols as $s) {
    $symbol_id = $s['id'];
    $symbol    = $s['symbol'];
    $now       = round(microtime(true) * 1000);

    echo "🔄 $symbol : ";

    // --- 1. Mise à jour 1m ---
    $last = $pdo->prepare("SELECT MAX(open_time_timestamp) FROM ohlcv_data_1m WHERE symbol_id = ?");
    $last->execute([$symbol_id]);
    $start = ($last->fetchColumn() ?: (strtotime("-36 months") * 1000)) + 1;

    $inserted = 0;
    while ($start < $now) {
        $klines = fetch1mKlines($symbol, $start);
        if (empty($klines)) break;

        $pdo->beginTransaction();
        foreach ($klines as $k) {
            $open_ts  = (int)$k[0];
            $close_ts = (int)$k[6];
            $isCompleted = ($close_ts <= $now) ? 1 : 0;

            // Previous data
            $prevStmt = $pdo->prepare("SELECT close_price, volume FROM ohlcv_data_1m 
                                       WHERE symbol_id = ? AND open_time_timestamp < ? 
                                       ORDER BY open_time_timestamp DESC LIMIT 1");
            $prevStmt->execute([$symbol_id, $open_ts]);
            $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);

            $priceVar = $prev['close_price'] ? round($k[4] / $prev['close_price'], 6) : null;
            $volVar   = $prev['volume'] > 0 ? round($k[5] / $prev['volume'], 6) : null;

            $stmt = $pdo->prepare("
                INSERT INTO ohlcv_data_1m 
                (symbol_id, open_time, open_time_timestamp, open_price, high_price, low_price, close_price,
                 volume, quote_volume, previous_close_price_variation, previous_volume_variation,
                 kline_completed, close_time_timestamp, close_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    high_price = GREATEST(high_price, VALUES(high_price)),
                    low_price  = LEAST(low_price, VALUES(low_price)),
                    close_price = VALUES(close_price),
                    volume = VALUES(volume),
                    quote_volume = VALUES(quote_volume),
                    kline_completed = VALUES(kline_completed)
            ");

            $stmt->execute([
                $symbol_id, date('Y-m-d H:i:s', $open_ts/1000), $open_ts,
                $k[1], $k[2], $k[3], $k[4], $k[5], $k[7],
                $priceVar, $volVar, $isCompleted, $close_ts, date('Y-m-d H:i:s', $close_ts/1000)
            ]);

            $inserted++;
        }
        $pdo->commit();
        $start = $close_ts + 1;
    }

    echo "1m (+$inserted) ";

    // --- 2. Agrégation idempotente ---
    if ($inserted > 0 || true) {   // On vérifie toujours pour compléter les bougies en cours
        foreach ($timeframes as $tfName => $minutes) {
            $table = "ohlcv_data_$tfName";
            $ms = $minutes * 60000;

            // Agrégation seulement des données non encore traitées ou incomplètes
            $pdo->exec("
                INSERT INTO `$table` 
                (symbol_id, open_time, open_time_timestamp, open_price, high_price, low_price, close_price,
                 volume, quote_volume, close_time_timestamp, close_time, kline_completed)
                SELECT 
                    symbol_id,
                    MIN(open_time),
                    MIN(open_time_timestamp),
                    MIN(CASE WHEN rn=1 THEN open_price END),
                    MAX(high_price),
                    MIN(low_price),
                    MAX(CASE WHEN rn=total THEN close_price END),
                    SUM(volume),
                    SUM(quote_volume),
                    MAX(close_time_timestamp),
                    MAX(close_time),
                    MAX(CASE WHEN close_time_timestamp <= $now THEN 1 ELSE 0 END) as completed
                FROM (
                    SELECT *,
                           FLOOR(open_time_timestamp / $ms) as bucket,
                           ROW_NUMBER() OVER (PARTITION BY FLOOR(open_time_timestamp / $ms) ORDER BY open_time_timestamp) rn,
                           COUNT(*) OVER (PARTITION BY FLOOR(open_time_timestamp / $ms)) total
                    FROM ohlcv_data_1m 
                    WHERE symbol_id = $symbol_id 
                      AND open_time_timestamp >= (
                          SELECT COALESCE(MAX(open_time_timestamp), 0) 
                          FROM `$table` 
                          WHERE symbol_id = $symbol_id
                      ) - ($ms * 2)   -- petite marge de sécurité
                ) sub
                GROUP BY bucket
                ON DUPLICATE KEY UPDATE 
                    high_price = GREATEST(high_price, VALUES(high_price)),
                    low_price  = LEAST(low_price, VALUES(low_price)),
                    close_price = VALUES(close_price),
                    volume = volume + VALUES(volume),
                    quote_volume = quote_volume + VALUES(quote_volume),
                    kline_completed = VALUES(kline_completed)
            ");

            // Mise à jour des variations (seulement sur les lignes sans variation)
            $pdo->exec("
                UPDATE `$table` t
                JOIN (
                    SELECT id,
                           close_price / NULLIF(LAG(close_price) OVER (PARTITION BY symbol_id ORDER BY open_time_timestamp), 0) as p_var,
                           volume      / NULLIF(LAG(volume)      OVER (PARTITION BY symbol_id ORDER BY open_time_timestamp), 0) as v_var
                    FROM `$table` WHERE symbol_id = $symbol_id
                ) prev ON t.id = prev.id
                SET t.previous_close_price_variation = prev.p_var,
                    t.previous_volume_variation = prev.v_var
                WHERE t.previous_close_price_variation IS NULL
            ");
        }
        echo "→ Agrégation OK";
    }

    echo "\n";
}

$duration = round(microtime(true) - $scriptStart, 3);
echo "[" . date('Y-m-d H:i:s') . "] ✅ Fin du cycle en {$duration}s\n\n";
