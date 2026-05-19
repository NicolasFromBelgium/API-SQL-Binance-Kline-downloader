<?php
// =====================================================
// BINANCE KLINE CRON - MULTI-FREQUENCY
// Usage: php binance_klines_cron.php [group]
// Groups: high | medium | low
// =====================================================

$startScript = microtime(true);
$logFile = __DIR__ . '/binance_cron.log';

function logMsg($msg, $level = 'INFO') {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] [$level] $msg\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// === Récupération du groupe ===
$group = $argv[1] ?? 'high';
$allowedGroups = ['high', 'medium', 'low'];

if (!in_array($group, $allowedGroups)) {
    die("❌ Groupe invalide. Utilisez : high | medium | low\n");
}

logMsg("🚀 Binance Kline Cron started - Group: **$group**");

// === Configuration DB ===
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

$host = $_ENV['DB_HOST'] ?? DB_HOST ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? DB_NAME ?? 'gecko_data';
$user = $_ENV['DB_USER'] ?? DB_USER ?? 'root';
$pass = $_ENV['DB_PASS'] ?? DB_PASS ?? '';

$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// === Symbols ===
$symbols = $pdo->query("SELECT id, symbol FROM trading_symbols ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (empty($symbols)) {
    $symbols = array_map(fn($s) => ['id' => 0, 'symbol' => $s], ['BTCUSDT','ETHUSDT','SOLUSDT']);
}

// === Définition des groupes ===
$groups = [
    'high' => [     // Toutes les minutes
        "1m"  => ["table" => "ohlcv_data_1m",  "ms" => 60000],
        "5m"  => ["table" => "ohlcv_data_5m",  "ms" => 300000],
        "15m" => ["table" => "ohlcv_data_15m", "ms" => 900000],
        "30m" => ["table" => "ohlcv_data_30m", "ms" => 1800000],
    ],
    'medium' => [   // Toutes les 15 minutes
        "1h"  => ["table" => "ohlcv_data_1h",  "ms" => 3600000],
        "4h"  => ["table" => "ohlcv_data_4h",  "ms" => 14400000],
    ],
    'low' => [      // Toutes les heures
        "1d"  => ["table" => "ohlcv_data_1d",  "ms" => 86400000],
        "1w"  => ["table" => "ohlcv_data_1w",  "ms" => 604800000],
        "1M"  => ["table" => "ohlcv_data_1M",  "ms" => 2592000000],
    ]
];

$intervals = $groups[$group];

function fetchKlines($symbol, $interval, $limit = 500, $startTime = null) {
    $url = "https://api.binance.com/api/v3/klines?symbol=$symbol&interval=$interval&limit=$limit";
    if ($startTime !== null) $url .= "&startTime=$startTime";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) throw new Exception("HTTP $httpCode");
    return json_decode($resp, true);
}

// ======================= MAIN =======================
$processed = 0;

foreach ($intervals as $interval => $info) {
    $table = $info['table'];
    $intervalMs = $info['ms'];

    logMsg("Processing $interval → $table");

    // Création table (structure minimale)
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

    foreach ($symbols as $s) {
        $symbol_id = $s['id'];
        $symbol    = $s['symbol'];

        $last = $pdo->prepare("SELECT MAX(open_time_timestamp) FROM `$table` WHERE symbol_id = ?");
        $last->execute([$symbol_id]);
        $lastTimestamp = $last->fetchColumn();

        $now = round(microtime(true) * 1000);
        $startFrom = $lastTimestamp ? $lastTimestamp + 1 : ($now - 90 * 24 * 3600 * 1000); // 90 jours max au démarrage

        $fetched = 0;

        while (true) {
            try {
                $klines = fetchKlines($symbol, $interval, 500, $startFrom);
                if (empty($klines)) break;

                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO `$table`
                    (symbol_id, open_time, open_time_timestamp, open_price, high_price, low_price, 
                     close_price, volume, close_time_timestamp, close_time, kline_completed)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");

                foreach ($klines as $k) {
                    $openTs  = (int)$k[0];
                    $closeTs = (int)$k[6];

                    $stmt->execute([
                        $symbol_id,
                        date('Y-m-d H:i:s', $openTs/1000),
                        $openTs,
                        $k[1], $k[2], $k[3], $k[4], $k[5],
                        $closeTs,
                        date('Y-m-d H:i:s', $closeTs/1000)
                    ]);
                    $fetched++;
                }

                $startFrom = (int)end($klines)[6] + 1;

                if ($startFrom > $now - ($intervalMs * 2)) break;

            } catch (Exception $e) {
                logMsg("Erreur $symbol $interval : " . $e->getMessage(), "ERROR");
                break;
            }
        }

        if ($fetched > 0) {
            $processed++;
            logMsg("   → $symbol : +$fetched klines");
        }
    }
}

$duration = round(microtime(true) - $startScript, 3);
logMsg("✅ Group **$group** terminé en {$duration}s | $processed mises à jour");
