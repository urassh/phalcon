<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\View;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Urassh\Phalcon\Internal\View\PhpRenderer;
use Urassh\Phalcon\Internal\View\ViewException;

final class PhpRendererTest extends TestCase
{
  private string $root;

  private string $views;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/phalcon-view-test-' . bin2hex(random_bytes(4));
    $this->views = "{$this->root}/views";
    mkdir("{$this->views}/users", 0777, true);
  }

  protected function tearDown(): void
  {
    exec('rm -rf ' . escapeshellarg($this->root));
  }

  public function testRendersTemplateWithDataAsVariables(): void
  {
    $this->template('index', '<h1><?= $title ?></h1><p><?= $count ?></p>');

    $this->assertSame('<h1>Top</h1><p>3</p>', $this->renderer()->render('index', ['title' => 'Top', 'count' => 3]));
  }

  public function testRendersTemplateInSubdirectory(): void
  {
    $this->template('users/show', 'Hello, <?= e($name) ?>');

    $this->assertSame('Hello, &lt;b&gt;&amp;', $this->renderer()->render('users/show', ['name' => '<b>&']));
  }

  public function testTemplateCannotSeeRendererInternals(): void
  {
    $this->template('scope', '<?= isset($this) ? "this" : "" ?><?= isset($path) ? "path" : "" ?><?= isset($__data) ? "data" : "" ?>');

    $this->assertSame('', $this->renderer()->render('scope', []));
  }

  public function testThrowsWhenTemplateDoesNotExist(): void
  {
    $this->expectException(ViewException::class);

    $this->renderer()->render('missing', []);
  }

  public function testThrowsWhenDirectoryDoesNotExist(): void
  {
    $this->expectException(ViewException::class);

    (new PhpRenderer("{$this->root}/nope"))->render('index', []);
  }

  public function testRejectsPathTraversal(): void
  {
    file_put_contents("{$this->root}/secret.php", 'secret');

    $this->expectException(ViewException::class);

    $this->renderer()->render('../secret', []);
  }

  public function testRejectsSymlinkPointingOutside(): void
  {
    file_put_contents("{$this->root}/secret.php", 'secret');
    symlink("{$this->root}/secret.php", "{$this->views}/link.php");

    $this->expectException(ViewException::class);

    $this->renderer()->render('link', []);
  }

  public function testDiscardsPartialOutputWhenTemplateThrows(): void
  {
    $this->template('broken', 'partial<?php throw new RuntimeException("boom"); ?>');
    $level = ob_get_level();

    try {
      $this->renderer()->render('broken', []);
      $this->fail('RuntimeException was not thrown');
    } catch (RuntimeException $e) {
      $this->assertSame('boom', $e->getMessage());
    }

    $this->assertSame($level, ob_get_level());
  }

  private function renderer(): PhpRenderer
  {
    return new PhpRenderer($this->views);
  }

  private function template(string $name, string $source): void
  {
    file_put_contents("{$this->views}/{$name}.php", $source);
  }
}
