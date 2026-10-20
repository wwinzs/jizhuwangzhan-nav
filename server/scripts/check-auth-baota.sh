#!/usr/bin/env bash
# Local HTTPS authentication smoke check; changes only test sessions.
# Run on the VPS after installing the isolated v1.9 Draft API.
set -euo pipefail
umask 077

API='https://api-nav.wwinzs.cn'
ORIGIN='https://nav.wwinzs.cn'
HOST='api-nav.wwinzs.cn'

for program in curl python3; do
  if ! command -v "$program" >/dev/null 2>&1; then
    printf '缺少运行依赖：%s\n' "$program" >&2
    exit 1
  fi
done
if [[ ! -r /dev/tty ]]; then
  printf '请在交互式 SSH 终端运行此脚本。\n' >&2
  exit 1
fi

tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
opts=(--silent --show-error --max-time 15 --noproxy '*' --resolve "${HOST}:443:127.0.0.1")

printf '检查 API 健康状态...\n'
status=$(curl "${opts[@]}" --output /dev/null --write-out '%{http_code}' "$API/health")
if [[ "$status" != 200 ]]; then
  printf '健康检查未通过：HTTP %s。请先检查站点根目录、PHP-FPM 和数据库。\n' "$status" >&2
  exit 1
fi
printf '[通过] /health HTTP 200\n'

IFS= read -r -p '站长邮箱：' email </dev/tty || { printf '无法读取邮箱。\n' >&2; exit 1; }
IFS= read -r -s -p '站长密码（不显示）：' password </dev/tty || { printf '\n无法读取密码。\n' >&2; exit 1; }
printf '\n'
if [[ -z "$email" || -z "$password" ]]; then
  printf '邮箱和密码不能为空。\n' >&2
  exit 1
fi

# Pass credentials only via stdin, never CLI arguments or logs.
status=$(
  printf '%s\n%s\n' "$email" "$password" |
    python3 -c 'import json,sys; print(json.dumps({"email":sys.stdin.readline().rstrip("\n"),"password":sys.stdin.readline().rstrip("\n")}))' |
    curl "${opts[@]}" \
      --output /dev/null --write-out '%{http_code}' \
      --header "Origin: $ORIGIN" \
      --header 'Content-Type: application/json' \
      --cookie-jar "$tmp/cookies" \
      --data-binary @- \
      "$API/auth/login"
)
unset password
if [[ "$status" != 200 ]]; then
  printf '登录验证未通过：HTTP %s（401 为账号/密码不匹配；429 为限流）。\n' "$status" >&2
  exit 1
fi
printf '[通过] /auth/login HTTP 200\n'

# Keep the old cookie so logout must revoke the token on the server.
cp -- "$tmp/cookies" "$tmp/old-cookies"
status=$(curl "${opts[@]}" --cookie "$tmp/old-cookies" --output /dev/null --write-out '%{http_code}' "$API/auth/me")
if [[ "$status" != 200 ]]; then
  printf '会话查询未通过：HTTP %s。请检查 HTTPS Cookie 和 PHP 会话。\n' "$status" >&2
  exit 1
fi
printf '[通过] /auth/me HTTP 200\n'

status=$(curl "${opts[@]}" \
  --cookie "$tmp/cookies" \
  --output /dev/null --write-out '%{http_code}' \
  --header "Origin: $ORIGIN" \
  --header 'Content-Type: application/json' \
  --data '{}' "$API/auth/logout")
if [[ "$status" != 200 ]]; then
  printf '退出验证未通过：HTTP %s。\n' "$status" >&2
  exit 1
fi
printf '[通过] /auth/logout HTTP 200\n'

status=$(curl "${opts[@]}" --cookie "$tmp/old-cookies" --output /dev/null --write-out '%{http_code}' "$API/auth/me")
if [[ "$status" != 401 ]]; then
  printf '原会话失效验证未通过：期望 HTTP 401，实际 HTTP %s。\n' "$status" >&2
  exit 1
fi
printf '[通过] 退出后旧会话 HTTP 401\n'
printf '本机 HTTPS 账号登录与退出闭环已通过；网页登录入口仍应保持关闭。\n'