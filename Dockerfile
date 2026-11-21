# syntax=docker/dockerfile:1

# The optional CDN contains only Apache; it never receives PHP or the sources.
FROM httpd:2.4-bookworm AS cdn
ENV CDN_HOST_PATTERN=*.localhost
COPY docker/httpd.conf /usr/local/apache2/conf/httpd.conf
COPY LICENSE /usr/local/apache2/LICENSE.runner
RUN mkdir -p /target && httpd -t
EXPOSE 80

# Keep the worker last so `docker build .` builds the RabbitMQ worker by default.
FROM php:8.5-cli-bookworm AS worker
LABEL org.opencontainers.image.source="https://github.com/leuffen/leuffen-pagebuilder-jekyll-runner" \
      org.opencontainers.image.licenses="MIT"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git rsync unzip jekyll ruby-dev libssl-dev ruby-zip \
        ruby-jekyll-sass-converter ruby-jekyll-optional-front-matter \
        ruby-jekyll-sitemap ruby-jekyll-seo-tag ruby-jekyll-feed \
    && docker-php-ext-install -j"$(nproc)" pcntl sockets \
    && php -r 'foreach (["pcntl", "posix", "sockets", "mbstring"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: " . $extension); } }' \
    && gem install --no-document openssl -v 4.0.2 \
    && gem install --no-document --ignore-dependencies jekyll-remote-theme -v 0.6.3 \
    && ruby -e 'require "jekyll-remote-theme"' \
    && apt-get purge -y --auto-remove ruby-dev libssl-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.* ./
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH \
    composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY src/ ./src/
COPY bin/ ./bin/
COPY LICENSE ./LICENSE
RUN composer dump-autoload --no-dev --no-interaction --optimize \
    && groupadd --gid 1000 runner \
    && useradd --uid 1000 --gid runner --create-home runner \
    && mkdir -p /source /target /tmp/jekyll-runner \
    && chown runner:runner /source /target /tmp/jekyll-runner

ENV JEKYLL_SOURCE_ROOT=/source \
    JEKYLL_TARGET_ROOT=/target \
    JEKYLL_BUILD_ROOT=/tmp/jekyll-runner \
    JEKYLL_NO_BUNDLER_REQUIRE=true \
    JEKYLL_BUILD_TIMEOUT=300 \
    JEKYLL_MAX_OUTPUT_BYTES=65536 \
    AMQP_HOST=rabbitmq \
    AMQP_PORT=5672 \
    AMQP_USER=runner \
    AMQP_PASSWORD=runner \
    AMQP_VHOST=/ \
    AMQP_EXCHANGE=leuffen.jekyll.v1 \
    AMQP_QUEUE=leuffen.jekyll.v1.requests \
    AMQP_ROUTING_KEY=rpc.request \
    AMQP_RECONNECT_DELAY=30

USER runner
ENTRYPOINT ["php", "/app/bin/worker.php"]
