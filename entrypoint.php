<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Urassh\Phalcon\Internal\Http\EchoRequestHandler;
use Urassh\Phalcon\Internal\Http\HttpProtocol;
use Urassh\Phalcon\Internal\Server\Server;

$HOST = 'tcp://0.0.0.0:8080';

$server = new Server($HOST, new HttpProtocol(new EchoRequestHandler()));
$server->run();
