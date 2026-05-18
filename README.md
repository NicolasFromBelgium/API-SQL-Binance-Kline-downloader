# Binance Kline Downloader + Feature Engineering

Professional PHP script to download historical OHLCV data from Binance and automatically compute advanced trading features.
Edit config.php with your MySQL credentials and preferences
**Features:**
- Downloads all timeframes (1m → 1M)
- Creates tables automatically (`CREATE TABLE IF NOT EXISTS`)
- Computes 10+ advanced features (log returns, wick ratios, true range, trend, etc.)
- Configurable via `config.php`
- Detailed logging and error handling
- Ready for cron jobs (next step)

## Installation

1. Clone the repo
2. Copy config:
   ```bash
   cp config.example.php config.php

Edit config.php with your MySQL credentials and preferences
Run:
php binance_klines_downloader.php

Requirements

PHP 8.0+
PDO + PDO_MYSQL
cURL extension
MariaDB / MySQL
