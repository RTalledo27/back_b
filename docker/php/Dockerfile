FROM php:8.3-cli-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    git \
    unzip \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    zip \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install \
    pdo \
    pdo_pgsql \
    intl \
    mbstring \
    zip \
    gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer