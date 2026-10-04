<?php

declare(strict_types=1);

use App\Actions\Templates\LetterTemplate;
use App\Actions\Templates\UpdateLetterTemplate;
use App\Domain\Letters\PdfRenderer;
use App\Enums\RoleName;
use App\Livewire\TataUsaha\TemplateSurat;
use App\Models\Setting;
use App\Notifications\Admin\LetterTemplateChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->tu = A::staff(RoleName::TataUsaha, ['name' => 'Ibu Siti Rahmawati', 'username' => 'tu']);
});

function b5TemplateInput(array $override = []): array
{
    return array_merge(LetterTemplate::defaults(), $override);
}

function b5RenderLetter(string $view): string
{
    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));

    return view('pdf::'.$view, [
        'content' => TemplateSurat::sample(),
        'number' => '400.3.8/313/MRD/X/2026',
        'verification' => ['code' => str_repeat('a', 32), 'url' => 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32)],
        'pdfImages' => [],
    ])->render();
}

// Value object ---------------------------------------------------------------------------------------

it('starts at version 0 with the built-in texts', function (): void {
    $template = LetterTemplate::current();

    expect($template->version)->toBe(0)
        ->and($template->siswaIntro())->toBe('Kepala SMA Negeri 3 Semarang memberikan tugas kepada :')
        ->and($template->siswaBasis('Puspresnas'))->toBe('Surat dari Puspresnas')
        ->and($template->kopTitleLines())->toHaveCount(3)
        // M1-A: the kop and the guru letter's "Dasar" are the texts of the school's own files.
        ->and($template->kopDetailLines())->toBe([
            'Jalan Pemuda Nomor 149, Kota Semarang, Semarang Tengah, Kota Semarang, Jawa Tengah',
            'Kode Pos 50132, Telepon 3544287, Faksimile 024-3544291',
            'Laman https://sman3-smg.sch.id, Pos-el smanegeri3semarang149@gmail.com',
        ])
        ->and($template->guruBasisItems('2026/2027'))->toHaveCount(3)
        ->and($template->guruBasisItems('2026/2027')[0])->toStartWith('Peraturan Daerah Provinsi Jawa Tengah Nomor 11 Tahun 2025')
        ->and($template->guruBasisItems('2026/2027')[2])->toStartWith('Peraturan Gubernur Jawa Tengah Nomor 36 Tahun 2025');
});

it('skips an empty regulation text and fills the codes of the others', function (): void {
    $template = LetterTemplate::draft([
        'guru_basis_1' => 'Program kerja {sekolah} tahun pelajaran {tahun_ajaran}.',
        'guru_basis_2' => '',
        'guru_basis_3' => 'DPA {sekolah}.',
    ]);

    expect($template->guruBasisItems('2026/2027'))->toBe([
        'Program kerja SMA Negeri 3 Semarang tahun pelajaran 2026/2027.',
        'DPA SMA Negeri 3 Semarang.',
    ]);
});

it('keeps a template saved before M1-A and uses the built-in text for fields it does not have', function (): void {
    Setting::put(LetterTemplate::SETTING_KEY, [
        'version' => 3,
        'values' => ['city' => 'Kota Semarang', 'kop_4' => 'Alamat lama', 'guru_basis' => 'Program kerja lama.'],
        'updated_at' => '2026-10-01T08:00:00+07:00',
        'updated_by_name' => 'Ibu Siti Rahmawati',
    ]);

    $template = LetterTemplate::current();

    expect($template->version)->toBe(3)
        ->and($template->city())->toBe('Kota Semarang')
        ->and($template->get('kop_4'))->toBe('Alamat lama')
        ->and($template->get('kop_6'))->toBe(LetterTemplate::FIELDS['kop_6'][4])
        ->and($template->guruBasisItems('2026/2027'))->toHaveCount(3)
        ->and($template->values)->not->toHaveKey('guru_basis');
});

it('validates required fields, lengths and allowed codes; Blade-like text is just an unknown code', function (array $override, string $field): void {
    try {
        LetterTemplate::validate(b5TemplateInput($override));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('form.'.$field);
    }
})->with([
    'empty kop 1' => [['kop_1' => '  '], 'kop_1'],
    'too long city' => [['city' => str_repeat('a', 61)], 'city'],
    'unknown code' => [['siswa_intro' => 'Kepala {nama_kepsek} memberi tugas'], 'siswa_intro'],
    'code not allowed here' => [['kop_1' => 'PEMERINTAH {sekolah}'], 'kop_1'],
    'blade echo' => [['siswa_closing' => 'Demikian {{ 7*7 }}'], 'siswa_closing'],
    'unclosed code' => [['guru_basis_1' => 'Program kerja {sekolah'], 'guru_basis_1'],
    'too long regulation text' => [['guru_basis_2' => str_repeat('a', 601)], 'guru_basis_2'],
]);

it('accepts empty regulation texts (the school may have fewer than three)', function (): void {
    $values = LetterTemplate::validate(b5TemplateInput(['guru_basis_1' => '', 'guru_basis_2' => ' ', 'guru_basis_3' => '']));

    expect($values['guru_basis_1'])->toBe('')
        ->and(LetterTemplate::draft($values)->guruBasisItems('2026/2027'))->toBe([]);
});

it('flattens line breaks and control characters', function (): void {
    $values = LetterTemplate::validate(b5TemplateInput(['city' => "Semarang\r\n\tKota"]));

    expect($values['city'])->toBe('Semarang Kota');
});

// Action ---------------------------------------------------------------------------------------------

it('saves a new version, audits the change with a snapshot and notifies the Kepala Sekolah and Admins', function (): void {
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $admin = A::admin();

    $saved = app(UpdateLetterTemplate::class)->handle($this->tu, b5TemplateInput(['city' => 'Kota Semarang']));

    expect($saved->version)->toBe(1)
        ->and(LetterTemplate::current()->city())->toBe('Kota Semarang')
        ->and(LetterTemplate::current()->updatedByName)->toBe('Ibu Siti Rahmawati');

    $log = Activity::query()->where('event', 'letter_template.updated')->sole();
    expect($log->causer_id)->toBe($this->tu->id)
        ->and($log->properties['version'])->toBe(1)
        ->and($log->properties['changed'])->toBe(['city' => ['from' => 'Semarang', 'to' => 'Kota Semarang']])
        ->and($log->properties['snapshot']['city'])->toBe('Kota Semarang');

    Notification::assertSentTo([$kepsek, $admin], LetterTemplateChanged::class);

    $again = app(UpdateLetterTemplate::class)->handle($this->tu, b5TemplateInput(['city' => 'Kota Semarang']));
    expect($again->version)->toBe(1)
        ->and(Activity::query()->where('event', 'letter_template.updated')->count())->toBe(1);
});

it('lets Tata Usaha and Admin change the template, nobody else', function (RoleName $role): void {
    expect(fn () => app(UpdateLetterTemplate::class)->handle(A::staff($role), b5TemplateInput(['city' => 'X'])))
        ->toThrow(AuthorizationException::class);
})->with([RoleName::KepalaSekolah, RoleName::Kesiswaan, RoleName::Humas, RoleName::Guru]);

it('accepts the Admin through setting.manage', function (): void {
    expect(app(UpdateLetterTemplate::class)->handle(A::admin(), b5TemplateInput(['city' => 'Semarang Tengah']))->version)->toBe(1);
});

// PDF + preview --------------------------------------------------------------------------------------

it('prints the saved template in both PDF letters, escaped and never evaluated', function (): void {
    app(UpdateLetterTemplate::class)->handle($this->tu, b5TemplateInput([
        'kop_5' => 'Telepon (024) 000000 · <b>laman</b>',
        'siswa_closing' => 'Demikian surat ini @php echo 1; @endphp dibuat oleh {sekolah}.',
        'guru_basis_1' => 'Program kerja {sekolah} tahun pelajaran {tahun_ajaran}.',
        'guru_basis_2' => '',
        'guru_closing' => 'Laksanakan dengan tanggung jawab.',
        'signer_title' => 'Plt. Kepala Sekolah',
    ]));

    $siswa = b5RenderLetter('surat-tugas-siswa');
    $guru = b5RenderLetter('surat-perintah-tugas-guru');

    expect($siswa)->toContain('Telepon (024) 000000 · &lt;b&gt;laman&lt;/b&gt;')
        ->and($siswa)->toContain('Demikian surat ini @php echo 1; @endphp dibuat oleh SMA Negeri 3 Semarang.')
        ->and($siswa)->toContain('Plt. Kepala Sekolah')
        ->and($siswa)->not->toContain('<b>laman</b>')
        ->and($guru)->toContain('Laksanakan dengan tanggung jawab.')
        ->and($guru)->toContain('Program kerja SMA Negeri 3 Semarang tahun pelajaran 2026/2027.')
        // the empty second text is skipped: regulation 3 becomes number 2, the invitation number 3
        ->and($guru)->toContain('<td class="n">3.</td><td class="rata">Surat dari Panitia NSIF 2026 Nomor 012/NSIF/IX/2026 Tanggal 15 September 2026 Hal Undangan</td>')
        ->and($guru)->not->toContain('<td class="n">4.</td>');
});

it('still renders a real PDF with the template', function (): void {
    $document = app(PdfRenderer::class)->render('surat-tugas-siswa', [
        'content' => TemplateSurat::sample(),
        'number' => '400.3.8/313/MRD/X/2026',
        'verification' => ['code' => str_repeat('b', 32), 'url' => 'https://contoh.test/v'],
    ]);

    expect($document->bytes)->toStartWith('%PDF-');
});

// Screen ---------------------------------------------------------------------------------------------

it('opens the template screen for Tata Usaha only', function (): void {
    $this->actingAs($this->tu)->get(route('tu.surat.template'))
        ->assertOk()
        ->assertSee('Teks bawaan')
        ->assertSee('PEMERINTAH PROVINSI JAWA TENGAH')
        ->assertSee('RAKA PRADIPTA')
        // M1-A: the number formats are only an example now, and the template has the new fields
        ->assertSee('Contoh nomor surat')
        ->assertSee('Kop baris 6 (laman, pos-el)')
        ->assertSee('Surat guru: Dasar nomor 3');

    foreach ([RoleName::KepalaSekolah, RoleName::Kesiswaan, RoleName::Humas, RoleName::Guru, RoleName::Admin] as $role) {
        $this->actingAs(A::staff($role, ['username' => 'lain.'.$role->value]))->get(route('tu.surat.template'))->assertForbidden();
    }
});

it('previews the draft live, saves it, and reloads an old version', function (): void {
    $this->actingAs($this->tu);

    $component = Livewire::test(TemplateSurat::class)
        ->set('form.siswa_closing', 'Kalimat penutup baru untuk {sekolah}.')
        ->assertSee('Kalimat penutup baru untuk SMA Negeri 3 Semarang.')
        ->assertSee('Belum disimpan')
        ->set('form.kop_1', '')
        ->call('save')
        ->assertHasErrors('form.kop_1')
        ->set('form.kop_1', 'PEMERINTAH PROVINSI JAWA TENGAH')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Template disimpan sebagai versi 1.');

    expect(LetterTemplate::current()->siswaClosing())->toBe('Kalimat penutup baru untuk SMA Negeri 3 Semarang.');

    $component->set('form.city', 'Kota Semarang')->call('save');
    expect(LetterTemplate::current()->version)->toBe(2);

    $first = Activity::query()->where('event', 'letter_template.updated')->orderBy('id')->first();
    $component->call('useVersion', $first->id)
        ->assertSet('form.city', 'Semarang')
        ->call('showPreview', 'guru')
        ->assertSee('SURAT PERINTAH TUGAS');
});
