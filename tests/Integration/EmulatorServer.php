<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Integration;

use RuntimeException;

/**
 * Runs tests/Emulator/index.php in PHP's built-in server on a free local port.
 */
final class EmulatorServer
{
    /** @var resource */
    private $process;
    private readonly string $baseUri;
    private readonly string $log;
    private readonly string $state;

    /**
     * @param array<string, string> $env e.g. MYRA_EMULATOR_PAGE_SIZE
     */
    public function __construct(array $env = [])
    {
        $port = self::freePort();
        $this->baseUri = 'http://127.0.0.1:' . $port;
        $log = tempnam(sys_get_temp_dir(), 'myra-emulator-');
        $this->log = $log !== false ? $log : throw new RuntimeException('No temp file for the call log.');
        // Created domains and DNS records; reset() forgets them, like the calls.
        $this->state = $this->log . '.state.json';

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../Emulator/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['MYRA_EMULATOR_LOG' => $this->log, 'MYRA_EMULATOR_STATE' => $this->state] + $env + self::environment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the emulator.');
        }
        $this->process = $process;
        $this->waitUntilReady($port);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function baseUri(): string
    {
        return $this->baseUri;
    }

    /**
     * Every API call since the last reset, as the emulator logged it.
     *
     * @return list<array{method: string, uri: string, auth: string, auth_ok: bool, body: string, content_length: int|null, body_bytes: int, status: int}>
     */
    public function calls(): array
    {
        $calls = [];
        foreach ((array) file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            /** @var array{method: string, uri: string, auth: string, auth_ok: bool, body: string, content_length: int|null, body_bytes: int, status: int} $call */
            $call = json_decode((string) $line, true, flags: JSON_THROW_ON_ERROR);
            $calls[] = $call;
        }

        return $calls;
    }

    public function reset(): void
    {
        file_put_contents($this->log, '');
        if (is_file($this->state)) {
            unlink($this->state);
        }
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach ([$this->log, $this->state] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitUntilReady(int $port): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException('The emulator did not start on port ' . $port . '.');
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return array_filter(getenv(), static fn(string $name): bool => !str_contains(strtolower($name), 'proxy'), ARRAY_FILTER_USE_KEY);
    }
}
