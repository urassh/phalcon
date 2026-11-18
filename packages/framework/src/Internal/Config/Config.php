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
   * server / view / csrf の値を検証し、相対パスを解決する
   *
   * @param array<mixed> $values
   *
   * @return array<mixed>
   */
  private static function normalize(array $values, string $baseDir): array
  {
    $server = self::section($values, 'server');
    $view = self::section($values, 'view');
    $csrf = self::section($values, 'csrf');

    $origins = $csrf['trusted_origins'] ?? null;
    if ($origins !== null) {
      if (!is_array($origins) || !array_is_list($origins)) {
        throw new ConfigException('csrf.trusted_origins must be a list of origins');
      }
      $csrf['trusted_origins'] = array_map(self::origin(...), $origins);
      $values['csrf'] = $csrf;
    }

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

    $workers = $server['workers'] ?? null;
    if ($workers !== null) {
      if (is_string($workers) && ctype_digit($workers)) {
        $workers = (int) $workers;
      }
      if (!is_int($workers) || $workers < 1) {
        throw new ConfigException('server.workers must be an integer of at least 1');
      }
      $server['workers'] = $workers;
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
   * `https://app.example.com` や `http://localhost:3000` の形のオリジンを検証し、Origin ヘッダと比べられるよう小文字にする
   *
   * ブラウザは Origin ヘッダに末尾のスラッシュやパスを付けないので、付いていたら書き間違いとして弾く。
   * 完全一致で比べるので `*` のようなワイルドカードも弾く
   */
  private static function origin(mixed $origin): string
  {
    $parts = is_string($origin) ? parse_url($origin) : false;
    if ($parts === false
      || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
      || preg_match('/\A(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])\z/', $parts['host'] ?? '') !== 1
      || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []) {
      $value = is_string($origin) ? "'{$origin}'" : get_debug_type($origin);

      throw new ConfigException("csrf.trusted_origins must contain origins like 'https://example.com', got {$value}");
    }

    return strtolower((string) $origin);
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

  public function workers(): ?int
  {
    $workers = $this->get('server.workers');

    return is_int($workers) ? $workers : null;
  }

  public function viewDirectory(): ?string
  {
    $directory = $this->get('view.directory');

    return is_string($directory) ? $directory : null;
  }

  /**
   * @return list<string> 小文字にしたオリジン。設定がなければ空
   */
  public function trustedOrigins(): array
  {
    $origins = $this->get('csrf.trusted_origins');

    return is_array($origins) ? array_values(array_filter($origins, is_string(...))) : [];
  }
}
