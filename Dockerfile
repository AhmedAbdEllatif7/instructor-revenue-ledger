FROM php:8.2

WORKDIR /public_html

RUN apt-get update && apt-get install -y unzip \
    && docker-php-ext-install pdo_mysql bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

COPY . .

RUN composer install --prefer-dist --no-interaction --no-progress

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

