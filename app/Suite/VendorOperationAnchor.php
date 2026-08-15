<?php

namespace App\Suite;

use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use RuntimeException;

class VendorOperationAnchor
{
    private ?S3Client $client = null;

    public function setClient(S3Client $client): void
    {
        $this->client = $client;
    }

    /** @param array{last_hash: string, event_count: int, integrity_status: string, integrity_checked_at: ?string} $head */
    public function publish(array $head): string
    {
        if (! config('suite.support.anchor.enabled')) {
            throw new RuntimeException('Vendor operation ledger anchoring is disabled.');
        }
        if ($head['integrity_status'] !== 'ok') {
            throw new RuntimeException('Refusing to anchor an unverified vendor operation ledger.');
        }
        $bucket = (string) config('suite.support.anchor.bucket');
        $keySecret = (string) config('suite.support.anchor.key');
        $retentionDays = (int) config('suite.support.anchor.retention_days');
        if ($bucket === '' || strlen($keySecret) < 32 || $retentionDays < 365) {
            throw new RuntimeException('Vendor operation anchor configuration is incomplete or unsafe.');
        }

        $generatedAt = CarbonImmutable::now('UTC');
        $document = [
            'version' => 1,
            'ledger' => 'vendor_operation_events',
            'generated_at' => $generatedAt->format('Y-m-d\TH:i:s\Z'),
            ...$head,
        ];
        ksort($document);
        $canonical = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $document['signature'] = 'hmac-sha256:'.hash_hmac('sha256', $canonical, $keySecret);
        $body = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        $prefix = (string) config('suite.support.anchor.prefix', 'vendor-operation-ledger');
        $objectKey = sprintf(
            '%s/%s-%012d-%s.json',
            $prefix,
            $generatedAt->format('Y/m/d/His'),
            $head['event_count'],
            $head['last_hash'],
        );
        $kmsKey = (string) config('suite.support.anchor.kms_key_id');
        $arguments = [
            'Bucket' => $bucket,
            'Key' => $objectKey,
            'Body' => $body,
            'ContentType' => 'application/json',
            'ChecksumSHA256' => base64_encode(hash('sha256', $body, true)),
            'ObjectLockMode' => 'COMPLIANCE',
            'ObjectLockRetainUntilDate' => $generatedAt->addDays($retentionDays),
            'ServerSideEncryption' => $kmsKey === '' ? 'AES256' : 'aws:kms',
        ];
        if ($kmsKey !== '') {
            $arguments['SSEKMSKeyId'] = $kmsKey;
        }
        ($this->client ?? new S3Client([
            'version' => 'latest',
            'region' => (string) config('filesystems.disks.s3.region', 'us-east-1'),
        ]))->putObject($arguments);

        return $objectKey;
    }
}
