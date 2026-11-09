<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Http;

use Urassh\Phalcon\Http\ValueObject\Request;
use Urassh\Phalcon\Http\ValueObject\Response;

final class EchoRequestHandler implements RequestHandler
{
  public function handle(Request $request): Response
  {
    echo "{$request->method()} {$request->target()} {$request->version()}\n";
    foreach ($request->headers()->all() as $name => $values) {
      foreach ($values as $value) {
        echo "{$name}: {$value}\n";
      }
    }
    echo "\n{$request->body()}";

    return new Response("OK\n");
  }
}
