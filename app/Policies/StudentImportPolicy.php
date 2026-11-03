<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\StudentImport;
use App\Models\User;

/**
 * Queued student imports (backlog #19). Deny by default: only the person who started the import, while they still
 * hold `student.import` (Kesiswaan, Admin), may see its progress. Nobody edits or deletes them through the app.
 */
class StudentImportPolicy
{
    public function view(User $user, StudentImport $import): bool
    {
        return (int) $import->user_id === (int) $user->getKey() && $user->can(Permission::StudentImport->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::StudentImport->value);
    }
}
