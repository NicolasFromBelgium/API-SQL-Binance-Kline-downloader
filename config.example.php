<?php
// ======================= CONFIGURATION =======================

// MySQL Database
define('DB_HOST', 'localhost'); 
define('DB_NAME', 'YOUR_DB_NAME_HERE'); // your input !!
define('DB_USER', 'YOUR_USER_NAME');
define('DB_PASS', 'AND_YOUR_PASSWORD');

// Time range to fetch (first run)
define('INITIAL_START_MONTHS', -6);     // -6 = 6 months, -12 = 1 year, -36 = 3 years...

// Symbols to download (you can add/remove)
$DEFAULT_SYMBOLS = [
    'BTCUSDT',
    'ETHUSDT',
    'SOLUSDT',
    'XRPUSDT',
    'BNBUSDT'
];

// Optional: your own list (uncomment to override)
// $CUSTOM_SYMBOLS = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT'];
