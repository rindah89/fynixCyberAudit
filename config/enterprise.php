<?php

return [
    'modules' => [
        'ai_audit' => (bool) env('MODULE_AI_AUDIT_ENABLED', false),
        'surveyor' => (bool) env('MODULE_SURVEYOR_ENABLED', false),
        'risk_assessor' => (bool) env('MODULE_RISK_ASSESSOR_ENABLED', false),
        'remediation' => (bool) env('MODULE_REMEDIATION_ENABLED', false),
        'incidents' => (bool) env('MODULE_INCIDENTS_ENABLED', false),
    ],
    'surveyor' => [
        'max_file_size' => (int) env('SURVEYOR_MAX_FILE_SIZE', 10 * 1024 * 1024),
        'max_batch_questions' => (int) env('SURVEYOR_MAX_BATCH_QUESTIONS', 100),
        'timeout_per_question' => (int) env('SURVEYOR_TIMEOUT_PER_QUESTION', 120),
    ],
];
