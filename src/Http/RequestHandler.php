<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

interface RequestHandler
{
  public function handle(string $request): Response;
}
