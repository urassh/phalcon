<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Urassh\Phalcon\App;
use Urassh\Phalcon\Core\Psr17Factory;
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

    $this->assertSame('top', (string) $this->send($app, 'GET', '/')->getBody());

    $response = $this->send($app, 'POST', '/users');
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame('created', (string) $response->getBody());
  }

  public function testEachMethodHelperRegistersItsMethod(): void
  {
    $app = new App();
    foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
      $app->{$method}('/', fn (Request $req) => Response::text($req->method()));
    }
    $app->route('options', '/', fn (Request $req) => Response::text($req->method()));

    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
      $this->assertSame($method, (string) $this->send($app, $method, '/')->getBody());
    }
  }

  public function testExtractsPathParams(): void
  {
    $app = (new App())->get(
      '/users/{id}/posts/{post_id}',
      fn (Request $req) => Response::text("{$req->param('id')}:{$req->param('post_id')}"),
    );

    $this->assertSame('42:hello world', (string) $this->send($app, 'GET', '/users/42/posts/hello%20world')->getBody());
  }

  public function testParamDoesNotMatchAcrossSegments(): void
  {
    $app = (new App())->get('/users/{id}', fn (Request $req) => Response::text('ok'));

    $this->assertSame(404, $this->send($app, 'GET', '/users/1/2')->getStatusCode());
    $this->assertSame(404, $this->send($app, 'GET', '/users/')->getStatusCode());
  }

  public function testIgnoresQueryStringWhenMatching(): void
  {
    $app = (new App())->get('/search', fn (Request $req) => Response::text((string) $req->query('q')));

    $this->assertSame('php', (string) $this->send($app, 'GET', '/search?q=php')->getBody());
  }

  public function testLiteralPartsAreNotTreatedAsRegex(): void
  {
    $app = (new App())->get('/a.b', fn (Request $req) => Response::text('ok'));

    $this->assertSame(200, $this->send($app, 'GET', '/a.b')->getStatusCode());
    $this->assertSame(404, $this->send($app, 'GET', '/axb')->getStatusCode());
  }

  public function testFirstRegisteredRouteWins(): void
  {
    $app = (new App())
      ->get('/users/me', fn (Request $req) => Response::text('me'))
      ->get('/users/{id}', fn (Request $req) => Response::text('id'));

    $this->assertSame('me', (string) $this->send($app, 'GET', '/users/me')->getBody());
    $this->assertSame('id', (string) $this->send($app, 'GET', '/users/1')->getBody());
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

    $this->assertSame('invoke', (string) $this->send($app, 'GET', '/invoke')->getBody());
    $this->assertSame('show', (string) $this->send($app, 'GET', '/show')->getBody());
  }

  public function testReturnsNotFoundWhenNoRouteMatches(): void
  {
    $app = (new App())->get('/', fn (Request $req) => Response::text('ok'));

    $this->assertSame(404, $this->send($app, 'GET', '/missing')->getStatusCode());
  }

  public function testReturnsMethodNotAllowedWithAllowHeader(): void
  {
    $app = (new App())
      ->get('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->put('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->delete('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->get('/users/{name}', fn (Request $req) => Response::text('ok'));

    $response = $this->send($app, 'POST', '/users/1');

    $this->assertSame(405, $response->getStatusCode());
    $this->assertSame('GET, PUT, DELETE', $response->getHeaderLine('Allow'));
  }

  public function testReturnsBadRequestOnMalformedJson(): void
  {
    $app = (new App())->post('/', fn (Request $req) => Response::json($req->json()));

    $this->assertSame(400, $this->send($app, 'POST', '/', '{')->getStatusCode());
    $this->assertSame('{"a":1}', (string) $this->send($app, 'POST', '/', '{"a":1}')->getBody());
  }

  public function testReturnsInternalServerErrorWhenHandlerThrows(): void
  {
    $app = (new App())->get('/', function (Request $req): Response {
      throw new RuntimeException('secret detail');
    });

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringNotContainsString('secret detail', (string) $response->getBody());
  }

  public function testReturnsInternalServerErrorWhenHandlerReturnsNonResponse(): void
  {
    // @phpstan-ignore argument.type (型に反するハンドラを意図的に渡す)
    $app = (new App())->get('/', fn (Request $req) => 'not a response');

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->getStatusCode());
  }

  public function testMiddlewareRunsInOrderAroundRouting(): void
  {
    $app = (new App())
      ->add($this->tagMiddleware('outer'))
      ->add($this->tagMiddleware('inner'))
      ->get('/', fn (Request $req) => Response::text((string) $req->header('X-Trace')));

    $response = $this->send($app, 'GET', '/');

    $this->assertSame('outer,inner', (string) $response->getBody());
    $this->assertSame('inner,outer', $response->getHeaderLine('X-Trace'));
  }

  public function testMiddlewareCanShortCircuit(): void
  {
    $app = (new App())
      ->add(new class () implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
          return (new Psr17Factory())->createResponse(401);
        }
      })
      ->get('/', fn (Request $req) => Response::text('unreachable'));

    $this->assertSame(401, $this->send($app, 'GET', '/')->getStatusCode());
  }

  public function testMiddlewareAttributesAreVisibleFromRequest(): void
  {
    $app = (new App())
      ->add(new class () implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
          return $handler->handle($request->withAttribute('user', 'urassh'));
        }
      })
      ->get('/', fn (Request $req) => Response::text((string) $req->param('user')));

    $this->assertSame('urassh', (string) $this->send($app, 'GET', '/')->getBody());
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

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    $this->assertSame('<h1>Hello, &lt;b&gt;</h1>', (string) $response->getBody());
    $this->assertSame(500, $missing->getStatusCode());
  }

  public function testViewWithoutRendererReturnsInternalServerError(): void
  {
    $app = (new App())->get('/', fn (Request $req) => Response::view('index'));

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->getStatusCode());
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

    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame('custom:index', (string) $response->getBody());
  }

  /**
   * リクエストの X-Trace に名前を足してから次へ渡し、レスポンスの X-Trace にも名前を足す
   */
  private function tagMiddleware(string $name): MiddlewareInterface
  {
    return new class ($name) implements MiddlewareInterface {
      public function __construct(
        private readonly string $name,
      ) {
      }

      public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
      {
        $response = $handler->handle($request->withHeader('X-Trace', $this->append($request->getHeaderLine('X-Trace'))));

        return $response->withHeader('X-Trace', $this->append($response->getHeaderLine('X-Trace')));
      }

      private function append(string $trace): string
      {
        return $trace === '' ? $this->name : "{$trace},{$this->name}";
      }
    };
  }

  private function send(App $app, string $method, string $target, string $body = ''): ResponseInterface
  {
    $factory = new Psr17Factory();
    $request = $factory->createServerRequest($method, $target)->withBody($factory->createStream($body));

    return $app->handle($request);
  }

  /**
   * @param callable(): ResponseInterface $fn
   */
  private function silenceErrorLog(callable $fn): ResponseInterface
  {
    $previous = ini_set('error_log', '/dev/null');
    try {
      return $fn();
    } finally {
      ini_set('error_log', $previous === false ? '' : $previous);
    }
  }
}
