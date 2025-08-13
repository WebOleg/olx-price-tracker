#!/bin/bash
set -e

echo "🚀 Starting OLX Price Tracker application..."

# Fix git ownership issue
git config --global --add safe.directory /var/www/html

# Install composer dependencies without running scripts
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader --no-scripts

# CRITICAL: Remove bootstrap cache after composer install to clear PailServiceProvider
echo "🧹 Clearing bootstrap cache..."
rm -rf /var/www/html/bootstrap/cache/*

# Copy environment file if it doesn't exist
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi

# Create SQLite database
echo "📊 Setting up database..."
touch /var/www/html/database/database.sqlite
chown www-data:www-data /var/www/html/database/database.sqlite

# Run migrations WITHOUT config cache
php artisan migrate --force

# Ensure proper permissions
echo "🔐 Setting up permissions..."
chown -R www-data:www-data /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache

# Create failed_jobs table if it doesn't exist
echo "📋 Setting up queue tables..."
php artisan queue:table 2>/dev/null || true
php artisan migrate --force

# Clear caches safely (skip config:cache to avoid PailServiceProvider issue)
echo "⚙️ Clearing caches..."
php artisan route:clear || true
php artisan view:clear || true

# Start cron service
echo "⏰ Starting cron service..."
service cron start

# Start supervisor (handles queue workers)
echo "👷 Starting background workers..."
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf &

# Start Apache
echo "🌐 Starting web server..."
echo "✅ OLX Price Tracker is ready!"
echo "📍 Access the application at: http://localhost:8080"

# Start Apache in foreground
apache2-foreground
