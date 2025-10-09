#!/usr/bin/env sh
set -e

should_run_migrations() {
    case "${RUN_MIGRATIONS:-true}" in
        [Tt][Rr][Uu][Ee]|1|yes|on|'')
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

if [ -f artisan ] && should_run_migrations; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
