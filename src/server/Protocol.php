<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Server;

interface Protocol
{
  public function isComplete(string $data): bool;

  public function respond(string $data): string;
}
