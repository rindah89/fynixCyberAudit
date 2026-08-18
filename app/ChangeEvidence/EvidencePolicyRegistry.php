<?php

namespace App\ChangeEvidence;

final class EvidencePolicyRegistry
{
    public const CONTRACT = 'fynix-cyberaudit-evidence-authorization-request/v3';

    /** @var array<string, array{producer:string,target:string}> */
    private const PROFILES = [
        'fynix-cyberaudit/deploy-release' => [
            'producer' => 'fynix-cyberaudit',
            'target' => 'fynix-cyberaudit',
        ],
        'fynix-executive-hq/deploy-release' => [
            'producer' => 'fynix-executive-hq',
            'target' => 'fynix-executive-hq',
        ],
    ];

    /** @var list<string> */
    public const REQUEST_FIELDS = [
        'contract_version', 'profile', 'company_id', 'suite_tenant_id', 'customer_id',
        'producer', 'request_id', 'target', 'environment', 'operation', 'purpose',
        'operation_id', 'policy_version', 'release_sha', 'image_digest',
        'artifact_digest', 'manifest_digest', 'previous_release_sha',
        'previous_image_digest', 'previous_artifact_digest', 'previous_manifest_digest',
        'rollback_ref', 'rollback_compatible', 'itsm_contract_version', 'itsm_profile',
        'itsm_request_id', 'itsm_change_id', 'itsm_authorization_id',
        'itsm_approval_revision', 'itsm_authority_binding_version', 'itsm_binding_digest',
        'soak_evidence_sha256', 'readiness_evidence_sha256',
        'security_evidence_sha256', 'regression_evidence_sha256', 'request_digest',
    ];

    public static function resolve(string $profile): ?array
    {
        return self::PROFILES[$profile] ?? null;
    }
}
