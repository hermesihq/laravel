<?php

declare(strict_types=1);

namespace Hermesi\Laravel\Tests\Support;

/** Starts tests/Support/server.php as a real process and talks to it through files. */
final class TestServer
{
    public const ACCEPTED = [
        'event_id' => 'evt_01K2QH8F3T7Y0RJ4N5V6WX8ZQD',
        'status' => 'accepted',
        'notifications' => [['id' => 'not_1', 'subscriber_id' => 'sub_1', 'workflow' => 'order-shipped']],
        'warnings' => [],
    ];

    /** @var resource|null */
    private $process;
    private string $dir;
    private int $port = 0;

    private function __construct()
    {
        $this->dir = sys_get_temp_dir().'/hermesi-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    public static function start(): self
    {
        $server = new self();
        $server->reset();
        $command = [\PHP_BINARY, __DIR__.'/server.php', $server->dir, (string) getmypid()];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the test server');
        }
        $server->process = $process;
        for ($i = 0; $i < 100; ++$i) {
            if (is_file($server->dir.'/port')) {
                $server->port = (int) file_get_contents($server->dir.'/port');
                break;
            }
            usleep(50_000);
        }
        if (0 === $server->port) {
            $server->stop();
            throw new \RuntimeException('The test server did not report a port');
        }

        return $server;
    }

    public function url(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /** Forget every request and queued answer; the default answer is a 202 with an accepted event. */
    public function reset(): void
    {
        file_put_contents($this->dir.'/requests.jsonl', '');
        file_put_contents($this->dir.'/queue.json', '[]');
        $this->setDefault(['status' => 202, 'body' => self::ACCEPTED]);
    }

    /** @param array<string, mixed> $answer */
    public function setDefault(array $answer): void
    {
        file_put_contents($this->dir.'/default.json', json_encode($answer, \JSON_THROW_ON_ERROR));
    }

    /**
     * Answers for the next requests, in order. Once they run out the default answer is used.
     *
     * @param array<string, mixed> ...$answers
     */
    public function enqueue(array ...$answers): void
    {
        $current = json_decode((string) file_get_contents($this->dir.'/queue.json'), true);
        file_put_contents($this->dir.'/queue.json', json_encode(array_merge(\is_array($current) ? $current : [], $answers), \JSON_THROW_ON_ERROR));
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    public function requests(): array
    {
        $out = [];
        foreach (explode("\n", (string) file_get_contents($this->dir.'/requests.jsonl')) as $line) {
            if ('' !== $line) {
                /** @var array{method: string, path: string, headers: array<string, string>, body: string} $decoded */
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /** @return array{method: string, path: string, headers: array<string, string>, body: string} */
    public function last(): array
    {
        $all = $this->requests();
        if ([] === $all) {
            throw new \LogicException('The server received no request');
        }

        return $all[array_key_last($all)];
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** A URL nothing listens on: bind a port, note it, release it. */
    public static function deadUrl(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $socket) {
            throw new \RuntimeException('Could not bind a port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return 'http://127.0.0.1:'.substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function error(string $code, array $extra = []): array
    {
        return ['error' => array_merge([
            'type' => 'invalid_request_error',
            'code' => $code,
            'message' => 'message for '.$code,
            'request_id' => 'req_abc123',
            'doc_url' => 'https://docs.example/errors/'.$code,
        ], $extra)];
    }
}
