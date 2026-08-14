FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git curl unzip libpq-dev libonig-dev libzip-dev zip autoconf pkg-config libssl-dev \
    && docker-php-ext-install pdo mbstring zip \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN php artisan config:clear && php artisan route:clear && php artisan view:clear

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

CMD php artisan serve --host=0.0.0.0 --port=${PORT:-10000}