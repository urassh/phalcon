<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Urassh\Phalcon\App;
use Urassh\Phalcon\Exception\ConfigException;
use Urassh\Phalcon\Renderer;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

final class AppTest extends TestCase
{
  public function testDispatchesToMatchingRoute(): void
  {
    $app = (new App())
      ->get('/', fn (Request $req) => Response::text('top'))
      ->post('/users', fn (Request $req) => Response::text('created', 201));

    $this->assertSame('top', $this->send($app, 'GET', '/')->body());

    $response = $this->send($app, 'POST', '/users');
    $this->assertSame(201, $response->status());
    $this->assertSame('created', $response->body());
  }

  public function testEachMethodHelperRegistersItsMethod(): void
  {
    $app = new App();
    foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
      $app->{$method}('/', fn (Request $req) => Response::text($req->method()));
    }
    $app->route('options', '/', fn (Request $req) => Response::text($req->method()));

    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
      $this->assertSame($method, $this->send($app, $method, '/')->body());
    }
  }

  public function testExtractsPathParams(): void
  {
    $app = (new App())->get(
      '/users/{id}/posts/{post_id}',
      fn (Request $req) => Response::text("{$req->param('id')}:{$req->param('post_id')}"),
    );

    $this->assertSame('42:hello world', $this->send($app, 'GET', '/users/42/posts/hello%20world')->body());
  }

  public function testParamDoesNotMatchAcrossSegments(): void
  {
    $app = (new App())->get('/users/{id}', fn (Request $req) => Response::text('ok'));

    $this->assertSame(404, $this->send($app, 'GET', '/users/1/2')->status());
    $this->assertSame(404, $this->send($app, 'GET', '/users/')->status());
  }

  public function testIgnoresQueryStringWhenMatching(): void
  {
    $app = (new App())->get('/search', fn (Request $req) => Response::text((string) $req->query('q')));

    $this->assertSame('php', $this->send($app, 'GET', '/search?q=php')->body());
  }

  public function testLiteralPartsAreNotTreatedAsRegex(): void
  {
    $app = (new App())->get('/a.b', fn (Request $req) => Response::text('ok'));

    $this->assertSame(200, $this->send($app, 'GET', '/a.b')->status());
    $this->assertSame(404, $this->send($app, 'GET', '/axb')->status());
  }

  public function testFirstRegisteredRouteWins(): void
  {
    $app = (new App())
      ->get('/users/me', fn (Request $req) => Response::text('me'))
      ->get('/users/{id}', fn (Request $req) => Response::text('id'));

    $this->assertSame('me', $this->send($app, 'GET', '/users/me')->body());
    $this->assertSame('id', $this->send($app, 'GET', '/users/1')->body());
  }

  public function testAcceptsInvokableAndArrayCallables(): void
  {
    $controller = new class () {
      public function __invoke(Request $req): Response
      {
        return Response::text('invoke');
      }

      public function show(Request $req): Response
      {
        return Response::text('show');
      }
    };

    $app = (new App())
      ->get('/invoke', $controller)
      ->get('/show', [$controller, 'show']);

    $this->assertSame('invoke', $this->send($app, 'GET', '/invoke')->body());
    $this->assertSame('show', $this->send($app, 'GET', '/show')->body());
  }

  public function testReturnsNotFoundWhenNoRouteMatches(): void
  {
    $app = (new App())->get('/', fn (Request $req) => Response::text('ok'));

    $this->assertSame(404, $this->send($app, 'GET', '/missing')->status());
  }

  public function testReturnsMethodNotAllowedWithAllowHeader(): void
  {
    $app = (new App())
      ->get('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->put('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->delete('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->get('/users/{name}', fn (Request $req) => Response::text('ok'));

    $response = $this->send($app, 'POST', '/users/1');

    $this->assertSame(405, $response->status());
    $this->assertSame('GET, PUT, DELETE', $response->header('Allow'));
  }

  public function testReturnsBadRequestOnMalformedJson(): void
  {
    $app = (new App())->post('/', fn (Request $req) => Response::json($req->json()));

    $this->assertSame(400, $this->send($app, 'POST', '/', '{')->status());
    $this->assertSame('{"a":1}', $this->send($app, 'POST', '/', '{"a":1}')->body());
  }

  public function testReturnsInternalServerErrorWhenHandlerThrows(): void
  {
    $app = (new App())->get('/', function (Request $req): Response {
      throw new RuntimeException('secret detail');
    });

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->status());
    $this->assertStringNotContainsString('secret detail', $response->body());
  }

  public function testReturnsInternalServerErrorWhenHandlerReturnsNonResponse(): void
  {
    // @phpstan-ignore argument.type (型に反するハンドラを意図的に渡す)
    $app = (new App())->get('/', fn (Request $req) => 'not a response');

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->status());
  }

  public function testConfigReturnsNullWithoutConfigFile(): void
  {
    $this->assertNull((new App())->config('server.host'));
  }

  public function testFromConfigLoadsYaml(): void
  {
    $path = tempnam(sys_get_temp_dir(), 'phalcon');
    $this->assertIsString($path);
    file_put_contents($path, "database:\n  dsn: sqlite::memory:\n");

    try {
      $app = App::fromConfig($path);
    } finally {
      unlink($path);
    }

    $this->assertSame('sqlite::memory:', $app->config('database.dsn'));
    $this->assertNull($app->config('database.user'));
  }

  public function testFromConfigThrowsWhenFileDoesNotExist(): void
  {
    $this->expectException(ConfigException::class);

    App::fromConfig('/nonexistent/phalcon.yaml');
  }

  public function testRendersViewFromConfiguredDirectory(): void
  {
    $dir = sys_get_temp_dir() . '/phalcon-app-view-' . bin2hex(random_bytes(4));
    mkdir("{$dir}/views/users", 0777, true);
    file_put_contents("{$dir}/phalcon.yaml", "view:\n  directory: views\n");
    file_put_contents("{$dir}/views/users/show.php", '<h1>Hello, <?= e($name) ?></h1>');

    try {
      $app = App::fromConfig("{$dir}/phalcon.yaml")
        ->get('/users/{id}', fn (Request $req) => Response::view('users/show', ['name' => $req->param('id')]))
        ->get('/missing', fn (Request $req) => Response::view('missing'));

      $response = $this->send($app, 'GET', '/users/%3Cb%3E');
      $missing = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/missing'));
    } finally {
      exec('rm -rf ' . escapeshellarg($dir));
    }

    $this->assertSame(200, $response->status());
    $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
    $this->assertSame('<h1>Hello, &lt;b&gt;</h1>', $response->body());
    $this->assertSame(500, $missing->status());
  }

  public function testViewWithoutRendererReturnsInternalServerError(): void
  {
    $app = (new App())->get('/', fn (Request $req) => Response::view('index'));

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->status());
  }

  public function testUseRendererReplacesTemplateEngine(): void
  {
    $app = (new App())
      ->useRenderer(new class () implements Renderer {
        public function render(string $name, array $data): string
        {
          return "custom:{$name}";
        }
      })
      ->get('/', fn (Request $req) => Response::view('index', status: 201));

    $response = $this->send($app, 'GET', '/');

    $this->assertSame(201, $response->status());
    $this->assertSame('custom:index', $response->body());
  }

  private function send(App $app, string $method, string $target, string $body = ''): Response
  {
    $length = strlen($body);

    return $app->handle(Request::parse("{$method} {$target} HTTP/1.1\r\nContent-Length: {$length}\r\n\r\n{$body}"));
  }

  /**
   * @param callable(): Response $fn
   */
  private function silenceErrorLog(callable $fn): Response
  {
    $previous = ini_set('error_log', '/dev/null');
    try {
      return $fn();
    } finally {
      ini_set('error_log', $previous === false ? '' : $previous);
    }
  }
}
