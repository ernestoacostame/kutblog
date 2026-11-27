#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html"
DATA_DIR="/data"
SEED_DIR="/opt/kutblog-seed"

mkdir -p "$DATA_DIR"

# Inicializar datos persistentes en /data si no existen aún
for dir in content media cache plugins themes; do
    if [ ! -d "$DATA_DIR/$dir" ] && [ -d "$SEED_DIR/$dir" ]; then
        cp -a "$SEED_DIR/$dir" "$DATA_DIR/$dir"
    fi
    mkdir -p "$DATA_DIR/$dir"
    rm -rf "$APP_DIR/$dir"
    ln -sfn "$DATA_DIR/$dir" "$APP_DIR/$dir"
done

if [ ! -f "$DATA_DIR/config.json" ] && [ -f "$SEED_DIR/config.json" ]; then
    cp "$SEED_DIR/config.json" "$DATA_DIR/config.json"
fi
if [ -f "$DATA_DIR/config.json" ]; then
    rm -f "$APP_DIR/config.json"
    ln -sfn "$DATA_DIR/config.json" "$APP_DIR/config.json"
fi

if [ -f "$DATA_DIR/.installed" ]; then
    ln -sfn "$DATA_DIR/.installed" "$APP_DIR/.installed"
fi

chown -R www-data:www-data "$DATA_DIR" "$APP_DIR"

php-fpm -D
exec nginx -g "daemon off;"
