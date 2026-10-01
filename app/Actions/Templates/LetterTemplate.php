<?php

declare(strict_types=1);

namespace App\Actions\Templates;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/**
 * Structured, versioned text of the surat tugas letters (TU › Template surat, backlog #7, owner B5).
 *
 * NOT a free-form template: a fixed list of plain-text fields (kop lines, school name, city, signer title, the
 * siswa letter's Dasar/intro/closing, the guru letter's Dasar 1 and closing points) stored as JSON in the
 * `settings` row `letter_template` ({version, values, updated_at, updated_by, updated_by_name}). Only the codes in
 * PLACEHOLDERS are replaced, with strtr(); the PDF views (resources/pdf) and the TU HTML preview print the
 * results with {{ }} (escaped). Nothing here is ever compiled or rendered as Blade (SSTI, CLAUDE.md).
 *
 * Version 0 = the built-in defaults below (no row yet, or the seeded null row); every saved change is a new version
 * (UpdateLetterTemplate, audited `letter_template.updated` with a full snapshot of the values).
 */
final readonly class LetterTemplate
{
    public const SETTING_KEY = SettingKey::LetterTemplate->value;

    /** code => meaning (shown to TU). */
    public const PLACEHOLDERS = [
        '{sekolah}' => 'nama sekolah',
        '{penyelenggara}' => 'penyelenggara lomba',
        '{tahun_ajaran}' => 'tahun pelajaran surat',
    ];

    /**
     * field => [label, max length, required, allowed placeholders, default, hint].
     *
     * @var array<string, array{0: string, 1: int, 2: bool, 3: list<string>, 4: string, 5: string}>
     */
    public const FIELDS = [
        'kop_1' => ['Kop baris 1', 100, true, [], 'PEMERINTAH PROVINSI JAWA TENGAH', 'Huruf tebal.'],
        'kop_2' => ['Kop baris 2', 100, false, [], 'DINAS PENDIDIKAN', 'Huruf tebal. Boleh kosong.'],
        'kop_3' => ['Kop baris 3 (nama sekolah)', 100, true, [], 'SEKOLAH MENENGAH ATAS NEGERI 3 SEMARANG', 'Huruf tebal, paling besar.'],
        'kop_4' => ['Kop baris 4 (alamat)', 150, false, [], 'Jalan Pemuda Nomor 149, Kota Semarang, Jawa Tengah 50132', 'Huruf kecil. Boleh kosong.'],
        'kop_5' => ['Kop baris 5 (kontak)', 150, false, [], '', 'Contoh: telepon, email, laman. Boleh kosong.'],
        'school_name' => ['Nama sekolah di isi surat', 100, true, [], 'SMA Negeri 3 Semarang', 'Dipakai untuk kode {sekolah}.'],
        'city' => ['Tempat surat', 60, true, [], 'Semarang', 'Tertulis sebelum tanggal surat.'],
        'signer_title' => ['Jabatan penandatangan', 60, true, [], 'Kepala Sekolah', 'Di atas tanda tangan.'],
        'siswa_basis' => ['Surat siswa: Dasar', 200, true, ['{penyelenggara}', '{sekolah}'], 'Surat dari {penyelenggara}', 'Nomor dan tanggal surat undangan ditulis TU saat membuat surat.'],
        'siswa_intro' => ['Surat siswa: kalimat pembuka', 200, true, ['{sekolah}'], 'Kepala {sekolah} memberikan tugas kepada :', ''],
        'siswa_closing' => ['Surat siswa: kalimat penutup', 500, true, ['{sekolah}'], 'Demikian surat tugas ini dibuat dan diberikan kepada yang bersangkutan untuk dapat dilaksanakan sebagaimana mestinya dengan penuh tanggung jawab.', ''],
        'guru_basis' => ['Surat guru: Dasar nomor 1', 300, true, ['{sekolah}', '{tahun_ajaran}'], 'Program kerja {sekolah} tahun pelajaran {tahun_ajaran}.', 'Dasar nomor 2 (surat penyelenggara) diisi otomatis.'],
        'guru_report' => ['Surat guru: Untuk nomor 2', 300, true, ['{sekolah}'], 'Melaporkan hasil pelaksanaan tugas kepada pejabat pemberi tugas.', ''],
        'guru_closing' => ['Surat guru: Untuk nomor 3', 300, true, ['{sekolah}'], 'Perintah ini dilaksanakan dengan penuh tanggung jawab.', ''],
    ];

    /**
     * @param  array<string, string>  $values  every key of FIELDS
     */
    public function __construct(
        public int $version,
        public array $values,
        public ?string $updatedAt = null,
        public ?string $updatedByName = null,
    ) {}

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return array_map(fn (array $field): string => $field[4], self::FIELDS);
    }

    /** The saved template (or version 0 = defaults). Unknown/missing keys fall back to the defaults. */
    public static function current(): self
    {
        $stored = Setting::get(self::SETTING_KEY);

        if (! is_array($stored)) {
            return new self(0, self::defaults());
        }

        return new self(
            (int) ($stored['version'] ?? 0),
            self::merge(is_array($stored['values'] ?? null) ? $stored['values'] : []),
            is_string($stored['updated_at'] ?? null) ? $stored['updated_at'] : null,
            is_string($stored['updated_by_name'] ?? null) ? $stored['updated_by_name'] : null,
        );
    }

    /** An unsaved draft (TU preview): same merge, no validation. */
    public static function draft(array $values): self
    {
        return new self(-1, self::merge($values));
    }

    /**
     * Cleaned values or ValidationException with keys "<prefix><field>".
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function validate(array $input, string $prefix = 'form.'): array
    {
        $errors = [];
        $values = [];

        foreach (self::FIELDS as $field => [$label, $max, $required, $allowed]) {
            $value = self::clean($input[$field] ?? '');

            if ($required && $value === '') {
                $errors[$prefix.$field] = $label.' wajib diisi.';
            } elseif (mb_strlen($value) > $max) {
                $errors[$prefix.$field] = $label.' maks. '.$max.' karakter.';
            } elseif (preg_match_all('/\{[^}]*\}?/u', $value, $m) > 0 && ($unknown = array_diff($m[0], $allowed)) !== []) {
                $errors[$prefix.$field] = $allowed === []
                    ? 'Kode '.reset($unknown).' tidak bisa dipakai di sini.'
                    : 'Kode '.reset($unknown).' tidak dikenal. Pakai '.implode(', ', $allowed).'.';
            }

            $values[$field] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    public function get(string $field): string
    {
        return $this->values[$field] ?? (self::FIELDS[$field][4] ?? '');
    }

    /** @return list<string> non-empty kop lines 1–3 (bold) */
    public function kopTitleLines(): array
    {
        return array_values(array_filter([$this->get('kop_1'), $this->get('kop_2'), $this->get('kop_3')], fn (string $l): bool => $l !== ''));
    }

    /** @return list<string> non-empty kop lines 4–5 (small) */
    public function kopDetailLines(): array
    {
        return array_values(array_filter([$this->get('kop_4'), $this->get('kop_5')], fn (string $l): bool => $l !== ''));
    }

    public function school(): string
    {
        return $this->get('school_name');
    }

    public function city(): string
    {
        return $this->get('city');
    }

    public function signerTitle(): string
    {
        return $this->get('signer_title');
    }

    public function siswaBasis(string $organizer): string
    {
        return $this->fill('siswa_basis', ['{penyelenggara}' => $organizer]);
    }

    public function siswaIntro(): string
    {
        return $this->fill('siswa_intro');
    }

    public function siswaClosing(): string
    {
        return $this->fill('siswa_closing');
    }

    public function guruBasis(string $schoolYear): string
    {
        return $this->fill('guru_basis', ['{tahun_ajaran}' => $schoolYear]);
    }

    public function guruReport(): string
    {
        return $this->fill('guru_report');
    }

    public function guruClosing(): string
    {
        return $this->fill('guru_closing');
    }

    /** @return array{version: int, values: array<string, string>} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'values' => $this->values];
    }

    /** @param array<string, string> $extra */
    private function fill(string $field, array $extra = []): string
    {
        return strtr($this->get($field), ['{sekolah}' => $this->school()] + $extra);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private static function merge(array $values): array
    {
        $merged = [];

        foreach (self::FIELDS as $field => $definition) {
            $merged[$field] = array_key_exists($field, $values) && is_scalar($values[$field])
                ? self::clean($values[$field])
                : $definition[4];
        }

        return $merged;
    }

    /** One line of plain text: control characters and line breaks become spaces, runs of spaces collapse. */
    private static function clean(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s{2,}/u', ' ', $text));
    }
}
