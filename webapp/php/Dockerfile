FROM php:8.3-fpm-bookworm

RUN apt update && apt install -y \
  unzip libmemcached-dev zlib1g-dev libssl-dev

RUN docker-php-ext-install pdo pdo_mysql opcache

COPY ../etc/php/conf.d/php-opcache.ini $PHP_INI_DIR/conf.d/

RUN pecl install memcached \
  && docker-php-ext-enable memcached

RUN curl -sS https://getcomposer.org/installer | php \
  && mv composer.phar /usr/local/bin/composer

COPY ./composer.json /var/www/html
COPY ./composer.lock /var/www/html
WORKDIR /var/www/html

RUN composer install --no-dev

COPY . /var/www/html