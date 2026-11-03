#!/bin/sh
# php-fpm liveness probe for the app container: FastCGI request to the pool's ping.path (fpm-pool.prod.conf).
# queue and scheduler use the same image but disable this healthcheck in docker/compose.prod.yaml.
set -eu

response=$(SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null) || exit 1

case "$response" in
    *pong*) exit 0 ;;
    *) exit 1 ;;
esac
