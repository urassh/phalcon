<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use Urassh\Phalcon\Http\ValueObject\Request;
use Urassh\Phalcon\Http\ValueObject\Response;

interface RequestHandler
{
  public function handle(Request $request): Response;
}
