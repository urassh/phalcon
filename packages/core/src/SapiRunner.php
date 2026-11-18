<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * PHP-FPM や php -S などの SAPI から呼ばれたスクリプトで、リクエストを 1 件処理する
 *
 * スーパーグローバルから ServerRequest::fromGlobals() でリクエストを作り、応答を SapiEmitter で書き出す
 */
final class SapiRunner implements Runner
{
  public function run(RequestHandlerInterface $handler): void
  {
    (new SapiEmitter())->emit($handler->handle(ServerRequest::fromGlobals()));
  }
}
