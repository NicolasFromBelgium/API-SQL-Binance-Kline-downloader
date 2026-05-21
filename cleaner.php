<?php
// =====================================================
// CLEANER - SQL
// =====================================================


$cutoffDate = '2026-05-19 23:59:59';


echo "🧹 CLEANING FROM DATE $cutoffDate...\n\n";

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
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


$cutoffTimestamp = strtotime($cutoffDate) * 1000;

$tables = [
    'ohlcv_data_1m', 'ohlcv_data_5m', 'ohlcv_data_15m', 'ohlcv_data_30m',
    'ohlcv_data_1h', 'ohlcv_data_4h', 'ohlcv_data_1d',
    'ohlcv_data_1w', 'ohlcv_data_1M'
];

$totalDeleted = 0;

foreach ($tables as $table) {
    $stmt = $pdo->prepare("
        DELETE FROM `$table` 
        WHERE open_time > ? 
           OR open_time_timestamp > ?
    ");
    
    $stmt->execute([$cutoffDate, $cutoffTimestamp]);
    $deleted = $stmt->rowCount();

    $totalDeleted += $deleted;
    echo "   → $table : $deleted lignes supprimées\n";
}

echo "\n🎉 Cleaning done !\n";
echo "Total of $totalDeleted entries cleaned\n";
echo "Cut-off applied : $cutoffDate\n";
