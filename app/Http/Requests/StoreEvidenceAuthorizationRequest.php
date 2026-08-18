<?php

namespace App\Http\Requests;

use App\ChangeEvidence\EvidenceAuthorizationAuditor;
use App\ChangeEvidence\EvidencePolicyRegistry as Registry;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class StoreEvidenceAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $profile = (string) $this->input('profile', '');
        $fields = Registry::resolve($profile) === null ? ['profile'] : Registry::requestFields($profile);

        return collect($fields)->mapWithKeys(fn (string $field): array => [$field => ['present']])->all();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $body = $this->all();
            $policy = Registry::resolve((string) ($body['profile'] ?? ''));
            if ($policy === null) {
                $validator->errors()->add('profile', 'Unknown evidence profile.');

                return;
            }
            $fields = Registry::requestFields($body['profile']);
            if (count($body) !== count($fields) || array_diff(array_keys($body), $fields)) {
                $validator->errors()->add('request', 'Closed request schema required.');

                return;
            }
            $valid = $body['contract_version'] === Registry::CONTRACT
                && $body['producer'] === $policy['producer'] && $body['target'] === $policy['target']
                && $body['environment'] === 'production' && $body['operation'] === 'deploy-release'
                && $body['purpose'] === 'deploy' && $body['policy_version'] === 'fynix-production-deploy/v2'
                && is_int($body['company_id']) && $body['company_id'] > 0
                && is_int($body['itsm_change_id']) && $body['itsm_change_id'] > 0
                && is_int($body['itsm_authorization_id']) && $body['itsm_authorization_id'] > 0
                && is_int($body['itsm_approval_revision']) && $body['itsm_approval_revision'] >= 0;
            foreach (['suite_tenant_id', 'customer_id', 'request_id', 'operation_id'] as $field) {
                $valid = $valid && is_string($body[$field]) && Str::isUuid($body[$field]);
            }
            foreach (['release_sha', 'previous_release_sha'] as $field) {
                $valid = $valid && is_string($body[$field]) && preg_match('/^[a-f0-9]{40}$/', $body[$field]);
            }
            foreach (['image_digest', 'previous_image_digest'] as $field) {
                $valid = $valid && is_string($body[$field]) && preg_match('/^sha256:[a-f0-9]{64}$/', $body[$field]);
            }
            $digests = ['artifact_sha256', 'manifest_sha256', 'previous_artifact_sha256', 'itsm_binding_digest', 'readiness_evidence_sha256', 'request_digest'];
            $digests = $body['profile'] === 'fynix-cyberaudit/deploy-release' ? [...$digests, 'soak_receipt_sha256', 'soak_evidence_sha256'] : [...$digests, 'tests_sha256', 'build_sha256'];
            foreach ($digests as $field) {
                $valid = $valid && is_string($body[$field]) && preg_match('/^[a-f0-9]{64}$/', $body[$field]);
            }
            $valid = $valid && $body['rollback_ref'] === 'fynix-release:'.$body['previous_release_sha'].'@'.$body['previous_image_digest'].'#sha256:'.$body['previous_artifact_sha256'];
            $valid = $valid && ($body['profile'] !== 'fynix-cyberaudit/deploy-release' || $body['rollback_compatible'] === true);
            $unsigned = $body;
            unset($unsigned['request_digest']);
            ksort($unsigned);
            $valid = $valid && hash_equals(hash('sha256', json_encode($unsigned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $body['request_digest']);
            if (! $valid) {
                $validator->errors()->add('request', 'Evidence request is invalid.');
            }
        }];
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        app(EvidenceAuthorizationAuditor::class)->denied(
            Registry::resolve((string) $this->input('profile')) === null ? 'profile_denied' : 'request_denied',
            $this->all()
        );

        throw new HttpResponseException(response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422));
    }
}
