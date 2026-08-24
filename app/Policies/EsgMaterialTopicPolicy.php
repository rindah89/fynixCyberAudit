<?php

namespace App\Policies;

use App\Models\EsgMaterialTopic;
use App\Models\User;

class EsgMaterialTopicPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('Read ESG') || $u->can('Manage ESG') || $u->can('Assess ESG') || $u->can('Validate ESG Data') || $u->can('Approve ESG Disclosures') || $u->can('Own ESG Topics');
    }

    public function view(User $u, EsgMaterialTopic $t): bool
    {
        return $u->can('Read ESG') || $u->can('Manage ESG') || $u->can('Assess ESG') || $u->can('Validate ESG Data') || $u->can('Approve ESG Disclosures') || ($u->can('Own ESG Topics') && ($t->owner_id === $u->id || $t->goals()->where('owner_id', $u->id)->exists() || $t->goals()->whereHas('kpis', fn ($kpi) => $kpi->where('owner_id', $u->id))->exists()));
    }

    public function create(User $u): bool
    {
        return $u->can('Manage ESG');
    }

    public function update(User $u, EsgMaterialTopic $t): bool
    {
        return $u->can('Manage ESG') || ($u->can('Own ESG Topics') && $t->owner_id === $u->id);
    }

    public function assess(User $u, EsgMaterialTopic $t): bool
    {
        return $u->can('Assess ESG') || $u->can('Manage ESG');
    }
}
