#!/usr/bin/env bash
# v1.9.31: Read-only HTTPS/CORS/file-exposure gate.
# Production mode reaches only the local HTTPS virtual host via --resolve.
# No password, session cookie, SMTP credentials, or database writes are used.
set -euo pipefail
umask 077
for cmd in curl grep mktemp; do command -v "$cmd" >/dev/null || exit 2; done
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT

case "${1:-}" in
  '')
    base='https://api-nav.wwinzs.cn'
    origin='https://nav.wwinzs.cn'
    opts=(--silent --show-error --noproxy '*' --resolve 'api-nav.wwinzs.cn:443:127.0.0.1'
          --max-time 12 --max-redirs 0)
    production=1
    ;;
  --ci-loopback)
    [[ "${CI:-}" == true ]] || { echo 'CI mode restricted' >&2; exit 2; }
    base='http://127.0.0.1:8767'
    origin='http://127.0.0.1:8766'
    opts=(--silent --show-error --noproxy '*' --max-time 12 --max-redirs 0)
    production=0
    ;;
  *) echo 'Invalid mode; do not supply alternate network targets' >&2; exit 2;;
esac

fail(){ printf 'BLOCKED: %s\n' "$1" >&2; exit 1; }
fetch(){
  local suffix="$1" headers="$2" method="${3:-GET}" requestOrigin="${4:-}" code
  local args=( -D "$headers" -o "$tmp/body" -w '%{http_code}' )
  if [[ "$method" == OPTIONS ]]; then
    args+=( -X OPTIONS -H 'Access-Control-Request-Method: POST'
            -H 'Access-Control-Request-Headers: content-type' )
  fi
  if [[ -n "$requestOrigin" ]]; then args+=( -H "Origin: $requestOrigin" ); fi
  code=$(curl "${opts[@]}" "${args[@]}" "$base$suffix") || fail 'TLS/connection unsuccessful'
  printf '%s' "$code"
}
header_count(){
  local file="$1" name="$2"
  (grep -Eic "^${name}:[[:space:]]*" "$file" || true)
}

status=$(fetch '/health' "$tmp/health" GET "$origin")
[[ "$status" == 200 ]] || fail 'health endpoint did not return HTTP 200'
grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "$tmp/body" || fail 'unexpected health response'

status=$(fetch '/auth/login' "$tmp/allowed" OPTIONS "$origin")
[[ "$status" == 204 ]] || fail 'trusted Origin CORS preflight rejected'
[[ "$(header_count "$tmp/allowed" 'Access-Control-Allow-Origin')" == 1 ]] ||
  fail 'trusted Origin has missing/duplicate CORS header'
grep -Fiq "Access-Control-Allow-Origin: $origin" "$tmp/allowed" ||
  fail 'CORS granted incorrect site'
grep -Eiq '^Access-Control-Allow-Credentials:[[:space:]]*true[[:space:]]*$' "$tmp/allowed" ||
  fail 'credentials CORS header missing'
grep -Eiq '^Vary:[[:space:]]*Origin([[:space:]]*|,.*)$' "$tmp/allowed" ||
  fail 'Origin response cache variation missing'

status=$(fetch '/auth/login' "$tmp/foreign" OPTIONS 'https://untrusted.example')
[[ "$status" == 403 ]] || fail 'untrusted Origin was not denied'
[[ "$(header_count "$tmp/foreign" 'Access-Control-Allow-Origin')" == 0 ]] ||
  fail 'CORS origin leaked to untrusted caller'
status=$(fetch '/auth/login' "$tmp/missing" OPTIONS)
[[ "$status" == 403 ]] || fail 'missing Origin was not denied'

# Private sources cannot be served or disguised as success. The Nginx root
# should point only to server/public, not the enclosing server/.
for path in '/server/src/bootstrap.php' '/server/config.local.php' \
            '/server/scripts/recover-owner.php' '/server/migrations/001_security_events.sql' \
            '/config.local.php' '/.env'; do
  status=$(fetch "$path" "$tmp/private")
  [[ "$status" == 403 || "$status" == 404 ]] ||
    fail 'private path exposure check returned a non-denial HTTP status'
done

if (( production )); then
  [[ "$(header_count "$tmp/health" 'Strict-Transport-Security')" == 1 ]] ||
    fail 'HSTS is missing or duplicated (Nginx/PHP collision)'
  grep -Eiq '^Strict-Transport-Security:[[:space:]]*max-age=[0-9]+' "$tmp/health" ||
    fail 'invalid HSTS policy'
  [[ "$(header_count "$tmp/health" 'Access-Control-Allow-Origin')" -le 1 ]] ||
    fail 'duplicate CORS response headers'
fi
echo 'PASS read-only edge checks: health, strict Origin, CORS, private paths and TLS policy (when HTTPS)'
