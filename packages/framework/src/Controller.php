<?php

declare(strict_types=1);

namespace Urassh\Phalcon;

/**
 * Phalcon::resources() に渡すコントローラ。
 * 次の名前の public メソッドをアクションとしてルートに割り当てる
 *
 * | アクション | メソッド      | パス                  |
 * |-----------|--------------|-----------------------|
 * | index     | GET          | /users                |
 * | new       | GET          | /users/new            |
 * | create    | POST         | /users                |
 * | show      | GET          | /users/{id}           |
 * | edit      | GET          | /users/{id}/edit      |
 * | update    | PATCH / PUT  | /users/{id}           |
 * | destroy   | DELETE       | /users/{id}           |
 *
 * アクションはハンドラと同じく `Request` を受け取って `Response` を返す。
 * 定義していないアクションのルートは登録しない。登録するアクションは resources() の `only` / `except` でも絞れる
 */
abstract class Controller
{
  /**
   * $location へリダイレクトするレスポンスを返す
   *
   * 既定の 303 See Other は、create / update / destroy のあとに一覧や詳細へ GET で戻すときに使う
   */
  protected static function redirect(string $location, int $status = 303): Response
  {
    return Response::text('', $status)->withHeader('Location', $location);
  }
}
