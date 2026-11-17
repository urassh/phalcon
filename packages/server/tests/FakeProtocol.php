<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server\Tests;

use Closure;
use Urassh\Phalcon\Server\Protocol;

final class FakeProtocol implements Protocol
{
  public ?string $received = null;
  /** @var ?Closure(): void respond() の中で呼ぶ。ハンドラがブロックする様子を再現する */
  public ?Closure $onRespond = null;

  public function isComplete(string $data): bool
  {
    return str_ends_with($data, 'END');
  }

  public function respond(string $data): string
  {
    $this->received = $data;
    if ($this->onRespond !== null) {
      ($this->onRespond)();
    }

    return 'response';
  }
}
