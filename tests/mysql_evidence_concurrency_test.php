<?php

declare(strict_types=1);

if (PDO::getAvailableDrivers() === [] || ! extension_loaded('pcntl')) {
    fwrite(STDERR, "mysql evidence concurrency: PDO/pcntl unavailable\n");
    exit(77);
}

$host = getenv('DB_HOST') ?: '';
$database = getenv('DB_DATABASE') ?: '';
$user = getenv('DB_USERNAME') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
if ($host === '' || $database === '' || $user === '') {
    fwrite(STDERR, "mysql evidence concurrency: DB environment unavailable\n");
    exit(77);
}

$suffix = bin2hex(random_bytes(6));
$authorization = "evidence_mysql_auth_$suffix";
$claim = "evidence_mysql_claim_$suffix";
$receipt = "evidence_mysql_receipt_$suffix";

$connect = static function () use ($host, $database, $user, $password): PDO {
    return new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
};
$db = $connect();

try {
    $db->exec("CREATE TABLE `$authorization` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, profile VARCHAR(96) NOT NULL, request_id CHAR(36) NOT NULL, operation_id CHAR(36) NOT NULL, request_digest CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'accepted', consumed_at DATETIME NULL, UNIQUE KEY request_unique(company_id,profile,request_id), UNIQUE KEY operation_unique(company_id,profile,operation_id)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE `$claim` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, authorization_id BIGINT UNSIGNED NOT NULL, nonce CHAR(36) NOT NULL, token_digest CHAR(64) NOT NULL, consumed_at DATETIME NULL, revoked_at DATETIME NULL, UNIQUE KEY authorization_unique(authorization_id), UNIQUE KEY nonce_unique(nonce), UNIQUE KEY token_unique(token_digest), CONSTRAINT `{$claim}_fk` FOREIGN KEY(authorization_id) REFERENCES `$authorization`(id) ON DELETE RESTRICT) ENGINE=InnoDB");
    $db->exec("CREATE TABLE `$receipt` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, authorization_id BIGINT UNSIGNED NOT NULL, operation_id CHAR(36) NOT NULL, receipt_digest CHAR(64) NOT NULL, UNIQUE KEY authorization_unique(authorization_id), UNIQUE KEY operation_unique(operation_id), CONSTRAINT `{$receipt}_fk` FOREIGN KEY(authorization_id) REFERENCES `$authorization`(id) ON DELETE RESTRICT) ENGINE=InnoDB");

    $requestId = '10000000-0000-4000-8000-000000000001';
    $operationId = '20000000-0000-4000-8000-000000000001';
    $digest = str_repeat('a', 64);
    $children = [];
    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $child = $connect();
            try {
                $q = $child->prepare("INSERT INTO `$authorization`(company_id,profile,request_id,operation_id,request_digest) VALUES(1,'fynix-cyberaudit/deploy-release',?,?,?)");
                $q->execute([$requestId, $operationId, $digest]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) exit(2);
            }
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) throw new RuntimeException('concurrent create failed');
    }
    $db = $connect();
    if ((int) $db->query("SELECT COUNT(*) FROM `$authorization`")->fetchColumn() !== 1) throw new RuntimeException('create uniqueness failed');
    $id = (int) $db->query("SELECT id FROM `$authorization`")->fetchColumn();

    $children = [];
    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $child = $connect();
            try {
                $q = $child->prepare("INSERT INTO `$claim`(authorization_id,nonce,token_digest) VALUES(?,?,?)");
                $q->execute([$id, sprintf('30000000-0000-4000-8000-%012d', $i), hash('sha256', "token-$i")]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) exit(3);
            }
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid, $status); if (pcntl_wexitstatus($status) !== 0) throw new RuntimeException('concurrent claim failed'); }
    $db = $connect();
    if ((int) $db->query("SELECT COUNT(*) FROM `$claim`")->fetchColumn() !== 1) throw new RuntimeException('claim uniqueness failed');

    $tokenDigest = (string) $db->query("SELECT token_digest FROM `$claim`")->fetchColumn();
    $children = [];
    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $child = $connect();
            try {
                $child->beginTransaction();
                $q = $child->prepare("SELECT token_digest,consumed_at,revoked_at FROM `$claim` WHERE authorization_id=? FOR UPDATE");
                $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
                if (!$row || ! hash_equals($row['token_digest'], $tokenDigest) || $row['revoked_at'] !== null) exit(4);
                if ($row['consumed_at'] === null) {
                    $child->prepare("UPDATE `$claim` SET consumed_at=UTC_TIMESTAMP() WHERE authorization_id=? AND consumed_at IS NULL")->execute([$id]);
                    $child->prepare("INSERT INTO `$receipt`(authorization_id,operation_id,receipt_digest) VALUES(?,?,?)")->execute([$id, $operationId, hash('sha256', $digest)]);
                } else {
                    $q = $child->prepare("SELECT receipt_digest FROM `$receipt` WHERE authorization_id=? AND operation_id=?"); $q->execute([$id, $operationId]);
                    if (! hash_equals((string) $q->fetchColumn(), hash('sha256', $digest))) exit(5);
                }
                $child->commit();
            } catch (PDOException $e) {
                if ($child->inTransaction()) $child->rollBack();
                if (! in_array((string) $e->getCode(), ['40001'], true) && ! in_array($e->errorInfo[1] ?? null, [1205, 1213], true)) exit(6);
            }
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid, $status); if (pcntl_wexitstatus($status) !== 0) throw new RuntimeException('concurrent consume failed'); }
    $db = $connect();
    if ((int) $db->query("SELECT COUNT(*) FROM `$receipt`")->fetchColumn() !== 1) throw new RuntimeException('consume/replay uniqueness failed');

    echo "mysql evidence concurrency: passed\n";
} finally {
    try { $db = $connect(); } catch (Throwable) {}
    $db->exec("DROP TABLE IF EXISTS `$receipt`");
    $db->exec("DROP TABLE IF EXISTS `$claim`");
    $db->exec("DROP TABLE IF EXISTS `$authorization`");
}
