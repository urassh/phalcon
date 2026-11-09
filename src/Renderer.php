<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

/**
 * Response::view() で使うテンプレートエンジン
 */
interface Renderer
{
  /**
   * @param array<string, mixed> $data
   */
  public function render(string $name, array $data): string;
}
