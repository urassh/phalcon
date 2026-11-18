<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use Closure;

/**
 * CLI から起動したときに使う Runner を、インストールされたパッケージが登録しておく場所
 *
 * urassh/phalcon-http は Composer の autoload.files で HttpServer を登録する。
 * phalcon-framework は http に依存できないので、Phalcon::run() はここを通して HttpServer を見つける
 *
 * @phpstan-type Options array{host?: string, port?: int, workers?: int}
 */
final class DefaultRunner
{
  /** @var (Closure(Options): Runner)|null */
  private static ?Closure $factory = null;

  /**
   * 既定の Runner を作る関数を登録する。あとから登録したものが優先され、null で登録を外す
   *
   * @param (Closure(Options): Runner)|null $factory 呼ばれるまで Runner は作らない。渡されなかったキーは Runner の既定値を使う
   */
  public static function register(?Closure $factory): void
  {
    self::$factory = $factory;
  }

  /**
   * 登録された Runner を作る。登録がなければ null
   *
   * @param Options $options 待ち受けるアドレスやワーカ数。phalcon-framework は設定ファイルの server の値を渡す
   */
  public static function create(array $options = []): ?Runner
  {
    return self::$factory === null ? null : (self::$factory)($options);
  }
}
