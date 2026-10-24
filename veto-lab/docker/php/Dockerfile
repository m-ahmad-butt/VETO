ARG WORDPRESS_IMAGE=wordpress:7.0-php8.3-fpm
FROM ${WORDPRESS_IMAGE}

# libfcgi-bin provides cgi-fcgi (healthcheck). Redis ext for object-cache drop-in.
# poppler-utils (pdftotext) is used to parse uploaded COA PDFs in wp-admin.
# Build phpredis from GitHub — pecl.php.net is often unreachable from builders.
ARG PHPREDIS_VERSION=6.1.0
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libfcgi-bin curl poppler-utils $PHPIZE_DEPS; \
    curl -fsSL "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" \
      -o /tmp/phpredis.tar.gz; \
    mkdir -p /tmp/phpredis; \
    tar -xzf /tmp/phpredis.tar.gz -C /tmp/phpredis --strip-components=1; \
    cd /tmp/phpredis; \
    phpize; \
    ./configure; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable redis; \
    cd /; \
    rm -rf /tmp/phpredis /tmp/phpredis.tar.gz; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

COPY conf.d/php-base.ini /usr/local/etc/php/conf.d/zz-base.ini
COPY fpm/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
