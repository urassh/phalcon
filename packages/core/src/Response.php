<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response implements ResponseInterface
{
  use MessageTrait;

  /** ステータスコードの既定の理由句 */
  public const REASON_PHRASES = [
    100 => 'Continue',
    101 => 'Switching Protocols',
    200 => 'OK',
    201 => 'Created',
    202 => 'Accepted',
    203 => 'Non-Authoritative Information',
    204 => 'No Content',
    205 => 'Reset Content',
    206 => 'Partial Content',
    300 => 'Multiple Choices',
    301 => 'Moved Permanently',
    302 => 'Found',
    303 => 'See Other',
    304 => 'Not Modified',
    307 => 'Temporary Redirect',
    308 => 'Permanent Redirect',
    400 => 'Bad Request',
    401 => 'Unauthorized',
    402 => 'Payment Required',
    403 => 'Forbidden',
    404 => 'Not Found',
    405 => 'Method Not Allowed',
    406 => 'Not Acceptable',
    408 => 'Request Timeout',
    409 => 'Conflict',
    410 => 'Gone',
    411 => 'Length Required',
    412 => 'Precondition Failed',
    413 => 'Content Too Large',
    414 => 'URI Too Long',
    415 => 'Unsupported Media Type',
    416 => 'Range Not Satisfiable',
    417 => 'Expectation Failed',
    418 => "I'm a teapot",
    422 => 'Unprocessable Content',
    426 => 'Upgrade Required',
    428 => 'Precondition Required',
    429 => 'Too Many Requests',
    431 => 'Request Header Fields Too Large',
    500 => 'Internal Server Error',
    501 => 'Not Implemented',
    502 => 'Bad Gateway',
    503 => 'Service Unavailable',
    504 => 'Gateway Timeout',
    505 => 'HTTP Version Not Supported',
  ];

  private int $statusCode;
  private string $reasonPhrase;

  /**
   * @param array<string, string|list<string>> $headers
   * @param string $reasonPhrase 空なら既定の理由句を使う
   */
  public function __construct(
    int $status = 200,
    array $headers = [],
    StreamInterface|string|null $body = null,
    string $version = '1.1',
    string $reasonPhrase = '',
  ) {
    $this->statusCode = self::validateStatus($status);
    $this->reasonPhrase = $reasonPhrase === '' ? (self::REASON_PHRASES[$status] ?? '') : $reasonPhrase;
    $this->setHeaders($headers);
    $this->body = $body instanceof StreamInterface ? $body : new Stream($body ?? '');
    $this->protocolVersion = $version;
  }

  public function getStatusCode(): int
  {
    return $this->statusCode;
  }

  public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
  {
    $new = clone $this;
    $new->statusCode = self::validateStatus($code);
    $new->reasonPhrase = $reasonPhrase === '' ? (self::REASON_PHRASES[$code] ?? '') : $reasonPhrase;

    return $new;
  }

  public function getReasonPhrase(): string
  {
    return $this->reasonPhrase;
  }

  private static function validateStatus(int $status): int
  {
    if ($status < 100 || $status > 599) {
      throw new InvalidArgumentException("Invalid status code: {$status}. Must be between 100 and 599");
    }

    return $status;
  }
}
