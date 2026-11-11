<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Server;

use Urassh\Phalcon\Internal\Server\Protocol;

final class FakeProtocol implements Protocol
{
  public ?string $received = null;

  public function isComplete(string $data): bool
  {
    return str_ends_with($data, 'END');
  }

  public function respond(string $data): string
  {
    $this->received = $data;

    return 'response';
  }
}
