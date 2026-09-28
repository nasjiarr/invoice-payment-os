# ==========================================
# Stage 1: Build Frontend Assets via Node.js
# ==========================================
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package*.json vite.config.js ./
RUN npm ci --ignore-scripts
COPY resources ./resources
COPY public ./public
RUN npm run build

# ==========================================
# Stage 2: Production PHP-FPM Image (PHP 8.4)
# ==========================================
FROM php:8.4-fpm-alpine AS base

# Install system dependencies & libraries required for PHP extensions
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    oniguruma-dev \
    icu-dev \
    linux-headers \
    $PHPIZE_DEPS

# Configure and install core PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache

# Install and enable Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Clean up build dependencies to reduce image size
RUN apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

# Install Composer 2 from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy custom PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Copy entrypoint script
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy application files
COPY . /var/www/html

# Copy compiled frontend assets from stage 1
COPY --from=frontend /app/public/build /var/www/html/public/build

# Install PHP composer dependencies (optimized for production)
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# Ensure storage & bootstrap/cache permissions
RUN mkdir -p \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["php-fpm"]
