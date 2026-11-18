# Changelog

パッケージ (server / core / http / framework) 全体の破壊的変更。バージョンはそろえて上げる。

## 次のリリースでの破壊的変更

- framework の `App` を `Phalcon` に改名した (`Urassh\Phalcon\Phalcon`)
- `HttpServer` の `host` / `port` / `workers` を `serve()` の引数から外し、サーバ層の `PhalconServerTransport` のコンストラクタに移した。`$app->useServer(new HttpServer(new PhalconServerTransport(host: '0.0.0.0', port: 8080)))->run()` のように書く。`Server` を渡していた場合は `new PhalconServerTransport(server: $server)` にする
- `Port\Platform::listen()` に `bool $reusePort = false` を足した。`Platform` を自前で実装している場合はシグネチャを合わせる
- `HttpServer::serve()` を `run()` に改名した (phalcon-core の `Runner` を実装する)。framework では `$app->run()` で起動する (待ち受け先などを変えるときは `$app->useServer(new HttpServer(...))` で渡す)
- phalcon-http が phalcon-server を require しなくなった。`HttpServer` を既定のまま使うなら `composer require urassh/phalcon-server` を足す (入っていないと `HttpServer` の生成時に `LogicException`)
- `new Phalcon()` が、プロジェクトのルート (Composer のルートパッケージのディレクトリ) に `phalcon.yaml` があれば読み込むようになった。読ませたくなければ `phalcon.yaml` を別の名前にして `Phalcon::fromConfig()` で読む
- `HttpProtocol` が phalcon-server の `Protocol` を実装しなくなった。`Server` に直接渡していた場合は `PhalconServerTransport` を使う

## 0.1 からの破壊的変更

- フレームワークのパッケージ名を `urassh/phalcon` から `urassh/phalcon-framework` に変えた (名前空間は `Urassh\Phalcon\` のまま)。`composer require` し直す
- `App::serve()` を削除した。起動は `urassh/phalcon-http` の `HttpServer` に任せる
- `App::handle()` は PSR-7 の `ServerRequestInterface` を受け取り `ResponseInterface` を返す
- `Request::parse()` や `Response::toString()` などの `@internal` メソッドを削除した (パースとシリアライズは phalcon-http に移った)
