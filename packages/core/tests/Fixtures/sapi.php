<?php

declare(strict_types=1);

// SapiTest から php -S のルータスクリプトとして起動する。SapiRunner でスーパーグローバルからリクエストを作り、header() と echo で返す

require __DIR__ . '/../../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Urassh\Phalcon\Core\Response;
use Urassh\Phalcon\Core\SapiRunner;

$handler = new class () implements RequestHandlerInterface {
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return new Response(
      201,
      ['Content-Type' => 'application/json', 'Set-Cookie' => ['a=1', 'b=2']],
      json_encode([
        'method' => $request->getMethod(),
        'uri' => (string) $request->getUri(),
        'host' => $request->getHeaderLine('Host'),
        'query' => $request->getQueryParams(),
        'parsedBody' => $request->getParsedBody(),
        'body' => (string) $request->getBody(),
      ], JSON_THROW_ON_ERROR),
      reasonPhrase: 'Created Here',
    );
  }
};

(new SapiRunner())->run($handler);
