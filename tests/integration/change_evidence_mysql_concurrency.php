<?php

declare(strict_types=1);

if (! extension_loaded('pcntl') || ! extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pcntl and pdo_mysql are required\n");
    exit(2);
}

$dsn = getenv('CYBERAUDIT_MYSQL_TEST_DSN') ?: '';
$user = getenv('CYBERAUDIT_MYSQL_TEST_USER') ?: '';
$password = getenv('CYBERAUDIT_MYSQL_TEST_PASSWORD') ?: '';
if ($dsn === '' || $user === '') {
    fwrite(STDERR, "Dedicated MySQL test credentials are required\n");
    exit(2);
}

$pdo = static function () use ($dsn, $user, $password): PDO {
    return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
};
$db = $pdo();
$suffix = bin2hex(random_bytes(8));
$company = random_int(100000, 999999);
$uuid = static function (): string {
    $hex = bin2hex(random_bytes(16));

    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
};
$request = $uuid();
$operation = $uuid();
$tenant = '123e4567-e89b-42d3-a456-426614174000';
$customer = '223e4567-e89b-42d3-a456-426614174000';
$digest = hash('sha256', $suffix);
$json = json_encode(['purpose' => 'deploy', 'operation_id' => $operation], JSON_THROW_ON_ERROR);

$insert = static function () use ($pdo, $company, $request, $operation, $tenant, $customer, $digest, $json): int {
    $db = $pdo();
    try {
        $statement = $db->prepare("INSERT INTO support_change_evidence_acceptances (company_id,suite_tenant_id,customer_id,producer,request_id,purpose,operation_id,request_digest,request_json,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $statement->execute([$company, $tenant, $customer, 'fynix-support', $request, 'deploy', $operation, $digest, $json]);
        return 0;
    } catch (PDOException $error) {
        if (! in_array((string) $error->getCode(), ['23000', '23505'], true)) {
            fwrite(STDERR, $error->getMessage()."\n");
        }
        return in_array((string) $error->getCode(), ['23000', '23505'], true) ? 10 : 20;
    }
};

$children = [];
for ($i = 0; $i < 2; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        exit($insert());
    }
    $children[] = $pid;
}
$codes = [];
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $codes[] = pcntl_wexitstatus($status);
}
sort($codes);
if ($codes !== [0, 10]) {
    throw new RuntimeException('Concurrent idempotency did not produce exactly one insert and one normalized duplicate: '.json_encode($codes));
}

$db = $pdo();
$id = (int) $db->query("SELECT id FROM support_change_evidence_acceptances WHERE company_id={$company}")->fetchColumn();
$db->exec("UPDATE support_change_evidence_acceptances SET status='accepted', reviewed_at=UTC_TIMESTAMP(), expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id={$id}");
$receipt = hash('sha256', 'receipt:'.$suffix);
$sockets = [];
$children = [];
for ($i = 0; $i < 2; $i++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($pair[0]);
        $child = $pdo();
        $child->beginTransaction();
        $row = $child->query("SELECT consumed_at,receipt_digest FROM support_change_evidence_acceptances WHERE id={$id} FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        if ($row['consumed_at'] === null) {
            usleep(200000);
            $statement = $child->prepare('UPDATE support_change_evidence_acceptances SET consumed_at=UTC_TIMESTAMP(),receipt_digest=? WHERE id=? AND consumed_at IS NULL');
            $statement->execute([$receipt, $id]);
            $row['receipt_digest'] = $receipt;
        }
        $child->commit();
        fwrite($pair[1], (string) $row['receipt_digest']);
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    $children[] = $pid;
    $sockets[] = $pair[0];
}
$observed = [];
foreach ($children as $index => $pid) {
    $observed[] = stream_get_contents($sockets[$index]);
    fclose($sockets[$index]);
    pcntl_waitpid($pid, $status);
    if (pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('Concurrent consumer failed.');
    }
}
if ($observed !== [$receipt, $receipt]) {
    throw new RuntimeException('Concurrent consume did not return one exact durable receipt: '.json_encode($observed));
}
$db = $pdo();
$count = (int) $db->query("SELECT COUNT(*) FROM support_change_evidence_acceptances WHERE id={$id} AND consumed_at IS NOT NULL AND receipt_digest=".$db->quote($receipt))->fetchColumn();
if ($count !== 1) {
    throw new RuntimeException('Consumed row is not durable.');
}

$db->prepare('DELETE FROM support_change_evidence_acceptances WHERE id=?')->execute([$id]);
fwrite(STDOUT, "mysql concurrency: pass\n");
