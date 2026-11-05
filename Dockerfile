# PHP web app (search UI + API). The embedder runs as a separate service.
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev \
 && docker-php-ext-install pdo_pgsql intl \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app
COPY composer.json ./
COPY src ./src
RUN composer dump-autoload --optimize --no-dev

COPY public ./public
COPY db ./db
COPY bin ./bin

# Serve /public; anything that isn't a real file goes to the front controller.
ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && printf '<Directory /var/www/app/public>\n  FallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-enabled/app.conf

# Hosting platforms (Render) pass the port in $PORT.
CMD sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf \
 && sed -i "s/:80>/:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf \
 && apache2-foreground
