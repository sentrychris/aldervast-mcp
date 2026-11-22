# The website (chatbot page, /chat and the MCP server at /mcp) as one Docker image.
# README.md, "Offering the server to other people", explains how it is deployed.

# Stage 1: install the PHP packages with Composer. Only vendor/ is kept from this stage.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

# Stage 2: PHP 8.4 with Apache, which runs public/index.php for every request.
FROM php:8.4-apache
# The production php.ini: errors go to the log, never into a response.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public
# Sums up the chatbot's usage log: docker exec aldervast-mcp-web-1 php show-chat-usage.php
COPY show-chat-usage.php ./
# cache/ holds the downloaded wiki pages and the chatbot's counts; Apache's user must be able to write it.
RUN mkdir -p cache logs && chown www-data:www-data cache logs
