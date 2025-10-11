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

should_run_seeders() {
    case "${RUN_SEEDERS:-false}" in
        [Tt][Rr][Uu][Ee]|1|yes|on)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

if [ -f artisan ] && should_run_migrations; then
    # Run migrations
    php artisan migrate --force --no-interaction

    # Only seed if explicitly requested
    if should_run_seeders; then
        echo "Running database seeders..."
        php artisan db:seed --force --no-interaction
    else
        echo "Skipping database seeders (set RUN_SEEDERS=true to enable)"
    fi
fi

exec "$@"
