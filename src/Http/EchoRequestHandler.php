<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

final class EchoRequestHandler implements RequestHandler
{
  public function handle(string $request): Response
  {
    echo $request;

    return new Response("OK\n");
  }
}
