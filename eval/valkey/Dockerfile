# PHP runner for hidden tests that need phpredis and a real Valkey.
# PHP 8.5: the base apps need ^8.3 (some packages ~8.4|~8.5|~8.6), so 8.5 and 8.6 both run them.
# phpredis 6.3.0 does not compile on 8.6 (XtOffsetOf, INI_STR and zval_dtor are gone); 6.4.0RC1 does.
# To try it: --build-arg PHP_VERSION=8.6 --build-arg PHPREDIS_VERSION=6.4.0RC1.
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli

ARG PHPREDIS_VERSION=6.3.0
LABEL phpredis.version=${PHPREDIS_VERSION}

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev libsqlite3-dev libonig-dev unzip \
 && docker-php-ext-install -j"$(nproc)" pdo_sqlite mbstring bcmath intl zip pcntl \
 && rm -rf /var/lib/apt/lists/*

# php:8.4+ images ship no pecl, so build phpredis from the PECL tarball, as `pecl install` would.
RUN mkdir /tmp/phpredis \
 && curl -fsSL https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz | tar -xz -C /tmp/phpredis --strip-components=1 \
 && cd /tmp/phpredis && phpize \
 && ./configure --enable-redis --disable-redis-igbinary --disable-redis-msgpack --disable-redis-lzf --disable-redis-zstd --disable-redis-lz4 \
 && make -j"$(nproc)" && make install \
 && docker-php-ext-enable redis \
 && rm -rf /tmp/phpredis

# default_socket_timeout caps any hang (a TLS handshake against a silent peer, a blocked read) at 15 s,
# so a build that sets no timeout fails fast instead of eating the harness's 900 s limit.
# openssl.cafile makes TLS to valkey.test verify against the repo's CA, like a system CA store would.
COPY php-hidden.ini /usr/local/etc/php/conf.d/zz-hidden.ini

WORKDIR /app
