<?php

$rawBindings = env('SUITE_GOVERNANCE_BINDINGS_JSON', '{}');
$decodedBindings = json_decode(is_string($rawBindings) ? $rawBindings : '{}', true);
$sourcePrefixes = [
    'hq' => 'HQ', 'ppm' => 'PPM', 'hr' => 'HR', 'finance' => 'FINANCE',
    'itsm' => 'ITSM', 'docflow' => 'DOCFLOW', 'devops' => 'DEVOPS',
    'office' => 'OFFICE', 'cyberaudit' => 'CYBERAUDIT',
];
$environmentBindings = [];
foreach ($sourcePrefixes as $source => $prefix) {
    $tenantId = env($prefix.'_GOVERNANCE_TENANT_ID');
    $webhookId = env($prefix.'_GOVERNANCE_WEBHOOK_ID');
    $secret = env($prefix.'_GOVERNANCE_SECRET');
    if ($tenantId || $webhookId || $secret) {
        $environmentBindings[$source] = [
            'enabled' => true,
            'tenant_id' => $tenantId,
            'webhook_id' => $webhookId,
            'secret' => $secret,
            'replay_tolerance' => 300,
        ];
    }
}

return [
    'schema_version' => 'fynix-governance-evidence/v1',
    'freshness_hours' => (int) env('SUITE_GOVERNANCE_FRESHNESS_HOURS', 26),
    'required_sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUITE_GOVERNANCE_REQUIRED_SOURCES', 'hq,ppm,hr,finance,itsm,docflow,devops,office,cyberaudit'))
    ))),
    'bindings' => is_array($decodedBindings) && $decodedBindings !== [] ? $decodedBindings : $environmentBindings,
    'publisher' => [
        'endpoint' => env('CYBERAUDIT_GOVERNANCE_ENDPOINT'),
        'tenant_id' => env('CYBERAUDIT_GOVERNANCE_TENANT_ID'),
        'webhook_id' => env('CYBERAUDIT_GOVERNANCE_WEBHOOK_ID'),
        'secret' => env('CYBERAUDIT_GOVERNANCE_SECRET'),
    ],
    'controls' => [
        'DG-01' => ['name' => 'Data inventory and accountable ownership', 'standards' => ['ISO/IEC 38505-1', 'NIST PF ID.IM-P']],
        'DG-02' => ['name' => 'Classification and handling', 'standards' => ['ISO/IEC 27001:2022', 'NIST CSF ID.AM']],
        'DG-03' => ['name' => 'Purpose, lawful basis, and data-subject rights', 'standards' => ['ISO/IEC 27701:2025', 'Cameroon Law 2024/017']],
        'DG-04' => ['name' => 'Least privilege and periodic access review', 'standards' => ['ISO/IEC 27001:2022', 'NIST CSF PR.AA']],
        'DG-05' => ['name' => 'Auditable reads, denials, changes, and exports', 'standards' => ['ISO/IEC 27001:2022', 'NIST CSF DE.CM']],
        'DG-06' => ['name' => 'Retention, legal hold, and defensible disposition', 'standards' => ['ISO 15489-1:2016', 'ISO/IEC 27701:2025']],
        'DG-07' => ['name' => 'Data quality, lineage, and reconciliation', 'standards' => ['ISO/IEC 38505-1', 'NIST PF CT.DP-P']],
        'DG-08' => ['name' => 'Encryption and secrets protection', 'standards' => ['ISO/IEC 27001:2022', 'NIST CSF PR.DS']],
        'DG-09' => ['name' => 'Backup, restoration, and continuity testing', 'standards' => ['ISO 22301:2019', 'NIST CSF RC.RP']],
        'DG-10' => ['name' => 'Security and privacy incident response', 'standards' => ['ISO/IEC 27001:2022', 'NIST CSF RS.MA']],
        'DG-11' => ['name' => 'Supplier, processor, and transfer governance', 'standards' => ['ISO/IEC 27701:2025', 'NIST CSF GV.SC']],
        'DG-12' => ['name' => 'Secure development and vulnerability management', 'standards' => ['NIST SP 800-218', 'OWASP ASVS']],
    ],
];
