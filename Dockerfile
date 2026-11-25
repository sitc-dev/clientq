FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql curl \
    && a2enmod rewrite \
    && echo "ServerName clientq.local" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

COPY docker/apache-clientq.conf /etc/apache2/sites-available/clientq.conf
RUN a2dissite 000-default \
    && a2ensite clientq

COPY . /var/www/html
ENTRYPOINT ["sh", "/var/www/html/docker/app-entrypoint.sh"]
CMD ["apache2-foreground"]
RUN mkdir -p /var/log/clientq /var/lib/clientq/pending-uploads \
    && chown -R www-data:www-data /var/www/html/uploads /var/log/clientq /var/lib/clientq
