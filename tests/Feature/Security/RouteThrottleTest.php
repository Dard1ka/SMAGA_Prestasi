<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Auth\AuthTestHelpers;

/*
| QA5 retest (2026-10-07): each throttled route counts its own requests. Laravel keys an unnamed `throttle:N,M` by
| user or IP only, so the public pages (60/min), the letter check of the QR code (30), public certificate links (30)
| and "Bukan saya?" (20) shared one counter per visitor: browsing the public pages first made the QR check answer 429
| (many school visitors share one IP). The limits stay as they were; only the counters are separate.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
});

it('does not let public certificate links use up the letter check of the QR code', function (): void {
    for ($i = 0; $i < 31; $i++) {
        $this->get(route('files.public', (string) Str::ulid()));
    }

    // That limit itself still holds…
    $this->get(route('files.public', (string) Str::ulid()))->assertStatus(429);

    // …but the QR check has a counter of its own (an unknown code answers its own "tidak terdaftar" page, 404).
    expect($this->get(route('public.surat.verifikasi', str_repeat('a', 32)))->status())->not->toBe(429);
});

it('keeps the 30 per minute limit of the letter check', function (): void {
    for ($i = 0; $i < 30; $i++) {
        expect($this->get(route('public.surat.verifikasi', str_repeat('b', 32)))->status())->not->toBe(429);
    }

    $this->get(route('public.surat.verifikasi', str_repeat('b', 32)))->assertStatus(429);
});
