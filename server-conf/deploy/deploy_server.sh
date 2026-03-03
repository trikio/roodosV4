#!/bin/bash

sudo mkdir -p /run/php/

# Actualizar repositorios
sudo apt-get update -y

# Instalar paquetes básicos
sudo apt-get install -y software-properties-common apt-transport-https cron vim wget unzip curl less git nginx sudo

# Ejecutar unattended-upgrades
# /usr/bin/unattended-upgrades -v

# Agregar repositorio de PHP y actualizar
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y

# Instalar PHP 8.3 y extensiones necesarias
sudo apt-get install -yq php8.3 php8.3-cli php8.3-common php8.3-curl php8.3-fpm php-json php8.3-mysql php8.3-readline php8.3-xml php8.3-gd php8.3-intl php8.3-bz2 php8.3-bcmath php8.3-imap php8.3-mbstring php8.3-pgsql php8.3-xmlrpc php8.3-zip php8.3-odbc php8.3-snmp php8.3-interbase php8.3-ldap php8.3-tidy php8.3-memcached php-tcpdf php-redis php-imagick php-mongodb php-pear php-dev

sudo apt install -y libmysqlclient-dev

# Establecer PHP 8.3 como predeterminado
sudo update-alternatives --set php /usr/bin/php8.3

# Instalar OpenSwoole
# apt install -y software-properties-common && add-apt-repository ppa:openswoole/ppa -y
# apt install -y php8.3-openswoole

# Instalar Manticore Search
sudo wget https://repo.manticoresearch.com/manticore-repo.noarch.deb

sudo dpkg -i manticore-repo.noarch.deb
sudo apt update -y
sudo apt install -y manticore manticore-extra

# Habilitar el servicio de Manticore para que inicie con el sistema

sudo cp /app/server-conf/service_systemd/manticore.service /etc/systemd/system/manticore.service

sudo systemctl daemon-reload
sudo systemctl enable manticore
