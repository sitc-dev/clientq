#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APACHE_CONFIG="$SCRIPT_DIR/apache-host-clientq.conf"

sudo -v
sudo a2enmod proxy proxy_http headers
sudo install -m 644 "$APACHE_CONFIG" /etc/apache2/sites-available/clientq.conf
sudo a2ensite clientq.conf

grep -qE '(^|[[:space:]])clientq\.local([[:space:]]|$)' /etc/hosts \
    || printf '%s\n' '127.0.0.1 clientq.local' | sudo tee -a /etc/hosts >/dev/null
grep -qE '(^|[[:space:]])roadtest-barbers\.clientq\.local([[:space:]]|$)' /etc/hosts \
    || printf '%s\n' '127.0.0.1 roadtest-barbers.clientq.local' | sudo tee -a /etc/hosts >/dev/null

sudo apache2ctl configtest
sudo systemctl reload apache2

printf '%s\n' \
    'ClientQ Apache proxy installed.' \
    'Homepage: http://clientq.local/' \
    'RoadTest Barbers: http://roadtest-barbers.clientq.local/sign-in'
