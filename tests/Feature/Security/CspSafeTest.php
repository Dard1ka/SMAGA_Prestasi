<?php

declare(strict_types=1);

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Support\TalentGate;

uses(RefreshDatabase::class);

/*
 | Backlog #17: Livewire runs in CSP-safe mode (its Alpine parses expressions, no eval), so no response may
 | carry 'unsafe-eval' in its Content-Security-Policy, and no view may use an expression the CSP parser cannot
 | handle (arrow functions, template literals, ??, ?., globals, DOM writes, several statements).
 */

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();
});

/** Directives whose value Livewire/Alpine evaluates as an expression. */
const B2_EXPRESSION_ATTRIBUTE = '/\s((?:wire:(?:click|submit|change|input|blur|focus|keydown|keyup|keypress|init|poll|intersect|show|text|bind|model\.(?:live\.)?(?:blur|change))[\w.:-]*)|(?:x-(?!cloak|ref|transition|ignore|teleport)[\w.:-]+)|(?:@[a-z][\w.:-]*))="([^"]*)"/';

/** @return list<array{file: string, attribute: string, expression: string}> */
function b2ViewExpressions(): array
{
    $found = [];

    foreach (Filesystem::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        // Blade echoes become a plain identifier/literal for the check.
        $source = (string) preg_replace('/\{\{.*?\}\}/s', 'x', $source);

        preg_match_all(B2_EXPRESSION_ATTRIBUTE, $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $found[] = ['file' => $file->getRelativePathname(), 'attribute' => $m[1], 'expression' => $m[2]];
        }
    }

    return $found;
}

function b2CspProblem(string $attribute, string $expression): ?string
{
    $expr = trim($expression);
    $checks = [
        '/=>/' => 'arrow function',
        '/`/' => 'template literal',
        '/\?\?/' => 'nullish operator ??',
        '/\?\./' => 'optional chaining ?.',
        '/\b(window|document|globalThis|console|localStorage|sessionStorage|navigator|location|eval|Function|setTimeout|setInterval|fetch)\b/' => 'global object',
        '/\bfunction\b|\bnew\s|\bthis\b|\btypeof\b|\binstanceof\b/' => 'unsupported keyword',
        '/\$(el|refs)(\.\w+)*\.\w+\s*=[^=]/' => 'DOM property write',
        '/;\s*\S/' => 'several statements',
    ];

    foreach ($checks as $pattern => $problem) {
        if (preg_match($pattern, $expr) === 1) {
            return $problem;
        }
    }

    // wire:* actions must be a method call (with literal arguments) or a magic action ($set, $refresh, $toggle, …).
    if (str_starts_with($attribute, 'wire:') && $expr !== '' && preg_match('/^\$?[A-Za-z_][\w.]*(\(.*\))?$/s', $expr) !== 1) {
        return 'not a method call';
    }

    return null;
}

it('turns on Livewire CSP-safe mode', function (): void {
    expect(config('livewire.csp_safe'))->toBeTrue();
});

it('serves the CSP build of Livewire', function (): void {
    $response = $this->get(EndpointResolver::scriptPath());

    $response->assertOk();
    $content = $response->baseResponse instanceof BinaryFileResponse
        ? (string) file_get_contents($response->baseResponse->getFile()->getPathname())
        : (string) $response->getContent();

    expect($content)->toContain('prohibited in the CSP build');
});

it('never sends unsafe-eval on any page (every parameterless GET route, as a guest)', function (): void {
    $checked = 0;

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
            continue;
        }

        $response = $this->get('/'.ltrim($route->uri(), '/'));
        $csp = (string) $response->headers->get('Content-Security-Policy');

        expect($csp)->not->toContain('unsafe-eval', "GET /{$route->uri()}");

        if ($csp !== '' && ! str_contains($csp, 'sandbox')) {
            expect($csp)->toContain("script-src 'self' 'nonce-");
        }

        $checked++;
    }

    expect($checked)->toBeGreaterThan(20);
});

it('renders Livewire screens of several roles without unsafe-eval', function (string $role, string $routeName): void {
    $user = F::staff(RoleName::from($role));

    $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route($routeName));

    $response->assertOk();
    expect((string) $response->headers->get('Content-Security-Policy'))->not->toContain('unsafe-eval')
        ->and($response->getContent())->toContain('wire:snapshot');
})->with([
    'kesiswaan import' => ['kesiswaan', 'kesiswaan.import'],
    'kesiswaan lomba' => ['kesiswaan', 'kesiswaan.lomba.index'],
    'admin pengaturan' => ['admin', 'admin.pengaturan'],
    'kepsek persetujuan' => ['kepala_sekolah', 'kepsek.persetujuan.index'],
]);

it('renders student and public Livewire pages without unsafe-eval, loading the CSP build', function (string $routeName, bool $asStudent): void {
    $student = F::student('Nadia Putri Anindya');
    TalentGate::pass($student); // D-153: else the talent gate holds every siswa page
    $response = $asStudent ? $this->actingAs($student->user)->get(route($routeName)) : $this->get(route($routeName));

    $response->assertOk();
    expect((string) $response->headers->get('Content-Security-Policy'))->not->toContain('unsafe-eval')
        // Own cache key for the CSP build, so no browser keeps running a cached eval build.
        ->and($response->getContent())->toContain(EndpointResolver::scriptPath().'?build=csp&id=');
})->with([
    'siswa beranda' => ['siswa.beranda', true],
    'siswa katalog' => ['siswa.lomba.index', true],
    'publik beranda' => ['public.home', false],
    'publik peringkat' => ['public.peringkat', false],
]);

it('uses only CSP-safe expressions in wire:* / x-* / @ attributes of every view', function (): void {
    $expressions = b2ViewExpressions();
    $problems = [];

    foreach ($expressions as $e) {
        if (($problem = b2CspProblem($e['attribute'], $e['expression'])) !== null) {
            $problems[] = "{$e['file']}: {$e['attribute']}=\"{$e['expression']}\" ({$problem})";
        }
    }

    expect($expressions)->not->toBeEmpty()
        ->and($problems)->toBe([]);
});

it('recognises expressions the CSP build cannot evaluate', function (string $attribute, string $expression): void {
    expect(b2CspProblem($attribute, $expression))->not->toBeNull();
})->with([
    ['x-on:click', 'items.forEach(i => i.done = true)'],
    ['x-text', '`Halo ${name}`'],
    ['x-show', 'user?.name'],
    ['x-text', 'count ?? 0'],
    ['@click', 'window.print()'],
    ['x-init', '$el.innerHTML = html'],
    ['@click', 'open = true; save()'],
    ['wire:click', "foo('a') + bar"],
]);

it('accepts the expression shapes the screens use', function (string $attribute, string $expression): void {
    expect(b2CspProblem($attribute, $expression))->toBeNull();
})->with([
    ['wire:click', 'save'],
    ['wire:click', "select('01J9ZQ')"],
    ['wire:click', "\$set('filter', 'bad')"],
    ['wire:click', 'kembali(3)'],
    ['wire:click', "openStaff(5, 'staf-reset')"],
    ['x-show', 'open && count > 0'],
    ['wire:poll.2s', ''],
]);
