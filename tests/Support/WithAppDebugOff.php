<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * `uses(WithAppDebugOff::class)` in a Pest file: every test of that file runs on an application booted with
 * APP_DEBUG=false, as on the school's server and in production (SV1). Livewire decides at boot which script route it
 * registers (livewire.js with debug, livewire.min.js without), so the switch must be set before the boot, not with
 * config() inside a test. phpunit.xml forces APP_DEBUG=true for every other file; the env is put back as soon as the
 * app is built.
 */
trait WithAppDebugOff
{
    public function createApplication()
    {
        return ProcessEnv::with(['APP_DEBUG' => 'false'], fn () => parent::createApplication());
    }
}
