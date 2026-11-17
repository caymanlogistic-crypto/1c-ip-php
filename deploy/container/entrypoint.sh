#!/bin/sh
set -eu
case "${APP_STAND:-}" in ui|integrations) ;; *) echo 'Invalid APP_STAND' >&2; exit 1;; esac
# No worker, scheduler, outbox or inherited external admission is enabled here.
if env | grep -Eq '^(IPPHP_|APP_.*ADMISSION|APP_INTEGRATION_)'; then
  echo 'Unexpected external/admission environment' >&2; exit 1
fi
mkdir -p /opt/app/runtime/sessions /opt/app/runtime/logs /opt/app/runtime/tmp /opt/app/runtime/upload-tmp
chown -R www-data:www-data /opt/app/runtime
php /opt/app/bin/server-diagnostics.php --check
exec docker-php-entrypoint "$@"

