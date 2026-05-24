FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    mysqli \
    pdo \
    pdo_mysql \
    zip

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# Configure PHP
RUN echo "display_errors = Off" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "error_log = /var/www/html/logs/php_errors.log" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "date.timezone = Asia/Bangkok" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time = 60" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "session.gc_maxlifetime = 604800" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "session.gc_probability = 1" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "session.gc_divisor = 100" >> /usr/local/etc/php/conf.d/custom.ini

# Apache configuration
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/custom.conf \
    && a2enconf custom

# Setup cron for scheduled jobs
COPY ./config/crontab /etc/cron.d/seo-cron
RUN chmod 0644 /etc/cron.d/seo-cron

# Set working directory
WORKDIR /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/logs

# Start Apache and cron
CMD service cron start && apache2-foreground
