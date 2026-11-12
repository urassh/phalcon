# urassh/phalcon-server

接続の受け付けと読み書きを回すイベントループと、ソケットのプラットフォーム実装。
HTTP などのプロトコルは知らず、`Protocol` 越しにバイト列だけを扱う。

> このリポジトリは [urassh/phalcon](https://github.com/urassh/phalcon) モノレポから split した読み取り専用のコピー。Issue と PR はモノレポで受け付ける。

## インストール

```sh
composer require urassh/phalcon-server
```

Linux で FFI 拡張が使えるときは epoll、それ以外は `stream_select` を使う。

## 使い方

`Protocol` を実装して `Server` に渡す。

```php
use Urassh\Phalcon\Server\Protocol;
use Urassh\Phalcon\Server\Server;

// 1 行受け取ったら大文字にして返す
$echo = new class () implements Protocol {
  public function isComplete(string $data): bool
  {
    return str_contains($data, "\n");
  }

  public function respond(string $data): string
  {
    return strtoupper($data);
  }
};

(new Server())->serve($echo, host: '127.0.0.1', port: 9000);
```

## 公開 API

| 型 | 役割 |
|---|---|
| `Server` | 待ち受けを始めてイベントループを回す。`serve(Protocol, string $host, int $port): never` |
| `Protocol` | 受け取ったバイト列が揃ったかの判定と、応答のバイト列の生成 |
| `EventLoop` | `Poller` の通知を受けて接続を振り分ける。`Server` を使わずに組み立てたいとき用 |
| `Port\*` | `Platform`, `Socket`, `ListeningSocket`, `Poller`, `Clock`, `Readiness`, `SocketException` |
| `Platform\PlatformFactory` | 実行環境に合うプラットフォームを選ぶ |
| `Platform\SystemClock` | 単調増加の時計 |

`Connection` とプラットフォームの実装 (`Platform\Linux\*`, `Platform\StreamSelect\*`) は `@internal`。

## 制限

- 接続は 1 つずつ順番に処理する (並行処理なし)
- 応答を返したら接続を閉じる (keep-alive なし)
- 受信は 1 接続あたり 1 MiB まで
- 接続を受け付けてから 5 秒以内に応答を送りきれなければ接続を閉じる
