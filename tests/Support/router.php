<?php

// Router for LocalHttpServer (php -S). Behaviour is chosen by the MODE environment variable.

declare(strict_types=1);

$log = getenv('REQUEST_LOG');
if (is_string($log) && $log !== '') {
    $entry = var_export($_SERVER['REQUEST_METHOD'] ?? '?', true) . ' ' . var_export($_SERVER['REQUEST_URI'] ?? '?', true) . "\n";
    foreach (getallheaders() as $name => $value) {
        $entry .= strtolower((string) $name) . ': ' . (is_string($value) ? $value : '') . "\n";
    }
    file_put_contents($log, $entry . "\n", \FILE_APPEND);
}

$meta = json_encode(['version' => '1.39.7', 'hostname' => 'http://[::]:8080', 'modules' => new stdClass()]);

switch (getenv('MODE')) {
    case 'redirect': // every request is redirected, keeping method and body (307)
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . (string) getenv('REDIRECT_TO') . (is_string($uri) ? $uri : '/'), true, 307);

        return true;

    case 'meta': // a plain Weaviate /v1/meta
        header('Content-Type: application/json');
        echo $meta;

        return true;

    case 'gzip-bomb': // ~200 KB of gzip that inflates to 200 MB, streamed so the router stays small
        header('Content-Type: application/json');
        header('Content-Encoding: gzip');
        $deflate = deflate_init(\ZLIB_ENCODING_GZIP, ['level' => 9]);
        if ($deflate === false) {
            http_response_code(500);

            return true;
        }
        $block = str_repeat('0', 1024 * 1024);
        for ($i = 0; $i < 200; ++$i) {
            echo deflate_add($deflate, $block, \ZLIB_NO_FLUSH);
        }
        echo deflate_add($deflate, '', \ZLIB_FINISH);

        return true;

    case 'big-chunked': // 3 MB without Content-Length
        header('Content-Type: application/json');
        for ($i = 0; $i < 3; ++$i) {
            echo str_repeat('x', 1024 * 1024);
            flush();
        }

        return true;

    case 'big-declared': // Content-Length announces 3 MB
        header('Content-Type: application/json');
        header('Content-Length: ' . (3 * 1024 * 1024));
        echo str_repeat('x', 3 * 1024 * 1024);

        return true;
}

http_response_code(500);

return true;
