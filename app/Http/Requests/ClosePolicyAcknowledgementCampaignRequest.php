<?php

namespace App\Http\Requests;

use App\Models\PolicyAcknowledgementCampaign;
use Illuminate\Foundation\Http\FormRequest;

class ClosePolicyAcknowledgementCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('campaign');

        return $campaign instanceof PolicyAcknowledgementCampaign
            && ($this->user()?->can('Update Policies') || $campaign->policy()->where('owner_id', $this->user()?->id)->exists());
    }

    public function rules(): array
    {
        return [];
    }
}
