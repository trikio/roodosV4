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

# Instalar PHP 8.4 y extensiones necesarias
sudo apt-get install -yq php8.4 php8.4-cli php8.4-common php8.4-curl php8.4-fpm php-json php8.4-mysql php8.4-readline php8.4-xml php8.4-gd php8.4-intl php8.4-bz2 php8.4-bcmath php8.4-imap php8.4-mbstring php8.4-pgsql php8.4-xmlrpc php8.4-zip php8.4-odbc php8.4-snmp php8.4-interbase php8.4-ldap php8.4-tidy php8.4-memcached php-tcpdf php-redis php-imagick php-mongodb php-pear php-dev

sudo apt install -y libmysqlclient-dev

# Establecer PHP 8.4 como predeterminado
sudo update-alternatives --set php /usr/bin/php8.4

# Instalar OpenSwoole
# apt install -y software-properties-common && add-apt-repository ppa:openswoole/ppa -y
# apt install -y php8.4-openswoole

# Instalar Manticore Search
sudo wget https://repo.manticoresearch.com/manticore-repo.noarch.deb

sudo dpkg -i manticore-repo.noarch.deb
sudo apt update -y
sudo apt install -y manticore manticore-extra

# Habilitar el servicio de Manticore para que inicie con el sistema

sudo cp /app/server-conf/service_systemd/manticore.service /etc/systemd/system/manticore.service

sudo systemctl daemon-reload
sudo systemctl enable manticore
