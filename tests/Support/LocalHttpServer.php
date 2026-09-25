<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Support;

/**
 * Runs `php -S` on 127.0.0.1 with tests/Support/router.php, for tests that need a misbehaving HTTP server
 * (redirects, compression bombs, oversized bodies). Every request's headers are appended to a log file.
 */
final class LocalHttpServer
{
    /** @var resource */
    private $process;

    public readonly int $port;
    public readonly string $log;

    /**
     * @param array<string, string> $env passed to the router (e.g. MODE, REDIRECT_TO)
     */
    public function __construct(array $env)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        \assert($socket !== false);
        $name = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        $this->log = (string) tempnam(sys_get_temp_dir(), 'weaviate-http-log-');
        // Portable: Windows has no /dev/null, and PHP there needs the inherited environment (SystemRoot, …).
        $nullDevice = \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . \DIRECTORY_SEPARATOR . 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']],
            $pipes,
            null,
            [...getenv(), ...$env, 'REQUEST_LOG' => $this->log],
        );
        \assert(\is_resource($process));
        $this->process = $process;

        for ($i = 0; $i < 250; ++$i) { // up to 5 s: process start is slower on Windows runners
            $probe = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(20_000);
        }

        throw new \RuntimeException('php -S did not start');
    }

    public function __destruct()
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->log);
    }

    public function requestLog(): string
    {
        return (string) file_get_contents($this->log);
    }
}
