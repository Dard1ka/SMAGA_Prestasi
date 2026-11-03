<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Livewire script as a browser loads it (SV1): the address the page printed with @livewireScripts, and the
 * JavaScript that address answers. Checking the printed address, not a path built in the test, is what catches a page
 * that names a script route Livewire never registered.
 */
final class LivewireScript
{
    /** The src of the Livewire script tag in a page, HTML entities decoded; null when the page has none. */
    public static function src(string $html): ?string
    {
        if (preg_match('#<script\b[^>]*\bsrc="([^"]*/livewire-[^"/]+/livewire(?:\.min)?\.js[^"]*)"#', $html, $match) !== 1) {
            return null;
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    /** The body of a script response (Livewire answers with a file response). */
    public static function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof BinaryFileResponse
            ? (string) file_get_contents($response->baseResponse->getFile()->getPathname())
            : (string) $response->getContent();
    }
}
