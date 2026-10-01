<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\TestCase;

uses(RefreshDatabase::class);

/*
 | Backlog #18: Livewire's update endpoint (/livewire-<hash>/update) is throttled per user when logged in and
 | per client IP for guests, and answers 429 with Retry-After and a short Indonesian text.
 */

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();
    $this->uri = app('livewire')->getUpdateUri();
});

/** A request that reaches Livewire's handler (it answers 404 for an empty payload, after the limiter counted it). */
function b2LivewirePost(TestCase $test, string $uri, string $ip = '127.0.0.1')
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson($uri, ['components' => []], ['X-Livewire' => '1']);
}

it('registers the update endpoint once, with the livewire-update limiter', function (): void {
    $route = Route::getRoutes()->getByName('livewire.update');

    expect($route)->not->toBeNull()
        ->and('/'.ltrim($route->uri(), '/'))->toBe($this->uri)
        ->and($route->middleware())->toContain('web')
        ->and($route->middleware())->toContain('throttle:livewire-update')
        ->and(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('POST', $r->methods(), true) && '/'.ltrim($r->uri(), '/') === $this->uri))
        ->toHaveCount(1);
});

it('throttles guests per IP and answers 429 with Retry-After and a short Indonesian text', function (): void {
    config(['livewire.sth_throttle.guest_per_minute' => 3]);

    for ($i = 0; $i < 3; $i++) {
        b2LivewirePost($this, $this->uri)->assertNotFound();
    }

    $blocked = b2LivewirePost($this, $this->uri);

    $blocked->assertStatus(429)->assertHeader('Retry-After');
    expect($blocked->getContent())->toBe(AppServiceProvider::LIVEWIRE_THROTTLED_MESSAGE)
        ->and((string) $blocked->headers->get('Content-Type'))->toStartWith('text/plain');

    // Another client IP has its own bucket.
    b2LivewirePost($this, $this->uri, '10.20.30.40')->assertNotFound();
});

it('throttles logged-in users per account, independent of the IP bucket', function (): void {
    config(['livewire.sth_throttle.user_per_minute' => 2, 'livewire.sth_throttle.guest_per_minute' => 100]);
    $kesiswaan = F::staff(RoleName::Kesiswaan);
    $admin = F::staff(RoleName::Admin, 'Operator STH');

    $this->actingAs($kesiswaan);
    b2LivewirePost($this, $this->uri)->assertNotFound();
    b2LivewirePost($this, $this->uri)->assertNotFound();
    b2LivewirePost($this, $this->uri)->assertStatus(429);

    // Same IP, other account: not affected.
    $this->actingAs($admin);
    b2LivewirePost($this, $this->uri)->assertNotFound();
});

it('lets the user try again after the window', function (): void {
    config(['livewire.sth_throttle.guest_per_minute' => 1]);

    b2LivewirePost($this, $this->uri)->assertNotFound();
    b2LivewirePost($this, $this->uri)->assertStatus(429);

    $this->travel(61)->seconds();

    b2LivewirePost($this, $this->uri)->assertNotFound();
});

it('still serves a real Livewire round trip through the throttled endpoint', function (): void {
    $page = $this->get(route('password.request'))->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $m);
    expect($m)->not->toBeEmpty();

    $response = $this->postJson($this->uri, [
        'components' => [['snapshot' => html_entity_decode($m[1], ENT_QUOTES), 'updates' => (object) [], 'calls' => []]],
    ], ['X-Livewire' => '1']);

    $response->assertOk()->assertHeader('X-RateLimit-Limit', '120');
    expect($response->json('components.0.snapshot'))->toBeString();
});

it('uses the configured defaults: 180 per user, 120 per guest IP per minute', function (): void {
    $config = require base_path('config/livewire.php');

    expect($config['sth_throttle'])->toBe(['user_per_minute' => 180, 'guest_per_minute' => 120]);
});
