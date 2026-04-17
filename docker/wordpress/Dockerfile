FROM wordpress:6.9.4-php8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip mariadb-client ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

COPY docker/wordpress/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/wordpress/scripts/bootstrap.sh /usr/local/bin/bootstrap.sh

RUN chmod +x /usr/local/bin/bootstrap.sh

ENTRYPOINT ["bootstrap.sh"]
CMD ["php-fpm"]
