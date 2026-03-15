#!/usr/bin/env bash
# install-packages.sh — installs system dependencies for Shipard on Ubuntu LTS (22.04 / 24.04)

set -euo pipefail

# Must be run as root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: this script must be run as root (use sudo bash scripts/install-packages.sh)" >&2
    exit 1
fi

echo "==> Installing prerequisites..."
apt-get install -y ca-certificates apt-transport-https software-properties-common

echo "==> Adding PHP PPA (ondrej/php)..."
add-apt-repository --yes ppa:ondrej/php
apt-get update

echo "==> Installing PHP 8.5, MariaDB, nginx, composer and tools..."
apt-get install -y \
    php8.5-cli php8.5-fpm \
    php8.5-mysql php8.5-xml php8.5-mbstring php8.5-curl php8.5-zip php8.5-intl \
    mariadb-server nginx composer git unzip

echo ""
echo "==> Installation complete."
php --version
mariadb --version
