<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Settings (Admin › Pengaturan). Deny by default; one permission per group:
 * setting.manage (batas & arsip, format surat, halaman publik), signature.manage (tanda tangan, penandatangan).
 */
final class SettingPolicy
{
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    /** Open the Pengaturan screen at all. */
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::SettingManage->value)
            || $actor->checkPermissionTo(Permission::WeightManage->value)
            || $actor->checkPermissionTo(Permission::SignatureManage->value);
    }

    public function update(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::SettingManage->value);
    }

    public function manageSignature(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::SignatureManage->value);
    }

    // --- B5: TU › Template surat (backlog #7) ---

    /** Read the letter template (TU, Kepala Sekolah, Kesiswaan via `letter.view`; Admin). */
    public function viewLetterTemplate(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::LetterView->value)
            || $actor->checkPermissionTo(Permission::SettingManage->value);
    }

    /** Change it (TU via `letter.generate`; Admin via `setting.manage`). */
    public function manageLetterTemplate(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::LetterGenerate->value)
            || $actor->checkPermissionTo(Permission::SettingManage->value);
    }

    // --- end B5 ---
}
