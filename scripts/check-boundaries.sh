#!/bin/sh
# パッケージ間の依存の向きを確かめる
#
# - framework はループ / プラットフォーム (server) と HTTP サーバ (http) に依存しない
# - server は HTTP を知らない
# - core は server / http に依存しない

set -u

ROOT=$(cd "$(dirname "$0")/.." && pwd)
failures=0

# forbid <パッケージ> <説明> <grep -E のパターン> <対象...>
forbid() {
  package=$1
  name=$2
  pattern=$3
  shift 3

  if (cd "$ROOT/packages/$package" && grep -rnE "$pattern" "$@"); then
    echo "FAIL $package: $name"
    failures=$((failures + 1))
  else
    echo "ok   $package: $name"
  fi
}

forbid framework 'does not require phalcon-server / phalcon-http' 'urassh/phalcon-(server|http)' composer.json
forbid framework 'does not reference Server / Http namespaces' 'Urassh\\Phalcon\\(Server|Http)\\' src
forbid server 'does not require phalcon-http / psr/http-*' 'urassh/phalcon-http|psr/http-' composer.json
forbid server 'does not reference Http / PSR-7 namespaces' 'Urassh\\Phalcon\\Http\\|Psr\\Http\\' src
forbid core 'does not require phalcon-server / phalcon-http' 'urassh/phalcon-(server|http)' composer.json
forbid core 'does not reference Server / Http namespaces' 'Urassh\\Phalcon\\(Server|Http)\\' src

if [ "$failures" -gt 0 ]; then
  echo "$failures check(s) failed"
  exit 1
fi
