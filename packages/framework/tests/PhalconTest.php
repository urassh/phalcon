<?php

declare(strict_types=1);

namespace Urassh\Phalcon\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Urassh\Phalcon\Controller;
use Urassh\Phalcon\Phalcon;
use Urassh\Phalcon\Core\Psr17Factory;
use Urassh\Phalcon\Exception\ConfigException;
use Urassh\Phalcon\Exception\ForbiddenException;
use Urassh\Phalcon\Exception\HttpException;
use Urassh\Phalcon\Exception\NotFoundException;
use Urassh\Phalcon\Exception\UnauthorizedException;
use Urassh\Phalcon\Renderer;
use Urassh\Phalcon\Request;
use Urassh\Phalcon\Response;

final class PhalconTest extends TestCase
{
  public function testDispatchesToMatchingRoute(): void
  {
    $app = (new Phalcon())
      ->get('/', fn (Request $req) => Response::text('top'))
      ->post('/users', fn (Request $req) => Response::text('created', 201));

    $this->assertSame('top', (string) $this->send($app, 'GET', '/')->getBody());

    $response = $this->send($app, 'POST', '/users');
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame('created', (string) $response->getBody());
  }

  public function testEachMethodHelperRegistersItsMethod(): void
  {
    $app = new Phalcon();
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
    $app = (new Phalcon())->get(
      '/users/{id}/posts/{post_id}',
      fn (Request $req) => Response::text("{$req->param('id')}:{$req->param('post_id')}"),
    );

    $this->assertSame('42:hello world', (string) $this->send($app, 'GET', '/users/42/posts/hello%20world')->getBody());
  }

  public function testParamDoesNotMatchAcrossSegments(): void
  {
    $app = (new Phalcon())->get('/users/{id}', fn (Request $req) => Response::text('ok'));

    $this->assertSame(404, $this->send($app, 'GET', '/users/1/2')->getStatusCode());
    $this->assertSame(404, $this->send($app, 'GET', '/users/')->getStatusCode());
  }

  public function testIgnoresQueryStringWhenMatching(): void
  {
    $app = (new Phalcon())->get('/search', fn (Request $req) => Response::text((string) $req->query('q')));

    $this->assertSame('php', (string) $this->send($app, 'GET', '/search?q=php')->getBody());
  }

  public function testLiteralPartsAreNotTreatedAsRegex(): void
  {
    $app = (new Phalcon())->get('/a.b', fn (Request $req) => Response::text('ok'));

    $this->assertSame(200, $this->send($app, 'GET', '/a.b')->getStatusCode());
    $this->assertSame(404, $this->send($app, 'GET', '/axb')->getStatusCode());
  }

  public function testFirstRegisteredRouteWins(): void
  {
    $app = (new Phalcon())
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

    $app = (new Phalcon())
      ->get('/invoke', $controller)
      ->get('/show', [$controller, 'show']);

    $this->assertSame('invoke', (string) $this->send($app, 'GET', '/invoke')->getBody());
    $this->assertSame('show', (string) $this->send($app, 'GET', '/show')->getBody());
  }

  public function testResourcesMapsActionsToRoutes(): void
  {
    $app = (new Phalcon())->resources('/users', new class () extends Controller {
      public function index(Request $req): Response
      {
        return Response::text('index');
      }

      public function new(Request $req): Response
      {
        return Response::text('new');
      }

      public function create(Request $req): Response
      {
        return Response::text('create', 201);
      }

      public function show(Request $req): Response
      {
        return Response::text("show:{$req->param('id')}");
      }

      public function edit(Request $req): Response
      {
        return Response::text("edit:{$req->param('id')}");
      }

      public function update(Request $req): Response
      {
        return Response::text("update:{$req->method()}:{$req->param('id')}");
      }

      public function destroy(Request $req): Response
      {
        return Response::text("destroy:{$req->param('id')}");
      }
    });

    $cases = [
      ['GET', '/users', 'index'],
      ['GET', '/users/new', 'new'],
      ['POST', '/users', 'create'],
      ['GET', '/users/42', 'show:42'],
      ['GET', '/users/42/edit', 'edit:42'],
      ['PATCH', '/users/42', 'update:PATCH:42'],
      ['PUT', '/users/42', 'update:PUT:42'],
      ['DELETE', '/users/42', 'destroy:42'],
    ];
    foreach ($cases as [$method, $target, $expected]) {
      $this->assertSame($expected, (string) $this->send($app, $method, $target)->getBody(), "{$method} {$target}");
    }
  }

  public function testResourcesRegistersOnlyDefinedPublicActions(): void
  {
    $app = (new Phalcon())->resources('/posts/', new class () extends Controller {
      public function show(Request $req): Response
      {
        return Response::text('show');
      }

      protected function index(Request $req): Response
      {
        return Response::text('index');
      }
    });

    $this->assertSame('show', (string) $this->send($app, 'GET', '/posts/1')->getBody());
    $this->assertSame(404, $this->send($app, 'GET', '/posts')->getStatusCode());

    $response = $this->send($app, 'DELETE', '/posts/1');
    $this->assertSame(405, $response->getStatusCode());
    $this->assertSame('GET', $response->getHeaderLine('Allow'));
  }

  public function testResourcesOnlyRegistersGivenActions(): void
  {
    $app = (new Phalcon())->resources('/users', $this->fullController(), only: ['index', 'show']);

    $this->assertSame('index', (string) $this->send($app, 'GET', '/users')->getBody());
    $this->assertSame('show', (string) $this->send($app, 'GET', '/users/1')->getBody());
    // new がないので /users/new は show に渡る
    $this->assertSame('show', (string) $this->send($app, 'GET', '/users/new')->getBody());
    $this->assertSame(405, $this->send($app, 'POST', '/users')->getStatusCode());
    $this->assertSame(405, $this->send($app, 'DELETE', '/users/1')->getStatusCode());
  }

  public function testResourcesExceptSkipsGivenActions(): void
  {
    $app = (new Phalcon())->resources('/users', $this->fullController(), except: ['destroy', 'edit']);

    $this->assertSame('update', (string) $this->send($app, 'PATCH', '/users/1')->getBody());
    $this->assertSame(404, $this->send($app, 'GET', '/users/1/edit')->getStatusCode());

    $response = $this->send($app, 'DELETE', '/users/1');
    $this->assertSame(405, $response->getStatusCode());
    $this->assertSame('GET, PATCH, PUT', $response->getHeaderLine('Allow'));
  }

  public function testResourcesRejectsUnknownActionName(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage("Unknown action 'delete'");

    (new Phalcon())->resources('/users', $this->fullController(), except: ['delete']);
  }

  public function testResourcesRejectsOnlyActionNotDefinedByController(): void
  {
    $controller = new class () extends Controller {
      public function index(Request $req): Response
      {
        return Response::text('index');
      }
    };

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage("does not define public action 'show'");

    (new Phalcon())->resources('/users', $controller, only: ['index', 'show']);
  }

  public function testReturnsNotFoundWhenNoRouteMatches(): void
  {
    $app = (new Phalcon())->get('/', fn (Request $req) => Response::text('ok'));

    $this->assertSame(404, $this->send($app, 'GET', '/missing')->getStatusCode());
  }

  public function testReturnsMethodNotAllowedWithAllowHeader(): void
  {
    $app = (new Phalcon())
      ->get('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->put('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->delete('/users/{id}', fn (Request $req) => Response::text('ok'))
      ->get('/users/{name}', fn (Request $req) => Response::text('ok'));

    $response = $this->send($app, 'POST', '/users/1');

    $this->assertSame(405, $response->getStatusCode());
    $this->assertSame('GET, PUT, DELETE', $response->getHeaderLine('Allow'));
  }

  public function testHttpExceptionBecomesTextResponse(): void
  {
    $app = (new Phalcon())
      ->get('/users/{id}', fn (Request $req) => throw new NotFoundException("User {$req->param('id')} not found"))
      ->get('/admin', fn (Request $req) => throw new ForbiddenException())
      ->get('/login', fn (Request $req) => throw new UnauthorizedException(headers: ['WWW-Authenticate' => 'Bearer']))
      ->get('/maintenance', fn (Request $req) => throw new HttpException(503));

    $notFound = $this->send($app, 'GET', '/users/42');
    $this->assertSame(404, $notFound->getStatusCode());
    $this->assertSame('text/plain; charset=utf-8', $notFound->getHeaderLine('Content-Type'));
    $this->assertSame("User 42 not found\n", (string) $notFound->getBody());

    $forbidden = $this->send($app, 'GET', '/admin');
    $this->assertSame(403, $forbidden->getStatusCode());
    $this->assertSame("Forbidden\n", (string) $forbidden->getBody());

    $unauthorized = $this->send($app, 'GET', '/login');
    $this->assertSame(401, $unauthorized->getStatusCode());
    $this->assertSame('Bearer', $unauthorized->getHeaderLine('WWW-Authenticate'));

    $maintenance = $this->send($app, 'GET', '/maintenance');
    $this->assertSame(503, $maintenance->getStatusCode());
    $this->assertSame("Service Unavailable\n", (string) $maintenance->getBody());
  }

  public function testControllerActionCanThrowHttpException(): void
  {
    $app = (new Phalcon())->resources('/users', new class () extends Controller {
      public function show(Request $req): Response
      {
        throw new NotFoundException();
      }
    });

    $response = $this->send($app, 'GET', '/users/1');

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame("Not Found\n", (string) $response->getBody());
  }

  public function testRendersErrorPageWhenTemplateExists(): void
  {
    $dir = sys_get_temp_dir() . '/phalcon-app-errors-' . bin2hex(random_bytes(4));
    mkdir("{$dir}/views/errors", 0777, true);
    file_put_contents("{$dir}/phalcon.yaml", "view:\n  directory: views\n");
    file_put_contents("{$dir}/views/errors/404.php", '<h1><?= e((string) $status) ?> <?= e($message) ?></h1>');
    file_put_contents("{$dir}/views/errors/500.php", '<h1>Oops <?= e($message) ?></h1>');
    file_put_contents("{$dir}/views/errors/405.php", '<?php throw new RuntimeException("broken");');

    try {
      $app = Phalcon::fromConfig("{$dir}/phalcon.yaml")
        ->get('/users/{id}', fn (Request $req) => throw new NotFoundException('<User> not found'))
        ->get('/admin', fn (Request $req) => throw new ForbiddenException())
        ->get('/boom', fn (Request $req) => throw new RuntimeException('secret'));

      $thrown = $this->send($app, 'GET', '/users/1');
      $unmatched = $this->send($app, 'GET', '/missing');
      $withoutPage = $this->send($app, 'GET', '/admin');
      $internal = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/boom'));
      $brokenPage = $this->silenceErrorLog(fn () => $this->send($app, 'POST', '/admin'));
    } finally {
      exec('rm -rf ' . escapeshellarg($dir));
    }

    $this->assertSame(404, $thrown->getStatusCode());
    $this->assertSame('text/html; charset=utf-8', $thrown->getHeaderLine('Content-Type'));
    $this->assertSame('<h1>404 &lt;User&gt; not found</h1>', (string) $thrown->getBody());

    // ルートにマッチしない場合も同じエラーページを使う
    $this->assertSame(404, $unmatched->getStatusCode());
    $this->assertSame('<h1>404 Not Found</h1>', (string) $unmatched->getBody());

    $this->assertSame(403, $withoutPage->getStatusCode());
    $this->assertSame("Forbidden\n", (string) $withoutPage->getBody());

    // 想定外の例外のメッセージはエラーページにも渡さない
    $this->assertSame(500, $internal->getStatusCode());
    $this->assertSame('<h1>Oops Internal Server Error</h1>', (string) $internal->getBody());

    // エラーページの描画に失敗したらテキストに戻す
    $this->assertSame(405, $brokenPage->getStatusCode());
    $this->assertSame('GET', $brokenPage->getHeaderLine('Allow'));
    $this->assertSame("Method Not Allowed\n", (string) $brokenPage->getBody());
  }

  public function testReturnsJsonErrorWhenClientAcceptsJson(): void
  {
    $app = (new Phalcon())
      ->get('/memos/{id}', fn (Request $req) => throw new NotFoundException('Memo not found'))
      ->get('/boom', fn (Request $req) => throw new RuntimeException('secret'));
    $json = ['Accept' => 'application/json'];

    $thrown = $this->send($app, 'GET', '/memos/1', headers: $json);
    $this->assertSame(404, $thrown->getStatusCode());
    $this->assertSame('application/json', $thrown->getHeaderLine('Content-Type'));
    $this->assertSame('{"error":"Memo not found"}', (string) $thrown->getBody());

    $notAllowed = $this->send($app, 'POST', '/memos/1', headers: ['Accept' => 'application/problem+json; q=0.9']);
    $this->assertSame(405, $notAllowed->getStatusCode());
    $this->assertSame('GET', $notAllowed->getHeaderLine('Allow'));
    $this->assertSame('{"error":"Method Not Allowed"}', (string) $notAllowed->getBody());

    $internal = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/boom', headers: $json));
    $this->assertSame(500, $internal->getStatusCode());
    $this->assertSame('{"error":"Internal Server Error"}', (string) $internal->getBody());

    // ブラウザのページ遷移 (text/html を含む) や Accept なしは JSON にしない
    $browser = $this->send($app, 'GET', '/memos/1', headers: ['Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8']);
    $this->assertSame('text/plain; charset=utf-8', $browser->getHeaderLine('Content-Type'));
    $this->assertSame('text/plain; charset=utf-8', $this->send($app, 'GET', '/memos/1')->getHeaderLine('Content-Type'));
  }

  public function testOnErrorHookDecidesErrorResponse(): void
  {
    $seen = [];
    $app = (new Phalcon())
      ->onError(function (HttpException $e, Request $req) use (&$seen): ?Response {
        $seen[] = $e->getPrevious()?->getMessage();
        if (!str_starts_with($req->path(), '/api/')) {
          return null;
        }

        return Response::json(['code' => $e->status(), 'message' => $e->getMessage()], $e->status());
      })
      ->get('/api/memos/{id}', fn (Request $req) => throw new NotFoundException())
      ->post('/api/memos', fn (Request $req) => Response::text('ok'))
      ->get('/api/boom', fn (Request $req) => throw new RuntimeException('secret'))
      ->get('/memos/{id}', fn (Request $req) => throw new NotFoundException());

    $api = $this->send($app, 'GET', '/api/memos/1');
    $this->assertSame(404, $api->getStatusCode());
    $this->assertSame('{"code":404,"message":"Not Found"}', (string) $api->getBody());

    // フックが返したレスポンスにも例外のヘッダを付ける
    $this->assertSame('POST', $this->send($app, 'GET', '/api/memos')->getHeaderLine('Allow'));

    // 想定外の例外は 500 に包まれ、元の例外は getPrevious() で見られる
    $internal = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/api/boom'));
    $this->assertSame('{"code":500,"message":"Internal Server Error"}', (string) $internal->getBody());
    $this->assertContains('secret', $seen);

    // null を返すと既定の形式に任せる
    $this->assertSame("Not Found\n", (string) $this->send($app, 'GET', '/memos/1')->getBody());
  }

  public function testFallsBackToDefaultWhenOnErrorHookFails(): void
  {
    $app = (new Phalcon())
      ->onError(fn (HttpException $e, Request $req) => throw new RuntimeException('broken hook'))
      ->get('/memos/{id}', fn (Request $req) => throw new NotFoundException());

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/memos/1'));

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame("Not Found\n", (string) $response->getBody());
  }

  public function testReturnsBadRequestOnMalformedJson(): void
  {
    $app = (new Phalcon())->post('/', fn (Request $req) => Response::json($req->json()));

    $this->assertSame(400, $this->send($app, 'POST', '/', '{')->getStatusCode());
    $this->assertSame('{"a":1}', (string) $this->send($app, 'POST', '/', '{"a":1}')->getBody());
  }

  public function testReturnsInternalServerErrorWhenHandlerThrows(): void
  {
    $app = (new Phalcon())->get('/', function (Request $req): Response {
      throw new RuntimeException('secret detail');
    });

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringNotContainsString('secret detail', (string) $response->getBody());
  }

  public function testReturnsInternalServerErrorWhenHandlerReturnsNonResponse(): void
  {
    // @phpstan-ignore argument.type (型に反するハンドラを意図的に渡す)
    $app = (new Phalcon())->get('/', fn (Request $req) => 'not a response');

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->getStatusCode());
  }

  public function testMiddlewareRunsInOrderAroundRouting(): void
  {
    $app = (new Phalcon())
      ->add($this->tagMiddleware('outer'))
      ->add($this->tagMiddleware('inner'))
      ->get('/', fn (Request $req) => Response::text((string) $req->header('X-Trace')));

    $response = $this->send($app, 'GET', '/');

    $this->assertSame('outer,inner', (string) $response->getBody());
    $this->assertSame('inner,outer', $response->getHeaderLine('X-Trace'));
  }

  public function testMiddlewareCanShortCircuit(): void
  {
    $app = (new Phalcon())
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
    $app = (new Phalcon())
      ->add(new class () implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
          return $handler->handle($request->withAttribute('user', 'urassh'));
        }
      })
      ->get('/', fn (Request $req) => Response::text((string) $req->param('user')));

    $this->assertSame('urassh', (string) $this->send($app, 'GET', '/')->getBody());
  }

  public function testMiddlewareHttpExceptionBecomesErrorResponse(): void
  {
    $app = (new Phalcon())
      ->add(new class () implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
          throw new UnauthorizedException(headers: ['WWW-Authenticate' => 'Bearer']);
        }
      })
      ->get('/', fn (Request $req) => Response::text('unreachable'));

    $response = $this->send($app, 'GET', '/');
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    $this->assertSame("Unauthorized\n", (string) $response->getBody());

    $json = $this->send($app, 'GET', '/', headers: ['Accept' => 'application/json']);
    $this->assertSame('{"error":"Unauthorized"}', (string) $json->getBody());
  }

  public function testBlocksCrossOriginRequestsBeforeMiddleware(): void
  {
    $app = (new Phalcon())
      ->add($this->tagMiddleware('user'))
      ->get('/memos', fn (Request $req) => Response::text('index'))
      ->post('/memos', fn (Request $req) => Response::text('created', 201))
      ->delete('/memos/{id}', fn (Request $req) => Response::noContent());
    $crossSite = ['Sec-Fetch-Site' => 'cross-site'];

    $blocked = $this->send($app, 'POST', '/memos', headers: $crossSite);
    $this->assertSame(403, $blocked->getStatusCode());
    $this->assertSame("Cross-origin request blocked\n", (string) $blocked->getBody());
    // add() したミドルウェアまで届かない
    $this->assertSame('', $blocked->getHeaderLine('X-Trace'));
    $this->assertSame(403, $this->send($app, 'DELETE', '/memos/1', headers: $crossSite)->getStatusCode());

    // 外部サイトからのリンクで GET するのは通常の遷移なので通す
    $this->assertSame('index', (string) $this->send($app, 'GET', '/memos', headers: $crossSite)->getBody());

    $sameOrigin = $this->send($app, 'POST', '/memos', headers: ['Sec-Fetch-Site' => 'same-origin']);
    $this->assertSame(201, $sameOrigin->getStatusCode());
    $this->assertSame('user', $sameOrigin->getHeaderLine('X-Trace'));

    $this->assertSame(201, $this->send($app, 'POST', '/memos', headers: ['Origin' => 'http://localhost:8080', 'Host' => 'localhost:8080'])->getStatusCode());
    $this->assertSame(201, $this->send($app, 'POST', '/memos')->getStatusCode());
  }

  public function testCrossOriginErrorUsesErrorHandling(): void
  {
    $dir = sys_get_temp_dir() . '/phalcon-app-csrf-' . bin2hex(random_bytes(4));
    mkdir("{$dir}/views/errors", 0777, true);
    file_put_contents("{$dir}/phalcon.yaml", "view:\n  directory: views\n");
    file_put_contents("{$dir}/views/errors/403.php", '<h1><?= e((string) $status) ?> <?= e($message) ?></h1>');

    try {
      $app = Phalcon::fromConfig("{$dir}/phalcon.yaml")
        ->onError(fn (HttpException $e, Request $req) => str_starts_with($req->path(), '/api/')
          ? Response::json(['code' => $e->status()], $e->status())
          : null)
        ->post('/memos', fn (Request $req) => Response::text('created', 201))
        ->post('/api/memos', fn (Request $req) => Response::text('created', 201));
      $crossSite = ['Sec-Fetch-Site' => 'cross-site'];

      $page = $this->send($app, 'POST', '/memos', headers: $crossSite);
      $api = $this->send($app, 'POST', '/api/memos', headers: $crossSite);
    } finally {
      exec('rm -rf ' . escapeshellarg($dir));
    }

    $this->assertSame(403, $page->getStatusCode());
    $this->assertSame('<h1>403 Cross-origin request blocked</h1>', (string) $page->getBody());

    $this->assertSame(403, $api->getStatusCode());
    $this->assertSame('{"code":403}', (string) $api->getBody());
  }

  public function testFromConfigTrustsConfiguredOrigins(): void
  {
    $path = tempnam(sys_get_temp_dir(), 'phalcon');
    $this->assertIsString($path);
    file_put_contents($path, "csrf:\n  trusted_origins:\n    - https://app.example.com\n");

    try {
      $app = Phalcon::fromConfig($path)->post('/memos', fn (Request $req) => Response::text('created', 201));
    } finally {
      unlink($path);
    }

    $trusted = $this->send($app, 'POST', '/memos', headers: ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://app.example.com']);
    $this->assertSame(201, $trusted->getStatusCode());

    $other = $this->send($app, 'POST', '/memos', headers: ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.example']);
    $this->assertSame(403, $other->getStatusCode());
  }

  public function testConfigReturnsNullWithoutConfigFile(): void
  {
    $this->assertNull((new Phalcon())->config('server.host'));
  }

  public function testFromConfigLoadsYaml(): void
  {
    $path = tempnam(sys_get_temp_dir(), 'phalcon');
    $this->assertIsString($path);
    file_put_contents($path, "database:\n  dsn: sqlite::memory:\n");

    try {
      $app = Phalcon::fromConfig($path);
    } finally {
      unlink($path);
    }

    $this->assertSame('sqlite::memory:', $app->config('database.dsn'));
    $this->assertNull($app->config('database.user'));
  }

  public function testFromConfigThrowsWhenFileDoesNotExist(): void
  {
    $this->expectException(ConfigException::class);

    Phalcon::fromConfig('/nonexistent/phalcon.yaml');
  }

  public function testRendersViewFromConfiguredDirectory(): void
  {
    $dir = sys_get_temp_dir() . '/phalcon-app-view-' . bin2hex(random_bytes(4));
    mkdir("{$dir}/views/users", 0777, true);
    file_put_contents("{$dir}/phalcon.yaml", "view:\n  directory: views\n");
    file_put_contents("{$dir}/views/users/show.php", '<h1>Hello, <?= e($name) ?></h1>');

    try {
      $app = Phalcon::fromConfig("{$dir}/phalcon.yaml")
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
    $app = (new Phalcon())->get('/', fn (Request $req) => Response::view('index'));

    $response = $this->silenceErrorLog(fn () => $this->send($app, 'GET', '/'));

    $this->assertSame(500, $response->getStatusCode());
  }

  public function testUseRendererReplacesTemplateEngine(): void
  {
    $app = (new Phalcon())
      ->useRenderer(new class () implements Renderer {
        public function render(string $name, array $data): string
        {
          return "custom:{$name}";
        }

        public function exists(string $name): bool
        {
          return true;
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

  private function fullController(): Controller
  {
    return new class () extends Controller {
      public function index(Request $req): Response
      {
        return Response::text('index');
      }

      public function new(Request $req): Response
      {
        return Response::text('new');
      }

      public function create(Request $req): Response
      {
        return Response::text('create');
      }

      public function show(Request $req): Response
      {
        return Response::text('show');
      }

      public function edit(Request $req): Response
      {
        return Response::text('edit');
      }

      public function update(Request $req): Response
      {
        return Response::text('update');
      }

      public function destroy(Request $req): Response
      {
        return Response::text('destroy');
      }
    };
  }

  /**
   * @param array<string, string> $headers
   */
  private function send(Phalcon $app, string $method, string $target, string $body = '', array $headers = []): ResponseInterface
  {
    $factory = new Psr17Factory();
    $request = $factory->createServerRequest($method, $target)->withBody($factory->createStream($body));
    foreach ($headers as $name => $value) {
      $request = $request->withHeader($name, $value);
    }

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
