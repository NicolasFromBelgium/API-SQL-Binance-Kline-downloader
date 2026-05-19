<?php
// =====================================================
// BINANCE LIVE UPDATER (Cron Every Minute)
// Updates latest candles + marks incomplete ones
// =====================================================

echo "[" . date('Y-m-d H:i:s') . "] Binance Live Updater started...\n";

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die("❌ config.php not found!\n");
}
require_once $configFile;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("❌ DB Connection failed: " . $e->getMessage() . "\n");
}

// Timeframes to update frequently
$intervals = [
    "1m"  => "ohlcv_data_1m",
    "5m"  => "ohlcv_data_5m",
    "15m" => "ohlcv_data_15m",
    "30m" => "ohlcv_data_30m",
    "1h"  => "ohlcv_data_1h"
];

$symbols = $pdo->query("SELECT id, symbol FROM trading_symbols")->fetchAll(PDO::FETCH_ASSOC);

function fetchKlines($symbol, $interval, $limit = 5) {
    $url = "https://api.binance.com/api/v3/klines?symbol=$symbol&interval=$interval&limit=$limit";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

// ======================= LIVE UPDATE =======================
foreach ($intervals as $interval => $table) {
    echo "Updating $interval...\n";

    foreach ($symbols as $s) {
        $symbol_id = $s['id'];
        $symbol    = $s['symbol'];

        $klines = fetchKlines($symbol, $interval, 5);
        if (empty($klines)) continue;

        // We take the last 2 candles (one may be incomplete)
        foreach (array_slice($klines, -2) as $k) {
            $open_unix  = (int)$k[0];
            $close_unix = (int)$k[6];
            $is_completed = (time() * 1000 > $close_unix) ? 1 : 0;

            $stmt = $pdo->prepare("
                INSERT INTO `$table` 
                (symbol_id, open_time, open_time_timestamp, open_price, high_price, low_price, close_price, volume,
                 close_time_timestamp, close_time, kline_completed)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    high_price = GREATEST(high_price, ?),
                    low_price  = LEAST(low_price, ?),
                    close_price = ?,
                    volume = ?,
                    kline_completed = ?
            ");

            $stmt->execute([
                $symbol_id,
                date('Y-m-d H:i:s', $open_unix/1000),
                $open_unix,
                $k[1], $k[2], $k[3], $k[4], $k[5],
                $close_unix,
                date('Y-m-d H:i:s', $close_unix/1000),
                $is_completed,
                // ON DUPLICATE
                $k[2], $k[3], $k[4], $k[5], $is_completed
            ]);
        }
    }

    // Recalculate features for this timeframe
    $pdo->exec("
        UPDATE `$table` t
        JOIN (
            SELECT symbol_id, open_time_timestamp, close_price, volume,
                   LAG(close_price) OVER (PARTITION BY symbol_id ORDER BY open_time_timestamp) AS prev_close,
                   LAG(volume)      OVER (PARTITION BY symbol_id ORDER BY open_time_timestamp) AS prev_volume
            FROM `$table`
        ) prev ON t.symbol_id = prev.symbol_id AND t.open_time_timestamp = prev.open_time_timestamp
        SET
            t.price_variation     = CASE WHEN prev.prev_close > 0 THEN ROUND((t.close_price - prev.prev_close)/prev.prev_close, 6) ELSE NULL END,
            t.volume_variation    = CASE WHEN prev.prev_volume > 0 THEN ROUND((t.volume - prev.prev_volume)/prev.prev_volume, 6) ELSE NULL END,
            t.high_low_deltaPrice = ROUND((t.high_price - t.low_price) / t.avg_kline_price, 6),
            t.kline_trend         = CASE WHEN t.close_price > t.avg_kline_price THEN 'bullish' WHEN t.close_price < t.avg_kline_price THEN 'bearish' ELSE 'neutral' END,
            t.is_bullish_candle   = (t.close_price > t.open_price)
    ");
}

echo "[" . date('Y-m-d H:i:s') . "] ✅ Live update completed successfully.\n";
?>
