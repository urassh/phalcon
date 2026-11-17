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

`workers` を 2 以上にすると、その数だけワーカを fork して並行に処理する (pcntl と posix の拡張が必要)。

```php
(new Server())->serve($echo, host: '127.0.0.1', port: 9000, workers: 4);
```

master は一度 listen してポートを確定させてから閉じ、各ワーカが `SO_REUSEPORT` 付きで同じポートに listen し直す。
起動時には `Listening on 127.0.0.1:9000 (4 workers)` と出力する。

## 公開 API

| 型 | 役割 |
|---|---|
| `Server` | 待ち受けを始めてイベントループを回す。`serve(Protocol, string $host, int $port, int $workers = 1): never` |
| `Protocol` | 受け取ったバイト列が揃ったかの判定と、応答のバイト列の生成 |
| `EventLoop` | `Poller` の通知を受けて接続を振り分ける。`Server` を使わずに組み立てたいとき用 |
| `Port\*` | `Platform`, `Socket`, `ListeningSocket`, `Poller`, `Clock`, `Readiness`, `SocketException` |
| `Platform\PlatformFactory` | 実行環境に合うプラットフォームを選ぶ |
| `Platform\SystemClock` | 単調増加の時計 |

`Connection`、ワーカを監視する `Supervisor`、プラットフォームの実装 (`Platform\Linux\*`, `Platform\StreamSelect\*`) は `@internal`。

## 並行処理のモデル

- 1 プロセスの中では接続の読み書きを多重化するが、`Protocol::respond()` は直列に呼ぶ。ブロッキングな処理の間はそのプロセスが止まる
- `workers` を 2 以上にすると、カーネルが接続をワーカに振り分ける (`SO_REUSEPORT`)。同時に処理できるのはワーカ数まで。ワーカ間でメモリ上の状態は共有されない
- 振り分けはハッシュで決まるので、ブロック中のワーカに当たった接続はそのワーカが戻るまで待つ。空いているワーカが代わりに取ることはない
- ワーカが落ちると master が起動し直すが、そのワーカの accept キューにいた接続は切れる。起動直後の終了が 5 回続くと master も終了する
- master は SIGTERM / SIGINT で全ワーカを止めてから終了する。SIGKILL で止めるとワーカが残る
- 接続の 5 秒の期限は accept してから数える。backlog にいる間は数えない
- `stream_select` の実装 (macOS など) では、接続がワーカに振り分けられるかは OS 次第
- Fiber による並行化は未対応

## 次のリリースでの破壊的変更

- `Port\Platform::listen()` に `bool $reusePort = false` を足した。`Platform` を実装している場合はシグネチャを合わせる

## 制限

- ハンドラ (`Protocol::respond()`) は 1 プロセス内で直列に呼ぶ。並行に処理したいときは `workers` を増やす
- 応答を返したら接続を閉じる (keep-alive なし)
- 受信は 1 接続あたり 1 MiB まで
- 接続を受け付けてから 5 秒以内に応答を送りきれなければ接続を閉じる
