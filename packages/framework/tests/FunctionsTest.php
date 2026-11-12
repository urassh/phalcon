<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase
{
  public function testEscapesHtmlSpecialCharacters(): void
  {
    $this->assertSame('&lt;a href=&quot;x&quot;&gt;&#039;&amp;&lt;/a&gt;', e('<a href="x">\'&</a>'));
  }

  public function testAcceptsNullAndScalars(): void
  {
    $this->assertSame('', e(null));
    $this->assertSame('42', e(42));
    $this->assertSame('1', e(true));
  }

  public function testAcceptsStringable(): void
  {
    $value = new class () implements \Stringable {
      public function __toString(): string
      {
        return '<x>';
      }
    };

    $this->assertSame('&lt;x&gt;', e($value));
  }

  public function testSubstitutesInvalidUtf8(): void
  {
    $this->assertSame("a\u{FFFD}b", e("a\xFFb"));
  }

  public function testThrowsOnArray(): void
  {
    $this->expectException(InvalidArgumentException::class);

    e(['a']);
  }
}
