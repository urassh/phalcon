#!/bin/sh
# entrypoint.php を起動し、curl で主要な応答を確かめるスモークテスト
#
#   sh scripts/smoke.sh                 # entrypoint.php を起動してから確かめる
#   BASE_URL=http://host:8080 NO_SERVE=1 sh scripts/smoke.sh   # 起動済みのサーバに当てる

set -u

BASE_URL=${BASE_URL:-http://127.0.0.1:8080}
ROOT=$(cd "$(dirname "$0")/.." && pwd)

if [ -z "${NO_SERVE:-}" ]; then
  php "$ROOT/entrypoint.php" > /dev/null &
  SERVER_PID=$!
  trap 'kill "$SERVER_PID" 2> /dev/null' EXIT

  i=0
  until curl -s -o /dev/null "$BASE_URL/"; do
    i=$((i + 1))
    if [ "$i" -ge 50 ]; then
      echo "server did not start" >&2
      exit 1
    fi
    sleep 0.1
  done
fi

failures=0

# check <説明> <期待するステータス> <期待するボディ (空なら確かめない)> <curl の引数...>
check() {
  name=$1
  want_status=$2
  want_body=$3
  shift 3

  body=$(curl -s -w '\n%{http_code}' "$@")
  status=$(printf '%s' "$body" | tail -n 1)
  body=$(printf '%s' "$body" | sed '$d')

  if [ "$status" != "$want_status" ] || { [ -n "$want_body" ] && [ "$body" != "$want_body" ]; }; then
    echo "FAIL $name: status=$status body=$body (want $want_status $want_body)"
    failures=$((failures + 1))
  else
    echo "ok   $name"
  fi
}

check 'GET /'           200 'Hello, world!' "$BASE_URL/"
check 'GET /users/42'   200 '{"id":"42"}'   "$BASE_URL/users/42"
check 'POST /echo'      200 'hello'         -X POST --data-raw 'hello' "$BASE_URL/echo"
check 'GET /missing'    404 ''              "$BASE_URL/missing"
check 'DELETE /'        405 ''              -X DELETE "$BASE_URL/"
check 'malformed'       400 ''              -X 'BAD REQUEST' "$BASE_URL/"

if [ "$failures" -gt 0 ]; then
  echo "$failures check(s) failed"
  exit 1
fi
