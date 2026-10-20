#!/usr/bin/env bash
# Loopback-only CI test. Never point at the real VPS or production accounts.
set -euo pipefail
umask 077
trap 'printf "Session-management check failed at line %s\\n" "$LINENO" >&2' ERR
api="$1"
origin="$2"
if [[ "$api" != 'http://127.0.0.1:8767' || "$origin" != 'http://127.0.0.1:8766' ]]; then
  echo 'Refusing to run against a non-CI API' >&2
  exit 2
fi
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
login='{"email":"owner@example.com","password":"a very long CI password 987654!"}'

session() {
  local headers="$1" code
  code=$(curl -sS -D "$headers" -o "$tmp/body" -w '%{http_code}' \
    -H "Origin: $origin" -H 'Content-Type: application/json' \
    --data-binary "$login" "$api/auth/login")
  [[ "$code" == 200 ]]
  sed -n -E 's/^[Ss]et-[Cc]ookie: __Host-nav_session=([a-f0-9]{64});.*$/\1/p' "$headers" | head -n 1
}
echo 'Session smoke: unauthenticated access checks'
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' -H "Origin: $origin" "$api/auth/sessions")
[[ "$code" == 401 ]]
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' "$api/auth/sessions")
[[ "$code" == 403 ]]
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' -H "Origin: $origin" \
    -H 'Content-Type: application/json' --data '{}' "$api/auth/sessions/revoke-others")
[[ "$code" == 401 ]]

echo 'Session smoke: create multiple sessions'
t1=$(session "$tmp/h1")
t2=$(session "$tmp/h2")
t3=$(session "$tmp/h3")
[[ "${#t1}" -eq 64 && "${#t2}" -eq 64 && "${#t3}" -eq 64 ]]
[[ "$t1" != "$t2" && "$t1" != "$t3" && "$t2" != "$t3" ]]

echo 'Session smoke: validate listing and Origin'
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
  -b "__Host-nav_session=$t3" -H 'Origin: https://untrusted.example' "$api/auth/sessions")
[[ "$code" == 403 ]]
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
  -b "__Host-nav_session=$t3" -H "Origin: $origin" "$api/auth/sessions")
[[ "$code" == 200 ]]
python3 - "$tmp/body" <<'PY'
import json, sys
from datetime import datetime
data = json.load(open(sys.argv[1], encoding='utf-8'))
sessions = data['sessions']
assert len(sessions) == 3, sessions
assert sum(s['current'] is True for s in sessions) == 1
assert sessions[0]['current'] is True
for item in sessions:
    assert set(item) == {'created_at','expires_at','current'}, item
    assert item['expires_at'].endswith('Z')
    assert datetime.fromisoformat(item['created_at'].replace('Z','+00:00'))
    assert datetime.fromisoformat(item['expires_at'].replace('Z','+00:00'))
PY

code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
  -b "__Host-nav_session=$t3" -H 'Origin: https://untrusted.example' \
  -H 'Content-Type: application/json' --data '{}' "$api/auth/sessions/revoke-others")
[[ "$code" == 403 ]]
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
  -b "__Host-nav_session=$t1" "$api/auth/me")
[[ "$code" == 200 ]]
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
  -b "__Host-nav_session=$t3" -H "Origin: $origin" \
  -H 'Content-Type: application/json' --data '{}' "$api/auth/sessions/revoke-others")
[[ "$code" == 200 ]]
python3 - "$tmp/body" <<'PY'
import json,sys
body=json.load(open(sys.argv[1],encoding='utf-8'))
assert body=={'ok':True,'revoked_count':2},body
PY

echo 'Session smoke: verify revoked tokens'
for token in "$t1" "$t2"; do
  code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
    -b "__Host-nav_session=$token" "$api/auth/me")
  [[ "$code" == 401 ]] || { echo "Revoked session is still active" >&2; exit 1; }
done
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
  -b "__Host-nav_session=$t3" "$api/auth/me")
[[ "$code" == 200 ]]
code=$(curl -sS -o "$tmp/body" -w '%{http_code}' \
  -b "__Host-nav_session=$t3" -H "Origin: $origin" "$api/auth/sessions")
[[ "$code" == 200 ]]
python3 - "$tmp/body" <<'PY'
import json,sys
items=json.load(open(sys.argv[1],encoding='utf-8'))['sessions']
assert len(items)==1 and items[0]['current'] is True,items
PY
echo 'Authenticated session listing, Origin rejection and revoke-others passed'
