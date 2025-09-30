FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    less nano curl sudo git unzip rsync findutils procps \
    libzip-dev zlib1g-dev libicu-dev libonig-dev \
    cron supervisor \
    && docker-php-ext-install zip intl pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install WP-CLI
RUN curl -sSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Create wpops user
ARG PUID=1000
ARG PGID=1000
RUN groupadd -g ${PGID} wpops && useradd -m -u ${PUID} -g ${PGID} -s /bin/bash wpops

# Set up working directory
WORKDIR /app
RUN chown wpops:wpops /app

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy crontab
COPY crontab /etc/cron.d/wpops-cron
RUN chmod 0644 /etc/cron.d/wpops-cron && crontab -u wpops /etc/cron.d/wpops-cron

# Switch to wpops user
USER wpops

# Copy application files first
COPY --chown=wpops:wpops . .

# Install PHP dependencies when composer.json is available
RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader; fi

# Start supervisor
USER root
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]