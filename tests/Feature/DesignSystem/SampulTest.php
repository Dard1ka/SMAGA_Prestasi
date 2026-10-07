<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\UserStatus;
use App\Models\Competition;
use App\Models\User;
use App\Support\Sampul;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

// The policy asks the permission tables for a lomba that is not published.
uses(RefreshDatabase::class);

/*
 | Cover of a lomba (DESIGN-SPEC-v3 §5): the uploaded poster, else one of three template drawings of the
 | bidang. The drawing is picked from the lomba id, so it never changes between screens or requests.
 */

it('picks a stable template drawing from the lomba id', function (): void {
    $id = '01m48q6222n2w5rv56btxa3v42';

    expect(Sampul::variant($id))->toBe(Sampul::variant($id))
        ->and(Sampul::variant($id))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(Sampul::VARIANTS)
        ->and(Sampul::variant(null))->toBe(1)
        ->and(Sampul::variant(''))->toBe(1);

    // Every variant is reachable, so the lomba of one bidang do not all look alike.
    $seen = collect(range(1, 60))->map(fn (int $i): int => Sampul::variant('lomba-'.$i))->unique()->sort()->values()->all();

    expect($seen)->toBe([1, 2, 3]);
});

it('maps a bidang to its design-system key', function (Bidang|string|null $bidang, string $key): void {
    expect(Sampul::key($bidang))->toBe($key)
        ->and(Sampul::templateClass($bidang, null))->toBe('sampul-'.$key.'-1');
})->with([
    'enum' => [Bidang::SeniBudaya, 'seni'],
    'value' => ['olahraga', 'olahraga'],
    'short form' => ['seni', 'seni'],
    'css key' => ['riset', 'riset'],
    'unknown' => ['matematika', 'sains'],
    'missing' => [null, 'sains'],
]);

it('gives a poster address only for an uploaded cover the signed-in user may see', function (): void {
    // Plain models, no database: the helper reads the column, asks the policy and builds an address.
    $without = (new Competition)->forceFill(['cover_file_id' => null, 'status' => CompetitionStatus::Dipublikasikan]);
    $published = (new Competition)->forceFill(['cover_file_id' => '01m48q6222n2w5rv56btxa3v42', 'status' => CompetitionStatus::Dipublikasikan]);
    $closed = (new Competition)->forceFill(['cover_file_id' => '01m48q6222n2w5rv56btxa3v42', 'status' => CompetitionStatus::Ditutup, 'submitted_by' => 99]);
    $student = (new User)->forceFill(['id' => 7, 'status' => UserStatus::Active]);

    // A guest never gets a poster address (public pages show the template drawing).
    expect(Sampul::poster($published))->toBeNull();

    $this->actingAs($student);

    expect(Sampul::poster($without))->toBeNull()
        ->and(Sampul::poster(null))->toBeNull()
        ->and(Sampul::poster($published))->toBe(route('files.cover', ['file' => '01m48q6222n2w5rv56btxa3v42']))
        // A lomba that is no longer published: the route would answer 404, so no request is made at all.
        ->and(Gate::forUser($student)->allows('view', $closed))->toBeFalse()
        ->and(Sampul::poster($closed))->toBeNull();
});

it('renders a template drawing when there is no poster', function (): void {
    $this->blade('<x-sth.sampul bidang="riset" seed="abc" class="h-24"><span>Nasional</span></x-sth.sampul>')
        ->assertSee('class="sampul '.Sampul::templateClass('riset', 'abc').' h-24"', false)
        ->assertSee('<span>Nasional</span>', false)
        ->assertDontSee('<img', false)
        ->assertDontSee('sampul-poster', false)
        ->assertDontSee('style=', false);
});

it('renders the poster as two images over the drawing, never as an inline style', function (): void {
    $this->blade('<x-sth.sampul bidang="seni_budaya" seed="abc" src="/berkas/sampul/01abc" alt="Poster LDBI 2026" tone="pekat" />')
        ->assertSee('sampul-poster', false)
        ->assertSee('sampul-pekat', false)
        ->assertSee(Sampul::templateClass('seni', 'abc'), false)
        ->assertSee('<img class="sampul-latar" src="/berkas/sampul/01abc" alt="" aria-hidden="true"', false)
        ->assertSee('<img class="sampul-gambar" src="/berkas/sampul/01abc" alt="Poster LDBI 2026"', false)
        ->assertSee('data-sth-sampul', false)
        ->assertDontSee('style=', false);
});

it('stands a portrait cover on its side of a card: one image that fills the 3:4 box', function (): void {
    // bentuk="tegak" (lomba card): no blurred copy, the poster fills the box. Lazy: a catalogue has many.
    $this->blade('<x-sth.sampul bidang="riset" seed="abc" src="/berkas/sampul/01abc" bentuk="tegak" class="w-[148px]" />')
        ->assertSee('sampul-tegak', false)
        ->assertSee('sampul-poster', false)
        ->assertSee('<img class="sampul-isi" src="/berkas/sampul/01abc" alt="" loading="lazy"', false)
        ->assertDontSee('sampul-latar', false)
        ->assertDontSee('sampul-gambar', false)
        ->assertDontSee('style=', false);

    // bentuk="utuh" (the one poster of a detail page): whole, loaded at once, with its alt text.
    $this->blade('<x-sth.sampul bidang="riset" seed="abc" src="/berkas/sampul/01abc" bentuk="utuh" tone="pekat" alt="Poster NSIF 2026" />')
        ->assertSee('sampul-utuh', false)
        ->assertSee('sampul-pekat', false)
        ->assertSee('<img class="sampul-isi" src="/berkas/sampul/01abc" alt="Poster NSIF 2026" loading="eager"', false)
        ->assertDontSee('sampul-latar', false);

    // Without a poster the same box shows the drawing of the bidang; an unknown shape is the wide band.
    $this->blade('<x-sth.sampul bidang="olahraga" seed="abc" bentuk="tegak" />')
        ->assertSee('sampul '.Sampul::templateClass('olahraga', 'abc').' sampul-tegak', false)
        ->assertDontSee('<img', false);
    $this->blade('<x-sth.sampul bidang="olahraga" seed="abc" bentuk="miring" />')
        ->assertDontSee('sampul-tegak', false)
        ->assertDontSee('sampul-utuh', false);
});

it('puts the cover on a lomba card, with the poster when the card has one', function (): void {
    $card = '<x-sth.lomba-card title="LDBI Kota Semarang 2026" href="/siswa/lomba/1" bidang="seni_budaya" seed="01abc" %s />';

    // The capsule: the cover stands on the left in the card's own 3:4 box.
    $this->blade(sprintf($card, ''))
        ->assertSee(Sampul::templateClass('seni', '01abc').' sampul-tegak', false)
        ->assertDontSee('<img', false);

    $this->blade(sprintf($card, 'poster="/berkas/sampul/01abc"'))
        ->assertSee('sampul-poster', false)
        ->assertSee('<img class="sampul-isi" src="/berkas/sampul/01abc"', false)
        ->assertDontSee('sampul-latar', false);
});

it('heads a lomba page with the poster on the left, the caller\'s text and the key facts beside it', function (): void {
    $facts = "[['icon' => 'clock', 'label' => 'Batas daftar', 'value' => '20 Okt 2026'], ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => 'Nasional']]";
    $head = '<x-sth.lomba-head bidang="riset" seed="01abc" :poster="$poster" :facts="'.$facts.'" alt="Poster NSIF 2026" aria-labelledby="h-lomba"><h1 id="h-lomba">NSIF 2026</h1></x-sth.lomba-head>';

    // No poster: the deep drawing of the bidang, nothing to open.
    $this->blade($head, ['poster' => null])
        ->assertSee('aria-labelledby="h-lomba"', false)
        ->assertSee('sampul-utuh', false)
        ->assertSee('sampul-pekat', false)
        ->assertSee('<h1 id="h-lomba">NSIF 2026</h1>', false)
        ->assertSee('Batas daftar')
        ->assertSee('20 Okt 2026')
        ->assertDontSee('<img', false)
        ->assertDontSee('Perbesar')
        ->assertDontSee('data-sth-preview', false);

    // Only an address (Undangan tim): the poster shows, without a link.
    $this->blade($head, ['poster' => '/berkas/sampul/01abc'])
        ->assertSee('<img class="sampul-isi" src="/berkas/sampul/01abc" alt="Poster NSIF 2026"', false)
        ->assertDontSee('Perbesar');

    // Detail lomba: poster and "Perbesar" are one link; the click opens the preview popup, the href downloads.
    $this->blade($head, ['poster' => ['src' => '/berkas/sampul/01abc', 'href' => '/berkas/01abc', 'preview' => '/berkas/01abc?tampil=1', 'previewType' => 'image', 'name' => 'poster-nsif.jpg']])
        ->assertSee('href="/berkas/01abc"', false)
        ->assertSee('data-sth-preview="/berkas/01abc?tampil=1"', false)
        ->assertSee('data-sth-preview-type="image"', false)
        ->assertSee('data-sth-preview-name="poster-nsif.jpg"', false)
        ->assertSee('Perbesar<span class="sr-only"> poster</span>', false)
        ->assertDontSee('style=', false);
});

it('keeps a stat tile quiet at zero and hides a warning tile that has nothing to warn about', function (): void {
    $this->blade('<x-sth.stat-tile icon="clock" tone="orange" label="Menunggu" value="3" />')
        ->assertSee('bg-orange-bg text-orange', false)
        ->assertSee('Menunggu');

    $this->blade('<x-sth.stat-tile icon="clock" tone="orange" label="Menunggu" value="0" />')
        ->assertSee('Menunggu')
        ->assertSee('bg-gray-bg text-muted', false)
        ->assertDontSee('bg-orange-bg', false);

    $this->blade('<x-sth.stat-tile icon="lock" tone="red" label="Dibekukan" value="0" zero="hide" />')
        ->assertDontSee('Dibekukan');

    $this->blade('<x-sth.stat-tile icon="lock" tone="red" label="Dibekukan" value="2" zero="hide" />')
        ->assertSee('Dibekukan')
        ->assertSee('bg-red-bg text-red', false);

    $this->blade('<x-sth.stat-tile icon="calendar" tone="blue" label="Jadwal" value="0" zero="show" />')
        ->assertSee('bg-blue-bg text-blue', false);
});
