<?php

namespace App\Http\Requests;

use App\Esg\EsgMaterialityManager;
use App\Models\EsgMaterialTopic;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreEsgTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('esg_management') && $this->user()?->can('create', EsgMaterialTopic::class) === true;
    }

    public function rules(): array
    {
        return EsgMaterialityManager::topicRules(true);
    }
}
