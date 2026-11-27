FROM php:8.3-fpm-alpine

LABEL maintainer="KutStudio"
LABEL description="KutBlog — CMS minimalista de código abierto"

# Dependencias del sistema y librerías para extensiones PHP
RUN apk add --no-cache \
    nginx \
    curl \
    bash \
    sqlite-libs \
    libpng \
    libjpeg-turbo \
    libwebp \
    freetype \
    libzip \
    icu-libs \
    oniguruma \
    libxml2 \
    && apk add --no-cache --virtual .build-deps \
    sqlite-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        gd \
        mbstring \
        zip \
        intl \
        xml \
        fileinfo \
        opcache \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# Configuración Nginx y PHP
COPY Docker/nginx.conf /etc/nginx/nginx.conf
COPY Docker/default.conf /etc/nginx/http.d/default.conf
COPY Docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-kutblog.conf
COPY Docker/php.ini /usr/local/etc/php/conf.d/kutblog.ini

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

# Respaldo de datos iniciales para hidratar /data en el primer arranque
RUN mkdir -p /opt/kutblog-seed /data \
    && cp -a content /opt/kutblog-seed/content \
    && cp -a media /opt/kutblog-seed/media \
    && cp -a cache /opt/kutblog-seed/cache \
    && cp -a plugins /opt/kutblog-seed/plugins \
    && cp -a themes /opt/kutblog-seed/themes \
    && if [ -f config.json ]; then cp config.json /opt/kutblog-seed/config.json; fi \
    && mkdir -p /var/log/nginx /var/lib/nginx/tmp \
    && chown -R www-data:www-data /var/www/html /data /var/log/nginx /var/lib/nginx

COPY Docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

VOLUME ["/data"]
EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
