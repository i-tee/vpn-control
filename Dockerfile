# Берём официальный образ PHP 8.2 с встроенным PHP-FPM
FROM php:8.2-fpm

# Установка системных зависимостей
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && pecl install redis \
    && docker-php-ext-enable redis

# Установка Composer (менеджер зависимостей PHP)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Устанавливаем рабочую директорию внутри контейнера
WORKDIR /var/www/html

# Копируем ВЕСЬ проект в контейнер
COPY . .

# Устанавливаем права на папки до установки зависимостей
RUN chown -R www-data:www-data . && chmod -R 755 .

# Устанавливаем PHP-зависимости
RUN composer install --no-interaction --prefer-dist --no-scripts

# Очищаем кэш Composer для уменьшения размера образа
RUN composer clear-cache
