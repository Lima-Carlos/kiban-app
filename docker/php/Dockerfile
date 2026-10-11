FROM php:8.4-fpm-bookworm

# Instalador de extensões PHP (resolve dependências de sistema automaticamente)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        intl \
        bcmath \
        gd \
        zip \
        pcntl \
        exif \
        opcache

# Composer dentro do container (não precisa de PHP no Windows)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-kiban.ini

# Alinha o UID/GID do www-data com o usuário do host (evita problemas de permissão)
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data \
    && usermod -o -u ${UID} -g ${GID} www-data \
    && mkdir -p /var/www/.composer /var/www/html/vendor \
    && chown -R www-data:www-data /var/www

RUN git config --system --add safe.directory /var/www/html

WORKDIR /var/www/html
USER www-data

EXPOSE 9000
CMD ["php-fpm"]
