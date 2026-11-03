<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Facade;

/**
 * Runtime switches that are read once per application boot (env → config → routes), e.g. STH_STAFF_2FA and
 * STH_DEV_PAGES. phpunit.xml forces both to their defaults, so a test of the "off" state must change the process
 * environment BEFORE an application boots and put it back right after (config is already built then):
 *
 * - with():    run a closure with some variables set in getenv(), $_ENV and $_SERVER (the three places Laravel's
 *              Env repository reads), then restore them exactly, also when the closure throws.
 * - bootApp(): boot a second, separate application with those variables (e.g. APP_ENV=local, which
 *              Tests\TestCase::createApplication() refuses on purpose), for checks of its config and routes only.
 *              It skips HandleExceptions (no extra error handler for PHPUnit to report) and afterwards points the
 *              container, the facades and Eloquent back at the test's own application. Never send requests or
 *              touch the database through it.
 *
 * For a whole test file in an "off" state, use a trait that overrides createApplication() and wraps
 * parent::createApplication() in with() (see tests/Feature/Auth/StaffTwoFactorSwitchTest.php): the normal
 * TestCase setUp, RefreshDatabase and the per-test disks then work on that application as usual.
 */
final class ProcessEnv
{
    /**
     * @template T
     *
     * @param  array<string, string>  $variables
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function with(array $variables, Closure $callback): mixed
    {
        $before = [];

        foreach ($variables as $name => $value) {
            $before[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        try {
            return $callback();
        } finally {
            foreach ($before as $name => [$process, $env, $server]) {
                putenv($process === false ? $name : $name.'='.$process);
                unset($_ENV[$name], $_SERVER[$name]);

                if ($env !== null) {
                    $_ENV[$name] = $env;
                }

                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    /** @param  array<string, string>  $variables */
    public static function bootApp(array $variables): Application
    {
        $current = Container::getInstance();

        try {
            return self::with($variables, function (): Application {
                /** @var Application $app */
                $app = require base_path('bootstrap/app.php');
                $app->bootstrapWith([
                    LoadEnvironmentVariables::class,
                    LoadConfiguration::class,
                    RegisterFacades::class,
                    RegisterProviders::class,
                    BootProviders::class,
                ]);

                return $app;
            });
        } finally {
            Container::setInstance($current);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($current);

            if ($current->bound('db')) {
                Model::setConnectionResolver($current['db']);
            }

            if ($current->bound('events')) {
                Model::setEventDispatcher($current['events']);
            }
        }
    }
}
