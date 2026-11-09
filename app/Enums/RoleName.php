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
     * which stays with the Kepala Sekolah and Tata Usaha (D-01, D-150, CLAUDE.md §8). Only siswa (usulan) and Admin
     * add a lomba (D-145); Kesiswaan checks, corrects and decides it. `classroom.manage` (the Kelas page) and
     * `student.status` (Ubah status of a student) are Admin, Kesiswaan and Tata Usaha since 2026-11-05; the account-wide
     * `user.*` permissions (Pengguna & peran, Arsip otomatis, staff accounts) stay Admin only.
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
                Permission::RegistrationMentor, // SV8, D-198: "Isi / Ganti guru pembimbing" until the report is sent
                Permission::RegistrationReviseDecide, // SV8, D-199: decide a revision after the approval (with Tata Usaha)
                Permission::LetterView,
                Permission::ReportVerify,
                Permission::AchievementViewAll,
                Permission::AchievementExport,
                Permission::AppreciationManage,
                Permission::TalentManage,
                Permission::TalentAssess,
                Permission::StudentImport,
                Permission::StudentView,
                Permission::StudentUpdate, // SV6, D-187: "Ubah data siswa"
                Permission::StudentTrash,  // SV6, D-185: Hapus siswa into the Sampah
                // 2026-11-05 (the lead: Kelas "sama persis Admin"): the Kelas page with every action of Admin › Kelas.
                Permission::ClassroomManage,
                Permission::StudentStatus,
                // SV8, D-197: Data guru (Nama, Pangkat/Gol, NIP, Jabatan of guru accounts) and "Tambah guru" (role guru only).
                Permission::GuruUpdate,
                Permission::GuruCreate,
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
                Permission::RegistrationApprove, // D-150: Kepala Sekolah or Tata Usaha approves
                Permission::RegistrationViewAll,
                Permission::RegistrationReviseDecide, // SV8, D-199: decide a revision after the approval (with Kesiswaan)
                Permission::LetterGenerate,
                Permission::LetterSend,
                Permission::LetterView,
                Permission::StudentView,
                Permission::StudentUpdate, // SV6, D-187: "Ubah data siswa"
                // 2026-11-05 (the lead: Kelas "sama persis Admin"): TU › Kelas replaces TU › Data siswa, with every action
                // of Admin › Kelas.
                Permission::ClassroomManage,
                Permission::StudentStatus,
                Permission::StudentTrash,
            ],
            self::Humas => [
                Permission::AchievementViewAll,
                Permission::PublicationManage,
                Permission::AppreciationManage,
                Permission::ReportVerify,
            ],
            // SV8, D-199: a revision after the approval is decided by "TU atau kesiswaan" only (the lead), so Admin
            // does not get `registration.revise_decide` either.
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p) => ! in_array($p, [Permission::RegistrationApprove, Permission::RegistrationReviseDecide], true),
            )),
        };
    }

    /** @return list<self> */
    public static function staff(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->isStaff()));
    }
}
