#!/bin/bash
set -e

# Railway injects $PORT - we need Apache to listen on it
PORT=${PORT:-80}

echo "Starting Apache on port $PORT..."

# Update Apache to listen on the correct port
sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-enabled/000-default.conf

# Start Apache in foreground
exec apache2-foreground
