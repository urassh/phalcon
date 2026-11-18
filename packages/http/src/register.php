<?php

declare(strict_types=1);

// Composer の autoload.files で読み込まれる。phalcon-http をインストールしただけで、
// phalcon-framework の Phalcon::run() が CLI から HttpServer で起動できるようにする

use Urassh\Phalcon\Core\DefaultRunner;
use Urassh\Phalcon\Http\HttpServer;

DefaultRunner::register(static fn (): HttpServer => new HttpServer());
