#!/bin/sh
set -eu
# Installed as /etc/letsencrypt/renewal-hooks/deploy/equo-nginx
sh /opt/equo/scripts/staging-compose.sh exec -T nginx nginx -s reload
