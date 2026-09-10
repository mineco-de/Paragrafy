#!/bin/sh
set -e

DATA_DIR="${PARAGRAFY_DATA_DIR:-/var/www/data}"

mkdir -p "$DATA_DIR"
chown -R www-data:www-data "$DATA_DIR"

exec "$@"
