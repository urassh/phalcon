# Issue #4 実装計画: SO_REUSEPORT によるマルチプロセス化

- Issue: https://github.com/urassh/phalcon/issues/4
- ブランチ: `feature/reuseport`

## 背景 (Issue の要点)

ハンドラはイベントループと同じスレッドで同期的に呼ばれる (`EventLoop::runOnce()` → `Connection::onReadable()` → `Protocol::respond()`)。
そのため `sleep()` や遅い PDO クエリ、外部 API 呼び出しの間はループ全体が止まり、新規接続の accept も他の接続の読み書きも進まない。

Issue では「マルチプロセス」「Fiber」「ドキュメントのみ」の 3 案が挙がっており、ここでは **マルチプロセス (SO_REUSEPORT)** を採る。
Fiber 化は非同期版の sleep / ソケット / DB クライアントが揃わないと効果がなく、「PDO など同期 I/O をそのまま使える」という利点が今回の目的に合う。

## 決定済み事項

| 項目 | 決定 |
|---|---|
| 方式 | マルチプロセス。ワーカごとに `SO_REUSEPORT` 付きで同じアドレスに listen し、接続の割り振りはカーネルに任せる (ユーザ指示) |
| Fiber | 今回は対象外。README に「将来の選択肢」として残す |
| 置き場所 | ワーカの fork / 監視は `phalcon-server` (プロトコル非依存)。`phalcon-http` と framework は引数と設定を通すだけ (Q2) |
| 既定値 | `workers: 1` で今と同じ単一プロセス。pcntl がなくても今まで通り動く (Q3) |
| ポート 0 | master が一度 `SO_REUSEPORT` 付きで listen してポートを確定し、閉じてからワーカに渡す (Q4) |
| Platform の変更 | `Platform::listen()` に `bool $reusePort = false` を足す (Q5) |

## 検討経緯

### Q1. SO_REUSEPORT か、共有 listener の pre-fork か → 決定: SO_REUSEPORT

| | SO_REUSEPORT (採用) | 共有 listener の pre-fork |
|---|---|---|
| 仕組み | ワーカごとに socket / bind / listen。カーネルが接続を 4-tuple のハッシュで各 listener に振り分ける | master が listen した fd を fork で引き継ぎ、全ワーカが同じ fd を accept する |
| 待ち行列 | ワーカごとに accept キューがある。**ブロック中のワーカに振られた接続は、そのワーカが戻るまで待つ** (他のワーカは取れない) | accept キューは 1 つ。空いているワーカが拾うので、全ワーカが塞がるまで待たされない |
| ループへの影響 | なし。各ワーカは今の `EventLoop` をそのまま回す | epoll (ET) で全ワーカが起きる thundering herd。`EPOLLEXCLUSIVE` (Linux 4.5+) で緩和できるが Poller の変更が要る |
| ワーカ停止時 | そのワーカのキューに溜まっていた接続は RST される | 影響なし |
| 実装の大きさ | listen のオプション追加 + fork の監督 | fork の監督 + Poller の変更 |

SO_REUSEPORT は「ワーカ数ぶんの同時処理」を最小の変更で得られる一方、ハッシュで振られるので 1 本の `/fast` が運悪くブロック中のワーカに当たることはある (ワーカ数 N で確率 1/N)。
これは方式の性質なので README に書き、テストもその前提で設計する (Phase 2)。
空いているワーカが拾う挙動が欲しくなったら、共有 listener 方式を `Platform` の実装差し替えで後から足せる余地を残す。

### Q2. どのパッケージに置くか → 決定: `phalcon-server`

- fork と監視はプロトコルに依存しないので `phalcon-server` に置く。`scripts/check-boundaries.sh` の制約 (server は HTTP を知らない) はそのまま
- 公開 API は `Server::serve(Protocol $protocol, string $host, int $port, int $workers = 1): never` に引数を 1 つ足すだけ。監督役のクラス (`Supervisor`) は `@internal`
- `phalcon-http` は `HttpServer::serve(..., int $workers = 1)` で素通しする
- framework は `server.workers` を設定キーとして認める (`App::fromConfig()` は `int` かつ 1 以上の検証だけ。`server.host` / `server.port` と同じく `$app->config()` で取り出してサーバに渡す)

### Q3. 既定値と pcntl の扱い → 決定: 既定は 1、2 以上で pcntl 必須

- `workers === 1` は fork せず今と同じ経路を通る。`HttpServerTest` や `PlatformContractTestCase` などの既存テストは変更なしで通る
- `workers >= 2` で `pcntl` がなければ `SocketException` ではなく `RuntimeException` を投げて起動を止める (設定ミスをサイレントに単一プロセスへ倒さない)
- `packages/server/composer.json` の `suggest` に `ext-pcntl` を足す
- Docker イメージ (`docker/php/Dockerfile`) には pcntl が入っていない (FFI と posix のみ確認済み) ので `docker-php-ext-install ffi pcntl` にする。CI の `shivammathur/setup-php` も `extensions: ffi, pcntl` に揃える

### Q4. ポート 0 と起動時のエラー検出 → 決定: master が先に listen してから閉じる

ワーカがそれぞれ bind すると、ポート 0 ではワーカごとに別のポートになってしまう。また bind の失敗 (他プロセスが `SO_REUSEPORT` なしで掴んでいるなど) がワーカ側で起きると、master が再起動を繰り返すだけで原因が見えない。

そこで master は次の順で動く:

1. `$platform->listen($host, $port, reusePort: true)` で一度 listen する。失敗はここで例外になる
2. `localAddress()` で実際のポートを読み、`Listening on 127.0.0.1:8080 (2 workers)` と出す (`HttpServerTest` が読む `Listening on (\S+)` の形は保つ)
3. **fork する前に** listener を閉じる。閉じずに fork すると、カーネルがこのソケットにも接続を振り、誰も accept しないので固まる
4. ワーカを fork し、各ワーカが確定したポートに `reusePort: true` で listen し直して `EventLoop` を回す

3 と 4 の間はどの listener もない (接続拒否になる) 短い窓があるが、起動時だけなので許容する。

### Q5. `Platform` インターフェースの変更 → 決定: 引数を足す

- `Port\Platform::listen(string $host, int $port, bool $reusePort = false): ListeningSocket`
- `Platform\Linux\Libc::listenTcp()` は `SO_REUSEPORT` (Linux では 15) を `setsockopt` する
- `Platform\StreamSelect\StreamSelectPlatform` は `stream_context_create(['socket' => ['so_reuseport' => true]])` を `stream_socket_server()` に渡す (PHP 7.0.1 以降の標準オプション)
- 0.x なので `Platform` 実装者への破壊的変更は README に書くにとどめる。`FakeListeningSocket` は `ListeningSocket` の実装なので影響なし

macOS (`StreamSelect`) では `SO_REUSEPORT` で同じポートに複数 bind できるが、TCP 接続が各 listener に分配されるかは OS 次第 (最後に bind したソケットに集中する可能性がある)。
開発環境は Docker 上の Linux なので、`StreamSelect` 側は「動くが分配は保証しない」と README に書く。

## 目標の構成

```
Server::serve(protocol, host, port, workers)
  ├─ workers === 1 ─> listen → EventLoop::run()        (今まで通り)
  └─ workers >= 2 ─> Supervisor::run()
        master: listen(reusePort) → 閉じる → fork × N → waitpid で監視 → 落ちたら再 fork
                 SIGTERM / SIGINT で全ワーカに SIGTERM → 回収 → exit
        worker: listen(reusePort) → createPoller() → EventLoop::run()
```

- `Poller` (epoll の fd) は必ずワーカ側 (`fork` 後) で作る。`Libc` の FFI ハンドルは master で読み込んだものを引き継いで構わない
- `Protocol` (HTTP なら `HttpProtocol` → `App`) は master で組み立てたものが fork でコピーされる。ワーカ間でメモリ上の状態は共有されない
- `EventLoop` と `Connection` は変更しない

## フェーズ

1 フェーズ = 1 PR (または 1 コミット) を目安にする。各フェーズの終わりで `make test`・`make analyse`・`make boundaries`・`make smoke` が通る状態を保つ。

### Phase 0: 現状の確認と足回り

- [x] `EventLoopTest` に「ハンドラが長くブロックすると、accept 済みの他の接続は応答されずに期限切れで閉じられる」テストを足す (`FakeProtocol::respond()` の中で `FakeClock` を 6 秒進める)。Issue の「待機中の接続がタイムアウトで切断されるか」の確認
  - 期待される挙動: accept 済みの接続は `dropExpired()` で切られる。カーネルの backlog にいた接続は accept されるまで期限が始まらないので切られず、ブロック後に新しい 5 秒をもらう。この 2 つを README に書く
- [x] `docker/php/Dockerfile` に pcntl を足して `make build`
- [x] `.github/workflows/ci.yml` の `extensions` に pcntl を足す (package / smoke の両ジョブ)
- [x] `packages/server/README.md` と `packages/http/README.md` の「接続は 1 つずつ順番に処理する」を直す (epoll 化後の実態は「読み書きは多重化されるが、ハンドラは直列」)

### Phase 1: `Platform` に `reusePort` を通す

- [x] `Port\Platform::listen()` に `bool $reusePort = false` を足す
- [x] `Libc`: `SO_REUSEPORT = 15` を定数に足し、`listenTcp()` で `reusePort` が真なら `SO_REUSEADDR` に続けて `setsockopt`
- [x] `StreamSelectPlatform::listen()`: `reusePort` が真なら `so_reuseport` 付きのコンテキストで `stream_socket_server()`
- [x] `PlatformContractTestCase` に「`reusePort: true` で listen したポートに、もう 1 つ `reusePort: true` で listen できる」テストを足す (ポート 0 で 1 つ目を作り、`localAddress()` のポートで 2 つ目を作る)。`reusePort: false` 同士は失敗することも確かめる
- [x] `LinuxPlatformTest` では、2 つの listener に 8 本ほど接続して両方の listener が少なくとも 1 本ずつ accept できることを確かめる (分配がカーネルで行われている証拠。`StreamSelect` では分配を保証しないのでこの確認は Linux だけ)

### Phase 2: `Supervisor` と `Server::serve(workers)`

- [x] `Server\Supervisor` (`@internal`): `__construct(Platform, Protocol, string $host, int $port, int $workers)`、`run(): never`
  - master: Q4 の手順で listen → 閉じる → `pcntl_fork()` × N
  - `pcntl_async_signals(true)` + `pcntl_signal(SIGTERM / SIGINT)` で停止フラグを立て、全ワーカに `SIGTERM`、`pcntl_waitpid()` で回収してから `exit(0)`
  - `pcntl_waitpid(-1, $status)` でワーカの終了を待ち、停止中でなければ同じ番号のワーカを再 fork する。起動直後 (1 秒以内) の終了が 5 回続いたら再起動を諦めて非 0 で終了する (設定ミスでの無限ループ防止)
  - worker: 継承した不要な fd はなし (master は fork 前に閉じている)。`listen(reusePort: true)` → `createPoller()` → `new EventLoop(...)` → `run()`。`SocketException` で落ちたら stderr に出して非 0 で終了
  - ログは `error_log()` ではなく今の `echo` に合わせる。`worker #2 (pid 123) started` 程度
- [x] `Server::serve(Protocol $protocol, string $host, int $port, int $workers = 1): never`。`$workers < 1` は `InvalidArgumentException`、`$workers >= 2` で `pcntl` がなければ `RuntimeException`。1 なら今の経路
- [x] `packages/server/composer.json` の `suggest` に `ext-pcntl`
- [x] テスト (`tests/ServerTest.php` + `tests/Fixtures/server.php`)。`HttpServerTest` と同じく `proc_open` で別プロセスに起動し、`Listening on` の行からアドレスを読む。`pcntl` がなければ skip
  - fixture の `Protocol`: 受信データに `slow` を含めば `sleep(2)` してから応答、それ以外は即応答。改行で区切る
  - `testServesOtherConnectionsWhileWorkerBlocks`: `workers: 4` で起動し、`slow` を 1 本送った 0.2 秒後に `fast` を 8 本 (別々の接続で) 送る。**少なくとも 1 本**は 1 秒以内に応答が返ることを確かめる (Q1 の通りハッシュでブロック中のワーカに当たる本もあるので、全部が速いことは主張しない。8 本すべてがブロック中のワーカに当たる確率は 4^-8)
  - `testPrintsResolvedPortWhenWorkersListenOnPortZero`: ポート 0 + `workers: 2` で `Listening on 127.0.0.1:<port> (2 workers)` と出て、そのポートに接続して応答が返る
  - `testRespawnsWorker`: fixture に `die` を受けたら `exit(1)` する分岐を入れ、`die` を送った後も次の `fast` に応答が返る
  - `testTerminatesWorkersOnSigterm`: `proc_terminate` 後にワーカが残っていないこと (fixture が起動時にワーカの pid を出力し、テスト側で `posix_kill($pid, 0)` が失敗することを確かめる)。`smoke.sh` と `HttpServerTest` は master を `kill` するので、ワーカが残ると次回以降 `SO_REUSEPORT` のせいで bind が成功してしまい気づけない。このテストで守る

### Phase 3: http / framework / 起動サンプル

- [x] `HttpServer::serve(RequestHandlerInterface $handler, string $host = '0.0.0.0', int $port = 8080, int $workers = 1): never`
- [x] framework: `App::fromConfig()` で `server.workers` を任意の `int` (1 以上) として検証。`ConfigTest` に不正値 (0、文字列) の確認を足す。`packages/framework/README.md` の設定表に行を足す
- [x] `entrypoint.php`: `/slow` (`sleep(2)`) を足し、`workers: 2` で起動する。サンプルとして「ワーカで並行する」ことを見せる
- [x] `scripts/smoke.sh`: 既存の 6 項目に加えて、`/slow` をバックグラウンドで送った 0.2 秒後に `GET /` を 4 本送り、少なくとも 1 本が 1 秒以内 (`curl --max-time 1`) に 200 を返すことを確かめる。終了時に `pkill -P "$SERVER_PID"` は不要 (master が SIGTERM でワーカを止める) だが、念のため `trap` 後にワーカが残っていないことを `pgrep -f entrypoint.php` で確認する

### Phase 4: README と完了条件の確認

- [x] ルート README に「並行処理のモデル」節を足す:
  - 1 プロセス内は epoll / `stream_select` で読み書きを多重化するが、ハンドラは直列。ブロッキング処理の間はそのワーカが止まる
  - `workers` で N プロセスにすると、カーネルが接続をワーカに振り分ける (SO_REUSEPORT)。同時に処理できるのはワーカ数まで。ワーカ間でメモリ上の状態 (static / プロパティ) は共有されない
  - ハッシュで振り分けるので、ブロック中のワーカに当たった接続はそのワーカが戻るまで待つ。空いているワーカが代わりに取ることはない
  - ワーカが落ちると master が再起動するが、そのワーカの accept キューにいた接続は切れる
  - 接続の 5 秒期限は accept 後から数える。backlog にいる間は数えない
  - macOS (`StreamSelect`) では分配を保証しない
  - Fiber 化は未対応 (非同期クライアントが揃ったときの選択肢)
- [x] `packages/server/README.md` / `packages/http/README.md` の「制限」を同じ内容に揃える。`Platform::listen()` の引数追加を破壊的変更として書く
- [x] Issue の完了条件を確認する
  - [x] ハンドラ内で `sleep()` している間も別のリクエストに応答できる (Phase 2 の `ServerTest`、Phase 3 の smoke)
  - [x] README に並行処理のモデルと制約が書かれている
- [ ] Issue のタスク 4 つにチェックを入れて閉じる

## 変更するファイル

| ファイル | 変更 |
|---|---|
| `packages/server/src/Port/Platform.php` | `listen()` に `$reusePort` |
| `packages/server/src/Platform/Linux/Libc.php` | `SO_REUSEPORT`、`listenTcp()` の `setsockopt` |
| `packages/server/src/Platform/Linux/LinuxPlatform.php` | `$reusePort` を `listenTcp()` に渡す |
| `packages/server/src/Platform/StreamSelect/StreamSelectPlatform.php` | `so_reuseport` のコンテキスト |
| `packages/server/src/Supervisor.php` | 新規。fork / 監視 / シグナル |
| `packages/server/src/Server.php` | `$workers` の分岐 |
| `packages/server/composer.json` | `suggest.ext-pcntl` |
| `packages/server/tests/EventLoopTest.php` | ブロック中の期限切れテスト |
| `packages/server/tests/Platform/PlatformContractTestCase.php`, `LinuxPlatformTest.php` | `reusePort` の約束 |
| `packages/server/tests/ServerTest.php`, `tests/Fixtures/server.php` | 新規。マルチプロセスの結合テスト |
| `packages/http/src/HttpServer.php` | `$workers` の素通し |
| `packages/framework/src/App.php` (と `Internal\Config`) | `server.workers` の検証 |
| `entrypoint.php`, `scripts/smoke.sh` | `/slow` と並行性の確認 |
| `docker/php/Dockerfile`, `.github/workflows/ci.yml` | pcntl |
| `README.md`, `packages/*/README.md` | 並行処理のモデルと制約、破壊的変更 |

## リスク・注意点

- **ハッシュ分配の性質**: ブロック中のワーカに当たった接続は救えない。Issue の再現手順 (`/fast` 1 本) は N 分の 1 で待たされうるので、テストは複数本で「少なくとも 1 本は速い」を確かめる。空いているワーカに拾わせたいなら共有 listener 方式 (Q1) が必要になる
- **ワーカの残留**: master が `SIGKILL` で落ちるとワーカは孤児になる。`SO_REUSEPORT` のせいで次の起動は成功してしまい、古いワーカが接続を取り続ける。Phase 2 の SIGTERM テストで通常経路は守るが、`SIGKILL` 対策 (Linux なら `prctl(PR_SET_PDEATHSIG)` を `Libc` に足す、または `EventLoop` の無期限待ちに上限を付けて `posix_getppid() === 1` を見る) は別 Issue にする
- **ポート確定後の窓**: master が listener を閉じてからワーカが listen するまでは接続拒否になる。`smoke.sh` と `HttpServerTest` は再試行 / `Listening on` 待ちなので影響しないが、本番で無停止再起動を期待しないこと
- **fork と FFI / epoll**: epoll の fd を fork 前に作ると親子で共有されて通知が分裂する。`createPoller()` は必ずワーカ側で呼ぶ。`Supervisor` の構造でこれを強制する (master は `Poller` に触らない)
- **出力のバッファ**: fork 前に `echo` した内容が出力バッファに残っていると、ワーカ側で二重に出る。master の `echo` のあとに `flush()` / `fflush(STDOUT)` しておく
- **macOS の分配**: `StreamSelect` では bind は通るが分配されない可能性がある。CI は Linux なので `LinuxPlatformTest` の分配テストだけで確認し、macOS は README に書くにとどめる
- **テストの時間**: `ServerTest` は `sleep(2)` を含むので数秒かかる。`@group slow` を付けるかは様子を見る
