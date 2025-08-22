# Используем официальный образ Laravel Sail для PHP 8.3
FROM laravel/sail-php:8.3-fpm

# Установка необходимых расширений
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Включаем GD (для работы с изображениями)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo_mysql

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Рабочая директория внутри контейнера
WORKDIR /var/www/html

# Копируем composer.json и composer.lock
COPY composer.json composer.lock ./

# Устанавливаем зависимости (без dev-пакетов!)
RUN composer install --optimize-autoloader --no-dev --no-scripts

# Копируем весь код проекта
COPY . .

# Права на папки storage и cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Кэшируем конфигурацию Laravel (для продакшна)
RUN php artisan config:cache
RUN php artisan route:cache
RUN php artisan view:cache

# Переключаемся на пользователя www-data
USER www-data

# Открываем порт 9000 (для PHP-FPM)
EXPOSE 9000

# Запускаем PHP-FPM
CMD ["php-fpm"]
