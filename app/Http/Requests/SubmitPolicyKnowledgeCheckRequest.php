<?php

namespace App\Http\Requests;

use App\Models\PolicyAcknowledgementAssignment;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPolicyKnowledgeCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof PolicyAcknowledgementAssignment && $assignment->user_id === $this->user()?->id;
    }

    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:20'],
            'answers.*' => ['required', 'integer', 'min:0', 'max:5'],
            'version' => ['prohibited'], 'submitted_by' => ['prohibited'], 'submitted_at' => ['prohibited'],
            'score_percentage' => ['prohibited'], 'passed' => ['prohibited'], 'question_snapshot' => ['prohibited'],
            'fingerprint' => ['prohibited'],
        ];
    }
}
