<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Http;

use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

/**
 * @internal
 */
interface RequestHandler
{
  public function handle(Request $request): Response;
}
