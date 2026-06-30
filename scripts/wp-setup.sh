#!/bin/bash
set -e

WP="wp --allow-root --path=/var/www/html"

echo "[*] Waiting for MySQL..."
until mysqladmin ping -h"${WORDPRESS_DB_HOST%%:*}" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" --silent; do
    sleep 2
done

echo "[*] Waiting for WordPress files..."
until [ -f /var/www/html/wp-load.php ]; do
    sleep 2
done

cat > /var/www/html/wp-config.php << EOF
<?php
define('DB_NAME', '${WORDPRESS_DB_NAME}');
define('DB_USER', '${WORDPRESS_DB_USER}');
define('DB_PASSWORD', '${WORDPRESS_DB_PASSWORD}');
define('DB_HOST', '${WORDPRESS_DB_HOST}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
\$table_prefix = 'wp_';
define('WP_DEBUG', false);
define('XMLRPC_ENABLED', true); // intentionally left enabled
if ( ! defined('ABSPATH') ) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
EOF

if ! $WP core is-installed 2>/dev/null; then
    echo "[*] Installing WordPress core..."
    $WP core install \
        --url="http://localhost:8080" \
        --title="Staff Portal" \
        --admin_user="admin" \
        --admin_password="AdminP@ssw0rd!" \
        --admin_email="admin@staffportal.local" \
        --skip-email

    echo "[*] Creating low-privilege staff account (matches leaked backup creds)..."
    $WP user create jsmith jsmith@staffportal.local \
        --role=subscriber \
        --user_pass='Summer2024!' \
        --display_name="John Smith"

    echo "[*] Creating a couple more staff accounts (for XML-RPC enumeration realism)..."
    $WP user create akumar akumar@staffportal.local --role=subscriber --user_pass="$(openssl rand -base64 12)" --display_name="Anita Kumar" || true
    $WP user create mlee mlee@staffportal.local --role=subscriber --user_pass="$(openssl rand -base64 12)" --display_name="Marcus Lee" || true

    echo "[*] Setting permalinks..."
    $WP rewrite structure '/%postname%/'

    echo "[*] Publishing a couple of internal announcement posts..."
    $WP post create --post_type=post --post_title="Office Closure Notice" \
        --post_content="The office will be closed for maintenance this Friday." \
        --post_status=publish

    echo "[*] Activating the Staff Portal Manager plugin..."
    cp -r /usr/src/staff-portal-manager /var/www/html/wp-content/plugins/staff-portal-manager
    $WP plugin activate staff-portal-manager

    echo "[*] Installing XML-RPC enumeration mu-plugin (vuln #1)..."
    mkdir -p /var/www/html/wp-content/mu-plugins
    cp /usr/src/mu-plugins/*.php /var/www/html/wp-content/mu-plugins/
    chown -R www-data:www-data /var/www/html/wp-content/mu-plugins

    echo "[*] Seeding leaked backup file into the (directory-listing-enabled) uploads folder..."
    mkdir -p /var/www/html/wp-content/uploads/backups
    cp /seed/portal_backup_2024-03.zip /var/www/html/wp-content/uploads/backups/
    chown -R www-data:www-data /var/www/html/wp-content/uploads

    echo "[*] Lab provisioning complete."
else
    echo "[*] WordPress already installed, skipping provisioning."
fi

echo "============================================================"
echo " Staff Portal lab is ready at: http://localhost:8080"
echo " Admin login (for grading/reset only): admin / AdminP@ssw0rd!"
echo " Leaked low-priv creds (intended discovery path):"
echo "   http://localhost:8080/wp-content/uploads/backups/"
echo "============================================================"
