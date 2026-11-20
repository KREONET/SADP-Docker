# Versions
# https://hub.docker.com/r/serversideup/php/tags?name=8.4-fpm-nginx-alpine
# Pinned to PHP >= 8.4.27 for CVE-2026-109618 / CVE-2026-109619 ("php2shell"); never go below this.
# Keep the serversideup v5 line (-v5.x suffix): v4 images ignore s6-overlay user-bundles.d, so Reverb/Horizon/migrations never start.
ARG SERVERSIDEUP_PHP_VERSION=8.4.27-fpm-nginx-alpine-v5.0.5
# https://github.com/cloudflare/cloudflared/releases
# >= 2026.10.3 for the eMudhra certificate compromise. Update the SHA-256 values with the version.
ARG CLOUDFLARED_VERSION=2026.10.7
ARG CLOUDFLARED_SHA256_AMD64=1f99fb518b83dec2ba8d870a7cc991d82a77e6196295a3d2754ba17ab9f91872
ARG CLOUDFLARED_SHA256_ARM64=3df43aedc9ff291d1735b5cb576cb97e0b157488796db2e32b28aecabb445775
# https://www.postgresql.org/support/versioning/
# Note: We are using version 18 of the postgres client (while still using postgres 15 for the postgres server) as version 15 has been removed from Alpine 3.23+ https://pkgs.alpinelinux.org/packages?name=postgresql*-client&branch=v3.23&repo=&arch=x86_64&origin=&flagged=&maintainer=
ARG POSTGRES_VERSION=18
# https://nginx.org/en/linux_packages.html
# >= 1.31.7 for CVE-2026-15176, CVE-2026-93479, CVE-2026-95101, CVE-2026-97205 and CVE-2026-97629.
ARG NGINX_VERSION=1.31.8-r1
# https://nodejs.org/en/about/previous-releases
ARG NODE_VERSION=24.22.1
# https://pecl.php.net/package/uv
ARG PHP_UV_VERSION=0.3.0

# Add user/group
ARG USER_ID=9999
ARG GROUP_ID=9999

# Set by the build workflow
ARG COOLIFY_VERSION
ARG SOURCE_DATE_EPOCH
ARG CREATED
ARG GIT_SHA

# =================================================================
# Stage 1: Composer dependencies
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION} AS base

USER root

# Install patched Nginx from the official nginx.org Alpine repository
ARG NGINX_VERSION
RUN set -eux; \
    apk add --no-cache ca-certificates curl; \
    NGINX_ALPINE_VERSION="$(egrep -o '^[0-9]+\.[0-9]+' /etc/alpine-release)"; \
    NGINX_REPOSITORY="https://nginx.org/packages/mainline/alpine/v${NGINX_ALPINE_VERSION}/main"; \
    sed -i 's|http://nginx.org/packages|https://nginx.org/packages|g' /etc/apk/repositories; \
    grep -qxF "@nginx ${NGINX_REPOSITORY}" /etc/apk/repositories || echo "@nginx ${NGINX_REPOSITORY}" >> /etc/apk/repositories; \
    curl -fsSL https://nginx.org/keys/nginx_signing.rsa.pub -o /etc/apk/keys/nginx_signing.rsa.pub; \
    apk add --no-cache --upgrade "nginx@nginx=${NGINX_VERSION}"; \
    rm -f /etc/nginx/nginx.conf /etc/nginx/conf.d/default.conf; \
    nginx -v

ARG USER_ID
ARG GROUP_ID

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/cache \
    COMPOSER_CACHE_DIR=/tmp/cache composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist

USER www-data

FROM node:${NODE_VERSION}-alpine3.24 AS nodejs

# =================================================================
# Stage 2: Frontend assets compilation
# =================================================================
FROM nodejs AS static-assets

WORKDIR /app
COPY package*.json vite.config.js postcss.config.cjs ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci
COPY . .
RUN npm run build

# =================================================================
# Stage 3: Terminal dependencies (match the production Node.js ABI and libc)
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION} AS terminal-dependencies

USER root

RUN apk upgrade && \
    apk add --no-cache make g++ python3
COPY --from=nodejs /usr/local/bin/node /usr/local/bin/node
COPY --from=nodejs /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
COPY docker/coolify-terminal/package*.json /terminal/
RUN --mount=type=cache,target=/root/.npm \
    npm ci --prefix /terminal && \
    npm rebuild node-pty --prefix /terminal --update-binary && \
    rm -rf /terminal/node_modules/node-pty/prebuilds/darwin-* \
        /terminal/node_modules/node-pty/prebuilds/win32-*
COPY docker/coolify-terminal/terminal-server.js /terminal/terminal-server.js
COPY docker/coolify-terminal/terminal-utils.js /terminal/terminal-utils.js

# =================================================================
# Final Stage: Production image
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION}

ARG USER_ID
ARG GROUP_ID
ARG TARGETPLATFORM
ARG POSTGRES_VERSION
ARG CLOUDFLARED_VERSION
ARG CLOUDFLARED_SHA256_AMD64
ARG CLOUDFLARED_SHA256_ARM64
ARG NGINX_VERSION
ARG PHP_UV_VERSION
ARG COOLIFY_VERSION
ARG CI=true

ENV COOLIFY_VERSION=${COOLIFY_VERSION}

WORKDIR /var/www/html

USER root

# Install patched Nginx from the official nginx.org Alpine repository
RUN set -eux; \
    apk add --no-cache ca-certificates curl; \
    NGINX_ALPINE_VERSION="$(egrep -o '^[0-9]+\.[0-9]+' /etc/alpine-release)"; \
    NGINX_REPOSITORY="https://nginx.org/packages/mainline/alpine/v${NGINX_ALPINE_VERSION}/main"; \
    sed -i 's|http://nginx.org/packages|https://nginx.org/packages|g' /etc/apk/repositories; \
    grep -qxF "@nginx ${NGINX_REPOSITORY}" /etc/apk/repositories || echo "@nginx ${NGINX_REPOSITORY}" >> /etc/apk/repositories; \
    curl -fsSL https://nginx.org/keys/nginx_signing.rsa.pub -o /etc/apk/keys/nginx_signing.rsa.pub; \
    apk add --no-cache --upgrade "nginx@nginx=${NGINX_VERSION}"; \
    rm -f /etc/nginx/nginx.conf /etc/nginx/conf.d/default.conf; \
    nginx -v

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# Install PostgreSQL repository and keys
RUN apk add --no-cache gnupg && \
    mkdir -p /usr/share/keyrings && \
    curl -fSsL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor > /usr/share/keyrings/postgresql.gpg

# Install system dependencies
RUN --mount=type=cache,target=/var/cache/apk \
    apk upgrade && \
    apk add --no-cache \
    postgresql${POSTGRES_VERSION}-client \
    libstdc++ \
    openssh-client \
    git \
    git-lfs \
    jq \
    lsof \
    procps \
    vim

# Install PHP extensions
# uv: Reverb uses an ext-uv event loop instead of stream_select(), which fails at 1024 open files (FD_SETSIZE)
RUN install-php-extensions uv-${PHP_UV_VERSION}

# Configure shell aliases
RUN echo "alias ll='ls -al'" >> /etc/profile && \
    echo "alias a='php artisan'" >> /etc/profile && \
    echo "alias logs='tail -f storage/logs/laravel.log'" >> /etc/profile

# Install Cloudflared based on architecture and verify the published SHA-256 checksum
RUN set -eu; \
    mkdir -p /usr/local/bin; \
    case "${TARGETPLATFORM}" in \
    linux/amd64) CLOUDFLARED_ARCH=amd64; CLOUDFLARED_SHA256="${CLOUDFLARED_SHA256_AMD64}" ;; \
    linux/arm64) CLOUDFLARED_ARCH=arm64; CLOUDFLARED_SHA256="${CLOUDFLARED_SHA256_ARM64}" ;; \
    *) echo "Unsupported TARGETPLATFORM for cloudflared: ${TARGETPLATFORM}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-${CLOUDFLARED_ARCH}" -o /usr/local/bin/cloudflared; \
    echo "${CLOUDFLARED_SHA256}  /usr/local/bin/cloudflared" | sha256sum -c -; \
    chmod +x /usr/local/bin/cloudflared

# Configure PHP
COPY docker/production/etc/php/conf.d/zzz-custom-php.ini /usr/local/etc/php/conf.d/zzz-custom-php.ini
ENV PHP_OPCACHE_ENABLE=1
# v5 defaults this to 0. Keep 1: app:init rebuilds the Laravel caches while php-fpm already runs.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
# v5 defaults to 256 MB with 32 MB interned strings. Every PHP CLI process has its own cache, so keep it small.
ENV PHP_OPCACHE_MEMORY_CONSUMPTION=128
ENV PHP_OPCACHE_INTERNED_STRINGS_BUFFER=16

# Configure entrypoint
COPY --chmod=755 docker/production/entrypoint.d/ /etc/entrypoint.d

# Copy application files from previous stages
COPY --from=base --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=static-assets --chown=www-data:www-data /app/public/build ./public/build

# Copy application source code
COPY --chown=www-data:www-data composer.json composer.lock ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data lang ./lang
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data storage ./storage
COPY --chown=www-data:www-data templates ./templates
COPY --chown=www-data:www-data resources/views ./resources/views
# Open source notices page (generate:open-source-licenses) and Coolify's own license.
COPY --chown=www-data:www-data resources/open-source ./resources/open-source
COPY --chown=www-data:www-data LICENSE ./LICENSE
COPY --chown=www-data:www-data resources/image-scan ./resources/image-scan
COPY --chown=www-data:www-data artisan artisan
COPY --chown=www-data:www-data openapi.yaml ./openapi.yaml
COPY --chown=www-data:www-data changelogs/ ./changelogs/
COPY --chown=www-data:www-data release.json upstream.json ./

RUN composer dump-autoload

# Install terminal WebSocket server dependencies
COPY --from=nodejs /usr/local/bin/node /usr/local/bin/node
COPY --from=terminal-dependencies --chown=www-data:www-data /terminal /terminal

# Configure Nginx and S6 overlay
COPY docker/production/etc/nginx/conf.d/custom.conf /etc/nginx/conf.d/custom.conf
COPY docker/production/etc/nginx/site-opts.d/http.conf /etc/nginx/site-opts.d/http.conf
COPY --chmod=755 docker/production/etc/s6-overlay/ /etc/s6-overlay/

RUN mkdir -p /etc/nginx/conf.d && \
    chown -R www-data:www-data /etc/nginx && \
    chmod -R 755 /etc/nginx

ARG SERVERSIDEUP_PHP_VERSION
ARG NODE_VERSION
ARG CREATED
ARG GIT_SHA

LABEL org.opencontainers.image.title="Coolify" \
    org.opencontainers.image.description="An open-source, self-hostable PaaS alternative to Vercel, Heroku & Netlify that lets you easily deploy static sites, databases, full-stack applications and 360+ one-click services on your own servers." \
    org.opencontainers.image.version="${COOLIFY_VERSION}" \
    org.opencontainers.image.created="${CREATED}" \
    org.opencontainers.image.revision="${GIT_SHA}" \
    org.opencontainers.image.licenses="Apache-2.0" \
    org.opencontainers.image.authors="coollabsio" \
    org.opencontainers.image.vendor="coollabsio" \
    org.opencontainers.image.url="https://coolify.io" \
    org.opencontainers.image.source="https://github.com/coollabsio/coolify" \
    org.opencontainers.image.documentation="https://coolify.io/docs" \
    org.opencontainers.image.base.name="docker.io/serversideup/php:${SERVERSIDEUP_PHP_VERSION}" \
    io.coolify.nginx.version="${NGINX_VERSION}" \
    io.coolify.node.version="${NODE_VERSION}" \
    io.coolify.cloudflared.version="${CLOUDFLARED_VERSION}" \
    io.coolify.postgresql-client.version="${POSTGRES_VERSION}" \
    io.coolify.php-uv.version="${PHP_UV_VERSION}"

# Switch to non-root user
USER www-data
