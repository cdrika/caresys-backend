#!/bin/sh
set -eu
case "${CARESYS_SURFACE:-}" in
  api) exec apache2-foreground -D MOBILE_API ;;
  web) exec apache2-foreground ;;
  *) echo 'CARESYS_SURFACE must be api or web' >&2; exit 1 ;;
esac
