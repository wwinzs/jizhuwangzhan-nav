#!/usr/bin/env bash
# Synthetic CI only: a real MySQL dump, isolated restore and integrity check.
# NEVER use this on a production database or with user-provided DSN.
set -euo pipefail
umask 077
[[ "${CI:-}" == true ]] || { echo 'CI only' >&2; exit 2; }
for cmd in mysql mysqldump php mktemp; do command -v "$cmd" >/dev/null; done
export MYSQL_PWD=integration_only_password
tmp=$(mktemp -d)
cleanup(){
  mysql -h127.0.0.1 -uroot -e 'DROP DATABASE IF EXISTS nav_restore_ci' >/dev/null 2>&1 || true
  mysql -h127.0.0.1 -uroot nav_ci -e \
    "DELETE FROM nav_sessions WHERE token_hash=REPEAT('6',64);
     DELETE FROM nav_user_documents WHERE revision=7" >/dev/null 2>&1 || true
  rm -rf -- "$tmp"
}
trap cleanup EXIT
[[ "$(mysql -N -h127.0.0.1 -uroot -e \
   "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='nav_restore_ci'")" == 0 ]] ||
  { echo 'A prior restore DB exists; refusing to replace it' >&2; exit 2; }
[[ "$(mysql -N -h127.0.0.1 -uroot nav_ci -e \
   "SELECT COUNT(*) FROM nav_user_documents")" == 0 ]] ||
  { echo 'Unexpected source fixture data' >&2; exit 2; }
mysql -h127.0.0.1 -uroot nav_ci <<'SQL'
INSERT INTO nav_user_documents(user_id,revision,document_json)
 SELECT id,7,'{"folders":[{"name":"Synthetic CI"}],"sites":["https://example.com"]}'
 FROM nav_users WHERE email='owner@example.com' AND role='owner';
INSERT INTO nav_sessions(user_id,token_hash,expires_at)
 SELECT id,REPEAT('6',64),UTC_TIMESTAMP(6)+INTERVAL 1 DAY
 FROM nav_users WHERE email='owner@example.com' AND role='owner';
SQL
# Only synthetic CI fixtures ever enter this backup. No real secrets in logs.
mysqldump -h127.0.0.1 -uroot --single-transaction --skip-lock-tables \
  --default-character-set=utf8mb4 nav_ci > "$tmp/synthetic-ci-only.sql"
test -s "$tmp/synthetic-ci-only.sql"
mysql -h127.0.0.1 -uroot -e 'CREATE DATABASE nav_restore_ci CHARACTER SET utf8mb4'
mysql -h127.0.0.1 -uroot nav_restore_ci < "$tmp/synthetic-ci-only.sql"
php server/tests/verify-backup-restore-ci.php
echo 'PASS CI-only backup restore: source untouched after fixture cleanup'
