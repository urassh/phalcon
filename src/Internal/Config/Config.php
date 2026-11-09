<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Urassh\Phalcon\Exception\ConfigException;

/**
 * @internal
 */
final class Config
{
  private const ENV_PATTERN = '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/';

  /**
   * @param array<mixed> $values
   */
  private function __construct(
    private readonly array $values,
  ) {
  }

  public static function empty(): self
  {
    return new self([]);
  }

  /**
   * @throws ConfigException ファイルが読めない、YAML として不正、値の型が不正な場合
   */
  public static function load(string $path): self
  {
    if (!is_file($path) || !is_readable($path)) {
      throw new ConfigException("Config file not found: {$path}");
    }

    // symfony/yaml の例外は公開 API に出さない
    try {
      $values = Yaml::parseFile($path);
    } catch (ParseException $e) {
      throw new ConfigException("Invalid YAML in {$path}: {$e->getMessage()}");
    }

    // 空ファイルは null になる
    $values ??= [];
    if (!is_array($values)) {
      throw new ConfigException("Config root must be a mapping: {$path}");
    }

    $values = self::expandEnv($values);
    $values = self::normalize($values, dirname((string) realpath($path)));

    return new self($values);
  }

  /**
   * 文字列中の `${NAME}` を環境変数の値に置き換える
   *
   * @param array<mixed> $values
   *
   * @return array<mixed>
   */
  private static function expandEnv(array $values): array
  {
    array_walk_recursive($values, function (mixed &$value): void {
      if (!is_string($value)) {
        return;
      }

      $value = preg_replace_callback(self::ENV_PATTERN, function (array $matches): string {
        $env = getenv($matches[1]);
        if ($env === false) {
          throw new ConfigException("Undefined environment variable: {$matches[1]}");
        }

        return $env;
      }, $value);
    });

    return $values;
  }

  /**
   * server / view の値を検証し、相対パスを解決する
   *
   * @param array<mixed> $values
   *
   * @return array<mixed>
   */
  private static function normalize(array $values, string $baseDir): array
  {
    $server = self::section($values, 'server');
    $view = self::section($values, 'view');

    $host = $server['host'] ?? null;
    if ($host !== null && !is_string($host)) {
      throw new ConfigException('server.host must be a string');
    }

    $port = $server['port'] ?? null;
    if ($port !== null) {
      // ${PORT} のように環境変数から渡すと文字列になるので、数字だけの文字列も許す
      if (is_string($port) && ctype_digit($port)) {
        $port = (int) $port;
      }
      if (!is_int($port) || $port < 1 || $port > 65535) {
        throw new ConfigException('server.port must be an integer between 1 and 65535');
      }
      $server['port'] = $port;
      $values['server'] = $server;
    }

    $directory = $view['directory'] ?? null;
    if ($directory !== null) {
      if (!is_string($directory) || $directory === '') {
        throw new ConfigException('view.directory must be a non-empty string');
      }
      if (!str_starts_with($directory, '/')) {
        $view['directory'] = $baseDir . '/' . $directory;
        $values['view'] = $view;
      }
    }

    return $values;
  }

  /**
   * @param array<mixed> $values
   *
   * @return array<mixed> セクションがなければ空配列
   */
  private static function section(array $values, string $name): array
  {
    $section = $values[$name] ?? [];
    if (!is_array($section)) {
      throw new ConfigException("{$name} must be a mapping");
    }

    return $section;
  }

  /**
   * `database.dsn` のようなドット区切りのキーで値を参照する。なければ null
   */
  public function get(string $key): mixed
  {
    $value = $this->values;
    foreach (explode('.', $key) as $segment) {
      if (!is_array($value) || !array_key_exists($segment, $value)) {
        return null;
      }
      $value = $value[$segment];
    }

    return $value;
  }

  public function host(): ?string
  {
    $host = $this->get('server.host');

    return is_string($host) ? $host : null;
  }

  public function port(): ?int
  {
    $port = $this->get('server.port');

    return is_int($port) ? $port : null;
  }
}
