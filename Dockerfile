FROM php:8.2

WORKDIR /public_html

RUN apt-get update && apt-get install -y unzip libicu-dev libzip-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install pdo_mysql bcmath intl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

COPY . .

RUN composer install --prefer-dist --no-interaction --no-progress

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

