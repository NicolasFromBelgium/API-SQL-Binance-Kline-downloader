<?php
// =====================================================
// BINANCE KLINE DOWNLOADER - VERSION SIMPLIFIÉE
// Sans colonnes avancées (avg_kline_price, log_return, etc.)
// =====================================================

echo "🚀 Binance Kline Downloader (version simplifiée) started...\n\n";

// Load .env if exists
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
            $_ENV[trim($key)] = trim($value);
        }
    }
    echo "✅ .env file loaded\n";
}

// Load config (fallback)
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

$host = $_ENV['DB_HOST'] ?? DB_HOST ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? DB_NAME ?? 'gecko_data';
$user = $_ENV['DB_USER'] ?? DB_USER ?? 'root';
$pass = $_ENV['DB_PASS'] ?? DB_PASS ?? '';
$initialMonths = $_ENV['INITIAL_START_MONTHS'] ?? INITIAL_START_MONTHS ?? -36;

// === Requirements check ===
echo "🔍 Checking requirements...\n";
if (version_compare(PHP_VERSION, '8.0', '<')) {
    die("❌ PHP 8.0+ is required. Your version: " . PHP_VERSION . "\n");
}
if (!extension_loaded('curl'))      die("❌ cURL extension is required.\n");
if (!extension_loaded('pdo_mysql')) die("❌ PDO_MYSQL extension is required.\n");
echo "✅ Requirements check passed\n\n";

// === Database connection ===
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Successfully connected to database '$dbname'\n\n";
} catch (Exception $e) {
    die("❌ Database connection failed: " . $e->getMessage() . "\n");
}

// === Load symbols ===
$symbols = $pdo->query("SELECT id, symbol FROM trading_symbols ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (empty($symbols)) {
    echo "⚠️ No symbols found in 'trading_symbols' table. Using default symbols...\n";
    $symbols = array_map(fn($s) => ['id' => 0, 'symbol' => $s], $DEFAULT_SYMBOLS ?? ['BTCUSDT','ETHUSDT','SOLUSDT','XRPUSDT','BNBUSDT']);
}

// === Timeframes ===
$intervals = [
    "1M"  => "ohlcv_data_1M",  "1w"  => "ohlcv_data_1w",  "1d"  => "ohlcv_data_1d",
    "4h"  => "ohlcv_data_4h",  "1h"  => "ohlcv_data_1h",  "30m" => "ohlcv_data_30m",
    "15m" => "ohlcv_data_15m", "5m"  => "ohlcv_data_5m",  "1m"  => "ohlcv_data_1m"
];

function fetchKlines($symbol, $interval, $limit, $startTime) {
    $url = "https://api.binance.com/api/v3/klines?symbol=$symbol&interval=$interval&limit=$limit&startTime=$startTime";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

// ======================= MAIN PROCESS =======================
foreach ($intervals as $interval => $table) {
    echo "📊 Processing $interval → $table\n";

    // Create table (version simplifiée)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `$table` (
            `id`                    BIGINT AUTO_INCREMENT PRIMARY KEY,
            `symbol_id`             INT NOT NULL,
            `open_time`             DATETIME NOT NULL,
            `open_time_timestamp`   BIGINT NOT NULL,
            `open_price`            DECIMAL(18,4) NOT NULL,
            `high_price`            DECIMAL(18,4) NOT NULL,
            `low_price`             DECIMAL(18,4) NOT NULL,
            `close_price`           DECIMAL(18,4) NOT NULL,
            `volume`                DECIMAL(18,4) NOT NULL,
            `kline_completed`       TINYINT(1) NOT NULL DEFAULT 1,
            `close_time_timestamp`  BIGINT NOT NULL,
            `close_time`            DATETIME NOT NULL,
            `row_created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
            `row_updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_candle` (`symbol_id`, `open_time_timestamp`),
            INDEX `idx_symbol_time` (`symbol_id`, `open_time_timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO `$table`
        (symbol_id, open_time, open_time_timestamp, open_price, high_price, low_price, close_price, volume,
         close_time_timestamp, close_time, kline_completed)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");

    foreach ($symbols as $s) {
        $symbol_id = $s['id'];
        $symbol    = $s['symbol'];

        $last = $pdo->prepare("SELECT MAX(open_time_timestamp) FROM `$table` WHERE symbol_id = ?");
        $last->execute([$symbol_id]);
        $last_unix = $last->fetchColumn() ?: (strtotime($initialMonths . ' months') * 1000);

        $start = $last_unix + 1;
        $now   = round(microtime(true) * 1000);

        echo "   → $symbol : ";
        $batch = 0;
        while ($start < $now) {
            $klines = fetchKlines($symbol, $interval, 1000, $start);
            if (empty($klines)) break;

            foreach ($klines as $k) {
                $open_unix  = (int)$k[0];
                $close_unix = (int)$k[6];
                $stmt->execute([
                    $symbol_id,
                    date('Y-m-d H:i:s', $open_unix/1000),
                    $open_unix,
                    $k[1], $k[2], $k[3], $k[4], $k[5],
                    $close_unix,
                    date('Y-m-d H:i:s', $close_unix/1000)
                ]);
            }
            $start = $close_unix + 1;
            $batch++;
            if ($batch % 10 == 0) echo ".";
        }
        echo " completed ($batch batches)\n";
    }

    echo "   → Table $table ready!\n\n";
}

echo "🎉 DOWNLOAD COMPLETED SUCCESSFULLY!\n";
echo "   All tables have been created/filled with basic OHLCV data.\n";
echo "   You can now view them in phpMyAdmin.\n\n";
?>
