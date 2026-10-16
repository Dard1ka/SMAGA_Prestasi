<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\RegistrationStatus;
use Illuminate\Support\Facades\Route;

/*
| Design-system components (<x-sth.*>) and layouts (W1-D). Fictional data from DESIGN-SPEC-v2 §5.
*/

beforeEach(function (): void {
    $this->withoutVite();
});

it('renders a button element by default and a link when href is given', function (): void {
    $this->blade('<x-sth.button>Simpan</x-sth.button>')
        ->assertSee('<button type="button"', false)
        ->assertSee('class="btn btn-primary"', false)
        ->assertSee('Simpan');

    $this->blade('<x-sth.button href="/siswa/lomba" variant="quiet" size="sm" icon="plus">Tambah</x-sth.button>')
        ->assertSee('href="/siswa/lomba"', false)
        ->assertSee('class="btn btn-quiet btn-sm"', false)
        ->assertDontSee('<button', false);

    $this->blade('<x-sth.button href="/x" disabled>Kirim</x-sth.button>')
        ->assertSee('aria-disabled="true"', false)
        ->assertDontSee('href="/x"', false);

    $this->blade('<x-sth.button type="submit" disabled>Kirim</x-sth.button>')
        ->assertSee('type="submit"', false)
        ->assertSee('disabled', false);
});

it('requires a label on icon buttons and hides decorative icons from screen readers', function (): void {
    $this->blade('<x-sth.icon-button icon="bell" label="Notifikasi, 2 baru" dot />')
        ->assertSee('aria-label="Notifikasi, 2 baru"', false)
        ->assertSee('class="dot"', false);

    $this->blade('<x-sth.icon name="home" />')->assertSee('aria-hidden="true"', false);
    $this->blade('<x-sth.icon name="bell" label="Notifikasi" />')
        ->assertSee('role="img"', false)
        ->assertSee('aria-label="Notifikasi"', false);
});

it('throws on unknown icon names while debugging', function (): void {
    config(['app.debug' => true]);

    $this->blade('<x-sth.icon name="does-not-exist" />');
})->throws(Exception::class);

it('renders status and bidang chips with text, accepting the Bidang enum', function (): void {
    $this->blade('<x-sth.chip tone="orange" icon="clock">Menunggu laporan</x-sth.chip>')
        ->assertSee('chip c-orange', false)
        ->assertSee('Menunggu laporan');

    $this->blade('<x-sth.chip :bidang="$b" />', ['b' => Bidang::SeniBudaya])
        ->assertSee('chip b-seni', false)
        ->assertSee('Seni Budaya');

    $this->blade('<x-sth.chip bidang="riset" icon />')->assertSee('b-riset', false)->assertSee('Riset');
});

it('renders the full tracker with done, action and upcoming phases', function (): void {
    $view = $this->blade('<x-sth.tracker :phase="5" state="action" :dates="[4 => \'21–24 Sep\']" />');

    $view->assertSee('class="trk-step s-done"', false)
        ->assertSee('class="trk-step s-act"', false)
        ->assertSee('aria-current="step"', false)
        ->assertSee('class="trk-step s-up"', false)
        ->assertSee('21–24 Sep')
        ->assertSee('tahap saat ini, perlu tindakan')
        ->assertSeeInOrder(['Daftar', 'Persetujuan', 'Surat tugas', 'Lomba', 'Laporan hasil', 'Poin &amp; apresiasi'], false);
});

it('derives tracker phase and state from a registration status', function (): void {
    $this->blade('<x-sth.tracker :status="$s" compact />', ['s' => RegistrationStatus::MenungguKepsek])
        ->assertSee('Persetujuan')
        ->assertSee('sg sg-cur', false);

    $this->blade('<x-sth.tracker :status="$s" compact />', ['s' => RegistrationStatus::MenungguLaporan])
        ->assertSee('Laporan hasil')
        ->assertSee('sg sg-act', false)
        ->assertSee('perlu tindakan');

    $this->blade('<x-sth.tracker status="selesai" />')->assertDontSee('s-up', false);
});

it('formats the points formula with a decimal comma and a gold result', function (): void {
    $this->blade('<x-sth.rumus-poin :tingkat="80" :capaian="0.85" :penyelenggara="1" total="68.00" />')
        ->assertSee('Rumus poin: 80 × 0,85 × 1,00 = 68 poin')
        ->assertSee('rt rt-gold', false);

    $this->blade('<x-sth.rumus-poin :tingkat="60" capaian="0.85" penyelenggara="0.60" total="30.60" />')
        ->assertSee('= 30,6 poin');
});

it('wires validation errors into inputs (aria-invalid + describedby + message)', function (): void {
    $this->withViewErrors(['nama' => 'Nama lomba wajib diisi.'])
        ->blade('<x-sth.input name="nama" label="Nama lomba" hint="Tulis tanpa singkatan." />')
        ->assertSee('for="f-nama"', false)
        ->assertSee('aria-invalid="true"', false)
        ->assertSee('aria-describedby="f-nama-hint f-nama-error"', false)
        ->assertSee('Nama lomba wajib diisi.');

    $this->blade('<x-sth.input name="password" type="password" label="Kata sandi" revealable value="rahasia" />')
        ->assertSee('data-sth-reveal="f-password"', false)
        ->assertDontSee('rahasia');
});

it('renders pick buttons and segmented controls as real radios', function (): void {
    $this->blade('<x-sth.radio-pick name="tingkat" label="Tingkat" value="nasional" :options="[\'kota\' => \'Kota/Kab\', \'nasional\' => \'Nasional\']" />')
        ->assertSee('<legend', false)
        ->assertSee('type="radio"', false)
        ->assertSee('value="nasional"', false)
        ->assertSee('checked', false);

    $this->blade('<x-sth.radio-pick name="minat" multiple label="Minat" :value="[\'riset\']" :options="[\'sains\' => \'Sains\', \'riset\' => \'Riset\']" />')
        ->assertSee('type="checkbox"', false)
        ->assertSee('name="minat[]"', false);

    // A tile with a second line is .pick-2; a grid of long labels shows two per row on phones. Same-height rows.
    $this->blade('<x-sth.radio-pick name="capaian" label="Capaian" :columns="5" :phone-columns="2" :options="[\'khusus\' => [\'label\' => \'Penghargaan khusus\', \'sub\' => \'0,30\']]" />')
        ->assertSee('grid auto-rows-fr grid-cols-2 sm:grid-cols-5', false)
        ->assertSee('class="pick pick-2"', false);
    $this->blade('<x-sth.radio-pick name="kategori" label="Kategori" :columns="5" :options="[\'A\' => [\'label\' => \'A\', \'sub\' => \'1,00\']]" />')
        ->assertSee('grid auto-rows-fr grid-cols-3 sm:grid-cols-5', false);

    $this->blade('<x-sth.segmented name="peserta" label="Daftar sebagai" value="tim" :options="[\'individu\' => \'Individu\', \'tim\' => \'Tim\']" />')
        ->assertSee('class="seg-btn"', false)
        ->assertSee('value="tim"', false);
});

it('renders a lomba card with cover, chips, meta and a contextual link', function (): void {
    $this->blade('<x-sth.lomba-card title="National Science and Invention Fair (NSIF) 2026" href="/siswa/lomba/1" bidang="riset"
        tingkat="Nasional" status="Dibuka · 20 hari lagi" peserta="Tim maks 3" penyelenggara="Penyelenggara swasta · Kategori D"
        date="7 Nov 2026" place="Daring" :names="[\'Raka Pradipta\', \'Bima Satria Wicaksono\', \'Yoga Pratama\']" :count="3" />')
        ->assertSee('sampul sampul-riset-', false)
        ->assertSee('Dibuka · 20 hari lagi')
        ->assertSee('3</span> siswa SMAGA mendaftar', false)
        ->assertSee('href="/siswa/lomba/1"', false)
        ->assertSee('Lihat detail<span class="sr-only"> National Science and Invention Fair (NSIF) 2026</span>', false);
});

it('shows the school crest as the brand mark, on a white tile where the ground is ink', function (): void {
    // D-120: the crest replaced the shield icon. It is a CSS picture (.brand-mark), so Blade builds no asset address.
    $this->blade('<x-sth.brand />')
        ->assertSee('role="img" aria-label="Lambang SMA Negeri 3 Semarang"', false)
        ->assertSee('brand-mark h-10', false)
        ->assertSee('SMAGA Talent Hub')
        ->assertSee('SMA Negeri 3 Semarang')
        ->assertDontSee('<svg', false)
        ->assertDontSee('<img', false);

    // As a link the whole brand has one name; the picture is then decoration.
    $this->blade('<x-sth.brand size="sm" href="/" />')
        ->assertSee('aria-label="SMAGA Talent Hub, beranda"', false)
        ->assertSee('brand-mark h-9', false)
        ->assertDontSee('role="img"', false);

    $this->blade('<x-sth.brand inverse />')
        ->assertSee('bg-white', false)
        ->assertSee('brand-mark h-8', false)
        ->assertSee('text-white', false);

    expect(file_exists(resource_path('images/brand/lambang-sma3.png')))->toBeTrue()
        ->and(file_get_contents(resource_path('css/app.css')))->toContain("url('../images/brand/lambang-sma3.png')")
        ->and(file_exists(public_path('favicon.ico')))->toBeTrue()
        ->and(file_exists(public_path('icon-192.png')))->toBeTrue()
        ->and(file_exists(public_path('favicon.svg')))->toBeFalse();
});

it('renders a native dialog with a labelled title and a close button', function (): void {
    $this->blade('<x-sth.modal id="usul" title="Usulkan lomba" variant="drawer">Isi</x-sth.modal>')
        ->assertSee('<dialog', false)
        ->assertSee('aria-labelledby="usul-title"', false)
        ->assertSee('dlg dlg-drawer', false)
        ->assertSee('wire:ignore.self', false)
        ->assertSee('data-sth-close', false);
});

// QA5 retest (2026-10-07, backlog #63): a dialog whose state lives on the server resets it however it is closed --

it('tells the page when a dialog with on-close is closed, by any button, Esc or a click outside', function (): void {
    $this->blade('<x-sth.modal id="kelas" title="Kelas X-1" on-close="closeClass">Isi</x-sth.modal>')
        ->assertSee('data-sth-on-close="closeClass"', false);

    $this->blade('<x-sth.modal id="usul" title="Usulkan lomba">Isi</x-sth.modal>')
        ->assertDontSee('data-sth-on-close', false);

    // app.js calls that Livewire method on the dialog's close event (the browser fires it for every way of closing).
    expect(file_get_contents(resource_path('js/app.js')))->toContain('data-sth-on-close');
});

it('renders a table with a caption and column headers', function (): void {
    $this->blade('<x-sth.table caption="Katalog lomba"><x-slot:head><th scope="col">Lomba</th></x-slot:head><tr><td>OSN</td></tr></x-sth.table>')
        ->assertSee('<caption class="sr-only">Katalog lomba</caption>', false)
        ->assertSee('<th scope="col">Lomba</th>', false);
});

it('serves the local showcase pages without inline styles or inline handlers (CSP)', function (string $uri, string $expect): void {
    Route::middleware('web')->group(base_path('routes/dev.php'));
    Route::getRoutes()->refreshNameLookups();

    $response = $this->get($uri)->assertOk()->assertSee($expect);

    expect($response->getContent())
        ->not->toMatch('/\sstyle="/')
        ->not->toMatch('/\son[a-z]+="/i');
})->with([
    'app shell, siswa' => ['/_dev/components', 'Nadia Putri Anindya'],
    'app shell, kesiswaan' => ['/_dev/components?role=kesiswaan', 'Verifikasi usulan lomba'],
    'app shell, kepala sekolah' => ['/_dev/components?role=kepala_sekolah', 'Persetujuan'],
    'public' => ['/_dev/components/public', 'Prestasi siswa SMAGA, dalam satu tempat.'],
    'auth split' => ['/_dev/components/auth', 'Pakai NISN dan kata sandi dari sekolah.'],
    'auth simple' => ['/_dev/components/auth?variant=simple', 'Ganti kata sandi'],
    'mobile' => ['/_dev/components/mobile', 'Laporkan hasil'],
]);

it('shows no report deadline in the local showcase, only the request to report (D-151)', function (string $uri): void {
    Route::middleware('web')->group(base_path('routes/dev.php'));
    Route::getRoutes()->refreshNameLookups();

    $this->get($uri)->assertOk()
        ->assertSee('Segera laporkan')
        ->assertDontSee('Batas laporan')
        ->assertDontSee('atas 8 Nov')
        ->assertDontSee('hari setelah lomba');
})->with(['app shell' => '/_dev/components', 'mobile' => '/_dev/components/mobile']);

it('shows flashed toasts from the session', function (): void {
    Route::middleware('web')->group(base_path('routes/dev.php'));
    Route::getRoutes()->refreshNameLookups();

    $this->withSession(['toast' => ['tone' => 'success', 'message' => 'Usulan lomba terkirim.']])
        ->get('/_dev/components/public')
        ->assertOk()
        ->assertSee('Usulan lomba terkirim.')
        ->assertSee('data-announce', false);
});

it('renders card padding="none" without any padding class (W3-A)', function (): void {
    $this->blade('<x-sth.card padding="none" class="overflow-hidden">Isi</x-sth.card>')
        ->assertSee('class="card overflow-hidden"', false)
        ->assertDontSee('p-5', false);

    $this->blade('<x-sth.card>Isi</x-sth.card>')->assertSee('class="card p-5"', false);
    $this->blade('<x-sth.card padding="sm" tone="gold">Isi</x-sth.card>')->assertSee('class="card card-gold p-4"', false);
});

it('prints the textarea value once, without Livewire block markers inside the field (W3-A)', function (): void {
    expect((string) $this->blade('<x-sth.textarea name="catatan" label="Catatan" value="Sudah lengkap." />'))
        ->toMatch('#<textarea[^>]*>Sudah lengkap\.</textarea>#');

    expect((string) $this->blade('<x-sth.textarea name="catatan" label="Catatan">Isi awal</x-sth.textarea>'))
        ->toMatch('#<textarea[^>]*>Isi awal</textarea>#');

    expect((string) $this->blade('<x-sth.textarea wire:model="note" label="Catatan" />'))
        ->toMatch('#<textarea[^>]*></textarea>#');
});
