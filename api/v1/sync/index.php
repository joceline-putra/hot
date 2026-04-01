<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../../api/config.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$apiKeyHeader = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!is_string($apiKeyHeader) || $apiKeyHeader === '' || !hash_equals((string) $config['api_key'], $apiKeyHeader)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$allowedTables = [
    '_bill_info',
    '_bill_rooms',
    '_room_info',
    '_employees',
    '_room_type',
    '_make_card_record',
    '_card_state',
];

$table = $data['table'] ?? null;
$primaryKey = $data['primary_key'] ?? null;
$rows = $data['rows'] ?? null;
$branchId = $data['branch_id'] ?? null;
$branchSession = $data['branch_session'] ?? null;

if (!is_string($table) || !in_array($table, $allowedTables, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid table']);
    exit;
}
if (!is_string($primaryKey) || $primaryKey === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid primary_key']);
    exit;
}
if (!is_array($rows)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid rows']);
    exit;
}
if (!is_int($branchId) && !is_string($branchId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid branch_id']);
    exit;
}
if (!is_string($branchSession) || $branchSession === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid branch_session']);
    exit;
}

$branchId = (int) $branchId;

$db = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    (int) $db['port'],
    $db['dbname'],
    $db['charset']
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

try {
    $stmtCols = $pdo->prepare(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmtCols->execute([':t' => $table]);
    $tableColumns = [];
    foreach ($stmtCols->fetchAll() as $colRow) {
        $name = $colRow['COLUMN_NAME'] ?? null;
        if (is_string($name)) {
            $tableColumns[$name] = true;
        }
    }

    if (!isset($tableColumns['branch_id']) || !isset($tableColumns['source_id'])) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Table schema missing branch_id/source_id']);
        exit;
    }

    $processed = 0;
    $pdo->beginTransaction();

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!array_key_exists($primaryKey, $row)) {
            continue;
        }
        $sourceId = $row[$primaryKey];
        if ($sourceId === null || $sourceId === '') {
            continue;
        }

        $row['branch_id'] = $branchId;
        $row['session'] = $row['session'] ?? $branchSession;
        $row['source_id'] = (string) $sourceId;
        if ($primaryKey === 'id') {
            unset($row[$primaryKey]);
        }

        $cols = [];
        $params = [];
        foreach ($row as $k => $v) {
            if (!is_string($k) || !isset($tableColumns[$k])) {
                continue;
            }
            if ($k === 'id') {
                continue;
            }
            $cols[] = $k;
            $params[":" . $k] = $v;
        }

        if (!in_array('branch_id', $cols, true)) {
            $cols[] = 'branch_id';
            $params[':branch_id'] = $branchId;
        }
        if (!in_array('source_id', $cols, true)) {
            $cols[] = 'source_id';
            $params[':source_id'] = (string) $sourceId;
        }

        $insertColsSql = implode(', ', array_map(static fn ($c) => "`$c`", $cols));
        $insertValsSql = implode(', ', array_map(static fn ($c) => ":" . $c, $cols));

        $updateParts = [];
        foreach ($cols as $c) {
            if ($c === 'branch_id' || $c === 'source_id') {
                continue;
            }
            $updateParts[] = sprintf("`%s` = VALUES(`%s`)", $c, $c);
        }
        if (isset($tableColumns['sync_at'])) {
            $updateParts[] = '`sync_at` = CURRENT_TIMESTAMP';
        }
        $updateSql = implode(', ', $updateParts);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            $insertColsSql,
            $insertValsSql,
            $updateSql
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $processed++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Sync failed']);
    exit;
}

echo json_encode(['ok' => true, 'processed' => $processed]);
