<?php

namespace App\Policies;

use App\Access\FileAccess;
use App\Models\FileAttachment;
use App\Models\User;
use Illuminate\Support\Str;

class FileAttachmentPolicy
{
    protected string $model = FileAttachment::class;

    public function viewAny(User $user): bool
    {
        return $user->can('List '.Str::plural(class_basename($this->model)));
    }

    public function view(User $user, FileAttachment $attachment): bool
    {
        return $user->can('Read '.Str::plural(class_basename($this->model)))
            && app(FileAccess::class)->canDownloadFileAttachment($user, $attachment);
    }

    public function create(User $user): bool
    {
        return $user->can('Create '.Str::plural(class_basename($this->model)));
    }

    public function update(User $user, FileAttachment $attachment): bool
    {
        return $user->can('Update '.Str::plural(class_basename($this->model)))
            && app(FileAccess::class)->canDownloadFileAttachment($user, $attachment);
    }

    public function delete(User $user, FileAttachment $attachment): bool
    {
        return $user->can('Delete '.Str::plural(class_basename($this->model)))
            && app(FileAccess::class)->canDownloadFileAttachment($user, $attachment);
    }
}
