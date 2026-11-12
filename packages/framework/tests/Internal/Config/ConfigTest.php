<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests\Internal\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Urassh\Phalcon\Exception\ConfigException;
use Urassh\Phalcon\Internal\Config\Config;

final class ConfigTest extends TestCase
{
  private string $dir;

  protected function setUp(): void
  {
    $this->dir = sys_get_temp_dir() . '/phalcon-config-test-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
  }

  protected function tearDown(): void
  {
    foreach (glob("{$this->dir}/*") ?: [] as $file) {
      unlink($file);
    }
    rmdir($this->dir);
    putenv('PHALCON_TEST_PASSWORD');
  }

  public function testReadsNestedValuesWithDotKeys(): void
  {
    $config = $this->load(<<<'YAML'
      server:
        host: 127.0.0.1
        port: 9000
      database:
        dsn: mysql:host=db;dbname=app
      YAML);

    $this->assertSame('127.0.0.1', $config->host());
    $this->assertSame(9000, $config->port());
    $this->assertSame('mysql:host=db;dbname=app', $config->get('database.dsn'));
    $this->assertSame(['dsn' => 'mysql:host=db;dbname=app'], $config->get('database'));
  }

  public function testReturnsNullForMissingKeys(): void
  {
    $config = $this->load("database:\n  dsn: x\n");

    $this->assertNull($config->get('database.user'));
    $this->assertNull($config->get('database.dsn.extra'));
    $this->assertNull($config->get('missing'));
    $this->assertNull($config->host());
    $this->assertNull($config->port());
  }

  public function testEmptyConfigHasNoValues(): void
  {
    $this->assertNull(Config::empty()->get('server.host'));
    $this->assertNull($this->load('')->get('server.host'));
  }

  public function testExpandsEnvironmentVariables(): void
  {
    putenv('PHALCON_TEST_PASSWORD=s3cret');

    $config = $this->load("database:\n  password: \${PHALCON_TEST_PASSWORD}\n  url: user:\${PHALCON_TEST_PASSWORD}@db\n");

    $this->assertSame('s3cret', $config->get('database.password'));
    $this->assertSame('user:s3cret@db', $config->get('database.url'));
  }

  public function testPortFromEnvironmentVariableIsCastToInt(): void
  {
    putenv('PHALCON_TEST_PASSWORD=9001');

    $this->assertSame(9001, $this->load("server:\n  port: \${PHALCON_TEST_PASSWORD}\n")->port());
  }

  public function testThrowsOnUndefinedEnvironmentVariable(): void
  {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('PHALCON_TEST_UNDEFINED');

    $this->load("database:\n  password: \${PHALCON_TEST_UNDEFINED}\n");
  }

  public function testResolvesRelativeViewDirectoryAgainstConfigFile(): void
  {
    $config = $this->load("view:\n  directory: views\n");

    $this->assertSame(realpath($this->dir) . '/views', $config->get('view.directory'));
  }

  public function testKeepsAbsoluteViewDirectory(): void
  {
    $this->assertSame('/srv/views', $this->load("view:\n  directory: /srv/views\n")->get('view.directory'));
  }

  public function testThrowsWhenFileDoesNotExist(): void
  {
    $this->expectException(ConfigException::class);

    Config::load("{$this->dir}/missing.yaml");
  }

  /**
   * @return iterable<string, array{string}>
   */
  public static function invalidConfigs(): iterable
  {
    yield 'invalid yaml' => ["server: [\n"];
    yield 'scalar root' => ["just a string\n"];
    yield 'server is not a mapping' => ["server: 8080\n"];
    yield 'port is not an integer' => ["server:\n  port: abc\n"];
    yield 'port is a float' => ["server:\n  port: 80.5\n"];
    yield 'port is out of range' => ["server:\n  port: 70000\n"];
    yield 'host is not a string' => ["server:\n  host: [a]\n"];
    yield 'view directory is empty' => ["view:\n  directory: ''\n"];
  }

  #[DataProvider('invalidConfigs')]
  public function testThrowsOnInvalidConfig(string $yaml): void
  {
    $this->expectException(ConfigException::class);

    $this->load($yaml);
  }

  public function testDoesNotLeakYamlParserException(): void
  {
    try {
      $this->load("server: [\n");
      $this->fail('ConfigException was not thrown');
    } catch (ConfigException $e) {
      $this->assertNull($e->getPrevious());
    }
  }

  private function load(string $yaml): Config
  {
    $path = "{$this->dir}/phalcon.yaml";
    file_put_contents($path, $yaml);

    return Config::load($path);
  }
}
