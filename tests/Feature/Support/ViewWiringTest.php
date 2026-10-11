<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\Finder\Finder;

/*
| RV1 (lead, 2026-10-11), the reading half of PageWiringTest. That test opens the pages; many controls only exist in
| a state it never reaches (a dialog after "ask", a form after an error, a row in a rare status). Here the Blade
| sources themselves are read, so every branch counts:
| - a wire:click / wire:submit names a public method of the Livewire component that renders the view (also through
|   its partials), a wire:model one of its public properties;
| - a data-sth-… hook used in a view is one the script (resources/js/app.js) knows;
| - a dialog opened by data-sth-open exists somewhere in the views;
| - a route('…') names a route that exists, and an @include names a view that exists.
| A control that fails one of these does nothing, or answers with an error page, the day its branch is shown.
*/

/** Blade source without its comments. */
function rv1Source(string $path): string
{
    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
}

/** "livewire.kepsek.persetujuan" → the file of that view, or null. */
function rv1ViewFile(string $view): ?string
{
    $path = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');

    return is_file($path) ? $path : null;
}

/**
 * Every Livewire component class with the view files it renders: the views its PHP file names and, from there, every
 * partial they include.
 *
 * @return array<class-string, list<string>>
 */
function rv1ComponentViews(): array
{
    $map = [];

    foreach (Finder::create()->files()->in(app_path('Livewire'))->name('*.php') as $file) {
        $class = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], str_replace('\\', '/', $file->getRelativePathname()));

        if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
            continue;
        }

        preg_match_all("/view\\(\\s*'(livewire\\.[a-z0-9._-]+)'/", $file->getContents(), $views);
        $queue = array_values(array_unique($views[1]));
        $files = [];

        while ($queue !== []) {
            $path = rv1ViewFile((string) array_shift($queue));

            if ($path === null || in_array($path, $files, true)) {
                continue;
            }

            $files[] = $path;
            preg_match_all("/@include(?:If|When|Unless|First)?\\((?:[^,()']*,\\s*)?\\[?\\s*'([a-z0-9._-]+)'/", rv1Source($path), $includes);
            $queue = [...$queue, ...$includes[1]];
        }

        if ($files !== []) {
            $map[$class] = $files;
        }
    }

    return $map;
}

it('names only methods and properties its Livewire component has, in every branch of every view', function (): void {
    $magic = ['$refresh', '$set', '$toggle', '$dispatch', '$dispatchSelf', '$dispatchTo', '$parent', '$commit', '$js'];
    $problems = [];
    $checked = 0;
    $map = rv1ComponentViews();

    foreach ($map as $class => $files) {
        foreach ($files as $path) {
            $source = rv1Source($path);
            $name = Str::after(str_replace('\\', '/', $path), 'resources/views/');

            preg_match_all('/wire:(click|submit|change|keydown|keyup|blur|focus|poll|init)(?:\.[a-z0-9.\-]+)*="([^"]*)"/i', $source, $calls, PREG_SET_ORDER);

            foreach ($calls as [, $directive, $value]) {
                $value = trim($value);

                // A name that is only known when the page is drawn ("{{ $resumeDownload }}") cannot be read here.
                if ($value === '' || str_starts_with($value, '{{') || str_starts_with($value, '{!!')) {
                    continue;
                }

                preg_match('/^\$?[A-Za-z_]\w*/', $value, $call);
                $method = $call[0] ?? $value;
                $checked++;

                if (! in_array($method, $magic, true) && ! (method_exists($class, $method) && (new ReflectionMethod($class, $method))->isPublic())) {
                    $problems[] = $name.': wire:'.$directive.'="'.$value.'" calls '.$method.'(), which '.$class.' does not have';
                }
            }

            preg_match_all('/wire:model(?:\.[a-z0-9.\-]+)*="([^"]*)"/i', $source, $models);

            foreach ($models[1] as $value) {
                $value = trim($value);

                if ($value === '' || str_starts_with($value, '{{')) {
                    continue;
                }

                $property = explode('.', $value)[0];
                $checked++;

                if (! (property_exists($class, $property) && (new ReflectionProperty($class, $property))->isPublic())) {
                    $problems[] = $name.': wire:model="'.$value.'" is bound to $'.$property.', which '.$class.' does not have';
                }
            }
        }
    }

    // Nearly every screen is a component with a view of its own: a scan that found few of them read the wrong folder.
    expect($problems)->toBe([])
        ->and(count($map))->toBeGreaterThan(50)
        ->and($checked)->toBeGreaterThan(300);
});

it('uses only data-sth hooks the script knows', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));
    $unknown = [];
    $used = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        preg_match_all('/\bdata-sth-[a-z0-9-]+/', rv1Source($file->getPathname()), $hooks);

        foreach (array_unique($hooks[0]) as $hook) {
            $used[$hook] = true;
            // The script reads a hook by its attribute name or by the dataset key (data-sth-on-close → sthOnClose).
            // The whole name must stand there: "data-sth-cop" is not known because "data-sth-copy" is.
            $dataset = Str::camel(Str::after($hook, 'data-'));
            $known = preg_match('/'.preg_quote($hook, '/').'(?![a-z0-9-])/', $script) === 1
                || preg_match('/\b'.preg_quote($dataset, '/').'(?![A-Za-z0-9])/', $script) === 1;

            if (! $known) {
                $unknown[$hook][] = $file->getRelativePathname();
            }
        }
    }

    expect($unknown)->toBe([])
        ->and(count($used))->toBeGreaterThan(15);
});

/**
 * The opening tags of the given elements, each from "<name" to the ">" that ends it. A ">" inside an attribute value
 * (:title="$row->name") does not end the tag.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function rv1OpeningTags(string $source, array $names): array
{
    $tags = [];
    $pattern = '/<(?:'.implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), $names)).')(?![A-Za-z0-9.\-])/';

    preg_match_all($pattern, $source, $starts, PREG_OFFSET_CAPTURE);

    foreach ($starts[0] as [, $offset]) {
        $quote = null;

        for ($i = $offset, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                $tags[] = substr($source, $offset, $i - $offset + 1);

                break;
            }
        }
    }

    return $tags;
}

it('opens only dialogs that exist', function (): void {
    $opened = [];
    $dialogs = [];
    $all = '';

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        $source = rv1Source($file->getPathname());
        $all .= "\n".$source;

        preg_match_all('/data-sth-open="([a-z0-9-]+)"/', $source, $buttons);

        foreach ($buttons[1] as $id) {
            $opened[$id][] = $file->getRelativePathname();
        }
    }

    // A dialog names its id as a literal (id="usul") or through a constant of its component (:id="…::BULK_DIALOG"),
    // anywhere among its attributes.
    foreach (rv1OpeningTags($all, ['x-sth.modal', 'dialog']) as $tag) {
        if (preg_match('/\sid="([a-z0-9-]+)"/', $tag, $literal) === 1) {
            $dialogs[$literal[1]] = true;
        }

        if (preg_match('/\s:id="\\\\?([A-Za-z\\\\]+)::([A-Z_]+)"/', $tag, $constant) === 1) {
            $dialogs[(string) constant($constant[1].'::'.$constant[2])] = true;
        }
    }

    $missing = array_diff_key($opened, $dialogs);

    expect($missing)->toBe([])
        ->and(count($opened))->toBeGreaterThan(10)
        // The tag reader itself: an attribute with "->" before the id must not hide the id.
        ->and(rv1OpeningTags('<x-sth.modal :title="$row->name" id="x" size="sm"><p>', ['x-sth.modal']))
        ->toBe(['<x-sth.modal :title="$row->name" id="x" size="sm">']);
});

it('compiles every Blade view to PHP that parses', function (): void {
    $broken = [];
    $count = 0;

    foreach (Finder::create()->files()->in([resource_path('views'), resource_path('pdf'), database_path('seeders/demo')])->name('*.blade.php') as $file) {
        $count++;

        try {
            // TOKEN_PARSE makes the tokenizer run the parser too: an unclosed @if or a broken expression throws here,
            // long before somebody opens the one screen that includes the view.
            token_get_all(Blade::compileString($file->getContents()), TOKEN_PARSE);
        } catch (Throwable $e) {
            $broken[] = $file->getRelativePathname().': '.$e->getMessage();
        }
    }

    expect($broken)->toBe([])
        ->and($count)->toBeGreaterThan(150);
});

it('names only routes and views that exist', function (): void {
    $problems = [];
    $routes = 0;

    $sources = Finder::create()->files()->in([resource_path('views'), app_path()])->name('*.php')
        // The dev showcase names routes of routes/dev.php, which only exist in APP_ENV=local.
        ->notPath('dev');

    foreach ($sources as $file) {
        $source = str_ends_with($file->getFilename(), '.blade.php') ? rv1Source($file->getPathname()) : $file->getContents();
        $name = $file->getRelativePathname();

        // route('name', …) with a literal name, and the redirects after a click: redirect()->route('name'),
        // to_route('name'), $this->redirectRoute('name'). ($request->route('param') reads a parameter, not a name,
        // so a bare "->route(" is not read.) A name tried first with Route::has() is a screen that may not exist yet
        // by design (notification links): those calls pass their names as lists, never to route() directly.
        preg_match_all(
            "/(?:(?<![A-Za-z_>:])route|redirect\\(\\)->route|to_route|redirectRoute)\\(\\s*'([A-Za-z0-9._-]+)'/",
            $source,
            $named,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($named[1] as [$route, $offset]) {
            $routes++;

            if (! Route::has($route) && ! str_contains(substr($source, max(0, $offset - 200), 200), "Route::has('".$route."')")) {
                $problems[] = $name.': route(\''.$route.'\') does not exist';
            }
        }

        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all("/@include(?:If|When|Unless)?\\((?:[^,()']*,\\s*)?'([A-Za-z0-9._:-]+)'/", $source, $includes);

        foreach ($includes[1] as $view) {
            if (! view()->exists($view)) {
                $problems[] = $name.': @include(\''.$view.'\') does not exist';
            }
        }

        // Anonymous components of the design system: <x-sth.card> is resources/views/components/sth/card.blade.php.
        preg_match_all('/<x-(sth\.[a-z0-9.-]+)/', $source, $components);

        foreach (array_unique($components[1]) as $component) {
            if (! view()->exists('components.'.$component)) {
                $problems[] = $name.': <x-'.$component.'> does not exist';
            }
        }
    }

    expect($problems)->toBe([])
        ->and($routes)->toBeGreaterThan(150);
});
