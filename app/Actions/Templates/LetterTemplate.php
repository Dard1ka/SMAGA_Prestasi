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
 * siswa letter's Dasar/intro/closing, the guru letter's three regulation texts under "Dasar" and its closing
 * points) stored as JSON in the `settings` row `letter_template` ({version, values, updated_at, updated_by,
 * updated_by_name}). Only the codes in PLACEHOLDERS are replaced, with strtr(); the PDF views (resources/pdf) and
 * the TU HTML preview print the results with {{ }} (escaped). Nothing here is ever compiled or rendered as Blade
 * (SSTI, CLAUDE.md).
 *
 * M1-A (lead, 2026-10-04): the built-in texts are the ones of the school's own files ("ST PENGUKUHAN KEG OPSI"
 * for the guru letter, "ST_LOMBA … TIM" for the student letter): the kop has three address lines, and the guru
 * letter's "Dasar" starts with three regulation texts that Tata Usaha replaces every budget year (an empty one
 * is skipped and the list renumbers; the invitation always comes last). A template saved before M1-A keeps its
 * values; fields it does not have (`kop_6`, `guru_basis_1…3`) use the built-in text.
 *
 * D-154 (lead, 2026-10-16, the school's printed student letter): the student letter's "Dasar" is one regulation
 * text (`siswa_dasar`, replaced every budget year like the guru texts), no longer "Surat dari {penyelenggara}" with
 * the invitation's Nomor and Tanggal (`siswa_basis`). A saved template without `siswa_dasar` takes the built-in
 * text; a letter printed with `siswa_basis` keeps it in $oldSiswaBasis, so its preview still shows what it printed.
 *
 * SV8, D-195 (lead, 2026-11-09: "dasarnya baru bisa 1. Dibuat bisa lebih dari 1 kayak surat guru"): the student "Dasar"
 * is a list of 1 to SISWA_DASAR_MAX regulation texts, stored in the fixed fields of SISWA_DASAR (`siswa_dasar` = number
 * 1, then `siswa_dasar_2` …, built-in text: empty). Saving closes the gaps (an empty text is dropped and the rest move
 * up), so at least the first one is required. A template saved before, and every letter printed before
 * (letters.printed, D-108), has only `siswa_dasar`: the others read as empty, so it is the one-item list it printed.
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
        '{tahun_ajaran}' => 'tahun pelajaran surat',
    ];

    /** The student letter's Dasar before D-154 (field `siswa_basis`), kept for the preview of letters printed with it. */
    private const OLD_SISWA_BASIS = 'siswa_basis';

    private const GURU_BASIS_HINT = 'Peraturan yang berlaku tahun ini. Boleh kosong: nomor berikutnya naik. Surat undangan selalu jadi nomor terakhir.';

    /**
     * SV8, D-194: the layouts of the guru letter (Surat Perintah Tugas). New letters print GURU_LAYOUT (body in
     * resources/pdf/partials/guru-<layout>.blade.php, TU preview in tata-usaha/partials/pratinjau-guru-<layout>), and it
     * is stored with the letter (toArray(), `guru_layout`). A letter printed before stores none: fromArray() reads it as
     * GURU_LAYOUT_LAMPIRAN (M1-A: "Kepada … Terlampir" and a Lampiran page), so its preview stays what it printed (D-108).
     * Another version from the school = one more key here, its two partials and its CSS.
     */
    public const GURU_LAYOUT_LAMPIRAN = 'lampiran';

    /** SV8, D-194: the school's example of 2026-11-09, one page, the one guru right under "Kepada". */
    public const GURU_LAYOUT_LANGSUNG = 'langsung';

    public const GURU_LAYOUT = self::GURU_LAYOUT_LANGSUNG;

    /** @var list<string> */
    public const GURU_LAYOUTS = [self::GURU_LAYOUT_LAMPIRAN, self::GURU_LAYOUT_LANGSUNG];

    private const SISWA_DASAR_HINT = 'Peraturan yang berlaku tahun ini. Tertulis di surat siswa sesudah "Dasar :".';

    /** The student letter's Dasar texts in print order (SV8, D-195): number 1 is the old single field. */
    public const SISWA_DASAR = ['siswa_dasar', 'siswa_dasar_2', 'siswa_dasar_3', 'siswa_dasar_4', 'siswa_dasar_5', 'siswa_dasar_6'];

    /** At most this many student Dasar texts in the template (the invitation of a letter comes on top). */
    public const SISWA_DASAR_MAX = 6;

    /**
     * field => [label, max length, required, allowed placeholders, default, hint].
     *
     * @var array<string, array{0: string, 1: int, 2: bool, 3: list<string>, 4: string, 5: string}>
     */
    public const FIELDS = [
        'kop_1' => ['Kop baris 1', 100, true, [], 'PEMERINTAH PROVINSI JAWA TENGAH', 'Huruf biasa.'],
        'kop_2' => ['Kop baris 2', 100, false, [], 'DINAS PENDIDIKAN', 'Huruf tebal. Boleh kosong.'],
        'kop_3' => ['Kop baris 3 (nama sekolah)', 100, true, [], 'SEKOLAH MENENGAH ATAS NEGERI 3 SEMARANG', 'Huruf tebal.'],
        'kop_4' => ['Kop baris 4 (alamat)', 150, false, [], 'Jalan Pemuda Nomor 149, Kota Semarang, Semarang Tengah, Kota Semarang, Jawa Tengah', 'Huruf kecil. Boleh kosong.'],
        'kop_5' => ['Kop baris 5 (kode pos, telepon)', 150, false, [], 'Kode Pos 50132, Telepon 3544287, Faksimile 024-3544291', 'Huruf kecil. Boleh kosong.'],
        'kop_6' => ['Kop baris 6 (laman, pos-el)', 150, false, [], 'Laman https://sman3-smg.sch.id, Pos-el smanegeri3semarang149@gmail.com', 'Huruf kecil. Boleh kosong.'],
        'school_name' => ['Nama sekolah di isi surat', 100, true, [], 'SMA Negeri 3 Semarang', 'Dipakai untuk kode {sekolah}.'],
        'city' => ['Tempat surat', 60, true, [], 'Semarang', 'Tertulis sebelum tanggal surat.'],
        'signer_title' => ['Jabatan penandatangan', 60, true, [], 'Kepala Sekolah', 'Di atas tanda tangan.'],
        'siswa_dasar' => ['Surat siswa: Dasar nomor 1', 600, true, ['{sekolah}', '{tahun_ajaran}'], 'Peraturan Menteri Pendidikan Dasar dan Menengah Republik Indonesia Nomor 8 Tahun 2026 Hal : Penggunaan Dana BOS Kinerja bagi sekolah yang memiliki prestasi', self::SISWA_DASAR_HINT],
        'siswa_dasar_2' => ['Surat siswa: Dasar nomor 2', 600, false, ['{sekolah}', '{tahun_ajaran}'], '', self::SISWA_DASAR_HINT],
        'siswa_dasar_3' => ['Surat siswa: Dasar nomor 3', 600, false, ['{sekolah}', '{tahun_ajaran}'], '', self::SISWA_DASAR_HINT],
        'siswa_dasar_4' => ['Surat siswa: Dasar nomor 4', 600, false, ['{sekolah}', '{tahun_ajaran}'], '', self::SISWA_DASAR_HINT],
        'siswa_dasar_5' => ['Surat siswa: Dasar nomor 5', 600, false, ['{sekolah}', '{tahun_ajaran}'], '', self::SISWA_DASAR_HINT],
        'siswa_dasar_6' => ['Surat siswa: Dasar nomor 6', 600, false, ['{sekolah}', '{tahun_ajaran}'], '', self::SISWA_DASAR_HINT],
        'siswa_intro' => ['Surat siswa: kalimat pembuka', 200, true, ['{sekolah}'], 'Kepala {sekolah} memberikan tugas kepada :', ''],
        'siswa_closing' => ['Surat siswa: kalimat penutup', 500, true, ['{sekolah}'], 'Demikian surat tugas ini dibuat dan diberikan kepada yang bersangkutan untuk dapat dilaksanakan sebagaimana mestinya dengan penuh tanggung jawab.', ''],
        'guru_basis_1' => ['Surat guru: Dasar nomor 1', 600, false, ['{sekolah}', '{tahun_ajaran}'], 'Peraturan Daerah Provinsi Jawa Tengah Nomor 11 Tahun 2025 tentang Anggaran Pendapatan Daerah dan Belanja Daerah Tahun Anggaran 2026 (Lembaran Daerah Provinsi Jawa Tengah Tahun 2025 Nomor 11, Tambahan Lembaran Provinsi Jawa Tengah Nomor 173) (tanggal 30 Desember 2025);', self::GURU_BASIS_HINT],
        'guru_basis_2' => ['Surat guru: Dasar nomor 2', 600, false, ['{sekolah}', '{tahun_ajaran}'], 'Peraturan Gubernur Jawa Tengah Nomor 58 Tahun 2023 tentang Perubahan Atas Peraturan Gubernur Nomor 45 Tahun 2022 tentang pedoman Perjalanan Dinas Dalam Negeri di Lingkungan Pemerintah Provinsi Jawa Tengah (Berita Daerah Provinsi Jawa Tengah Tahun 2023 Nomor 58);', self::GURU_BASIS_HINT],
        'guru_basis_3' => ['Surat guru: Dasar nomor 3', 600, false, ['{sekolah}', '{tahun_ajaran}'], 'Peraturan Gubernur Jawa Tengah Nomor 36 Tahun 2025 tentang Penjabaran Anggaran Pendapatan Dan Belanja Daerah Tahun Anggaran 2026 (Berita Daerah Provinsi Jawa Tengah Tahun 2025 Nomor 36) (tanggal 30 Desember 2025);', self::GURU_BASIS_HINT],
        'guru_report' => ['Surat guru: Untuk nomor 2', 300, true, ['{sekolah}'], 'Melaporkan hasil pelaksanaan tugas kepada pejabat pemberi tugas.', ''],
        'guru_closing' => ['Surat guru: Untuk nomor 3', 300, true, ['{sekolah}'], 'Perintah ini dilaksanakan dengan penuh tanggung jawab.', ''],
    ];

    /**
     * @param  array<string, string>  $values  every key of FIELDS
     * @param  string|null  $oldSiswaBasis  only from fromArray(): the `siswa_basis` text of a letter printed before D-154
     * @param  string  $guruLayout  one of GURU_LAYOUTS (SV8, D-194); only fromArray() passes another than GURU_LAYOUT
     */
    public function __construct(
        public int $version,
        public array $values,
        public ?string $updatedAt = null,
        public ?string $updatedByName = null,
        public ?string $oldSiswaBasis = null,
        public string $guruLayout = self::GURU_LAYOUT,
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

    /** The template a letter printed (`letters.printed['template']`, from toArray()); null when it is not one. */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_int($data['version'] ?? null) || ! is_array($data['values'] ?? null)) {
            return null;
        }

        $values = $data['values'];
        $old = ! array_key_exists('siswa_dasar', $values) && is_string($values[self::OLD_SISWA_BASIS] ?? null)
            ? self::clean($values[self::OLD_SISWA_BASIS])
            : null;

        // SV8, D-194: a letter printed before the layout was stored has the M1-A guru letter with its Lampiran page.
        $guruLayout = in_array($data['guru_layout'] ?? null, self::GURU_LAYOUTS, true) ? $data['guru_layout'] : self::GURU_LAYOUT_LAMPIRAN;

        return new self($data['version'], self::merge($values), oldSiswaBasis: $old, guruLayout: $guruLayout);
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
        // SV8, D-195: the student Dasar list has no gaps, an empty text is dropped and the next ones move up (number 1
        // stays required, so an all-empty list is refused on `siswa_dasar`).
        $input = self::withSiswaDasar($input, self::siswaDasarTexts($input));

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

    /** Name of a field for TU (version history); the old student Dasar of saved versions before D-154 too. */
    public static function label(string $field): string
    {
        return self::FIELDS[$field][0] ?? ($field === self::OLD_SISWA_BASIS ? 'Surat siswa: Dasar' : $field);
    }

    /**
     * The non-empty student Dasar texts of a set of values, in print order, cleaned (codes not filled yet).
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function siswaDasarTexts(array $values): array
    {
        $texts = [];

        foreach (self::SISWA_DASAR as $field) {
            $text = self::clean($values[$field] ?? '');

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * $values with the student Dasar fields set to $texts in order (the first text is number 1) and the rest empty;
     * texts beyond SISWA_DASAR_MAX are dropped. Every other field stays as it is.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $texts
     * @return array<string, mixed>
     */
    public static function withSiswaDasar(array $values, array $texts): array
    {
        $texts = array_values($texts);

        foreach (self::SISWA_DASAR as $index => $field) {
            $values[$field] = $texts[$index] ?? '';
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

    /** @return list<string> non-empty kop lines 4–6 (small: address, phone, website) */
    public function kopDetailLines(): array
    {
        return array_values(array_filter([$this->get('kop_4'), $this->get('kop_5'), $this->get('kop_6')], fn (string $l): bool => $l !== ''));
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

    /**
     * The student letter's "Dasar" texts of the template (D-154; a list since SV8, D-195), codes filled, empty ones
     * skipped. LetterContent::siswaBasis() puts the invitation of a letter after them.
     *
     * @return list<string>
     */
    public function siswaDasarItems(string $schoolYear): array
    {
        $items = [];

        foreach (self::SISWA_DASAR as $field) {
            $text = $this->fill($field, ['{tahun_ajaran}' => $schoolYear]);

            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    /**
     * A letter printed before D-154: its first Dasar row ("Surat dari <what TU typed>"), shown above its Nomor and
     * Tanggal rows in the preview. Null for every other template.
     */
    public function oldSiswaBasis(string $from): ?string
    {
        return $this->oldSiswaBasis === null ? null : strtr($this->oldSiswaBasis, ['{sekolah}' => $this->school(), '{penyelenggara}' => $from]);
    }

    public function siswaIntro(): string
    {
        return $this->fill('siswa_intro');
    }

    public function siswaClosing(): string
    {
        return $this->fill('siswa_closing');
    }

    /**
     * The fixed regulation texts under "Dasar" of the guru letter (empty ones skipped). The invitation
     * (LetterContent::guruBasis()) is printed after them.
     *
     * @return list<string>
     */
    public function guruBasisItems(string $schoolYear): array
    {
        $items = [];

        foreach (['guru_basis_1', 'guru_basis_2', 'guru_basis_3'] as $field) {
            $text = $this->fill($field, ['{tahun_ajaran}' => $schoolYear]);

            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    public function guruReport(): string
    {
        return $this->fill('guru_report');
    }

    public function guruClosing(): string
    {
        return $this->fill('guru_closing');
    }

    /**
     * SV8, D-194: a kop line in pieces for the guru letter, which prints the host of a web address ("sman3-smg.sch.id"
     * after "https://") blue and underlined as the school's letter does; everything else, the scheme and the comma
     * after it included, is plain. A line without an address is one plain piece.
     *
     * @return list<array{text: string, link: bool}>
     */
    public static function kopLineParts(string $line): array
    {
        $pieces = preg_split('~(?<=https://|http://)([^\s,;]+?)(?=[.,;]?(?:\s|$))~u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$line];
        $parts = [];

        foreach ($pieces as $index => $piece) {
            // A host is the piece right after one that ends with the scheme.
            $parts[] = ['text' => $piece, 'link' => preg_match('~https?://$~u', $pieces[$index - 1] ?? '') === 1];
        }

        return $parts;
    }

    /** @return array{version: int, values: array<string, string>, guru_layout: string} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'values' => $this->values, 'guru_layout' => $this->guruLayout];
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
