FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev libcurl4-openssl-dev unzip \
    && docker-php-ext-install intl mbstring mysqli curl \
    && a2enmod rewrite headers setenvif remoteip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader
COPY app ./app
COPY public ./public
COPY spark ./spark
COPY deploy/container/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/container/serve.sh /usr/local/bin/caresys-serve
RUN mkdir -p writable/cache writable/logs writable/session writable/debugbar writable/uploads \
    && chown -R www-data:www-data writable && chmod +x /usr/local/bin/caresys-serve
ENV CI_ENVIRONMENT=production
EXPOSE 80
CMD ["caresys-serve"]
