<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Exception;

use RuntimeException;
use Throwable;
use Urassh\Phalcon\Core\Response as PsrResponse;

/**
 * ハンドラの処理を打ち切ってエラーレスポンスを返すための例外
 *
 * 投げるとフレームワークが捕まえ、view ディレクトリに `errors/{ステータス}.php` があればそれを描画し、
 * なければメッセージをテキストで返す。メッセージはクライアントにそのまま見えるので、内部の情報を入れないこと
 */
class HttpException extends RuntimeException
{
  /**
   * @param string $message 空ならステータスの理由句 (`Not Found` など)
   * @param array<string, string> $headers レスポンスに付けるヘッダ
   */
  public function __construct(
    private readonly int $status,
    string $message = '',
    private readonly array $headers = [],
    ?Throwable $previous = null,
  ) {
    parent::__construct($message === '' ? self::reasonPhrase($status) : $message, 0, $previous);
  }

  public function status(): int
  {
    return $this->status;
  }

  /**
   * @return array<string, string>
   */
  public function headers(): array
  {
    return $this->headers;
  }

  /**
   * ステータスの理由句を返す。知らないステータスなら `Error`
   */
  public static function reasonPhrase(int $status): string
  {
    return PsrResponse::REASON_PHRASES[$status] ?? 'Error';
  }
}
