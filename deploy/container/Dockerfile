ARG PHP_IMAGE=php:8.5.6-apache-bookworm@sha256:c7c967859d6c4e4d3d215e325048b0678d3a44441507bfb5dbdd6c4bcac7c442
FROM ${PHP_IMAGE}
LABEL org.opencontainers.image.source="https://github.com/caymanlogistic-crypto/1c-ip-php"
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    ca-certificates curl unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_mysql bcmath intl zip gd \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.8.9 /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --classmap-authoritative \
 && composer check-platform-reqs --no-dev
COPY app/ app/
COPY modules/ modules/
COPY public/ public/
COPY bin/ bin/
COPY database/ database/
COPY SOURCE-ORIGIN.json CURRENT-SPEC.md ./
COPY deploy/container/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/container/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY deploy/container/entrypoint.sh /usr/local/bin/app-entrypoint
ARG SOURCE_COMMIT
LABEL org.opencontainers.image.revision=${SOURCE_COMMIT}
RUN test -n "$SOURCE_COMMIT" \
 && printf '{"commit":"%s"}\n' "$SOURCE_COMMIT" > /opt/app/release.json \
 && chmod 0755 /usr/local/bin/app-entrypoint \
 && find app modules public bin -name '*.php' -print0 | xargs -0 -n1 php -l > /tmp/php-lint.log \
 && rm /tmp/php-lint.log \
 && apache2ctl -t
EXPOSE 80
ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]

