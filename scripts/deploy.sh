#!/usr/bin/env bash
#
# Publish the working tree to the panel site directory.
#
# The site's own .env is preserved: it holds the database credentials and the
# application key for this installation. rsync is not available on every
# host, so the sync is done with tar and an explicit delete pass.
#
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="${KJAIU_TARGET:-/www/wwwroot/kjaiu.782778.xyz}"
PHP_BIN="${KJAIU_PHP:-/www/server/php/83/bin/php}"
OWNER="${KJAIU_OWNER:-www:www}"

if [[ ! -d "$TARGET_DIR" ]]; then
    echo "目标目录不存在: $TARGET_DIR" >&2
    exit 1
fi

# Paths that must never be copied from the working tree, or must survive the
# sync on the target.
EXCLUDES=(
    './.git'
    './.env'
    './.env.testing'
    './.env.local'
    './node_modules'
    './admin-spa/node_modules'
    './storage/app'
    './storage/framework/cache/data'
    './storage/framework/sessions'
    './storage/framework/views'
    './storage/logs'
    './tests'
    './.phpunit.cache'
    './.phpunit.result.cache'
    './proxylog'
    './phpunit.xml'
)

tar_args=()
for path in "${EXCLUDES[@]}"; do
    tar_args+=("--exclude=${path}")
done

echo "==> 同步源码 $SOURCE_DIR -> $TARGET_DIR"

# Back up the live environment file, which the sync must not clobber.
ENV_BACKUP=""
if [[ -f "$TARGET_DIR/.env" ]]; then
    ENV_BACKUP="$(mktemp)"
    cp "$TARGET_DIR/.env" "$ENV_BACKUP"
fi

# Replace only the application tree; storage/ and public/ keep local state.
for dir in app bootstrap config database docs resources routes scripts; do
    if [[ -d "$SOURCE_DIR/$dir" ]]; then
        rm -rf "${TARGET_DIR:?}/$dir"
    fi
done

tar -C "$SOURCE_DIR" "${tar_args[@]}" -cf - . | tar -C "$TARGET_DIR" -xf -

# Restore the live environment file over whatever the tree carried.
if [[ -n "$ENV_BACKUP" ]]; then
    cp "$ENV_BACKUP" "$TARGET_DIR/.env"
    rm -f "$ENV_BACKUP"
elif [[ -f "$SOURCE_DIR/.env" ]]; then
    cp "$SOURCE_DIR/.env" "$TARGET_DIR/.env"
fi

echo "==> 目录权限"
mkdir -p "$TARGET_DIR/storage/framework/"{cache/data,sessions,views} \
         "$TARGET_DIR/storage/logs" \
         "$TARGET_DIR/bootstrap/cache"
chown -R "$OWNER" "$TARGET_DIR"

echo "==> 重建缓存"
cd "$TARGET_DIR"
"$PHP_BIN" artisan config:clear >/dev/null
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:clear >/dev/null
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:clear >/dev/null
"$PHP_BIN" artisan view:cache

echo "==> 完成：https://kjaiu.782778.xyz"
