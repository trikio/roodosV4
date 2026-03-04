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

# Instalar PHP 8.5 y extensiones necesarias
sudo apt-get install -yq php8.5 php8.5-cli php8.5-common php8.5-curl php8.5-fpm php-json php8.5-mysql php8.5-readline php8.5-xml php8.5-gd php8.5-intl php8.5-bz2 php8.5-bcmath php8.5-imap php8.5-mbstring php8.5-pgsql php8.5-xmlrpc php8.5-zip php8.5-odbc php8.5-snmp php8.5-interbase php8.5-ldap php8.5-tidy php8.5-memcached php-tcpdf php-redis php-imagick php-mongodb php-pear php-dev

sudo apt install -y libmysqlclient-dev

# Establecer PHP 8.5 como predeterminado
sudo update-alternatives --set php /usr/bin/php8.5

# Instalar OpenSwoole
# apt install -y software-properties-common && add-apt-repository ppa:openswoole/ppa -y
# apt install -y php8.5-openswoole

# Instalar Manticore Search
sudo wget https://repo.manticoresearch.com/manticore-repo.noarch.deb

sudo dpkg -i manticore-repo.noarch.deb
sudo apt update -y
sudo apt install -y manticore manticore-extra

# Habilitar el servicio de Manticore para que inicie con el sistema

sudo cp /app/server-conf/service_systemd/manticore.service /etc/systemd/system/manticore.service

sudo systemctl daemon-reload
sudo systemctl enable manticore

sudo adduser manticore sudo;
sudo mkdir /var/run/manticore;
sudo chown -R manticore:manticore /var/lib/manticore/;
sudo chown -R manticore:manticore /var/run/manticore/;
sudo chown -R manticore:manticore /var/log/manticore/;