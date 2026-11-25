#!/bin/sh
set -eu

mkdir -p /var/log/clientq
# Retain the bind-mounted directory's host group for local access.
chown www-data /var/log/clientq
chmod 2770 /var/log/clientq
find /var/log/clientq -maxdepth 1 -type f -name 'clientq-*.log' \
    -exec chown www-data {} + \
    -exec chmod g+rw {} +

exec docker-php-entrypoint "$@"
