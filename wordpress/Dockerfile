FROM wordpress:6.5-php8.2-apache

# --- Vulnerability: Directory Listing Enabled ---------------------------
# Enables Apache "Indexes" for uploads/backups so an unauthenticated
# attacker can browse and download files placed in wp-content/uploads.
COPY conf/directory-listing.conf /etc/apache2/conf-enabled/zz-directory-listing.conf
RUN a2enmod autoindex

# wp-cli, used by the setup container to provision the lab
RUN apt-get update && apt-get install -y --no-install-recommends curl less default-mysql-client unzip \
    && curl -sSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && rm -rf /var/lib/apt/lists/*

# Bundle the deliberately vulnerable plugin into the image so it's
# always present in wp-content/plugins after the volume is populated.
COPY plugins/staff-portal-manager /usr/src/staff-portal-manager

# Bundle the mu-plugin that makes XML-RPC username enumeration (vuln #1)
# deterministic. mu-plugins auto-load with no activation step.
COPY mu-plugins /usr/src/mu-plugins

EXPOSE 80
