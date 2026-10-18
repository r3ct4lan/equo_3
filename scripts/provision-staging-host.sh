#!/bin/sh
# Run as root on a dedicated Ubuntu 24.04 staging host.
set -eu

. /etc/os-release
[ "$ID" = ubuntu ] && [ "$VERSION_CODENAME" = noble ] || {
    echo 'This script requires Ubuntu 24.04.' >&2
    exit 1
}
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl python3 openssl certbot
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: noble
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
apt-get update -qq
apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker certbot.timer
install -d -m 0755 /opt/equo /var/www/letsencrypt
docker version --format '{{.Server.Version}}'
docker compose version
