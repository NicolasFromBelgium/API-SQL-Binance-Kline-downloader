Binance Kline Downloader - Optimized Cron Version

Professional PHP script for downloading Binance OHLCV data and storing it in a MySQL database.

Current Version: Minimal + Multi-Frequency Cron

Features
Lightweight and high-performance table structure (essential columns only)
Ultra-fast incremental updates
Automatic gap detection (in case of internet outage)
Three frequency levels via cron:
High: every minute → 1m, 5m, 15m, 30m
Medium: every 15 minutes → 1h, 4h
Low: every hour → 1d, 1w, 1M
INSERT IGNORE → zero duplicates
Clear grouped logging
Automatic table creation
Installation
Configure your database in .env or config.php

php binance_klines_downloader.php (will call API for historical klines, in one job)
php binance_klines_cron.php high (will call API for realtime klines, cronjob)
php binance_klines_cron.php medium (will call API for realtime klines, cronjob)
php binance_klines_cron.php low (will call API for realtime klines, cronjob)

CRONTAB ex:

# === HIGH (1m,5m,15m,30m) - toutes les minutes ===
* * * * * cd /YOURPROJECTFOLDER && /usr/bin/php binance_klines_cron.php high >> /YOURPROJECTFOLDER/cron_high.log 2>&1

# === MEDIUM (1h,4h) - toutes les 15 minutes ===
*/15 * * * * cd /YOURPROJECTFOLDER && /usr/bin/php binance_klines_cron.php medium >> /YOURPROJECTFOLDER/cron_medium.log 2>&1

# === LOW (1d,1w,1M) - toutes les heures ===
0 * * * * cd /YOURPROJECTFOLDER && /usr/bin/php binance_klines_cron.php low >> /YOURPROJECTFOLDER/cron_low.log 2>&1



