<?php

declare(strict_types=1);

function now_iso(): string
{
    $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $dt->format('Y-m-d\TH:i:s\Z');
}

function json_safe($value)
{
    if ($value === null) {
        return null;
    }
    if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
        return $value;
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format(DateTimeInterface::ATOM);
    }
    if (is_resource($value)) {
        return (string) $value;
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = json_safe($v);
        }
        return $out;
    }
    return (string) $value;
}

function stable_row_hash(array $row): string
{
    $normalized = [];
    foreach ($row as $k => $v) {
        $normalized[(string) $k] = json_safe($v);
    }
    ksort($normalized);
    $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        $encoded = '{}';
    }
    return hash('sha256', $encoded);
}

function is_absolute_path(string $path): bool
{
    if ($path === '') {
        return false;
    }
    if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
        return true;
    }
    if (substr($path, 0, 2) === '\\\\') {
        return true;
    }
    if ($path[0] === '/' || $path[0] === '\\') {
        return true;
    }
    return false;
}

function resolve_path(string $path): string
{
    if (is_absolute_path($path)) {
        return $path;
    }
    return rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

function load_state(string $state_path): array
{
    if (!file_exists($state_path)) {
        return ['tables' => []];
    }
    $raw = file_get_contents($state_path);
    if ($raw === false) {
        return ['tables' => []];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    if (!isset($decoded['tables']) || !is_array($decoded['tables'])) {
        $decoded['tables'] = [];
    }
    return $decoded;
}

function save_state(string $state_path, array $state): void
{
    $tmp_path = $state_path . '.tmp';
    $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        $encoded = "{}\n";
    } else {
        $encoded .= "\n";
    }
    $dir = dirname($state_path);
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents($tmp_path, $encoded);
    @rename($tmp_path, $state_path);
}

function build_access_conn_str(string $db_path, string $password): array
{
    $driver = '{Microsoft Access Driver (*.mdb, *.accdb)}';
    return [
        'DRIVER=' . $driver . ';DBQ=' . $db_path . ';PWD=' . $password . ';',
        'DRIVER=' . $driver . ';DBQ=' . $db_path . ';Jet OLEDB:Database Password=' . $password . ';',
    ];
}

function connect_access(string $db_path, string $password)
{
    if (!function_exists('odbc_connect')) {
        throw new RuntimeException('Ekstensi ODBC PHP tidak tersedia (odbc_connect). Aktifkan ekstensi ODBC dan pastikan driver Access terpasang.');
    }
    $last_err = null;
    foreach (build_access_conn_str($db_path, $password) as $conn_str) {
        $conn = @odbc_connect($conn_str, '', '', SQL_CUR_USE_ODBC);
        if ($conn !== false) {
            return $conn;
        }
        $last_err = odbc_errormsg();
    }
    throw new RuntimeException("Gagal konek ke Access. Pastikan driver 'Microsoft Access Driver (*.mdb, *.accdb)' terpasang dan path DB benar. " . (string) $last_err);
}

function fetch_table_rows($conn, string $table_name): array
{
    $sql = 'SELECT * FROM [' . str_replace(']', ']]', $table_name) . ']';
    $rs = @odbc_exec($conn, $sql);
    if ($rs === false) {
        throw new RuntimeException('Query gagal: ' . odbc_errormsg($conn));
    }
    $rows = [];
    while (($row = odbc_fetch_array($rs)) !== false) {
        $clean = [];
        foreach ($row as $k => $v) {
            $clean[(string) $k] = $v;
        }
        $rows[] = $clean;
    }
    return $rows;
}

function chunked(array $items, int $chunk_size): Generator
{
    $total = count($items);
    for ($i = 0; $i < $total; $i += $chunk_size) {
        yield array_slice($items, $i, $chunk_size);
    }
}

function post_rows(
    string $api_url,
    string $api_key,
    int $branch_id,
    string $branch_session,
    string $table,
    string $primary_key,
    array $rows,
    int $timeout_seconds = 45
): array {
    $payload_rows = [];
    foreach ($rows as $row) {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = json_safe($v);
        }
        $payload_rows[] = $out;
    }

    $payload = [
        'branch_id' => $branch_id,
        'branch_session' => $branch_session,
        'sent_at' => now_iso(),
        'table' => $table,
        'primary_key' => $primary_key,
        'rows' => $payload_rows,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Gagal encode JSON payload.');
    }

    $ch = curl_init($api_url);
    if ($ch === false) {
        throw new RuntimeException('Gagal init cURL.');
    }

    $started = microtime(true);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => $timeout_seconds,
    ]);

    $text = curl_exec($ch);
    $curl_err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $elapsed_ms = (int) round((microtime(true) - $started) * 1000);

    if ($text === false) {
        throw new RuntimeException('HTTP request failed: ' . $curl_err);
    }

    $resp_json = null;
    $json_error = null;
    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $resp_json = $decoded;
    } else {
        $json_error = json_last_error_msg();
    }

    $result = [
        'status_code' => $status,
        'elapsed_ms' => $elapsed_ms,
        'json' => $resp_json,
        'json_error' => $json_error,
        'text' => (function_exists('mb_substr') ? mb_substr((string) $text, 0, 2000) : substr((string) $text, 0, 2000)),
    ];

    if ($status >= 400) {
        throw new RuntimeException('HTTP ' . $status . ': ' . $result['text']);
    }

    return $result;
}

function resolve_tables(array $cfg): array
{
    $tables = $cfg['tables'] ?? [];
    if (!is_array($tables)) {
        return [];
    }
    $resolved = [];
    foreach ($tables as $t) {
        if (!is_array($t) || !isset($t['source'])) {
            continue;
        }
        $resolved[] = [
            'source' => (string) $t['source'],
            'target' => (string) (($t['target'] ?? null) ?: $t['source']),
            'primary_key' => (string) (($t['primary_key'] ?? null) ?: 'id'),
        ];
    }
    return $resolved;
}

function sync_once(array $cfg): void
{
    $state_path = resolve_path((string) ($cfg['state_path'] ?? '.\sync_state.json'));
    $max_rows_per_request = (int) ($cfg['max_rows_per_request'] ?? 500);
    $branch_id = (int) ($cfg['branch_id'] ?? 0);
    $branch_session = (string) ($cfg['branch_session'] ?? '');
    $api_url = (string) ($cfg['api_url'] ?? '');
    $api_key = (string) ($cfg['api_key'] ?? '');
    $db_path = (string) ($cfg['db_path'] ?? '');
    $db_password = (string) (($cfg['db_password'] ?? null) ?: 'eLock0103');

    $state = load_state($state_path);
    if (!isset($state['tables']) || !is_array($state['tables'])) {
        $state['tables'] = [];
    }
    $tables_state = &$state['tables'];

    $api_responses = $state['api_responses'] ?? [];
    if (!is_array($api_responses)) {
        $api_responses = [];
    }
    $state['api_responses'] = $api_responses;

    $conn = connect_access($db_path, $db_password);
    try {
        foreach (resolve_tables($cfg) as $table_cfg) {
            $source = $table_cfg['source'];
            $target = $table_cfg['target'];
            $primary_key = $table_cfg['primary_key'];

            echo now_iso() . ' table ' . $source . ' -> ' . $target . " fetch...\n";
            $rows = fetch_table_rows($conn, $source);

            if (!isset($tables_state[$target]) || !is_array($tables_state[$target])) {
                $tables_state[$target] = [];
            }
            $known_hashes = &$tables_state[$target];

            $changed_rows = [];
            foreach ($rows as $row) {
                if (!array_key_exists($primary_key, $row)) {
                    continue;
                }
                $pk_val = $row[$primary_key];
                if ($pk_val === null || $pk_val === '') {
                    continue;
                }
                $pk_str = (string) $pk_val;
                $row_hash = stable_row_hash($row);
                if (!isset($known_hashes[$pk_str]) || $known_hashes[$pk_str] !== $row_hash) {
                    $changed_rows[] = $row;
                    $known_hashes[$pk_str] = $row_hash;
                }
            }

            if (count($changed_rows) === 0) {
                echo now_iso() . ' table ' . $target . ' no changes (' . count($rows) . " rows scanned)\n";
                continue;
            }

            echo now_iso() . ' table ' . $target . ' changes=' . count($changed_rows) . '/' . count($rows) . " posting...\n";

            $total_chunks = (int) ceil(count($changed_rows) / max(1, $max_rows_per_request));
            $chunk_idx = 0;
            foreach (chunked($changed_rows, max(1, $max_rows_per_request)) as $chunk) {
                $chunk_idx++;
                echo now_iso() . ' POST ' . $target . ' chunk ' . $chunk_idx . '/' . $total_chunks . ' rows=' . count($chunk) . " ...\n";
                $resp_info = [];
                $err = null;
                try {
                    $resp_info = post_rows(
                        $api_url,
                        $api_key,
                        $branch_id,
                        $branch_session,
                        $target,
                        $primary_key,
                        $chunk,
                    );
                } catch (Throwable $e) {
                    $err = $e->getMessage();
                    throw $e;
                } finally {
                    $entry = [
                        'at' => now_iso(),
                        'table' => $target,
                        'primary_key' => $primary_key,
                        'rows_sent' => count($chunk),
                        'error' => $err,
                        'response' => $resp_info,
                    ];
                    $api_responses = $state['api_responses'];
                    $api_responses[] = $entry;
                    if (count($api_responses) > 100) {
                        $api_responses = array_slice($api_responses, -100);
                    }
                    $state['api_responses'] = $api_responses;
                    save_state($state_path, $state);
                }

                $processed = null;
                if (isset($resp_info['json']) && is_array($resp_info['json'])) {
                    $processed = $resp_info['json']['processed'] ?? null;
                }
                echo now_iso() . ' OK ' . $target . ' chunk ' . $chunk_idx . '/' . $total_chunks . ' status=' . ($resp_info['status_code'] ?? null) . ' processed=' . (is_scalar($processed) ? (string) $processed : '') . ' ms=' . ($resp_info['elapsed_ms'] ?? null) . "\n";
            }

            echo now_iso() . ' synced ' . count($changed_rows) . ' rows -> ' . $target . "\n";
        }
    } finally {
        @odbc_close($conn);
    }

    save_state($state_path, $state);
}

function test_send(array $cfg): void
{
    $payload_rows = [
        ['emp_id' => 'TEST-CLIENT-001', 'emp_name' => 'CLIENT TEST'],
    ];
    echo now_iso() . ' test_send start url=' . (string) ($cfg['api_url'] ?? '') . "\n";
    try {
        $info = post_rows(
            (string) ($cfg['api_url'] ?? ''),
            (string) ($cfg['api_key'] ?? ''),
            (int) ($cfg['branch_id'] ?? 0),
            (string) ($cfg['branch_session'] ?? ''),
            '_employees',
            'emp_id',
            $payload_rows,
        );
        $processed = null;
        if (isset($info['json']) && is_array($info['json'])) {
            $processed = $info['json']['processed'] ?? null;
        }
        echo now_iso() . ' status=' . ($info['status_code'] ?? null) . ' processed=' . (is_scalar($processed) ? (string) $processed : '') . ' ms=' . ($info['elapsed_ms'] ?? null) . "\n";
    } catch (Throwable $e) {
        echo now_iso() . ' error: ' . $e->getMessage() . "\n";
    }
}

$cfg = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (!is_array($cfg)) {
    fwrite(STDERR, "config.php harus return array.\n");
    exit(2);
}

$opts = getopt('', ['test', 'loop']);

if (isset($opts['test'])) {
    test_send($cfg);
    exit(0);
}

if (isset($opts['loop'])) {
    $interval_seconds = (int) ($cfg['interval_seconds'] ?? 300);
    while (true) {
        $started = microtime(true);
        try {
            sync_once($cfg);
        } catch (Throwable $e) {
            echo now_iso() . ' error: ' . $e->getMessage() . "\n";
        }
        $elapsed = microtime(true) - $started;
        $sleep_for = max(1, $interval_seconds - (int) floor($elapsed));
        echo now_iso() . ' sleep ' . $sleep_for . "s\n";
        sleep($sleep_for);
    }
}

try {
    sync_once($cfg);
} catch (Throwable $e) {
    echo now_iso() . ' error: ' . $e->getMessage() . "\n";
    exit(1);
}
