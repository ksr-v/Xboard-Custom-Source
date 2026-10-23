FROM phpswoole/swoole:php8.2-alpine@sha256:85fbb4fa34e8075e8d8b746fa2faebfbd8f1313abb96bb93578a4e647fbd6daf

COPY --from=mlocati/php-extension-installer@sha256:ac4b252c153a9f6de5578ff2c417790aba836bb49855543144606033eceda253 /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    apk --no-cache add shadow su-exec sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis caddy && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /

# Build only the already verified independent source context, including plugins.
COPY --chown=www:www . /www

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN chown www:www /www
USER www
RUN mkdir -p bootstrap/cache plugins storage/app/public storage/app/private storage/app/node_endpoint \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/theme \
    && chmod 0700 storage/app/private storage/app/node_endpoint \
    && composer install --no-cache --no-dev --no-interaction \
    && php artisan storage:link
USER root
RUN mkdir -p /data \
    && chown redis:redis /data
    
ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"] 
