<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounts\InitialPassword;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Letters\LetterNumber;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;
use App\Enums\AppreciationStatus;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\Level;
use App\Enums\MemberRole;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\PublicationStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Enums\UserStatus;
use App\Enums\WeightCategory;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\File;
use App\Models\Letter;
use App\Models\Publication;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentProfile;
use App\Models\TalentType;
use App\Models\User;
use App\Models\WeightVersion;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Demo\DemoFileRefresher;
use Database\Seeders\Support\DemoFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LOCAL ONLY demo data (APP_ENV=local). Everything is fictional and follows docs/design/DESIGN-SPEC-v2.md §5
 * and docs/design/mockup-v2-notes/* ("today" in the mockup = 30 Sep 2026).
 *
 * - Staff sign in with their username (kesiswaan, kepsek, tu, humas, operator, retno.w, hendra.k, maya.a,
 *   agus.s) and the dev-only password "password".
 * - Students sign in with NIS or NISN; their password is the initial password built by
 *   App\Domain\Accounts\InitialPassword from the seeded birth date (must_change_password = true).
 * - Leaderboard totals match the spec (Raka 312 … Nadia 104 = #14 of active students).
 */
final class DemoSeeder extends Seeder
{
    /** Dev-only password for the demo staff accounts. Never used outside APP_ENV=local. */
    public const STAFF_PASSWORD = 'password';

    /** key => [name, username, role, honorific, position, rank]. Public: the names are the whitelist of Support\Demo\DemoPeople. */
    public const STAFF = [
        'kesiswaan' => ['Dwi Rahayu', 'kesiswaan', RoleName::Kesiswaan, 'Bu', 'Wakil Kepala Sekolah Bidang Kesiswaan', null],
        'kepsek' => ['Drs. Bambang Hartono, M.Pd.', 'kepsek', RoleName::KepalaSekolah, 'Pak', 'Kepala Sekolah', 'Pembina Tk. I, IV/b'],
        'tu' => ['Sri Handayani', 'tu', RoleName::TataUsaha, 'Bu', 'Staf Tata Usaha', null],
        'humas' => ['Galih Prasetyo', 'humas', RoleName::Humas, 'Mas', 'Humas', null],
        'admin' => ['Operator STH', 'operator', RoleName::Admin, null, 'Operator STH', null],
        'retno' => ['Retno Wulandari, S.Pd.', 'retno.w', RoleName::Guru, 'Bu', 'Guru pembina Riset', null],
        'hendra' => ['Hendra Kusuma, S.Pd., M.Si.', 'hendra.k', RoleName::Guru, 'Pak', 'Guru pembina Sains', null],
        'maya' => ['Maya Anggraini, S.Sn.', 'maya.a', RoleName::Guru, 'Bu', 'Guru pembina Seni Budaya', null],
        'agus' => ['Agus Setiawan, S.Or.', 'agus.s', RoleName::Guru, 'Pak', 'Guru pembina Olahraga', null],
    ];

    /** key => [name, class, NIS, NISN, birth date, photo consent, alumni] — NIS pattern 22<yy><yy+1>NNN. Public: see STAFF. */
    public const STUDENTS = [
        'nadia' => ['Nadia Putri Anindya', 'XI-4', '225226201', '0098765432', '2009-03-02', true, false],
        'dimas' => ['Dimas Arya Nugroho', 'XI-4', '225226204', '0093417728', '2009-07-19', false, false],
        'keisha' => ['Keisha Larasati', 'XI-6', '225226166', '0095263014', '2009-11-08', false, false],
        'raka' => ['Raka Pradipta', 'XII-2', '224225108', '0087315529', '2008-05-14', true, false],
        'salsabila' => ['Salsabila Kirana', 'XI-5', '225226157', '0094128806', '2009-09-23', false, false],
        'bima' => ['Bima Satria Wicaksono', 'XII-7', '224225219', '0086650213', '2008-12-01', false, false],
        'aurelia' => ['Aurelia Maharani', 'X-3', '226227031', '0101482657', '2010-04-11', true, false],
        'fajar' => ['Fajar Ramadhan', 'XII-1', '224225012', '0085902347', '2008-10-05', false, false],
        'citra' => ['Citra Ayu Lestari', 'XI-2', '225226073', '0096604581', '2009-06-17', false, false],
        'yoga' => ['Yoga Pratama', 'XII-5', '224225147', '0088123906', '2008-02-27', false, false],
        'hana' => ['Hana Safira', 'X-8', '226227088', '0103519214', '2010-08-30', false, false],
        'rizky' => ['Rizky Maulana', 'XI-1', '225226118', '0092745530', '2009-01-12', false, false],
        'farhan' => ['Farhan Aditya', 'XI-3', '225226092', '0097381145', '2009-05-04', false, false],
        'laras' => ['Laras Wening Pangestu', 'XI-7', '225226181', '0091836627', '2009-10-21', false, false],
        'galuh' => ['Galuh Prameswari', 'XII-4', '224225066', '0084471935', '2008-07-09', false, false],
        'bayu' => ['Bayu Anggara', 'XII-6', '224225031', '0089254410', '2008-03-15', false, false],
        'galang' => ['Galang Putra Mahendra', 'X-TAHFIDZ', '226227004', '0102736486', '2010-12-03', false, false],
        // Alumni: angkatan 2022, kelas XII from July 2024, archived 1 January 2026.
        'ardiansyah' => ['Ardiansyah Nur Hakim', 'XII-3', '222223041', '0067219043', '2006-09-18', false, true],
        'tiara' => ['Tiara Anjani Lestari', 'XII-8', '222223187', '0065508172', '2006-11-25', false, true],
        'putri' => ['Putri Maharani Kusuma', 'XII-1', '222223112', '0066341809', '2006-04-02', false, true],
        'bagas' => ['Bagas Wirayudha', 'XII-5', '222223009', '0064792251', '2006-08-07', false, true],
    ];

    /** Organizer presets: [name, organizer type, weight category, event status]. */
    private const ORGANIZERS = [
        'puspresnas' => ['Puspresnas', 'pemerintah_pusat', 'A', 'puspresnas'],
        'pemkot' => ['Pemerintah Kota Semarang', 'pemerintah_daerah', 'B', 'pemerintah_non_puspresnas'],
        'pemprov' => ['Pemerintah Provinsi Jawa Tengah', 'pemerintah_daerah', 'B', 'pemerintah_non_puspresnas'],
        'disdikjateng' => ['Dinas Pendidikan dan Kebudayaan Provinsi Jawa Tengah', 'pemerintah_daerah', 'B', 'pemerintah_non_puspresnas'],
        'disdikkota' => ['Dinas Pendidikan Kota Semarang', 'pemerintah_daerah', 'B', 'pemerintah_non_puspresnas'],
        'dispora' => ['Dinas Kepemudaan dan Olahraga Kota Semarang', 'pemerintah_daerah', 'B', 'pemerintah_non_puspresnas'],
        'kemenbud' => ['Kementerian Kebudayaan', 'pemerintah_pusat', 'B', 'pemerintah_non_puspresnas'],
        'kemenperin' => ['Kementerian Perindustrian', 'pemerintah_pusat', 'B', 'pemerintah_non_puspresnas'],
        'brin' => ['Badan Riset dan Inovasi Nasional (BRIN)', 'pemerintah_pusat', 'B', 'pemerintah_non_puspresnas'],
        'badanbahasa' => ['Badan Pengembangan dan Pembinaan Bahasa', 'pemerintah_pusat', 'B', 'pemerintah_non_puspresnas'],
        'undip' => ['Universitas Diponegoro', 'ptn', 'C', 'terkurasi'],
        'unnes' => ['Universitas Negeri Semarang', 'ptn', 'C', 'terkurasi'],
        'cys' => ['Center for Young Scientists', 'yayasan_lembaga', 'C', 'non_pemerintah'],
        'ayrn' => ['Asian Young Researchers Network', 'yayasan_lembaga', 'C', 'non_pemerintah'],
        'hfi' => ['Himpunan Fisika Indonesia', 'organisasi_profesi', 'C', 'non_pemerintah'],
        'forki' => ['PB FORKI', 'federasi_olahraga', 'C', 'non_pemerintah'],
        'ipsi' => ['IPSI Jawa Tengah', 'federasi_olahraga', 'C', 'non_pemerintah'],
        'prsi' => ['PRSI Jawa Tengah', 'federasi_olahraga', 'C', 'non_pemerintah'],
        'iysa' => ['IYSA', 'yayasan_lembaga', 'D', 'non_pemerintah'],
        'eduversal' => ['Eduversal', 'perusahaan_swasta', 'D', 'non_pemerintah'],
        'swasta' => ['Penyelenggara swasta', 'perusahaan_swasta', 'D', 'non_pemerintah'],
        'sanggar' => ['Sanggar Seni Pelajar Semarang', 'lainnya', 'E', 'non_pemerintah'],
    ];

    /**
     * Past / closed competitions: key => [name, bidang, level, organizer, starts_on, final_on, location, mode, participation, max team].
     */
    private const PAST = [
        'fiksi26' => ['FIKSI 2026', 'riset', 'nasional', 'puspresnas', '2026-09-03', '2026-09-05', 'Jakarta'],
        'opsi25' => ['OPSI 2025', 'riset', 'nasional', 'puspresnas', '2025-08-05', '2025-08-08', 'Jakarta'],
        'opsi26' => ['OPSI 2026', 'riset', 'nasional', 'puspresnas', '2026-08-04', '2026-08-07', 'Jakarta'],
        'ayrc25' => ['Asian Young Researchers Contest 2025', 'riset', 'internasional', 'ayrn', '2025-11-22', '2025-11-22', 'Daring', 'daring'],
        'osnprov24' => ['OSN Provinsi Jawa Tengah 2024', 'sains', 'provinsi', 'puspresnas', '2024-09-17', '2024-09-18', 'Kota Semarang'],
        'krenova25' => ['KRENOVA Kota Semarang 2025', 'riset', 'kota', 'pemkot', '2025-02-22', '2025-02-22', 'Kota Semarang', 'luring', 'keduanya', 3],
        'fls3nnas25' => ['FLS3N Nasional 2025', 'seni_budaya', 'nasional', 'puspresnas', '2025-10-21', '2025-10-25', 'Jakarta'],
        'filmpendek26' => ['Festival Film Pendek Pelajar 2026', 'seni_budaya', 'nasional', 'kemenbud', '2026-03-14', '2026-03-14', 'Daring', 'daring'],
        'fls3nprov26' => ['FLS3N Provinsi Jawa Tengah 2026', 'seni_budaya', 'provinsi', 'puspresnas', '2026-09-09', '2026-09-10', 'Kota Semarang'],
        'ldbiprov25' => ['LDBI Provinsi Jawa Tengah 2025', 'seni_budaya', 'provinsi', 'puspresnas', '2025-09-26', '2025-09-27', 'Kota Semarang', 'luring', 'keduanya', 3],
        'ldbikota25' => ['LDBI Kota Semarang 2025', 'seni_budaya', 'kota', 'puspresnas', '2025-08-30', '2025-08-30', 'Kota Semarang', 'luring', 'keduanya', 3],
        'robotiknas25' => ['Kompetisi Robotik Nasional 2025', 'riset', 'nasional', 'kemenperin', '2025-10-18', '2025-10-18', 'Jakarta'],
        'o2snnas25' => ['O2SN Nasional 2025', 'olahraga', 'nasional', 'puspresnas', '2025-09-15', '2025-09-20', 'Jakarta'],
        'karate26' => ['Kejurnas Karate Pelajar 2026', 'olahraga', 'nasional', 'forki', '2026-05-14', '2026-05-16', 'Jakarta'],
        'wseec25' => ['WSEEC 2025', 'riset', 'internasional', 'iysa', '2025-12-06', '2025-12-06', 'Daring', 'daring'],
        'biologinas26' => ['Kompetisi Biologi Nasional 2026', 'sains', 'nasional', 'brin', '2026-09-19', '2026-09-19', 'Daring', 'daring'],
        'ospjateng26' => ['Olimpiade Sains Pelajar Jawa Tengah 2026', 'sains', 'provinsi', 'disdikjateng', '2026-08-22', '2026-08-22', 'Kota Semarang'],
        'biologijateng26' => ['Olimpiade Biologi Jawa Tengah 2026', 'sains', 'provinsi', 'undip', '2026-08-29', '2026-08-29', 'Kota Semarang'],
        'inovasi26' => ['Lomba Inovasi Pelajar Kota Semarang 2026', 'riset', 'kota', 'pemkot', '2026-08-15', '2026-08-15', 'Kota Semarang'],
        'osebi26' => ['OSEBI 2026', 'sains', 'nasional', 'eduversal', '2026-09-13', '2026-09-13', 'Daring', 'daring'],
        'o2snprov26' => ['O2SN Provinsi Jawa Tengah 2026', 'olahraga', 'provinsi', 'puspresnas', '2026-09-07', '2026-09-08', 'Kota Semarang'],
        'o2snkota25' => ['O2SN Kota Semarang 2025', 'olahraga', 'kota', 'puspresnas', '2025-08-19', '2025-08-19', 'Kota Semarang'],
        'silatjateng25' => ['Kejuaraan Pencak Silat Pelajar Jawa Tengah 2025', 'olahraga', 'provinsi', 'ipsi', '2025-10-31', '2025-11-01', 'Jawa Tengah'],
        'popda25' => ['Popda Kota Semarang 2025', 'olahraga', 'kota', 'dispora', '2025-10-11', '2025-10-11', 'Kota Semarang'],
        'o2snkota24' => ['O2SN Kota Semarang 2024', 'olahraga', 'kota', 'puspresnas', '2024-08-20', '2024-08-20', 'Kota Semarang'],
        'musikalisasi26' => ['Lomba Musikalisasi Puisi Nasional 2026', 'seni_budaya', 'nasional', 'badanbahasa', '2026-05-16', '2026-05-16', 'Daring', 'daring'],
        'fls3nprov25' => ['FLS3N Provinsi Jawa Tengah 2025', 'seni_budaya', 'provinsi', 'puspresnas', '2025-09-19', '2025-09-20', 'Kota Semarang'],
        'fls3nkota25' => ['FLS3N Kota Semarang 2025', 'seni_budaya', 'kota', 'puspresnas', '2025-08-23', '2025-08-23', 'Kota Semarang'],
        'lpb25' => ['LPB 2025', 'riset', 'nasional', 'cys', '2025-10-25', '2025-10-25', 'Daring', 'daring', 'tim', 3],
        'nsif25' => ['NSIF 2025', 'riset', 'nasional', 'swasta', '2025-11-08', '2025-11-08', 'Daring', 'daring', 'tim', 3],
        'o2snprov24' => ['O2SN Provinsi Jawa Tengah 2024', 'olahraga', 'provinsi', 'puspresnas', '2024-10-14', '2024-10-15', 'Kota Semarang'],
        'mtkunnes26' => ['Kompetisi Matematika Nasional UNNES 2026', 'sains', 'nasional', 'unnes', '2026-09-06', '2026-09-06', 'Kota Semarang'],
        'kimiajateng26' => ['Olimpiade Kimia Jawa Tengah 2026', 'sains', 'provinsi', 'undip', '2026-08-29', '2026-08-29', 'Kota Semarang'],
        'osnprov25' => ['OSN Provinsi Jawa Tengah 2025', 'sains', 'provinsi', 'puspresnas', '2025-08-15', '2025-08-16', 'Kota Semarang'],
        'fisikajateng26' => ['Olimpiade Fisika Jawa Tengah 2026', 'sains', 'provinsi', 'hfi', '2026-02-21', '2026-02-21', 'Kota Semarang'],
        'o2snkota26' => ['O2SN Kota Semarang 2026', 'olahraga', 'kota', 'puspresnas', '2026-08-29', '2026-08-29', 'Kota Semarang'],
        'inovasi25' => ['Lomba Inovasi Pelajar Kota Semarang 2025', 'riset', 'kota', 'pemkot', '2025-10-04', '2025-10-04', 'Kota Semarang'],
        'osnnas25' => ['OSN Nasional 2025', 'sains', 'nasional', 'puspresnas', '2025-10-06', '2025-10-12', 'Jakarta'],
        'taripelajarjateng26' => ['Festival Tari Pelajar Jawa Tengah 2026', 'seni_budaya', 'provinsi', 'disdikjateng', '2026-04-18', '2026-04-18', 'Kota Semarang'],
        'krenova26' => ['KRENOVA Kota Semarang 2026', 'riset', 'kota', 'pemkot', '2026-02-24', '2026-02-24', 'Kota Semarang', 'luring', 'keduanya', 3],
        'fls3nnas24' => ['FLS3N Nasional 2024', 'seni_budaya', 'nasional', 'puspresnas', '2024-11-10', '2024-11-12', 'Jakarta'],
        'fls3nkota24' => ['FLS3N Kota Semarang 2024', 'seni_budaya', 'kota', 'puspresnas', '2024-08-27', '2024-08-27', 'Kota Semarang'],
        'ldbikota24' => ['LDBI Kota Semarang 2024', 'seni_budaya', 'kota', 'puspresnas', '2024-09-07', '2024-09-07', 'Kota Semarang', 'luring', 'keduanya', 3],
        'ldbiprov24' => ['LDBI Provinsi Jawa Tengah 2024', 'seni_budaya', 'provinsi', 'puspresnas', '2024-10-26', '2024-10-26', 'Kota Semarang', 'luring', 'keduanya', 3],
        'osnnas24' => ['OSN Nasional 2024', 'sains', 'nasional', 'puspresnas', '2024-10-14', '2024-10-20', 'Jakarta'],
        'osnkota24' => ['OSN Kota Semarang 2024', 'sains', 'kota', 'puspresnas', '2024-08-10', '2024-08-10', 'Kota Semarang'],
        'lctmipa24' => ['LCT MIPA Kota Semarang 2024', 'sains', 'kota', 'disdikkota', '2024-11-09', '2024-11-09', 'Kota Semarang'],
        'kimiaundip24' => ['Olimpiade Kimia Nasional Universitas Diponegoro 2024', 'sains', 'nasional', 'undip', '2024-12-07', '2024-12-07', 'Kota Semarang'],
        'fls3nprov24' => ['FLS3N Provinsi Jawa Tengah 2024', 'seni_budaya', 'provinsi', 'puspresnas', '2024-10-04', '2024-10-05', 'Kota Semarang'],
        'paduansuara24' => ['Lomba Paduan Suara Pelajar Kota Semarang 2024', 'seni_budaya', 'kota', 'disdikkota', '2024-11-23', '2024-11-23', 'Kota Semarang'],
        'popda24' => ['Popda Kota Semarang 2024', 'olahraga', 'kota', 'dispora', '2024-09-28', '2024-09-28', 'Kota Semarang'],
        'renangjateng24' => ['Kejuaraan Daerah Renang Pelajar Jawa Tengah 2024', 'olahraga', 'provinsi', 'prsi', '2024-12-14', '2024-12-14', 'Kota Semarang'],
        // Reports waiting for Kesiswaan (E.md) and Nadia's WICE (spec §5a).
        'kimiaundip26' => ['Olimpiade Kimia Nasional Universitas Diponegoro 2026', 'sains', 'nasional', 'undip', '2026-09-19', '2026-09-20', 'Kota Semarang'],
        'lktijateng26' => ['Lomba Karya Tulis Ilmiah Remaja Jawa Tengah 2026', 'riset', 'provinsi', 'pemprov', '2026-09-17', '2026-09-17', 'Kota Semarang'],
        'popda26' => ['Popda Kota Semarang 2026', 'olahraga', 'kota', 'dispora', '2026-09-12', '2026-09-12', 'Kota Semarang'],
        'taripelajar26' => ['Festival Tari Pelajar Semarang 2026', 'seni_budaya', 'kota', 'sanggar', '2026-09-05', '2026-09-05', 'Kota Semarang'],
        'wice26' => ['WICE 2026', 'riset', 'internasional', 'iysa', '2026-09-21', '2026-09-24', 'SEGi University, Kuala Lumpur', 'luring', 'tim', 3],
    ];

    /**
     * Verified achievements: [competition, members (first = ketua), result, mentor, cabang, verified on].
     * Per-student totals = spec §5 leaderboard / mockup-v2-notes/A.md.
     */
    private const HISTORY = [
        // Raka 312
        ['fiksi26', ['raka'], 'juara_1', 'retno', null, '2026-09-25'],
        ['opsi25', ['raka'], 'juara_1', 'retno', null, '2025-08-20'],
        ['ayrc25', ['raka'], 'medali_emas', 'retno', null, '2025-12-03'],
        ['osnprov24', ['raka'], 'juara_1', 'hendra', 'Informatika', '2024-09-30'],
        ['krenova25', ['raka'], 'juara_1', 'retno', null, '2025-03-08'],
        // Salsabila 268
        ['fls3nnas25', ['salsabila'], 'juara_1', 'maya', 'Monolog', '2025-11-05'],
        ['filmpendek26', ['salsabila'], 'juara_1', 'maya', null, '2026-03-28'],
        ['fls3nprov26', ['salsabila'], 'juara_3', 'maya', 'Baca Puisi', '2026-09-22'],
        ['ldbiprov25', ['salsabila'], 'juara_3', 'maya', null, '2025-10-08'],
        ['ldbikota25', ['salsabila'], 'juara_1', 'maya', null, '2025-09-10'],
        // Bima 240
        ['robotiknas25', ['bima'], 'juara_1', 'retno', null, '2025-10-30'],
        ['o2snnas25', ['bima'], 'medali_perunggu', 'agus', 'Karate', '2025-09-30'],
        ['karate26', ['bima'], 'medali_emas', 'agus', null, '2026-05-28'],
        ['wseec25', ['bima'], 'medali_emas', 'retno', null, '2025-12-16'],
        ['krenova25', ['bima'], 'juara_1', 'retno', null, '2025-03-08'],
        // Aurelia 196
        ['biologinas26', ['aurelia'], 'juara_1', 'hendra', null, '2026-09-24'],
        ['ospjateng26', ['aurelia'], 'juara_1', 'hendra', 'Biologi', '2026-08-27'],
        ['biologijateng26', ['aurelia'], 'juara_1', 'hendra', null, '2026-09-04'],
        ['inovasi26', ['aurelia'], 'juara_1', 'retno', null, '2026-08-24'],
        ['osebi26', ['aurelia'], 'finalis', 'hendra', null, '2026-09-23'],
        // Fajar 180
        ['o2snprov26', ['fajar'], 'juara_1', 'agus', 'Pencak Silat', '2026-09-21'],
        ['o2snkota25', ['fajar'], 'juara_1', 'agus', 'Pencak Silat', '2025-08-29'],
        ['silatjateng25', ['fajar'], 'juara_1', 'agus', null, '2025-11-12'],
        ['popda25', ['fajar'], 'juara_1', 'agus', 'Pencak Silat', '2025-10-20'],
        ['o2snkota24', ['fajar'], 'semifinalis', 'agus', 'Bulu Tangkis', '2024-08-30'],
        // Citra 164
        ['musikalisasi26', ['citra'], 'juara_1', 'maya', null, '2026-05-27'],
        ['fls3nprov25', ['citra'], 'juara_1', 'maya', 'Baca Puisi', '2025-10-01'],
        ['fls3nkota25', ['citra'], 'juara_1', 'maya', 'Baca Puisi', '2025-09-02'],
        // Yoga 150 (LPB 2025 with Dimas, NSIF 2025 with Keisha + Dimas: every member gets full points)
        ['lpb25', ['yoga', 'dimas'], 'juara_1', 'retno', null, '2025-11-06'],
        ['o2snkota24', ['yoga'], 'juara_1', 'agus', 'Pencak Silat', '2024-08-30'],
        ['nsif25', ['yoga', 'keisha', 'dimas'], 'medali_emas', 'hendra', null, '2025-11-19'],
        ['o2snprov24', ['yoga'], 'finalis', 'agus', 'Pencak Silat', '2024-10-25'],
        // Hana 132
        ['mtkunnes26', ['hana'], 'juara_1', 'hendra', null, '2026-09-15'],
        ['ospjateng26', ['hana'], 'juara_1', 'hendra', 'Kimia', '2026-08-27'],
        ['kimiajateng26', ['hana'], 'juara_1', 'hendra', null, '2026-09-04'],
        // Rizky 121
        ['osnprov25', ['rizky'], 'juara_2', 'hendra', 'Fisika', '2025-08-27'],
        ['fisikajateng26', ['rizky'], 'juara_1', 'hendra', null, '2026-03-05'],
        ['o2snkota26', ['rizky'], 'juara_2', 'agus', 'Atletik', '2026-09-10'],
        // Keisha 118 (+ NSIF 2025 above)
        ['opsi25', ['keisha'], 'juara_2', 'retno', null, '2025-08-20'],
        ['ayrc25', ['keisha'], 'semifinalis', 'retno', null, '2025-12-03'],
        // Dimas 112 (+ LPB 2025, NSIF 2025 above)
        ['inovasi25', ['dimas'], 'juara_1', 'retno', null, '2025-10-15'],
        // Farhan 110
        ['osnnas25', ['farhan'], 'medali_perak', 'hendra', 'Matematika', '2025-10-22'],
        ['osnprov25', ['farhan'], 'juara_3', 'hendra', 'Matematika', '2025-08-27'],
        // Laras 108
        ['fls3nprov25', ['laras'], 'juara_1', 'maya', 'Tari', '2025-10-01'],
        ['taripelajarjateng26', ['laras'], 'juara_1', 'maya', null, '2026-04-29'],
        // Galuh 96, Bayu 88
        ['fls3nnas24', ['galuh'], 'juara_3', 'maya', 'Kriya', '2024-11-25'],
        ['fls3nkota24', ['galuh'], 'juara_1', 'maya', 'Kriya', '2024-09-05'],
        ['ldbikota24', ['bayu'], 'juara_1', 'maya', null, '2024-09-18'],
        ['ldbiprov24', ['bayu'], 'finalis', 'maya', null, '2024-11-06'],
        ['fls3nprov25', ['bayu'], 'semifinalis', 'maya', 'Teater', '2025-10-01'],
        // Alumni: Ardiansyah 236, Tiara 94, Putri 85,2, Bagas 100,8
        ['osnnas24', ['ardiansyah'], 'medali_emas', 'hendra', 'Kimia', '2024-10-30'],
        ['osnprov24', ['ardiansyah'], 'juara_1', 'hendra', 'Kimia', '2024-09-30'],
        ['osnkota24', ['ardiansyah'], 'juara_1', 'hendra', 'Kimia', '2024-08-20'],
        ['lctmipa24', ['ardiansyah'], 'juara_1', 'hendra', null, '2024-11-20'],
        ['kimiaundip24', ['ardiansyah'], 'finalis', 'hendra', null, '2024-12-18'],
        ['o2snprov24', ['tiara'], 'medali_emas', 'agus', 'Atletik', '2024-10-25'],
        ['o2snkota24', ['tiara'], 'medali_perak', 'agus', 'Atletik', '2024-08-30'],
        ['fls3nkota24', ['putri'], 'juara_1', 'maya', 'Menyanyi Solo Putri', '2024-09-05'],
        ['fls3nprov24', ['putri'], 'semifinalis', 'maya', 'Menyanyi Solo Putri', '2024-10-16'],
        ['paduansuara24', ['putri'], 'juara_2', 'maya', null, '2024-12-04'],
        ['o2snkota24', ['bagas'], 'juara_1', 'agus', 'Renang', '2024-08-30'],
        ['o2snprov24', ['bagas'], 'finalis', 'agus', 'Renang', '2024-10-25'],
        ['popda24', ['bagas'], 'juara_2', 'agus', 'Renang', '2024-10-08'],
        ['renangjateng24', ['bagas'], 'peserta', 'agus', null, '2024-12-20'],
    ];

    /**
     * Nadia's finished registrations with the tracker dates of mockup-v2-notes/D.md and letters:
     * key => [result, mentor, cabang, registered, approved, letters issued, report sent, verified, siswa seq, guru seq, file].
     */
    private const NADIA_DONE = [
        'krenova26' => ['juara_1', 'retno', null, '2026-02-02', '2026-02-04', '2026-02-06', '2026-03-02', '2026-03-06', 38, 214, 'sertifikat-krenova-2026.pdf'],
        'opsi26' => ['juara_2', 'retno', null, '2026-07-06', '2026-07-08', '2026-07-10', '2026-08-12', '2026-08-15', 241, 1502, 'sertifikat-opsi-2026.pdf'],
        'o2snkota26' => ['peserta', 'agus', 'Atletik', '2026-08-03', '2026-08-05', '2026-08-07', '2026-09-03', '2026-09-10', 276, 1590, 'piagam-o2sn-2026.pdf'],
    ];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, Student> */
    private array $students = [];

    /** @var array<string, Competition> */
    private array $competitions = [];

    /** @var array<string, Achievement> "<competition key>:<student key>" */
    private array $achievements = [];

    /** @var array<string, string> classroom name => id */
    private array $classrooms = [];

    private WeightVersion $version;

    private WeightTable $weights;

    private PointCalculator $calculator;

    private LetterNumber $letterNumber;

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoSeeder dilewati: hanya untuk APP_ENV=local.');

            return;
        }

        if (User::query()->where('username', 'kesiswaan')->exists()) {
            $this->command?->warn('Data demo sudah ada. Jalankan migrate:fresh --seed untuk mengulang.');

            return;
        }

        $this->version = WeightVersion::active() ?? throw new RuntimeException('Jalankan WeightSeeder lebih dulu.');
        $this->weights = $this->version->toWeightTable();
        $this->calculator = new PointCalculator;
        $this->letterNumber = new LetterNumber;
        $this->classrooms = Classroom::query()->pluck('id', 'name')->all();

        // Placeholder PDFs of a previous demo seed are orphans after migrate:fresh; drop them.
        if (File::query()->where('path', 'like', DemoFiles::DIRECTORY.'/%')->doesntExist()) {
            Storage::disk('local')->deleteDirectory(DemoFiles::DIRECTORY);
        }

        Model::unguarded(fn () => DB::transaction(function (): void {
            $this->seedStaff();
            $this->seedStudents();
            $this->seedHistory();
            $this->seedNadiaHistory();
            $this->seedCatalog();
            $this->seedProposals();
            $this->seedInFlight();
            $this->seedHumas();
            $this->seedTalent();
        }));

        $this->command?->info(sprintf(
            'Data demo: %d staf, %d siswa, %d lomba, %d pendaftaran, %d prestasi.',
            count($this->staff),
            count($this->students),
            Competition::query()->count(),
            Registration::query()->count(),
            Achievement::query()->count(),
        ));

        // The files above are tiny placeholders. On a real local seed they become realistic sample documents
        // (certificates, piagam, research report, juknis, brochures, letters); tests keep the placeholders.
        if (DemoFiles::realistic()) {
            foreach (DemoFileRefresher::lines(app(DemoFileRefresher::class)->refresh()) as $line) {
                $this->command?->line($line);
            }
        }
    }

    // Accounts -------------------------------------------------------------------------------

    private function seedStaff(): void
    {
        foreach (self::STAFF as $key => [$name, $username, $role, $honorific, $position, $rank]) {
            $user = User::query()->create([
                'name' => $name,
                'username' => $username,
                'email' => $username.'@sth.test',
                'email_verified_at' => '2026-07-01 08:00:00',
                'password' => self::STAFF_PASSWORD,
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'password_changed_at' => '2026-07-01 08:00:00',
                // Same as B1's backfill for active accounts. Demo students all start pending_activation
                // (CompleteActivation sets activated_at when they activate; alumni are archived).
                'activated_at' => '2026-07-01 08:00:00',
            ]);
            $user->assignRole($role->value);

            StaffProfile::query()->create([
                'user_id' => $user->getKey(),
                'honorific' => $honorific,
                'nip' => null,
                'rank' => $rank,
                'position' => $position,
            ]);

            $this->staff[$key] = $user;
        }

        Setting::put(SettingKey::SignerName, self::STAFF['kepsek'][0]);
        Setting::put(SettingKey::SignerRank, self::STAFF['kepsek'][5]);
    }

    private function seedStudents(): void
    {
        $initialPassword = app(InitialPassword::class);
        $graduation = app(GraduationCalculator::class);

        foreach (self::STUDENTS as $key => [$name, $class, $nis, $nisn, $birthDate, $photoConsent, $alumni]) {
            $importYear = $alumni ? '2024/2025' : '2026/2027';
            $dates = $graduation->calculate((int) Classroom::levelFromName($class), $importYear);

            $user = User::query()->create([
                'name' => $name,
                'email' => null,
                // Hashed by the model cast; the plain value is never stored or printed.
                'password' => $initialPassword->make($birthDate),
                'status' => $alumni ? UserStatus::Archived : UserStatus::PendingActivation,
                'must_change_password' => ! $alumni,
            ]);
            $user->assignRole(RoleName::Siswa->value);

            $this->students[$key] = Student::query()->create([
                'user_id' => $user->getKey(),
                'nis' => $nis,
                'nisn' => $nisn,
                'birth_date' => $birthDate,
                'classroom_id' => $this->classrooms[$class],
                'import_school_year' => $importYear,
                'grade12_start_date' => $dates->grade12StartDate->format('Y-m-d'),
                'archive_at' => $dates->archiveAt->format('Y-m-d'),
                'phone' => null,
                'photo_consent' => $photoConsent,
                'publish_consent' => true,
            ]);
        }
    }

    // Verified history -----------------------------------------------------------------------

    private function seedHistory(): void
    {
        foreach (self::HISTORY as [$competitionKey, $members, $result, $mentor, $branch, $verifiedOn]) {
            $competition = $this->competition($competitionKey);
            $registeredAt = $competition->starts_on->subDays(21)->setTime(9, 0);

            $this->verifiedRegistration($competitionKey, $members, ResultType::from($result), $mentor, $branch, [
                'registered' => $registeredAt,
                'approved' => $registeredAt->addDays(2)->setTime(10, 0),
                'reported' => $competition->final_on->addDays(3)->setTime(19, 0),
                'verified' => CarbonImmutable::parse($verifiedOn.' 10:00'),
            ]);
        }
    }

    private function seedNadiaHistory(): void
    {
        foreach (self::NADIA_DONE as $competitionKey => [$result, $mentor, $branch, $registered, $approved, $issued, $reported, $verified, $siswaSeq, $guruSeq, $file]) {
            $registration = $this->verifiedRegistration($competitionKey, ['nadia'], ResultType::from($result), $mentor, $branch, [
                'registered' => CarbonImmutable::parse($registered.' 09:00'),
                'approved' => CarbonImmutable::parse($approved.' 10:00'),
                'reported' => CarbonImmutable::parse($reported.' 19:00'),
                'verified' => CarbonImmutable::parse($verified.' 10:00'),
            ], $file);

            $this->issueLetters($registration, $siswaSeq, $guruSeq, $issued, null, null, $competitionKey);
        }
    }

    /**
     * @param  list<string>  $memberKeys
     * @param  array{registered: CarbonImmutable, approved: CarbonImmutable, reported: CarbonImmutable, verified: CarbonImmutable}  $dates
     */
    private function verifiedRegistration(string $competitionKey, array $memberKeys, ResultType $result, string $mentor, ?string $branch, array $dates, ?string $certificate = null): Registration
    {
        $competition = $this->competition($competitionKey);
        $registration = $this->registration($competitionKey, $memberKeys, $mentor, RegistrationStatus::Selesai, $dates['registered'], [
            'approved' => $dates['approved'],
            'branch' => $branch,
            'updated' => $dates['verified'],
        ]);

        $leader = $this->students[$memberKeys[0]];
        $report = Report::query()->create([
            'registration_id' => $registration->getKey(),
            'claimed_result' => $result,
            'submitted_by' => $leader->user_id,
            'submitted_at' => $dates['reported'],
            'status' => ReportStatus::Diverifikasi,
            'final_result' => $result,
            'verified_by' => $this->staff['kesiswaan']->getKey(),
            'verified_at' => $dates['verified'],
            'created_at' => $dates['reported'],
            'updated_at' => $dates['verified'],
        ]);

        $this->attachEvidence($report, $certificate ?? 'sertifikat-'.Str::slug($competition->name).'.pdf', $leader);

        $breakdown = $this->calculator->breakdown($this->weights, $competition->level, $result, $competition->weight_category);

        foreach ($memberKeys as $key) {
            $this->achievements[$competitionKey.':'.$key] = Achievement::query()->create([
                'report_id' => $report->getKey(),
                'student_id' => $this->students[$key]->getKey(),
                'competition_id' => $competition->getKey(),
                'result' => $result,
                'level' => $competition->level,
                'weight_category' => $competition->weight_category,
                ...$breakdown->toArray(),
                'weight_version_id' => $this->version->getKey(),
                'verified_at' => $dates['verified'],
                'created_at' => $dates['verified'],
                'updated_at' => $dates['verified'],
            ]);
        }

        return $registration;
    }

    // Catalog & proposals --------------------------------------------------------------------

    private function seedCatalog(): void
    {
        $catalog = [
            'osnkota26' => ['OSN Tingkat Kota Semarang 2026', 'sains', 'kota', 'puspresnas', '2026-09-08', '2026-10-08', '2026-10-14', 'Kota Semarang', 'luring', 'individu', null, 'hendra', 'juknis-osn-kota-2026.pdf'],
            'lpb26' => ['Lomba Peneliti Belia (LPB) 2026', 'riset', 'nasional', 'cys', '2026-09-01', '2026-10-10', '2026-10-17', 'Daring', 'daring', 'tim', 3, 'retno', 'juknis-lpb-2026.pdf'],
            'fls3nkota26' => ['FLS3N Kota Semarang 2026', 'seni_budaya', 'kota', 'puspresnas', '2026-09-10', '2026-10-02', '2026-10-27', 'Kota Semarang', 'luring', 'individu', null, 'maya', 'juknis-fls3n-kota-2026.pdf'],
            'o2snkota26bt' => ['O2SN Kota Semarang 2026 · Bulu Tangkis', 'olahraga', 'kota', 'puspresnas', '2026-09-09', '2026-10-09', '2026-10-20', 'Kota Semarang', 'luring', 'individu', null, 'agus', 'juknis-o2sn-kota-2026.pdf'],
            'nsif26' => ['National Science and Invention Fair (NSIF) 2026', 'riset', 'nasional', 'swasta', '2026-09-15', '2026-10-20', '2026-11-07', 'Daring', 'daring', 'tim', 3, 'hendra', 'juknis-nsif-2026.pdf'],
            'ldbikota26' => ['Lomba Debat Bahasa Indonesia (LDBI) Kota Semarang 2026', 'seni_budaya', 'kota', 'puspresnas', '2026-09-12', '2026-10-12', '2026-10-24', 'Kota Semarang', 'luring', 'tim', 3, 'maya', 'juknis-ldbi-kota-2026.pdf'],
            'krenova27' => ['KRENOVA Kota Semarang 2027', 'riset', 'kota', 'pemkot', '2026-12-01', '2027-01-31', '2027-02-20', 'Kota Semarang', 'luring', 'keduanya', 3, 'retno', null],
            'silatjateng26' => ['Kejuaraan Pencak Silat Pelajar Jawa Tengah 2026', 'olahraga', 'provinsi', 'ipsi', '2026-09-15', '2026-10-15', '2026-11-01', 'Jawa Tengah', 'luring', 'individu', null, 'agus', 'juknis-silat-pelajar-jateng-2026.pdf'],
        ];

        $kesiswaan = $this->staff['kesiswaan'];

        foreach ($catalog as $key => [$name, $bidang, $level, $organizer, $opens, $closes, $startsOn, $location, $mode, $participation, $maxTeam, $mentor, $juknis]) {
            [$organizerName, $organizerType, $category, $eventStatus] = self::ORGANIZERS[$organizer];

            $this->competitions[$key] = Competition::query()->create([
                'name' => $name,
                'description' => 'Info lomba contoh untuk pengembangan (data fiktif). Baca juknis untuk syarat lengkap.',
                'bidang' => Bidang::from($bidang),
                'organizer_name' => $organizerName,
                'organizer_type' => OrganizerType::from($organizerType),
                'weight_category' => WeightCategory::from($category),
                'event_status' => EventStatus::from($eventStatus),
                'level' => Level::from($level),
                'mode' => EventMode::from($mode),
                'registration_opens_at' => $opens.' 00:00:00',
                'registration_closes_at' => $closes.' 23:59:59',
                'starts_on' => $startsOn,
                'final_on' => $startsOn,
                'location' => $location,
                'participation' => Participation::from($participation),
                'max_team_size' => $maxTeam,
                'default_mentor_id' => $this->staff[$mentor]->getKey(),
                'website_url' => 'https://example.org/lomba/'.Str::slug($name),
                'registration_url' => 'https://example.org/daftar/'.Str::slug($name),
                'juknis_file_id' => $juknis === null ? null : DemoFiles::pdf($juknis, FilePurpose::Juknis, $kesiswaan->getKey(), $name)->getKey(),
                'status' => CompetitionStatus::Dipublikasikan,
                'submitted_by' => $kesiswaan->getKey(),
                'verified_by' => $kesiswaan->getKey(),
                'verified_at' => min($opens, '2026-09-25').' 08:00:00',
                'created_at' => min($opens, '2026-09-25').' 07:30:00',
            ]);
        }
    }

    /** Student proposals (usulan, D-04) waiting in the Kesiswaan queue (mockup-v2-notes/E.md). */
    private function seedProposals(): void
    {
        $proposals = [
            ['International Science Project Olympiad (ISPO) 2027', 'riset', 'Panitia ISPO', 'https://ispo.or.id', 'brosur-ispo-2027.pdf', 'raka', '2026-09-28 15:10:00'],
            ['Lomba Film Pendek Pelajar Semarang', 'seni_budaya', 'Komunitas Film Pelajar Semarang', null, 'poster-film-pendek.pdf', 'salsabila', '2026-09-27 19:45:00'],
            ['Kompetisi Robotik Pelajar Jateng 2026', 'riset', 'Lembaga Robotika Pelajar Jateng', 'https://instagram.com/robotikpelajar.jateng', 'juknis-robotik-jateng.pdf', 'bima', '2026-09-21 16:20:00'],
        ];

        foreach ($proposals as [$name, $bidang, $organizer, $url, $brochure, $studentKey, $createdAt]) {
            $userId = $this->students[$studentKey]->user_id;

            Competition::query()->create([
                'name' => $name,
                'bidang' => Bidang::from($bidang),
                'organizer_name' => $organizer,
                'website_url' => $url,
                'juknis_file_id' => DemoFiles::pdf($brochure, FilePurpose::Juknis, $userId, $name)->getKey(),
                'status' => CompetitionStatus::MenungguVerifikasi,
                'submitted_by' => $userId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    // Registrations in flight ----------------------------------------------------------------

    private function seedInFlight(): void
    {
        // Nadia a: WICE 2026 — letters issued 8 Sep, now waiting for the report.
        $wice = $this->registration('wice26', ['nadia', 'dimas', 'keisha'], 'retno', RegistrationStatus::MenungguLaporan, CarbonImmutable::parse('2026-09-02 08:30'), [
            'approved' => CarbonImmutable::parse('2026-09-05 10:00'),
            'updated' => CarbonImmutable::parse('2026-09-25 01:00'),
        ]);
        $this->issueLetters($wice, 312, 1680, '2026-09-08', 'IYSA/WICE/INV/118/VIII/2026', '2026-08-28', 'wice26');

        // Kepala Sekolah queue (5), all registered 29 Sep.
        $this->registration('lpb26', ['nadia', 'dimas'], 'retno', RegistrationStatus::MenungguKepsek, CarbonImmutable::parse('2026-09-29 09:12'));
        $this->registration('osnkota26', ['hana'], 'hendra', RegistrationStatus::MenungguKepsek, CarbonImmutable::parse('2026-09-29 10:05'));
        $this->registration('o2snkota26bt', ['fajar'], 'agus', RegistrationStatus::MenungguKepsek, CarbonImmutable::parse('2026-09-29 10:30'));
        $this->registration('ldbikota26', ['salsabila', 'citra', 'aurelia'], 'maya', RegistrationStatus::MenungguKepsek, CarbonImmutable::parse('2026-09-29 11:00'));
        $this->registration('silatjateng26', ['yoga'], 'agus', RegistrationStatus::MenungguKepsek, CarbonImmutable::parse('2026-09-29 13:15'));

        // TU queue (3): approved 30 Sep, letters not issued yet.
        $approved = CarbonImmutable::parse('2026-09-30 08:30');
        $this->registration('fls3nkota26', ['citra'], 'maya', RegistrationStatus::Disetujui, CarbonImmutable::parse('2026-09-15 09:00'), ['approved' => $approved, 'branch' => 'Baca Puisi']);
        $this->registration('nsif26', ['raka', 'bima', 'yoga'], 'hendra', RegistrationStatus::Disetujui, CarbonImmutable::parse('2026-09-20 10:00'), ['approved' => $approved->addMinutes(5)]);
        $this->registration('osnkota26', ['aurelia'], 'hendra', RegistrationStatus::Disetujui, CarbonImmutable::parse('2026-09-12 09:00'), ['approved' => $approved->addMinutes(10)]);

        // Other states for the tracker: team still waiting for member confirmations, rejected, cancelled.
        $this->registration('lpb26', ['farhan', 'rizky', 'keisha'], 'retno', RegistrationStatus::MenungguKonfirmasi, CarbonImmutable::parse('2026-09-30 13:40'), ['pending' => ['rizky', 'keisha']]);
        $this->registration('fls3nkota26', ['laras'], 'maya', RegistrationStatus::Ditolak, CarbonImmutable::parse('2026-09-22 09:00'), [
            'approved' => CarbonImmutable::parse('2026-09-28 14:00'),
            'note' => 'Jadwal bentrok dengan Penilaian Tengah Semester. Coba ikut tahun depan.',
            'branch' => 'Monolog',
        ]);
        $this->registration('osnkota26', ['rizky'], 'hendra', RegistrationStatus::Dibatalkan, CarbonImmutable::parse('2026-09-14 09:00'), [
            'cancelled' => CarbonImmutable::parse('2026-09-16 12:00'),
        ]);

        // Reports waiting for Kesiswaan verification (4, E.md).
        $this->pendingReport('kimiaundip26', 'rizky', ResultType::Juara3, 'hendra', '2026-09-29 19:20', 'sertifikat-okn-undip-2026.pdf');
        $this->pendingReport('lktijateng26', 'keisha', ResultType::Finalis, 'retno', '2026-09-28 16:00', 'sertifikat-lkti-remaja-jateng-2026.pdf', null, [
            'research_title' => 'Bioplastik dari limbah kulit pisang untuk kemasan jajanan sekolah',
            'research_abstract' => 'Penelitian contoh (fiktif): uji kuat tarik dan waktu urai bioplastik kulit pisang pada 3 komposisi gliserol.',
        ]);
        $this->pendingReport('popda26', 'bima', ResultType::MedaliPerak, 'agus', '2026-09-27 15:30', 'piagam-popda-atletik-2026.pdf', 'Atletik');
        $this->pendingReport('taripelajar26', 'citra', ResultType::Juara1, 'maya', '2026-09-26 20:10', 'sertifikat-festival-tari-2026.pdf');
    }

    /** @param array<string, string|null> $research */
    private function pendingReport(string $competitionKey, string $studentKey, ResultType $claimed, string $mentor, string $sentAt, string $certificate, ?string $branch = null, array $research = []): void
    {
        $competition = $this->competition($competitionKey);
        $registeredAt = $competition->starts_on->subDays(21)->setTime(9, 0);
        $sent = CarbonImmutable::parse($sentAt);

        $registration = $this->registration($competitionKey, [$studentKey], $mentor, RegistrationStatus::LaporanDikirim, $registeredAt, [
            'approved' => $registeredAt->addDays(2)->setTime(10, 0),
            'branch' => $branch,
            'updated' => $sent,
        ]);

        $student = $this->students[$studentKey];
        $report = Report::query()->create([
            'registration_id' => $registration->getKey(),
            'claimed_result' => $claimed,
            'submitted_by' => $student->user_id,
            'submitted_at' => $sent,
            'status' => ReportStatus::Dikirim,
            'research_title' => $research['research_title'] ?? null,
            'research_abstract' => $research['research_abstract'] ?? null,
            'created_at' => $sent,
            'updated_at' => $sent,
        ]);

        $this->attachEvidence($report, $certificate, $student);

        // A riset report carries its research report next to the certificate (SubmitReport asks for one).
        if (($research['research_title'] ?? null) !== null) {
            $this->attachEvidence($report, DemoFiles::researchReportName($research['research_title']), $student, ReportFileKind::LaporanRiset);
        }
    }

    /**
     * @param  list<string>  $memberKeys  first = ketua
     * @param  array{approved?: CarbonImmutable, updated?: CarbonImmutable, cancelled?: CarbonImmutable, note?: string, branch?: string|null, pending?: list<string>}  $options
     */
    private function registration(string $competitionKey, array $memberKeys, string $mentor, RegistrationStatus $status, CarbonImmutable $registeredAt, array $options = []): Registration
    {
        $decided = $options['approved'] ?? null;

        $registration = Registration::query()->create([
            'competition_id' => $this->competition($competitionKey)->getKey(),
            'leader_student_id' => $this->students[$memberKeys[0]]->getKey(),
            'type' => count($memberKeys) > 1 ? RegistrationType::Tim : RegistrationType::Individu,
            'branch' => $options['branch'] ?? null,
            'mentor_id' => $this->staff[$mentor]->getKey(),
            'status' => $status,
            'principal_decision_by' => $decided === null ? null : $this->staff['kepsek']->getKey(),
            'principal_decided_at' => $decided,
            'principal_note' => $options['note'] ?? null,
            'cancelled_at' => $options['cancelled'] ?? null,
            'created_at' => $registeredAt,
            'updated_at' => $options['updated'] ?? $options['cancelled'] ?? $decided ?? $registeredAt,
        ]);

        foreach ($memberKeys as $index => $key) {
            $pending = in_array($key, $options['pending'] ?? [], true);

            RegistrationMember::query()->create([
                'registration_id' => $registration->getKey(),
                'student_id' => $this->students[$key]->getKey(),
                'role' => $index === 0 ? MemberRole::Ketua : MemberRole::Anggota,
                'invited_at' => $registeredAt,
                'confirmed_at' => $pending ? null : $registeredAt->addHours($index * 3),
                'created_at' => $registeredAt,
                'updated_at' => $registeredAt,
            ]);
        }

        return $registration;
    }

    private function issueLetters(Registration $registration, int $siswaSequence, int $guruSequence, string $issuedOn, ?string $basisNumber, ?string $basisDate, string $slug): void
    {
        $issued = CarbonImmutable::parse($issuedOn.' 10:00');
        $tu = $this->staff['tu'];

        foreach ([[LetterType::Siswa, $siswaSequence, 'surat-tugas-siswa-'], [LetterType::Guru, $guruSequence, 'surat-perintah-tugas-guru-']] as [$type, $sequence, $prefix]) {
            $number = $this->letterNumber->format((string) Setting::get($type->formatSetting()), $sequence, $issuedOn);

            Letter::query()->create([
                'registration_id' => $registration->getKey(),
                'type' => $type,
                'sequence' => $sequence,
                'full_number' => $number,
                'year' => $issued->year,
                'month' => $issued->month,
                'issued_on' => $issuedOn,
                'basis_number' => $basisNumber,
                'basis_date' => $basisDate,
                'file_id' => DemoFiles::pdf($prefix.$slug.'.pdf', FilePurpose::Letter, $tu->getKey(), 'Nomor '.$number)->getKey(),
                'verification_code' => Letter::newVerificationCode(),
                'generated_by' => $tu->getKey(),
                'generated_at' => $issued,
                'sent_at' => $issued->addHour(),
                'created_at' => $issued,
                'updated_at' => $issued->addHour(),
            ]);
        }
    }

    private function attachEvidence(Report $report, string $fileName, Student $owner, ReportFileKind $kind = ReportFileKind::Sertifikat): void
    {
        ReportFile::query()->create([
            'report_id' => $report->getKey(),
            'kind' => $kind,
            'file_id' => DemoFiles::pdf($fileName, FilePurpose::ReportEvidence, $owner->user_id)->getKey(),
        ]);
    }

    private function competition(string $key): Competition
    {
        if (isset($this->competitions[$key])) {
            return $this->competitions[$key];
        }

        $row = self::PAST[$key] ?? throw new RuntimeException("Lomba demo tidak dikenal: {$key}");
        [$name, $bidang, $level, $organizer, $startsOn, $finalOn, $location] = $row;
        [$organizerName, $organizerType, $category, $eventStatus] = self::ORGANIZERS[$organizer];
        $starts = CarbonImmutable::parse($startsOn);
        $kesiswaan = $this->staff['kesiswaan']->getKey();

        return $this->competitions[$key] = Competition::query()->create([
            'name' => $name,
            'bidang' => Bidang::from($bidang),
            'organizer_name' => $organizerName,
            'organizer_type' => OrganizerType::from($organizerType),
            'weight_category' => WeightCategory::from($category),
            'event_status' => EventStatus::from($eventStatus),
            'level' => Level::from($level),
            'mode' => EventMode::from($row[7] ?? 'luring'),
            'registration_opens_at' => $starts->subDays(35)->startOfDay(),
            'registration_closes_at' => $starts->subDays(10)->endOfDay(),
            'starts_on' => $startsOn,
            'final_on' => $finalOn,
            'location' => $location,
            'participation' => Participation::from($row[8] ?? 'individu'),
            'max_team_size' => $row[9] ?? null,
            'status' => CompetitionStatus::Ditutup,
            'submitted_by' => $kesiswaan,
            'verified_by' => $kesiswaan,
            'verified_at' => $starts->subDays(36)->setTime(8, 0),
            'created_at' => $starts->subDays(36)->setTime(7, 30),
            'updated_at' => $starts->addDays(1),
        ]);
    }

    // Humas ----------------------------------------------------------------------------------

    private function seedHumas(): void
    {
        $humas = $this->staff['humas']->getKey();

        // [achievement, status, channels, scheduled at, published at] — mockup-v2-notes/F.md
        $publications = [
            ['fiksi26:raka', PublicationStatus::Siap, [], null, null],
            ['o2snprov26:fajar', PublicationStatus::Siap, [], null, null],
            ['osebi26:aurelia', PublicationStatus::Siap, [], null, null],
            ['opsi26:nadia', PublicationStatus::Terjadwal, ['instagram', 'website'], '2026-10-02 09:00:00', null],
            ['fls3nprov26:salsabila', PublicationStatus::Terjadwal, ['instagram'], '2026-10-05 09:00:00', null],
            ['krenova26:nadia', PublicationStatus::Dipublikasikan, ['instagram', 'website'], null, '2026-09-22 10:00:00'],
            ['o2snkota26:rizky', PublicationStatus::Dipublikasikan, ['instagram'], null, '2026-09-18 10:00:00'],
            ['biologinas26:aurelia', PublicationStatus::Dipublikasikan, ['instagram', 'website'], null, '2026-09-25 10:00:00'],
            ['mtkunnes26:hana', PublicationStatus::Dipublikasikan, ['instagram'], null, '2026-09-16 10:00:00'],
            ['ospjateng26:aurelia', PublicationStatus::Dipublikasikan, ['website'], null, '2026-08-28 10:00:00'],
            ['kimiajateng26:hana', PublicationStatus::Dipublikasikan, ['instagram'], null, '2026-09-05 10:00:00'],
        ];

        foreach ($publications as [$key, $status, $channels, $scheduledAt, $publishedAt]) {
            $achievement = $this->achievements[$key];
            [$competitionKey, $studentKey] = explode(':', $key);
            [$studentName, $class] = self::STUDENTS[$studentKey];

            Publication::query()->create([
                'achievement_id' => $achievement->getKey(),
                'status' => $status,
                'channels' => $channels,
                'scheduled_at' => $scheduledAt,
                'caption' => $status === PublicationStatus::Siap ? null : sprintf(
                    'Selamat kepada %s (%s) atas %s di %s!',
                    $studentName,
                    $class,
                    $achievement->result->label(),
                    $this->competition($competitionKey)->name,
                ),
                'published_at' => $publishedAt,
                'created_by' => $humas,
                'created_at' => $achievement->verified_at,
                'updated_at' => $publishedAt ?? $scheduledAt ?? $achievement->verified_at,
            ]);
        }

        $event = AppreciationEvent::query()->create(['name' => 'Upacara Senin, 5 Oktober 2026', 'held_on' => '2026-10-05']);

        foreach (['fiksi26:raka', 'o2snprov26:fajar'] as $key) {
            Appreciation::query()->create([
                'achievement_id' => $this->achievements[$key]->getKey(),
                'appreciation_event_id' => $event->getKey(),
                'status' => AppreciationStatus::Dijadwalkan,
            ]);
        }
    }

    // Talent scouting ------------------------------------------------------------------------

    private function seedTalent(): void
    {
        $types = TalentType::query()->get()->keyBy(fn (TalentType $type) => $type->code->value);
        $branches = TalentBranch::query()->get()->groupBy('talent_type_id');

        // [student, type, branch, status, mentor, assessment on, evidence file, submitted on] — mockup-v2-notes/T2.md
        $applications = [
            ['nadia', 'riset', 'OPSI', TalentStatus::Pembinaan, 'retno', null, 'sertifikat-opsi-2026.pdf', '2026-09-16'],
            ['nadia', 'osn', 'Kimia', TalentStatus::Terdaftar, null, null, null, '2026-09-29'],
            ['hana', 'osn', 'Biologi', TalentStatus::Pemetaan, 'hendra', '2026-10-07', 'sertifikat-osn-smp-biologi-2025.pdf', '2026-09-17'],
            ['rizky', 'osn', 'Kimia', TalentStatus::Seleksi, 'hendra', '2026-10-05', 'sertifikat-okn-undip-2026.pdf', '2026-09-16'],
            ['salsabila', 'fls3n', 'Baca Puisi', TalentStatus::Pembinaan, 'maya', null, 'sertifikat-fls3n-prov-2026.pdf', '2026-09-16'],
            ['citra', 'fls3n', 'Tari Kreasi', TalentStatus::Pemetaan, 'maya', '2026-10-09', 'sertifikat-festival-tari-2026.pdf', '2026-09-21'],
            ['fajar', 'o2sn', 'Pencak Silat', TalentStatus::Pembinaan, 'agus', null, 'piagam-o2sn-prov-2026.pdf', '2026-09-16'],
            ['aurelia', 'debat', 'Debat Bahasa Indonesia (LDBI)', TalentStatus::Seleksi, 'maya', '2026-10-06', null, '2026-09-18'],
            ['galang', 'olpain', null, TalentStatus::Terdaftar, null, null, 'sertifikat-olimpiade-pai-smp-2025.pdf', '2026-09-28'],
            ['bima', 'riset', 'FIKSI', TalentStatus::Seleksi, 'retno', '2026-10-08', 'sertifikat-robotik-nasional-2025.pdf', '2026-09-17'],
        ];

        foreach ($applications as [$studentKey, $code, $branchName, $status, $mentor, $assessmentOn, $evidence, $submittedOn]) {
            $type = $types[TalentTypeCode::from($code)->value];
            $branch = $branchName === null ? null : $branches[$type->getKey()]->firstWhere('name', $branchName)
                ?? throw new RuntimeException("Cabang talenta tidak ditemukan: {$code} / {$branchName}");
            $student = $this->students[$studentKey];

            TalentApplication::query()->create([
                'student_id' => $student->getKey(),
                'talent_type_id' => $type->getKey(),
                'talent_branch_id' => $branch?->getKey(),
                'evidence_file_id' => $evidence === null ? null : DemoFiles::pdf($evidence, FilePurpose::TalentEvidence, $student->user_id)->getKey(),
                'status' => $status,
                'mentor_id' => $mentor === null ? null : $this->staff[$mentor]->getKey(),
                'assessment_on' => $assessmentOn,
                'created_at' => $submittedOn.' 18:00:00',
                'updated_at' => $submittedOn.' 18:00:00',
            ]);
        }

        // Riwayat talenta: [interests, skills, organizations, junior-high achievements]
        $profiles = [
            'nadia' => [['Penelitian', 'Kimia'], 'Kimia dan metodologi penelitian', 'KIR SMAGA — anggota sejak 2025', 'Juara II LKTI tingkat kota, 2024'],
            'hana' => [['Biologi', 'Kimia'], null, 'KIR SMAGA, PMR', 'Juara 1 OSN SMP Biologi, Kota Semarang (2025)'],
            'rizky' => [['Kimia', 'Fisika'], null, 'Tim olimpiade SMAGA', 'Finalis OSN SMP Fisika, Provinsi (2024)'],
            'salsabila' => [['Sastra', 'Teater', 'Film'], null, 'Teater SMAGA, Jurnalistik', 'Juara 1 FLS2N SMP Baca Puisi, Kota (2024)'],
            'citra' => [['Tari kreasi', 'Puisi'], null, 'Sanggar tari SMAGA', 'Juara 2 FLS2N SMP Tari, Kota (2024)'],
            'fajar' => [['Pencak silat'], null, 'Ekskul pencak silat', 'Juara 1 O2SN SMP Pencak Silat, Kota (2023)'],
            'aurelia' => [['Biologi', 'Debat'], null, 'Klub debat SMAGA, KIR', 'Juara 2 Lomba Debat SMP, Kota (2025)'],
            'galang' => [['Tahfidz', 'Kajian PAI'], null, 'Rohis SMAGA', 'Juara 1 MTQ Pelajar SMP, Kota (2025)'],
            'bima' => [['Robotika', 'Rekayasa'], null, 'Klub robotik SMAGA', 'Juara 2 Lomba Robotik SMP, Provinsi (2023)'],
        ];

        foreach ($profiles as $studentKey => [$interests, $skills, $organizations, $juniorHigh]) {
            TalentProfile::query()->create([
                'student_id' => $this->students[$studentKey]->getKey(),
                'interests' => $interests,
                'skills' => $skills,
                'organizations' => $organizations,
                'junior_high_achievements' => $juniorHigh,
            ]);
        }
    }
}
