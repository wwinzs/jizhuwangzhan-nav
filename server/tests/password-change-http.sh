#!/usr/bin/env bash
# CI-only regression for password rotation on the isolated PHP test service.
set -euo pipefail
umask 077

api="${1:-}"
origin="${2:-}"
if [[ "$api" != "http://127.0.0.1:8767" || "$origin" != "http://127.0.0.1:8766" ]]; then
  echo "Refusing to test outside the isolated CI loopback service" >&2
  exit 2
fi
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

json_change_good='{"current_password":"a very long CI password 987654!","new_password":"a different long CI password 654321!"}'
json_change_bad='{"current_password":"wrong existing password","new_password":"a different long CI password 654321!"}'
json_change_short='{"current_password":"a very long CI password 987654!","new_password":"too-short"}'
json_change_same='{"current_password":"a very long CI password 987654!","new_password":"a very long CI password 987654!"}'
login_old='{"email":"owner@example.com","password":"a very long CI password 987654!"}'
login_new='{"email":"owner@example.com","password":"a different long CI password 654321!"}'

login() {
  local payload="$1" head="$2" code
  code=$(curl -sS -D "$head" -o "$tmp/body" -w '%{http_code}' \
    -H "Origin: $origin" -H 'Content-Type: application/json' \
    --data-binary "$payload" "$api/auth/login")
  [[ "$code" == 200 ]] || { echo "Login failed with HTTP $code" >&2; return 1; }
}

token_from_header() {
  sed -n -E 's/^[Ss]et-[Cc]ookie: __Host-nav_session=([a-f0-9]{64});.*$/\1/p' "$1" | head -n 1
}

post_change() {
  local token="$1" origin_value="$2" payload="$3"
  curl -sS -D "$tmp/change-head" -o "$tmp/body" -w '%{http_code}' \
    -b "__Host-nav_session=$token" -H "Origin: $origin_value" \
    -H 'Content-Type: application/json' \
    --data-binary "$payload" "$api/auth/change-password"
}

login "$login_old" "$tmp/h1"
token1=$(token_from_header "$tmp/h1")
[[ "${#token1}" -eq 64 ]]
login "$login_old" "$tmp/h2"
token2=$(token_from_header "$tmp/h2")
[[ "${#token2}" -eq 64 && "$token1" != "$token2" ]]

# Trusted origin and authenticated session are both mandatory.
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' -H "Origin: $origin" \
  -H 'Content-Type: application/json' --data-binary "$json_change_good" \
  "$api/auth/change-password")
[[ "$code" == 401 ]]
[[ "$(post_change "$token1" 'https://untrusted.example' "$json_change_good")" == 403 ]]
[[ "$(post_change "$token1" "$origin" "$json_change_short")" == 400 ]]
[[ "$(post_change "$token1" "$origin" "$json_change_bad")" == 401 ]]
[[ "$(post_change "$token1" "$origin" "$json_change_same")" == 400 ]]

# Real rotation must revoke every pre-existing session in one transaction.
[[ "$(post_change "$token1" "$origin" "$json_change_good")" == 200 ]]
grep -q '"reauth_required":true' "$tmp/body"
grep -qi '^Set-Cookie: __Host-nav_session=' "$tmp/change-head"
for token in "$token1" "$token2"; do
  code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
    -b "__Host-nav_session=$token" "$api/auth/me")
  [[ "$code" == 401 ]] || { echo "A revoked token was still accepted" >&2; exit 1; }
done

code=$(curl -sS -o "$tmp/body" -w '%{http_code}' -H "Origin: $origin" \
  -H 'Content-Type: application/json' --data-binary "$login_old" "$api/auth/login")
[[ "$code" == 401 ]]
login "$login_new" "$tmp/h3"
token3=$(token_from_header "$tmp/h3")
[[ "${#token3}" -eq 64 ]]
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
  -b "__Host-nav_session=$token3" "$api/auth/me")
[[ "$code" == 200 ]]

echo 'Password rotation: origin, auth, validation, multi-session revocation, and new login passed'
