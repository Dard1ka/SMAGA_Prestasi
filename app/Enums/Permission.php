<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * spatie/laravel-permission names, `<area>.<action>` (ARCHITECTURE §2), guard `web`.
 * Role → permission matrix: RoleName::defaultPermissions(). Policies stay the source of truth;
 * "own record" checks (my registration, my mentee) belong in policies, not here.
 */
enum Permission: string
{
    // Competition catalog (D-02, D-04, D-27)
    case CompetitionCreate = 'competition.create';          // siswa (usulan) + Admin only (D-145); always lands in menunggu_verifikasi
    case CompetitionVerify = 'competition.verify';          // publish / reject queue items
    case CompetitionUpdate = 'competition.update';          // edit catalog incl. tingkat/kategori/status ajang
    case CompetitionClose = 'competition.close';
    case CompetitionDelete = 'competition.delete';

    // Registrations (D-03, D-05)
    case RegistrationCreate = 'registration.create';        // siswa registers individu/tim
    case RegistrationApprove = 'registration.approve';      // Kepala Sekolah or Tata Usaha (D-150; D-01: not Admin)
    case RegistrationViewAll = 'registration.view_all';

    // Surat tugas (D-06)
    case LetterGenerate = 'letter.generate';
    case LetterSend = 'letter.send';
    case LetterView = 'letter.view';

    // Laporan hasil & prestasi
    case ReportSubmit = 'report.submit';
    case ReportVerify = 'report.verify';
    case AchievementViewAll = 'achievement.view_all';
    case AchievementExport = 'achievement.export';

    // Humas
    case PublicationManage = 'publication.manage';
    case AppreciationManage = 'appreciation.manage';

    // Talent scouting (D-21)
    case TalentApply = 'talent.apply';
    case TalentManage = 'talent.manage';                    // move pipeline stages, assign pembina
    case TalentAssess = 'talent.assess';                    // guru pembina: notes/assessment on own binaan

    // Students & accounts
    case StudentImport = 'student.import';                  // Kesiswaan + Admin (D-26)
    case StudentView = 'student.view';
    case StudentUpdate = 'student.update';
    case UserManage = 'user.manage';
    case UserResetPassword = 'user.reset_password';
    case UserArchive = 'user.archive';                      // manual disable/archive (D-30)
    case UserReplacePrincipal = 'user.replace_principal';   // D-01
    case ClassroomManage = 'classroom.manage';              // kelola kelas: tambah, ganti nama, hapus (Admin only, Admin › Kelas)

    // Settings
    case WeightManage = 'weight.manage';
    case SettingManage = 'setting.manage';
    case SignatureManage = 'signature.manage';

    // Oversight
    case DashboardView = 'dashboard.view';
    case AuditView = 'audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
