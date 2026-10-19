FROM php:8.2-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libonig-dev \
        libicu-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql bcmath zip mbstring intl opcache \
    && printf 'upload_max_filesize=64M\npost_max_size=68M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/uploads.ini \
    && printf 'expose_php=Off\n' > /usr/local/etc/php/conf.d/security.ini \
    && printf '%s\n' \
        'opcache.enable=1' \
        'opcache.enable_cli=1' \
        'opcache.memory_consumption=128' \
        'opcache.interned_strings_buffer=16' \
        'opcache.max_accelerated_files=20000' \
        'opcache.validate_timestamps=0' \
        'opcache.revalidate_freq=0' \
        'opcache.save_comments=1' \
        'opcache.jit=1255' \
        'opcache.jit_buffer_size=64M' \
        > /usr/local/etc/php/conf.d/opcache-production.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 8080

CMD ["bash", "start.sh"]
