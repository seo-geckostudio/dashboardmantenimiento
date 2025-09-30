FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    nginx supervisor \
    libzip-dev zlib1g-dev libicu-dev libonig-dev \
    && docker-php-ext-install zip intl pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set up working directory
WORKDIR /var/www/html

# Copy Nginx configuration
COPY nginx/default.conf /etc/nginx/sites-available/default
COPY nginx/nginx.conf /etc/nginx/nginx.conf

# Copy supervisor configuration
COPY supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy PHP-FPM configuration
COPY php-fpm/www.conf /usr/local/etc/php-fpm.d/www.conf

# Create necessary directories
RUN mkdir -p /var/log/nginx /var/log/supervisor /run/php

# Set permissions
RUN chown -R www-data:www-data /var/www/html /var/log/nginx

# Install PHP dependencies when composer.json is available
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader || true

# Copy application files
COPY . .
RUN chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]