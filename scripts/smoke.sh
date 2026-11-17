#!/bin/sh
# entrypoint.php を起動し、curl で主要な応答を確かめるスモークテスト
#
#   sh scripts/smoke.sh                 # entrypoint.php を起動してから確かめる
#   BASE_URL=http://host:8080 NO_SERVE=1 sh scripts/smoke.sh   # 起動済みのサーバに当てる

set -u

BASE_URL=${BASE_URL:-http://127.0.0.1:8080}
ROOT=$(cd "$(dirname "$0")/.." && pwd)

# entrypoint.php を実行中のプロセスの pid を並べる (pgrep がない環境向けに /proc を見る)
server_pids() {
  for cmdline in /proc/[0-9]*/cmdline; do
    if tr '\0' ' ' < "$cmdline" 2> /dev/null | grep -q 'entrypoint\.php'; then
      pid=${cmdline#/proc/}
      echo "${pid%/cmdline}"
    fi
  done
}

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

# /slow でワーカが 1 つ塞がっている間も、別のワーカが応答できるか。
# 接続はハッシュで振り分けられ、塞がったワーカに当たった分は待たされるので、
# 何本か送って 1 本でも 1 秒以内に返ればよい (16 本すべてが当たる確率は 2^-16)
curl -s -o /dev/null --max-time 5 "$BASE_URL/slow" &
SLOW_PID=$!
sleep 0.2
concurrent=no
i=0
while [ "$i" -lt 16 ]; do
  i=$((i + 1))
  if [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 1 "$BASE_URL/")" = 200 ]; then
    concurrent=yes
    break
  fi
done
if [ "$concurrent" = yes ]; then
  echo "ok   GET / while /slow is blocking"
else
  echo "FAIL GET / while /slow is blocking: no response within 1s"
  failures=$((failures + 1))
fi
wait "$SLOW_PID"

# master を止めたらワーカも止まっているか。残ると SO_REUSEPORT のせいで次の起動が成功してしまい気づけない
if [ -z "${NO_SERVE:-}" ]; then
  kill "$SERVER_PID"
  wait "$SERVER_PID" 2> /dev/null
  trap - EXIT
  leftover=$(server_pids)
  if [ -n "$leftover" ]; then
    echo "FAIL workers left after stopping the server: $leftover"
    kill $leftover 2> /dev/null
    failures=$((failures + 1))
  else
    echo "ok   workers stop with the server"
  fi
fi

if [ "$failures" -gt 0 ]; then
  echo "$failures check(s) failed"
  exit 1
fi
