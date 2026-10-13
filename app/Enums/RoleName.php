<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** The seven spatie roles (D-01, ARCHITECTURE §2), guard `web`. */
enum RoleName: string
{
    use HasOptions;

    case Siswa = 'siswa';
    case Guru = 'guru';
    case Kesiswaan = 'kesiswaan';
    case KepalaSekolah = 'kepala_sekolah';
    case TataUsaha = 'tata_usaha';
    case Humas = 'humas';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Siswa => 'Siswa',
            self::Guru => 'Guru pembimbing',
            self::Kesiswaan => 'Kesiswaan',
            self::KepalaSekolah => 'Kepala Sekolah',
            self::TataUsaha => 'Tata Usaha',
            self::Humas => 'Humas',
            self::Admin => 'Admin',
        };
    }

    public function isStaff(): bool
    {
        return $this !== self::Siswa;
    }

    /**
     * Seeded permission set (RoleSeeder). Admin gets everything except approving registrations,
     * which stays with the Kepala Sekolah (D-01, CLAUDE.md §8). Only siswa (usulan) and Admin add a lomba
     * (D-145); Kesiswaan checks, corrects and decides it.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Siswa => [
                Permission::CompetitionCreate,
                Permission::RegistrationCreate,
                Permission::ReportSubmit,
                Permission::TalentApply,
            ],
            self::Guru => [
                Permission::TalentAssess,
            ],
            self::Kesiswaan => [
                Permission::CompetitionVerify,
                Permission::CompetitionUpdate,
                Permission::CompetitionClose,
                Permission::CompetitionDelete,
                Permission::RegistrationViewAll,
                Permission::LetterView,
                Permission::ReportVerify,
                Permission::AchievementViewAll,
                Permission::AchievementExport,
                Permission::AppreciationManage,
                Permission::TalentManage,
                Permission::TalentAssess,
                Permission::StudentImport,
                Permission::StudentView,
                Permission::DashboardView,
            ],
            self::KepalaSekolah => [
                Permission::RegistrationApprove,
                Permission::RegistrationViewAll,
                Permission::LetterView,
                Permission::AchievementViewAll,
                Permission::AchievementExport, // B5: kepsek.laporan export (UI-MAP §4b; watermarked + audited)
                Permission::StudentView,
                Permission::DashboardView,
            ],
            self::TataUsaha => [
                Permission::RegistrationViewAll,
                Permission::LetterGenerate,
                Permission::LetterSend,
                Permission::LetterView,
                Permission::StudentView,
            ],
            self::Humas => [
                Permission::AchievementViewAll,
                Permission::PublicationManage,
                Permission::AppreciationManage,
                Permission::ReportVerify,
            ],
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p) => $p !== Permission::RegistrationApprove,
            )),
        };
    }

    /** @return list<self> */
    public static function staff(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->isStaff()));
    }
}
