<?php

namespace App\Http\Requests;

use App\Models\PolicyAcknowledgementCampaign;
use Illuminate\Foundation\Http\FormRequest;

class ListPolicyAcknowledgementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('campaign');
        if (! $campaign) {
            return $this->user() !== null;
        }

        return $campaign instanceof PolicyAcknowledgementCampaign
            && ($this->user()?->can('Update Policies') || $campaign->policy()->where('owner_id', $this->user()?->id)->exists());
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
