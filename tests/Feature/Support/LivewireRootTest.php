<?php

declare(strict_types=1);

use App\Livewire\Siswa\KonfirmasiTim;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

/*
| Livewire puts wire:id and the snapshot on the first tag that starts a line of the rendered view. A view whose root
| element sits inside an @if (or any directive that prints something) gets a marker in front of that tag, so the id
| lands on an inner element and every wire:click outside it does nothing: no request, no error. That is how "Ikut
| tim" on Undangan tim died (lead, 2026-10-11).
*/

it('starts every Livewire screen with its root element, not with a directive', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(resource_path('views/livewire'))->name('*.blade.php')->notPath('partials') as $file) {
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
        $source = (string) preg_replace('/@php\b(?!\s*\().*?@endphp/s', '', $source);
        // Lines that print nothing: @php(...) on one line and @use(...).
        $source = (string) preg_replace('/^\s*@(?:php|use)\(.*\)\s*$/m', '', $source);

        if (! str_starts_with(ltrim($source), '<')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the answer buttons of Undangan tim inside the Livewire root', function (): void {
    $invitee = Student::factory()->activated()->create();
    $registration = Registration::factory()->team()->create();
    RegistrationMember::factory()->create(['registration_id' => $registration->getKey(), 'student_id' => $invitee->getKey()]);

    $html = Livewire::actingAs($invitee->user)
        ->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSee('Ikut tim')
        ->html();

    // Read as a browser reads it: both buttons must stand inside the one element that carries wire:id.
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $inside = fn (string $method): int => $xpath
        ->query('//*[@*[name()="wire:id"]]//button[@*[name()="wire:click"]="'.$method.'"]')->length;
    $anywhere = fn (string $method): int => $xpath->query('//button[@*[name()="wire:click"]="'.$method.'"]')->length;

    expect($xpath->query('//*[@*[name()="wire:id"]]')->length)->toBe(1)
        ->and($anywhere('ikut'))->toBe(1)
        ->and($inside('ikut'))->toBe(1)
        ->and($anywhere('tolak'))->toBe(1)
        ->and($inside('tolak'))->toBe(1);
});
