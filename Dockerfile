# syntax=docker/dockerfile:1

# Dedicated static CDN: Ubuntu apt Apache only, without PHP or Jekyll.
FROM ubuntu:26.04 AS cdn
ENV DEBIAN_FRONTEND=noninteractive \
    CDN_HOST_PATTERN=*.localhost \
    APACHE_RUN_DIR=/tmp \
    APACHE_LOCK_DIR=/tmp \
    APACHE_PID_FILE=/tmp/apache2.pid \
    APACHE_LOG_DIR=/tmp \
    APACHE_RUN_USER=www-data \
    APACHE_RUN_GROUP=www-data
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends apache2 ca-certificates \
    && a2enmod headers rewrite vhost_alias negotiation \
    && a2dissite 000-default \
    && rm -rf /var/lib/apt/lists/*
COPY docker/httpd.conf /etc/apache2/sites-available/runner.conf
COPY LICENSE /usr/share/doc/apache2/LICENSE.runner
RUN ln -s /etc/apache2/sites-available/runner.conf /etc/apache2/sites-enabled/runner.conf \
    && mkdir -p /target \
    && apache2ctl configtest
EXPOSE 80
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]

# Kickstart already provides Ubuntu, PHP CLI/extensions and Composer.
# Keep worker last so docker build . builds the Jekyll worker by default.
FROM ghcr.io/nfra-project/kickstart-flavor-php:8.4 AS worker
LABEL org.opencontainers.image.source="https://github.com/leuffen/leuffen-pagebuilder-jekyll-runner" \
      org.opencontainers.image.licenses="MIT"
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        git rsync unzip jekyll ruby-zip \
        ruby-jekyll-sass-converter ruby-jekyll-optional-front-matter \
        ruby-jekyll-sitemap ruby-jekyll-seo-tag ruby-jekyll-feed \
    && php -r 'if (PHP_VERSION_ID < 80500) { throw new RuntimeException("Jekyll runner requires PHP >= 8.5"); } foreach (["pcntl", "posix", "sockets", "mbstring"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: " . $extension); } }' \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY composer.* ./
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH \
    composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY src/ ./src/
COPY bin/ ./bin/
COPY docs/ ./docs/
COPY LICENSE ./LICENSE
RUN composer dump-autoload --no-dev --no-interaction --optimize \
    && mkdir -p /source /target /tmp/jekyll-runner \
    && chown 1000:1000 /source /target /tmp/jekyll-runner

ENV JEKYLL_SOURCE_ROOT=/source \
    JEKYLL_TARGET_ROOT=/target \
    JEKYLL_BUILD_ROOT=/tmp/jekyll-runner \
    JEKYLL_NO_BUNDLER_REQUIRE=true \
    JEKYLL_BUILD_TIMEOUT=300 \
    JEKYLL_MAX_OUTPUT_BYTES=65536 \
    CONF_AMQ_CONNECTION=rabbitmq://runner:runner@rabbitmq:5672/

USER 1000:1000
ENTRYPOINT ["php", "/app/bin/worker.php"]
