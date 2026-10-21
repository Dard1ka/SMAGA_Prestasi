<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * `uses(WithoutStaffTwoFactor::class)` in a Pest file: every test of that file runs on an application booted with
 * STH_STAFF_2FA=false (2FA does not exist: no Fortify 2FA feature or routes, no /akun/2fa). The switch decides which
 * routes are registered, so it must be set before the boot, not with config() inside a test. The trait method
 * overrides Tests\TestCase::createApplication() for that file only; the env is put back as soon as the app is built.
 */
trait WithoutStaffTwoFactor
{
    public function createApplication()
    {
        return ProcessEnv::with(['STH_STAFF_2FA' => 'false'], fn () => parent::createApplication());
    }
}
