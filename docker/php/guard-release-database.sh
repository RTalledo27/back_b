#!/bin/sh
set -eu

case "${DB_DATABASE:-}" in
    ""|backend_rifas_app|defaultdb|postgres)
        echo "[runtime] Refusing to use a missing or protected database name." >&2
        exit 64
        ;;
esac

exec docker-php-entrypoint "$@"
