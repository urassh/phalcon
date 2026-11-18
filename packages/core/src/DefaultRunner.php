<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Core;

use Closure;

/**
 * CLI から起動したときに使う Runner を、インストールされたパッケージが登録しておく場所
 *
 * urassh/phalcon-http は Composer の autoload.files で HttpServer を登録する。
 * phalcon-framework は http に依存できないので、Phalcon::run() はここを通して HttpServer を見つける
 */
final class DefaultRunner
{
  /** @var (Closure(): Runner)|null */
  private static ?Closure $factory = null;

  /**
   * 既定の Runner を作る関数を登録する。あとから登録したものが優先され、null で登録を外す
   *
   * @param (Closure(): Runner)|null $factory 呼ばれるまで Runner は作らない
   */
  public static function register(?Closure $factory): void
  {
    self::$factory = $factory;
  }

  /**
   * 登録された Runner を作る。登録がなければ null
   */
  public static function create(): ?Runner
  {
    return self::$factory === null ? null : (self::$factory)();
  }
}
