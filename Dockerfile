FROM wordpress:php8.3-apache

# Tools for the first-start setup: wp-cli + unzip; larger PHP limits for the story import.
RUN apt-get update && apt-get install -y --no-install-recommends unzip less mariadb-client \
 && rm -rf /var/lib/apt/lists/* \
 && curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp \
 && { echo 'upload_max_filesize=64M'; echo 'post_max_size=64M'; echo 'memory_limit=512M'; echo 'max_execution_time=300'; } > /usr/local/etc/php/conf.d/site.ini

# Vendored theme + plugin + story bundles. They are copied into the live wp-content on every start,
# so a redeploy always ships the repo's versions (the wp-content volume keeps uploads + DB-managed state).
COPY wp-content/ /opt/site/wp-content/
COPY data/ /opt/site/data/
COPY seed/ /opt/site/seed/
COPY docker/site-entrypoint.sh docker/site-init.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/site-entrypoint.sh /usr/local/bin/site-init.sh

ENTRYPOINT ["site-entrypoint.sh"]
CMD ["apache2-foreground"]
