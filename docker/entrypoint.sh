#!/bin/sh
set -e

cd /var/www/html || exit 1

# Named volume on `storage`: first run is empty — seed from the image skeleton.
if [ ! -d storage/framework ] && [ -d /opt/storage-skeleton ]; then
  mkdir -p storage
  cp -a /opt/storage-skeleton/. storage/
fi

# Ensure writable paths exist (named volumes may mount empty trees on first run)
mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data storage bootstrap/cache
  chmod -R ug+rwx storage bootstrap/cache
fi

exec "$@"
