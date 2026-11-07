<?php

declare(strict_types=1);

$HOST = 'tcp://0.0.0.0:8080';

$socket = stream_socket_server(
  $HOST,
  $errno,
  $errstr
);

echo "Listening on {$HOST}\n";

do {
  $stream = stream_socket_accept($socket, -1);
  if ($stream) {
    echo 'Accepted connection from ' . stream_socket_get_name($stream, true) . "\n";
    fwrite($stream, "Hello from the server!\n");
    fclose($stream);
  }

} while ($stream);

