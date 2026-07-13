#!/bin/sh
set -eu

echo "[runtime] Starting Laravel with PHP-FPM (release parity)."
php artisan optimize

exec php-fpm -F
