<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Internal\View;

use Throwable;
use Urassh\Phalcon\Renderer;

/**
 * `{directory}/{name}.php` を PHP テンプレートとして描画する
 *
 * @internal
 */
final class PhpRenderer implements Renderer
{
  public function __construct(
    private readonly string $directory,
  ) {
  }

  public function render(string $name, array $data): string
  {
    $path = $this->resolve($name);

    // テンプレートから $this や Renderer の変数が見えないよう、static クロージャで描画する
    $render = static function (string $__path, array $__data): void {
      extract($__data, EXTR_SKIP);
      unset($__data);
      require $__path;
    };

    $level = ob_get_level();
    ob_start();
    try {
      $render($path, $data);

      return (string) ob_get_clean();
    } catch (Throwable $e) {
      // 途中までの出力がレスポンスに混ざらないよう捨てる
      while (ob_get_level() > $level) {
        ob_end_clean();
      }
      throw $e;
    }
  }

  /**
   * テンプレート名をファイルパスに変換する。ディレクトリの外は指せない
   */
  private function resolve(string $name): string
  {
    if ($name === '' || str_contains($name, "\0") || in_array('..', explode('/', $name), true)) {
      throw new ViewException("Invalid template name: {$name}");
    }

    $directory = realpath($this->directory);
    if ($directory === false || !is_dir($directory)) {
      throw new ViewException("View directory not found: {$this->directory}");
    }

    $path = realpath("{$directory}/{$name}.php");
    if ($path === false || !is_file($path)) {
      throw new ViewException("Template not found: {$name}");
    }

    // シンボリックリンクで外を指している場合も弾く
    if (!str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
      throw new ViewException("Template is outside the view directory: {$name}");
    }

    return $path;
  }
}
