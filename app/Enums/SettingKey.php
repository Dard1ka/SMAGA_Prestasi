<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Keys of the `settings` table (ARCHITECTURE §4). The initial-password pattern is deliberately NOT here.
 */
enum SettingKey: string
{
    case ReportDeadlineDays = 'report_deadline_days';
    case ArchiveMonthsAfterGrade12 = 'archive_months_after_grade12';
    case OtpTestMode = 'otp_test_mode';
    case SignatureFileId = 'signature_file_id';
    case SignerName = 'signer_name';
    case SignerRank = 'signer_rank';
    case SignerNip = 'signer_nip';
    case LetterFormatSiswa = 'letter_format_siswa';
    case LetterFormatGuru = 'letter_format_guru';
    /** Search engines may index the aggregate public pages (student pages are always noindex). */
    case PublicIndexing = 'public_indexing';
    /** "Lihat sertifikat" links on public pages (still needs publish_consent of every team member). */
    case PublicCertificates = 'public_certificates';

    public function label(): string
    {
        return match ($this) {
            self::ReportDeadlineDays => 'Batas laporan hasil (hari setelah final)',
            self::ArchiveMonthsAfterGrade12 => 'Arsip otomatis (bulan setelah mulai kelas XII)',
            self::OtpTestMode => 'Mode uji OTP (hanya lokal)',
            self::SignatureFileId => 'Tanda tangan + stempel',
            self::SignerName => 'Nama penandatangan',
            self::SignerRank => 'Pangkat/golongan penandatangan',
            self::SignerNip => 'NIP penandatangan',
            self::LetterFormatSiswa => 'Format nomor surat siswa',
            self::LetterFormatGuru => 'Format nomor surat guru',
            self::PublicIndexing => 'Izinkan mesin pencari mengindeks halaman publik',
            self::PublicCertificates => 'Tampilkan tautan sertifikat di halaman publik',
        };
    }

    /** Value written by SettingSeeder when the key does not exist yet. */
    public function defaultValue(): mixed
    {
        return match ($this) {
            self::ReportDeadlineDays => 45,
            self::ArchiveMonthsAfterGrade12 => 18,
            self::OtpTestMode => false,
            self::SignatureFileId => null,
            self::SignerName => '[Nama Kepala Sekolah]',
            self::SignerRank => '[Pangkat/Golongan]',
            self::SignerNip => '[NIP]',
            self::LetterFormatSiswa => '400.3.8/{seq}/MRD/{roman_month}/{year}',
            self::LetterFormatGuru => '400.3.8.6/{seq}/{year}',
            self::PublicIndexing, self::PublicCertificates => false,
        };
    }
}
