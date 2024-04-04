FROM php:8.3-fpm-bookworm

RUN apt update && apt install -y \
  unzip libmemcached-dev zlib1g-dev libssl-dev

RUN docker-php-ext-install pdo pdo_mysql

RUN pecl install memcached \
  && docker-php-ext-enable memcached

RUN curl -sS https://getcomposer.org/installer | php \
  && mv composer.phar /usr/local/bin/composer

RUN composer install --no-dev
