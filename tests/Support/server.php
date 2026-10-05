<?php

declare(strict_types=1);

/**
 * A scripted HTTP server on localhost for the tests to talk to. A fake of the HTTP client would prove the SDK against the fake;
 * this proves it against the wire: headers as they arrive, a body as it is read, a socket that dies, a server that never answers.
 *
 * Usage: php server.php <state-dir>. It writes its port to <state-dir>/port, appends every request to <state-dir>/requests.jsonl,
 * and answers from <state-dir>/queue.json (a list consumed in order) and then <state-dir>/default.json.
 *
 * An answer: {"status": 202, "headers": {...}, "body": <json or string>, "destroy": bool, "hang": bool, "truncate": bool}.
 */
$dir = $argv[1] ?? '';
if ('' === $dir || !is_dir($dir)) {
    fwrite(\STDERR, "usage: php server.php <state-dir>\n");
    exit(2);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (false === $server) {
    fwrite(\STDERR, "cannot listen: $error\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
file_put_contents($dir.'/port.tmp', (string) substr((string) $name, (int) strrpos((string) $name, ':') + 1));
rename($dir.'/port.tmp', $dir.'/port');

/** @var list<resource> $held connections kept open and never answered */
$held = [];

// If the test process dies (a fatal error, a kill), this server must not outlive it: an orphan holds the terminal, or a CI
// container, open forever.
$parent = (int) ($argv[2] ?? 0);

while (true) {
    $conn = @stream_socket_accept($server, 1);
    if (false === $conn) {
        if ($parent > 0 && function_exists('posix_kill') && !posix_kill($parent, 0)) {
            exit(0);
        }
        continue;
    }
    stream_set_timeout($conn, 5);

    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($conn, 4096);
        if (false === $chunk || '' === $chunk) {
            break;
        }
        $head .= $chunk;
    }
    if (!str_contains($head, "\r\n\r\n")) {
        fclose($conn);
        continue;
    }
    [$headText, $body] = explode("\r\n\r\n", $head, 2);
    $lines = explode("\r\n", $headText);
    [$method, $path] = explode(' ', (string) array_shift($lines)) + [1 => ''];
    $headers = [];
    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    $length = (int) ($headers['content-length'] ?? 0);
    while (strlen($body) < $length) {
        $chunk = fread($conn, $length - strlen($body));
        if (false === $chunk || '' === $chunk) {
            break;
        }
        $body .= $chunk;
    }

    file_put_contents($dir.'/requests.jsonl', json_encode(['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);

    $queue = is_file($dir.'/queue.json') ? json_decode((string) file_get_contents($dir.'/queue.json'), true) : [];
    $answer = is_array($queue) && [] !== $queue ? array_shift($queue) : json_decode((string) file_get_contents($dir.'/default.json'), true);
    file_put_contents($dir.'/queue.json', json_encode(is_array($queue) ? array_values($queue) : []));

    if (!empty($answer['destroy'])) {
        fclose($conn);
        continue;
    }
    if (!empty($answer['hang'])) {
        $held[] = $conn;
        continue;
    }
    $payload = is_string($answer['body'] ?? null) ? $answer['body'] : json_encode($answer['body'] ?? new stdClass());
    $status = (int) ($answer['status'] ?? 200);
    $extra = !empty($answer['truncate']) ? 100 : 0;
    $out = "HTTP/1.1 $status X\r\nContent-Type: application/json\r\nContent-Length: ".(strlen((string) $payload) + $extra)."\r\nConnection: close\r\n";
    foreach (($answer['headers'] ?? []) as $k => $v) {
        $out .= "$k: $v\r\n";
    }
    fwrite($conn, $out."\r\n".$payload);
    if ($extra > 0) {
        usleep(20_000);
    }
    fclose($conn);
}
