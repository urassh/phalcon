<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Urassh\Phalcon\Server\Server;

$HOST = 'tcp://0.0.0.0:8080';

$server = new Server($HOST);
$server->run();
