<?php

namespace App\Policies;

use App\Access\VendorAccess;
use App\Models\Survey;
use App\Models\VendorUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

class SurveyPolicy
{
    protected string $model = Survey::class;

    public function viewAny(Authenticatable $user): bool
    {
        // VendorUsers can view their own surveys (handled by resource query scope)
        if ($user instanceof VendorUser) {
            return true;
        }

        return $user->can('List '.Str::plural(class_basename($this->model)));
    }

    public function view(Authenticatable $user, Survey $survey): bool
    {
        // VendorUsers can view surveys assigned to their vendor OR where they are the respondent
        if ($user instanceof VendorUser) {
            return app(VendorAccess::class)->mayOpenSurvey($user, $survey);
        }

        return $user->can('Read '.Str::plural(class_basename($this->model)));
    }

    public function create(Authenticatable $user): bool
    {
        // VendorUsers cannot create surveys
        if ($user instanceof VendorUser) {
            return false;
        }

        return $user->can('Create '.Str::plural(class_basename($this->model)));
    }

    public function update(Authenticatable $user, Survey $survey): bool
    {
        // VendorUsers can update (respond to) surveys assigned to their vendor OR where they are the respondent
        if ($user instanceof VendorUser) {
            return app(VendorAccess::class)->mayOpenSurvey($user, $survey);
        }

        return $user->can('Update '.Str::plural(class_basename($this->model)));
    }

    public function delete(Authenticatable $user): bool
    {
        // VendorUsers cannot delete surveys
        if ($user instanceof VendorUser) {
            return false;
        }

        return $user->can('Delete '.Str::plural(class_basename($this->model)));
    }

    public function restore(Authenticatable $user): bool
    {
        // VendorUsers cannot restore surveys
        if ($user instanceof VendorUser) {
            return false;
        }

        return $user->can('Delete '.Str::plural(class_basename($this->model)));
    }

    public function forceDelete(Authenticatable $user): bool
    {
        // VendorUsers cannot force delete surveys
        if ($user instanceof VendorUser) {
            return false;
        }

        return $user->can('Delete '.Str::plural(class_basename($this->model)));
    }
}
