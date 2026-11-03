<?php

declare(strict_types=1);

/*
| QA7 (explore round after UI3, 2026-10-08): one long word from a user (a lomba name, a penyelenggara, a place)
| must wrap inside the new UI3 cards instead of widening the page on a phone. In the browser at 390 px a 60-letter word
| pushed the capsule card 349 px and the lomba head 491 px out of the screen. The text columns inherit
| `overflow-wrap: anywhere` (it also lowers the column's min-content, which `overflow-wrap: break-word` would not).
| Fictional data only.
*/

const QA7_WORD = 'PanitiaNasionalOlimpiadeSainsTerapanUntukPelajarSMAdanSederajat2026';

it('wraps a long word in the capsule card of a lomba', function (): void {
    $this->blade('<x-sth.lomba-card :title="$t" href="/siswa/lomba/1" bidang="riset" seed="01abc" :penyelenggara="$t" date="7 Nov 2026" :place="$t" />', ['t' => QA7_WORD])
        ->assertSee(QA7_WORD)
        ->assertSee('class="flex min-w-0 flex-1 flex-col items-start gap-2 [overflow-wrap:anywhere]"', false);
});

it('wraps a long word in the head of a lomba page', function (): void {
    $this->blade('<x-sth.lomba-head bidang="riset" seed="01abc" alt="Poster"><h1>{{ $t }}</h1><p>{{ $t }}</p></x-sth.lomba-head>', ['t' => QA7_WORD])
        ->assertSee(QA7_WORD)
        ->assertSee('class="flex min-w-0 flex-col gap-2.5 [overflow-wrap:anywhere]"', false);
});

it('wraps a long lomba name on a card of the Humas queue instead of cutting it', function (): void {
    $card = [
        'id' => '01pub', 'title' => QA7_WORD, 'headline' => 'Juara 1 · Nasional', 'student' => 'Nadia Putri Anindya',
        'class' => 'XI-D', 'mentor' => 'Pembimbing Pak Hendra', 'when' => null, 'photo' => true, 'bidang' => 'riset',
        'seed' => '01abc', 'selected' => false, 'overdue' => false,
    ];

    $this->view('livewire.humas.partials.publication-card', ['card' => $card])
        ->assertSee(QA7_WORD)
        ->assertSee('class="flex flex-col gap-1 px-3 pt-3 pb-3.5 [overflow-wrap:anywhere]"', false);
});

it('breaks a word that does not fit its line anywhere on a page, without changing how boxes are sized (QA7)', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // break-word only acts on a word wider than its line; unlike anywhere it leaves min-content (tables, flex) alone.
    expect($css)->toMatch('/body \{[^}]*overflow-wrap: break-word;/');
});

it('wraps a long word in the title, subtitle and breadcrumb of a page header (QA7)', function (): void {
    $this->blade('<x-sth.page-header :title="$t" :subtitle="$t" :breadcrumbs="[[\'label\' => \'Siswa binaan\', \'href\' => \'#\'], [\'label\' => $t]]" />', ['t' => QA7_WORD])
        ->assertSee('class="flex min-w-0 flex-col gap-1 [overflow-wrap:anywhere]"', false);
});

it('lets a two-line tag break a word that is longer than its line (QA7)', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)->toMatch('/\.chip-wrap \{[^}]*overflow-wrap: anywhere;/')
        ->and($css)->toMatch('/\.chip-wrap \{[^}]*max-width: 100%;/');
});

it('never puts sr-only on a table: a table does not shrink to 1px and pushed the dashboard 111px sideways (QA7)', function (): void {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')
            && preg_match('/<table\b[^>]*\bsr-only\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    // Wrap the table in <div class="sr-only"> instead.
    expect($offenders)->toBe([]);
});
