<?php

namespace App\ChangeEvidence;

final class EvidencePolicyRegistry
{
    public const CONTRACT = 'fynix-cyberaudit-evidence-authorization-request/v3';

    /** @var array<string, array{producer:string,target:string}> */
    private const PROFILES = [
        'fynix-cyberaudit/deploy-release' => [
            'producer' => 'fynix-cyberaudit-release',
            'target' => 'fynix-cyberaudit',
            'evidence' => ['soak_receipt_sha256', 'soak_evidence_sha256', 'readiness_evidence_sha256', 'rollback_compatible'],
        ],
        'fynix-executive-hq/deploy-release' => [
            'producer' => 'fynix-executive-hq-release',
            'target' => 'fynix-executive-hq',
            'evidence' => ['tests_sha256', 'build_sha256', 'readiness_evidence_sha256'],
        ],
    ];

    /** @var list<string> */
    private const COMMON_FIELDS = [
        'contract_version', 'profile', 'company_id', 'suite_tenant_id', 'customer_id',
        'producer', 'request_id', 'target', 'environment', 'operation', 'purpose',
        'operation_id', 'policy_version', 'release_sha', 'image_digest',
        'artifact_sha256', 'manifest_sha256', 'previous_release_sha',
        'previous_image_digest', 'previous_artifact_sha256', 'rollback_ref',
        'itsm_change_id', 'itsm_authorization_id',
        'itsm_approval_revision', 'itsm_binding_digest',
    ];

    public static function resolve(string $profile): ?array
    {
        return self::PROFILES[$profile] ?? null;
    }

    public static function requestFields(string $profile): array
    {
        $policy = self::resolve($profile);

        return $policy ? [...self::COMMON_FIELDS, ...$policy['evidence'], 'request_digest'] : [];
    }
}
