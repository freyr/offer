FROM php:8.4-cli-alpine
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk --no-cache add  \
    unzip  \
    && rm -rf /var/cache/apk/* \
    && install-php-extensions \
        opcache \
        zip \
        pcntl \
        redis \
        xdebug-3.4.0 \
    && docker-php-source delete \
    && rm -rf /tmp/*

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_CS_FIXER_IGNORE_ENV=1
COPY --from=composer/composer:2-bin /composer /usr/bin/composer
WORKDIR /app
CMD sh -c "vendor/bin/phpunit"