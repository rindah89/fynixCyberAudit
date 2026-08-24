<?php

namespace App\Http\Requests;

use App\Esg\EsgMaterialityManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviseEsgTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('esg_management') && $this->user()?->can('update', $this->route('topic')) === true;
    }

    public function rules(): array
    {
        return EsgMaterialityManager::topicRules(false);
    }
}
