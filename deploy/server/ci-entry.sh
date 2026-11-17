#!/bin/sh
set -eu
case "$1" in ui|integrations) ;; *) exit 1;; esac
test "${SSH_ORIGINAL_COMMAND:-}" = release || exit 1
exec sudo -n /usr/local/sbin/ipphp-deploy "$1" release

