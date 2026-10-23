# urassh/phalcon

## 開発環境

Docker 上で PHP 8.3 + Composer を使って開発する。

```sh
make build     # イメージをビルド
make install   # composer install
make test      # PHPUnit
make analyse   # PHPStan
make shell     # コンテナに入る
```

## ディレクトリ構成

```
src/            ライブラリ本体 (名前空間 Urassh\Phalcon\)
tests/          テスト (名前空間 Urassh\Phalcon\Tests\)
docker/php/     開発用 PHP イメージ
```
