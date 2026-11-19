#!/bin/bash
set -euo pipefail

app=(/usr/bin/docker exec --user www-data --workdir /opt/app ipphp-ui-app-1 php)
due=$("${app[@]}" bin/cabinet-schedule.php due)
if [[ -z "$due" ]]; then
    exit 0
fi
/usr/local/sbin/ipphp-cabinet-network
selection=$("${app[@]}" bin/cabinet-schedule.php claim)
if [[ -z "$selection" ]]; then
    exit 0
fi
read -r -a providers <<< "$selection"
for provider in "${providers[@]}"; do
    case "$provider" in
        diadoc) ;;
        mosenergo)
            /usr/local/sbin/ipphp-cabinet-mes-mail || true
            ;;
        mosvodokanal|pik)
            /usr/bin/docker exec --env IPPHP_CABINET_OWNER_AUTH=approved-interactive-restore \
                --env IPPHP_CAFILE=/opt/app/.local/cabinet-ca.pem --user www-data --workdir /opt/app \
                ipphp-ui-app-1 php bin/restore-cabinet-session.php "$provider" --if-expired || true
            ;;
        *) exit 2 ;;
    esac
done
exec /usr/bin/docker exec --env IPPHP_CAFILE=/opt/app/.local/cabinet-ca.pem --user www-data --workdir /opt/app \
    ipphp-ui-app-1 php bin/sync-cabinets-hourly.php "${providers[@]}"
