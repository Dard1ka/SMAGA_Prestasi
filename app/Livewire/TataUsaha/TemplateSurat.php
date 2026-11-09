<?php

declare(strict_types=1);

namespace App\Livewire\TataUsaha;

use App\Actions\Letters\LetterContent;
use App\Actions\Templates\LetterTemplate;
use App\Actions\Templates\UpdateLetterTemplate;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * Tata Usaha · Template surat (UI-MAP §4c `tu.surat.template`, backlog #7, owner B5).
 *
 * Structured plain-text fields of the surat tugas (App\Actions\Templates\LetterTemplate — never free-form Blade),
 * a live preview of both letters with fictional sample data (the same TU preview partials the issue screen uses,
 * fed with the unsaved draft), "Simpan template" (UpdateLetterTemplate: new version, audited, Kepala Sekolah +
 * Admins notified), "Kembalikan bawaan" and the version history from the audit log with "Pakai lagi" (loads an
 * old snapshot into the form; saving makes it a new version). The letter number formats are shown read-only: since
 * M1-A they are only the example under the number field (TU types the complete number; Admin › Pengaturan › Surat).
 * Viewing: SettingPolicy::viewLetterTemplate; saving: manageLetterTemplate.
 *
 * SV8, D-195: the student letter's Dasar is a list of 1 to LetterTemplate::SISWA_DASAR_MAX texts ("Tambah dasar",
 * "Hapus", "Naikkan" / "Turunkan" change the unsaved draft only). The form shows the first $siswaDasarRows fields of
 * LetterTemplate::SISWA_DASAR; fields after them count as empty. Saving drops the empty rows first, so a validation
 * error stands on the row TU sees.
 */
#[Title('Template surat')]
#[Layout('layouts::app', ['subtitle' => 'Teks tetap surat tugas. Perubahan berlaku untuk surat berikutnya.'])]
final class TemplateSurat extends Component
{
    /** @var array<string, string> field => value (draft) */
    public array $form = [];

    #[Url(as: 'surat', except: 'siswa')]
    public string $preview = 'siswa';

    /** SV8, D-195: how many student Dasar fields the form shows (1 … SISWA_DASAR_MAX); changed only by this class. */
    #[Locked]
    public int $siswaDasarRows = 1;

    public function mount(): void
    {
        Gate::authorize('viewLetterTemplate', Setting::class);
        $this->load(LetterTemplate::current()->values);
        $this->preview = $this->preview === 'guru' ? 'guru' : 'siswa';
    }

    public function updatedPreview(): void
    {
        $this->preview = $this->preview === 'guru' ? 'guru' : 'siswa';
    }

    public function showPreview(string $type): void
    {
        $this->preview = $type === 'guru' ? 'guru' : 'siswa';
    }

    public function save(UpdateLetterTemplate $update): void
    {
        Gate::authorize('manageLetterTemplate', Setting::class);

        $this->resetErrorBag();
        // SV8, D-195: the empty Dasar rows go first and the rest move up, as the Action stores them.
        $this->load($this->visibleForm());
        $before = LetterTemplate::current()->version;
        $saved = $update->handle($this->actor(), $this->form);
        $this->load($saved->values);

        $this->dispatch('sth-toast',
            message: $saved->version === $before ? 'Tidak ada perubahan.' : 'Template disimpan sebagai versi '.$saved->version.'.',
            tone: 'success',
        );
    }

    public function restoreDefaults(): void
    {
        Gate::authorize('manageLetterTemplate', Setting::class);
        $this->resetErrorBag();
        $this->load(LetterTemplate::defaults());
        $this->dispatch('sth-toast', message: 'Teks bawaan dimuat. Simpan untuk memakainya.', tone: 'info');
    }

    public function revert(): void
    {
        $this->resetErrorBag();
        $this->load(LetterTemplate::current()->values);
    }

    /** SV8, D-195: one more, empty student Dasar row at the end (at most SISWA_DASAR_MAX). */
    public function addSiswaDasar(): void
    {
        Gate::authorize('manageLetterTemplate', Setting::class);

        $rows = $this->siswaDasarRows();

        if ($rows >= LetterTemplate::SISWA_DASAR_MAX) {
            $this->dispatch('sth-toast', message: 'Dasar surat siswa paling banyak '.LetterTemplate::SISWA_DASAR_MAX.'.', tone: 'warning');

            return;
        }

        $this->form = LetterTemplate::withSiswaDasar($this->form, [...$this->siswaDasarDraft(), '']);
        $this->siswaDasarRows = $rows + 1;
    }

    /** SV8, D-195: remove the student Dasar row $index (0 = number 1); the rows under it move up. One row always stays. */
    public function removeSiswaDasar(int $index): void
    {
        Gate::authorize('manageLetterTemplate', Setting::class);

        $texts = $this->siswaDasarDraft();

        if (count($texts) <= 1) {
            $this->dispatch('sth-toast', message: 'Surat siswa perlu minimal satu dasar.', tone: 'warning');

            return;
        }

        if (! array_key_exists($index, $texts)) {
            return;
        }

        array_splice($texts, $index, 1);
        $this->resetErrorBag();
        $this->form = LetterTemplate::withSiswaDasar($this->form, $texts);
        $this->siswaDasarRows = count($texts);
    }

    /** SV8, D-195: swap the student Dasar row $index with the one above it ("Turunkan" on row i is this on row i + 1). */
    public function moveSiswaDasarUp(int $index): void
    {
        Gate::authorize('manageLetterTemplate', Setting::class);

        $texts = $this->siswaDasarDraft();

        if ($index < 1 || $index >= count($texts)) {
            return;
        }

        [$texts[$index - 1], $texts[$index]] = [$texts[$index], $texts[$index - 1]];
        $this->resetErrorBag();
        $this->form = LetterTemplate::withSiswaDasar($this->form, $texts);
    }

    /** Load an older version (from its audit snapshot) into the form; saving it makes a new version. */
    public function useVersion(int $activityId): void
    {
        Gate::authorize('manageLetterTemplate', Setting::class);

        $entry = Activity::query()->where('event', 'letter_template.updated')->findOrFail($activityId);
        $snapshot = $entry->properties['snapshot'] ?? null;
        abort_unless(is_array($snapshot), 404);

        $this->resetErrorBag();
        $this->load(LetterTemplate::draft($snapshot)->values);
        $this->dispatch('sth-toast', message: 'Versi '.($entry->properties['version'] ?? '?').' dimuat. Simpan untuk memakainya lagi.', tone: 'info');
    }

    public function render(): View
    {
        Gate::authorize('viewLetterTemplate', Setting::class);

        $current = LetterTemplate::current();
        $draft = LetterTemplate::draft($this->visibleForm());
        $sample = self::sample();

        return view('livewire.tata-usaha.template-surat', [
            'current' => $current,
            'draft' => $draft,
            'dirty' => $draft->values !== $current->values,
            'fields' => LetterTemplate::FIELDS,
            // SV8, D-195: the group's `siswa_dasar` stands for the whole list of these fields.
            'siswaDasarFields' => array_slice(LetterTemplate::SISWA_DASAR, 0, $this->siswaDasarRows()),
            'siswaDasarMax' => LetterTemplate::SISWA_DASAR_MAX,
            'groups' => [
                'Kop surat' => ['kop_1', 'kop_2', 'kop_3', 'kop_4', 'kop_5', 'kop_6'],
                'Identitas' => ['school_name', 'city', 'signer_title'],
                'Surat tugas siswa' => ['siswa_dasar', 'siswa_intro', 'siswa_closing'],
                'Surat perintah tugas guru' => ['guru_basis_1', 'guru_basis_2', 'guru_basis_3', 'guru_report', 'guru_closing'],
            ],
            'placeholders' => LetterTemplate::PLACEHOLDERS,
            'canEdit' => Gate::allows('manageLetterTemplate', Setting::class),
            'formats' => [
                'Surat siswa' => (string) Setting::get(SettingKey::LetterFormatSiswa),
                'Surat guru' => (string) Setting::get(SettingKey::LetterFormatGuru),
            ],
            'history' => Activity::query()
                ->where('event', 'letter_template.updated')
                ->with('causer')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Activity $a): array => [
                    'id' => $a->id,
                    'version' => (int) ($a->properties['version'] ?? 0),
                    'by' => (string) ($a->causer?->name ?? '-'),
                    'at' => $a->created_at?->translatedFormat('j M Y H:i'),
                    'changed' => collect(array_keys((array) ($a->properties['changed'] ?? [])))
                        ->map(fn (string $f): string => LetterTemplate::label($f))->unique()->implode(', '),
                    'restorable' => is_array($a->properties['snapshot'] ?? null),
                ]),
            // Preview: the TU partials with a fictional sample (DESIGN-SPEC-v2 §5) and the unsaved draft.
            'content' => $sample,
            'tpl' => $draft,
            // M1-A: numbers are typed in full by TU; the preview shows an example of each letter.
            'number' => $this->preview === 'guru' ? '400.3.8.6/1681/2026' : '400.3.8/313/MRD/X/2026',
        ]);
    }

    /** Fictional NSIF 2026 team (DESIGN-SPEC-v2 §5, TU queue), as a letter prints it: never real names or numbers. */
    public static function sample(): LetterContent
    {
        $issued = CarbonImmutable::create(2026, 10, 1);

        return new LetterContent(
            competitionName: 'National Science and Invention Fair (NSIF) 2026',
            competitionShort: 'National Science and Invention Fair (NSIF) 2026',
            organizer: 'Panitia NSIF 2026',
            basisFrom: 'Panitia NSIF 2026',
            basisNumber: '012/NSIF/IX/2026',
            basisDateText: '15 September 2026',
            basisSubject: 'Undangan',
            students: [
                ['no' => 1, 'name' => 'RAKA PRADIPTA', 'nis' => '224225101', 'class' => 'XII - 2'],
                ['no' => 2, 'name' => 'BIMA SATRIA WICAKSONO', 'nis' => '224225102', 'class' => 'XII - 7'],
                ['no' => 3, 'name' => 'YOGA PRATAMA', 'nis' => '224225103', 'class' => 'XII - 5'],
            ],
            mentor: ['name' => 'Hendra Kusuma, S.Pd., M.Si.', 'rank' => 'Penata, III/c', 'nip' => '[NIP]', 'position' => 'Guru'],
            scheduleDay: 'Sabtu',
            scheduleDate: '7 November 2026',
            scheduleTime: '08.00 WIB s.d Selesai',
            place: 'Daring',
            online: true,
            issuedOnText: $issued->locale('id')->translatedFormat('j F Y'),
            issuedYear: (string) $issued->year,
            schoolYear: '2026/2027',
            signer: [
                'name' => (string) Setting::get(SettingKey::SignerName),
                'rank' => (string) Setting::get(SettingKey::SignerRank),
                'nip' => (string) Setting::get(SettingKey::SignerNip),
            ],
        );
    }

    /**
     * SV8, D-195: put $values in the form with the student Dasar texts closed up (number 1 first), one row per text
     * (at least one row).
     *
     * @param  array<string, mixed>  $values
     */
    private function load(array $values): void
    {
        $texts = LetterTemplate::siswaDasarTexts($values);
        $this->form = LetterTemplate::withSiswaDasar($values, $texts);
        $this->siswaDasarRows = max(1, count($texts));
    }

    private function siswaDasarRows(): int
    {
        return max(1, min(LetterTemplate::SISWA_DASAR_MAX, $this->siswaDasarRows));
    }

    /**
     * The student Dasar texts of the rows the form shows, as typed (empty ones included).
     *
     * @return list<string>
     */
    private function siswaDasarDraft(): array
    {
        return array_map(
            fn (string $field): string => is_scalar($this->form[$field] ?? null) ? (string) $this->form[$field] : '',
            array_slice(LetterTemplate::SISWA_DASAR, 0, $this->siswaDasarRows()),
        );
    }

    /**
     * The form as the preview and "Simpan template" read it: a student Dasar field after the shown rows is empty.
     *
     * @return array<string, mixed>
     */
    private function visibleForm(): array
    {
        return LetterTemplate::withSiswaDasar($this->form, $this->siswaDasarDraft());
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
