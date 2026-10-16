<?php

declare(strict_types=1);

use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\UserStatus;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\User;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;

/*
| RV1 (lead, 2026-10-11: "pastikan semua sistem berjalan lancar, jangan ada error yang sebenarnya simpel seperti di
| tombol Ikut tim"). That button did nothing, without a request and without an error: the page had rendered, but the
| button stood outside the element Livewire had taken as the component (LivewireRootTest keeps that one cause away).
|
| This test looks for the whole family of such mistakes on the pages themselves. With the demo data it opens every
| page an account of each role can reach by its menu and by the links on those pages, and checks what the browser
| would need for the controls on them to work:
| - the page answers 200 (a link a screen offers never ends in an error page);
| - every control with a wire: directive stands inside a Livewire component;
| - wire:click / wire:submit name a public method of that component, and wire:model one of its public properties;
| - a button that opens a dialog (data-sth-open) or submits a form by id (form="…") has that dialog or form on the page.
| It reads HTML only: what a click then does is the business of the component tests.
*/

uses(RefreshDatabase::class);

/** Directives that only work on an element inside a Livewire component. */
const RV1_NEEDS_COMPONENT = ['click', 'submit', 'model', 'change', 'input', 'keydown', 'keyup', 'blur', 'focus', 'poll', 'init', 'loading', 'target', 'dirty', 'confirm'];

/** Magic actions of Livewire: not methods of the component. */
const RV1_MAGIC = ['$refresh', '$set', '$toggle', '$dispatch', '$dispatchSelf', '$dispatchTo', '$parent', '$commit', '$js'];

/**
 * What is wrong with the wiring of one page; an empty list = nothing.
 *
 * @return list<string>
 */
function rv1WiringProblems(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $problems = [];

    $ids = [];

    foreach ($xpath->query('//*[@id]') as $node) {
        $ids[$node->nodeName][$node->getAttribute('id')] = true;
    }

    /** The class of the Livewire component an element belongs to, or null when it stands outside every component. */
    $componentOf = static function (DOMElement $element): ?string {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('wire:id')) {
                $name = json_decode($node->getAttribute('wire:snapshot'), true)['memo']['name'] ?? null;

                return is_string($name) ? app('livewire.factory')->resolveComponentClass($name) : 'unknown';
            }
        }

        return null;
    };

    $describe = static fn (DOMElement $element): string => '<'.$element->nodeName.'> "'.mb_substr(trim(preg_replace('/\s+/', ' ', $element->textContent)), 0, 40).'"';

    foreach ($xpath->query('//*[@*]') as $element) {
        /** @var DOMElement $element */
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = $attribute->nodeName;
            $value = trim((string) $attribute->nodeValue);

            if ($name === 'data-sth-open' && ! isset($ids['dialog'][$value])) {
                $problems[] = $describe($element).' opens the dialog "'.$value.'", which is not on the page';
            }

            if ($name === 'form' && in_array($element->nodeName, ['button', 'input', 'select', 'textarea'], true) && ! isset($ids['form'][$value])) {
                $problems[] = $describe($element).' belongs to the form "'.$value.'", which is not on the page';
            }

            if (! str_starts_with($name, 'wire:')) {
                continue;
            }

            $directive = explode('.', substr($name, 5))[0];

            if (! in_array($directive, RV1_NEEDS_COMPONENT, true)) {
                continue;
            }

            $class = $componentOf($element);

            if ($class === null) {
                $problems[] = $describe($element).' has '.$name.'="'.$value.'" outside every Livewire component: it does nothing';

                continue;
            }

            if (in_array($directive, ['click', 'submit', 'change', 'keydown', 'keyup', 'blur', 'focus', 'poll', 'init'], true) && $value !== '') {
                preg_match('/^\$?[A-Za-z_]\w*/', $value, $call);
                $method = $call[0] ?? $value;

                if (! in_array($method, RV1_MAGIC, true) && ! (method_exists($class, $method) && (new ReflectionMethod($class, $method))->isPublic())) {
                    $problems[] = $describe($element).' calls '.$method.'(), which '.class_basename($class).' does not have';
                }
            }

            if ($directive === 'model') {
                $property = explode('.', $value)[0];

                if (! (property_exists($class, $property) && (new ReflectionProperty($class, $property))->isPublic())) {
                    $problems[] = $describe($element).' is bound to $'.$property.', which '.class_basename($class).' does not have';
                }
            }
        }
    }

    return array_values(array_unique($problems));
}

/** "/siswa/lomba/01jb…?tab=x" → "/siswa/lomba/{id}?tab": pages of one kind are opened a few times, not a hundred. */
function rv1Pattern(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $segments = array_map(
        fn (string $segment): string => match (true) {
            preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $segment) === 1 => '{id}',
            preg_match('/^\d+$/', $segment) === 1 => '{n}',
            default => $segment,
        },
        explode('/', trim($path, '/')),
    );

    if (($segments[0] ?? '') === 'prestasi' && ($segments[1] ?? '') === 'siswa' && isset($segments[2])) {
        $segments[2] = '{slug}';
    }

    $keys = array_keys($query);
    sort($keys);

    return '/'.implode('/', $segments).($keys === [] ? '' : '?'.implode('&', $keys));
}

/**
 * Open $start and what those pages link to (same site, GET only), at most $perPattern pages of one kind.
 *
 * @param  list<string>  $start
 * @return array{pages: int, problems: list<string>} pages = HTML pages that were read (not redirects or downloads)
 */
function rv1Crawl(object $test, ?User $user, string $label, array $start, int $perPattern = 2, int $cap = 90): array
{
    $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    $queue = array_map(fn (string $url): array => [$url, 'menu'], $start);
    $seen = [];
    $patterns = [];
    $problems = [];
    $requests = 0;
    $pages = 0;

    while ($queue !== [] && $requests < $cap) {
        [$url, $from] = array_shift($queue);
        $url = (string) preg_replace('/#.*$/', '', $url);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $relative = $path.(parse_url($url, PHP_URL_QUERY) !== null ? '?'.parse_url($url, PHP_URL_QUERY) : '');

        if (isset($seen[$relative]) || $path === '') {
            continue;
        }

        $pattern = rv1Pattern($relative);

        if (($patterns[$pattern] ?? 0) >= $perPattern) {
            continue;
        }

        $seen[$relative] = true;
        $patterns[$pattern] = ($patterns[$pattern] ?? 0) + 1;

        $user !== null ? $test->actingAs($user) : null;
        $response = $test->get($relative);
        $status = $response->getStatusCode();
        $requests++;

        if ($response->isRedirection()) {
            $target = (string) $response->headers->get('Location');
            $targetPath = (string) parse_url($target, PHP_URL_PATH);

            // An account of this test never lands on the login or the activation page: there the crawl would see
            // nothing of the screens it is meant to open, and still count the page.
            if ($user !== null && in_array($targetPath, ['/login', '/aktivasi'], true)) {
                $problems[] = "{$label}: {$relative} (from {$from}) sends a signed-in, activated account to {$targetPath}";
            } elseif (parse_url($target, PHP_URL_HOST) === null || parse_url($target, PHP_URL_HOST) === $host) {
                $queue[] = [$target, $relative];
            }

            continue;
        }

        if ($status !== 200) {
            $problems[] = "{$label}: {$relative} (linked from {$from}) answers {$status}";

            continue;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            continue;
        }

        $html = (string) $response->getContent();
        // Only a page that was read counts: a redirect or a download proves nothing about the controls.
        $pages++;

        foreach (rv1WiringProblems($html) as $problem) {
            $problems[] = "{$label}: {$relative}: {$problem}";
        }

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        foreach ((new DOMXPath($dom))->query('//a[@href]') as $link) {
            $href = trim((string) $link->getAttribute('href'));
            $linkHost = parse_url($href, PHP_URL_HOST);
            $linkPath = (string) parse_url($href, PHP_URL_PATH);

            if ($href === '' || $href[0] === '#' || preg_match('/^(mailto|tel|javascript|data):/i', $href) === 1
                || ($linkHost !== null && $linkHost !== $host)
                // Downloads, the dev showcase, the letter check of other people's codes and the way out.
                || preg_match('#^/(logout|berkas|_dev|verifikasi-surat|build|storage)(/|$)#', $linkPath) === 1
                // The public pages are the same for everyone: the guest walks through them once.
                || ($user !== null && preg_match('#^/(prestasi|peringkat)(/|$)#', $linkPath) === 1)
                || $link->hasAttribute('download')) {
                continue;
            }

            $queue[] = [$href, $relative];
        }
    }

    return ['pages' => $pages, 'problems' => $problems];
}

it('finds no dead control in a page text: the checker itself', function (): void {
    // Outside a component, an unknown dialog and an unknown form: each is reported, in words a person can act on.
    $problems = rv1WiringProblems('<main><section wire:id="x" wire:snapshot="{}"><p>Poster</p></section>'
        .'<button wire:click="ikut">Ikut tim</button>'
        .'<button data-sth-open="tidak-ada">Buka</button>'
        .'<button form="form-hilang">Kirim</button>'
        .'<dialog id="ada"></dialog><button data-sth-open="ada">Buka yang ada</button>'
        .'<a href="/x" wire:navigate>Tautan</a></main>');

    expect($problems)->toBe([
        '<button> "Ikut tim" has wire:click="ikut" outside every Livewire component: it does nothing',
        '<button> "Buka" opens the dialog "tidak-ada", which is not on the page',
        '<button> "Kirim" belongs to the form "form-hilang", which is not on the page',
    ]);
});

it('opens every page each role can reach with the demo data and finds every control wired', function (): void {
    $this->withoutVite();
    $this->withoutMiddleware(ThrottleRequests::class);
    config(['fortify.sth.require_staff_2fa' => false]);

    // The demo data is written for the first days of October 2026 (fixed dates): on that day its deadlines are still
    // open, so the pages show their buttons ("Ikut tim" needs an invitation whose lomba still takes registrations).
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Jakarta'));

    app()['env'] = 'local';
    $this->seed(DatabaseSeeder::class);
    app()['env'] = 'testing';

    // The demo students wait for their activation; the pages behind it are what this test is about. What the
    // first-login flow leaves behind (DetermineActivationStep::finished()): a verified address, no forced password
    // change, status active.
    foreach (User::query()->where('status', UserStatus::PendingActivation)->whereHas('student')->get() as $pending) {
        $pending->forceFill([
            'email' => 'siswa.'.$pending->getKey().'@example.test',
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'activated_at' => now(),
            'password_changed_at' => now(),
        ])->save();
    }

    $menu = function (User $user, string $role): array {
        $urls = [];

        foreach (Navigation::for($user, $role)['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if (is_string($item['href'] ?? null)) {
                    $urls[] = $item['href'];
                }
            }
        }

        return array_values(array_unique(['/beranda', ...$urls, '/notifikasi', '/akun/keamanan']));
    };

    $accounts = [];

    foreach (['kesiswaan' => 'kesiswaan', 'kepsek' => 'kepala_sekolah', 'tu' => 'tata_usaha', 'humas' => 'humas', 'operator' => 'admin', 'retno.w' => 'guru'] as $username => $role) {
        $accounts[$username] = [User::query()->where('username', $username)->firstOrFail(), $role];
    }

    // Students in different places of the flow: the ketua of one registration per status, and an invitee who has not answered.
    foreach (RegistrationStatus::cases() as $status) {
        $leader = Registration::query()->where('status', $status)->with('leader.user')->oldest('id')->first()?->leader?->user;

        if ($leader !== null && $leader->canLogIn()) {
            $accounts['siswa:'.$status->value.':'.$leader->getKey()] ??= [$leader, 'siswa'];
        }
    }

    $invitee = RegistrationMember::query()
        ->where('role', MemberRole::Anggota)->whereNull('confirmed_at')->whereNull('declined_at')
        ->whereHas('registration', fn ($q) => $q->where('status', RegistrationStatus::MenungguKonfirmasi))
        ->with('student.user')->first()?->student?->user;

    if ($invitee !== null) {
        $accounts['siswa:diundang:'.$invitee->getKey()] = [$invitee, 'siswa'];
    }

    $problems = [];
    $pages = 0;
    $crawled = [];

    // Guest first: the public pages, the login page and the way back to a forgotten password.
    $result = rv1Crawl($this, null, 'tamu', ['/', '/prestasi', '/peringkat', '/login', '/lupa-kata-sandi']);
    $pages += $result['pages'];
    $problems = [...$problems, ...$result['problems']];

    foreach ($accounts as $label => [$user, $role]) {
        if (isset($crawled[$user->getKey()])) {
            continue;
        }

        $crawled[$user->getKey()] = true;
        $result = rv1Crawl($this, $user, $label, $menu($user, $role));
        $pages += $result['pages'];
        $problems = [...$problems, ...$result['problems']];
    }

    // Every staff role and several students were really walked through: a crawl that opened little proves little.
    // The two numbers follow the demo data (six staff accounts, students in six places of the flow, about 190
    // requests): when DemoSeeder is trimmed on purpose, lower them here rather than loosen the checks above.
    expect($problems)->toBe([])
        ->and(count($crawled))->toBeGreaterThanOrEqual(9)
        ->and($pages)->toBeGreaterThan(120);
});
