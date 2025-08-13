# Use PHP 8.2 with Apache
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    cron \
    supervisor \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache modules
RUN a2enmod rewrite headers expires deflate

# Copy Apache configuration
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy composer files first
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application files
COPY . /var/www/html

# Run composer post-install scripts
RUN composer run-script post-autoload-dump

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copy environment file
COPY .env.example .env

# Generate application key
RUN php artisan key:generate

# Create SQLite database directory and file
RUN mkdir -p /var/www/html/database \
    && touch /var/www/html/database/database.sqlite \
    && chown www-data:www-data /var/www/html/database/database.sqlite

# Copy supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy crontab
COPY docker/crontab /etc/cron.d/laravel-cron
RUN chmod 0644 /etc/cron.d/laravel-cron \
    && crontab /etc/cron.d/laravel-cron

# Copy entrypoint script
COPY docker/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose port
EXPOSE 80

# Start services
ENTRYPOINT ["docker-entrypoint.sh"]
