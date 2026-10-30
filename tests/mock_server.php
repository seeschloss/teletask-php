<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/MockCentral.php';

use Teletask\Tests\MockCentral;

$port = (int) ($argv[1] ?? 45956);
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "mock: could not listen on 127.0.0.1:$port: $errstr ($errno)\n");
    exit(1);
}

fwrite(STDERR, "mock: listening on 127.0.0.1:$port\n");
MockCentral::run($server, static function (string $message): void {
    fwrite(STDERR, 'mock: ' . $message . "\n");
});
@fclose($server);
