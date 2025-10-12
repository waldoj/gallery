<?php

declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Unable to determine repository root.\n");
    exit(1);
}

class ServerStartException extends RuntimeException
{
}

/**
 * @param string $docroot Absolute path to serve
 * @param string $router  Router script relative to docroot
 * @param int    $port    TCP port
 * @return array{process: resource, pipes: array<int, resource>}
 */
function start_server(string $docroot, string $router, int $port): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', sys_get_temp_dir() . '/gallery_http_server_stdout.log', 'a'],
        2 => ['file', sys_get_temp_dir() . '/gallery_http_server_stderr.log', 'a'],
    ];

    $cmd = sprintf(
        'php -S 127.0.0.1:%d -t %s %s',
        $port,
        escapeshellarg($docroot),
        escapeshellarg($router)
    );

    $process = proc_open($cmd, $descriptorSpec, $pipes, $docroot);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch PHP built-in server.');
    }

    try {
        wait_for_server($port);
    } catch (Throwable $throwable) {
        stop_server($process, $pipes);
        throw new ServerStartException(
            'Unable to start PHP built-in server on port ' . $port . ': ' . $throwable->getMessage(),
            0,
            $throwable
        );
    }

    return ['process' => $process, 'pipes' => $pipes];
}

function wait_for_server(int $port, int $timeoutSeconds = 5): void
{
    $start = microtime(true);
    while (true) {
        $socket = @fsockopen('127.0.0.1', $port);
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }

        if ((microtime(true) - $start) > $timeoutSeconds) {
            throw new RuntimeException('Timed out waiting for server on port ' . $port);
        }

        usleep(100_000);
    }
}

function stop_server($process, array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}

/**
 * @return array{status:int, body:string}
 */
function http_request(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $error = error_get_last();
        throw new RuntimeException('HTTP request failed for ' . $url . ': ' . ($error['message'] ?? 'unknown error'));
    }

    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header) && $http_response_header !== []) {
        if (preg_match('#HTTP/\d\.\d\s+(\d{3})#', (string)$http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }
    }

    return ['status' => $status, 'body' => $body];
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function assertStatus(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . $expected . ', got ' . $actual . ')');
    }
}

$tests = [
    'root' => [
        'docroot' => $repoRoot,
        'router' => 'index.php',
        'baseUrl' => 'http://127.0.0.1',
        'paths' => [
            '/' => ['status' => 200, 'contains' => 'Charlottesville Photos'],
            '/map/' => ['status' => 200, 'contains' => 'Photo Map'],
            '/view/?id=3bb2a5' => ['status' => 200, 'contains' => 'Rachel Playing Cards'],
            '/assets/styles.css' => ['status' => 200, 'contains' => '.gallery'],
        ],
    ],
    'subdirectory' => [
        'docroot' => dirname($repoRoot),
        'router' => 'gallery/index.php',
        'baseUrl' => 'http://127.0.0.1',
        'paths' => [
            '/gallery/' => ['status' => 200, 'contains' => 'Charlottesville Photos'],
            '/gallery/map/' => ['status' => 200, 'contains' => 'Photo Map'],
            '/gallery/view/?id=3bb2a5' => ['status' => 200, 'contains' => 'Rachel Playing Cards'],
            '/gallery/assets/styles.css' => ['status' => 200, 'contains' => '.gallery'],
        ],
    ],
];

$passed = 0;
$failed = 0;
$failures = [];

$port = 18080;
foreach ($tests as $name => $config) {
    try {
        $server = start_server($config['docroot'], $config['router'], $port);
        foreach ($config['paths'] as $path => $expectation) {
            $url = $config['baseUrl'] . ':' . $port . $path;
            $response = http_request($url);
            assertStatus($expectation['status'], $response['status'], $name . ' ' . $path . ' status mismatch');
            assertContains($expectation['contains'], $response['body'], $name . ' ' . $path . ' missing expected content');
        }
        $passed++;
        echo '.';
    } catch (ServerStartException $exception) {
        echo 'Skipping HTTP tests: ' . $exception->getMessage() . PHP_EOL;
        exit(0);
    } catch (Throwable $throwable) {
        $failed++;
        echo 'F';
        $failures[] = [$name, $throwable->getMessage()];
    } finally {
        if (isset($server)) {
            stop_server($server['process'], $server['pipes']);
            unset($server);
        }
        $port++;
    }
}

echo PHP_EOL;
if ($failed > 0) {
    foreach ($failures as [$name, $message]) {
        echo $name . ': ' . $message . PHP_EOL;
    }
    echo 'HTTP tests failed: ' . $failed . ' scenario(s).' . PHP_EOL;
    exit(1);
}

echo 'HTTP tests passed: ' . $passed . ' scenario(s).' . PHP_EOL;
