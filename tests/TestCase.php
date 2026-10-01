<?php

namespace Tests;

use App\Domain\Letters\PdfRenderer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDisks;

abstract class TestCase extends BaseTestCase
{
    /** This test's parent folder of PdfRenderer sandboxes (instead of the shared storage/app/pdf-tmp). */
    protected string $pdfSandboxRoot;

    /** Compiled Blade/Livewire views of this PHP process (see isolateCompiledViews()). */
    private static ?string $compiledViewPath = null;

    /**
     * Every Feature test gets its own empty `local` + `public` disk and PDF sandbox folder, so test runs at
     * the same time never see or delete each other's files (never Storage::fake(), see Tests\Support\TestDisks).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateCompiledViews();

        TestDisks::isolate('local');
        TestDisks::isolate('public');

        $this->pdfSandboxRoot = TestDisks::directory('pdf-tmp');
        $this->app->when(PdfRenderer::class)->needs('$sandboxRoot')->give($this->pdfSandboxRoot);
    }

    protected function tearDown(): void
    {
        TestDisks::cleanup();

        parent::tearDown();
    }

    /**
     * W4: compiled views go to a folder of this PHP process only (storage/framework/testing/views/<pid>-<random>),
     * deleted when the process ends. Laravel's parallel default `storage/framework/views/test_<token>` is shared by
     * every run that uses the same token, and each worker deletes it when it finishes: two runs at the same time
     * (several containers on the same bind mount, CI + a developer) then compile into and delete each other's
     * folder — the Blade `tempnam()`/include races seen in the B1/B6 notes. Sequential runs no longer share
     * storage/framework/views with the dev web container either. The pid alone is not unique across containers.
     */
    private function isolateCompiledViews(): void
    {
        if (self::$compiledViewPath === null) {
            $path = storage_path(sprintf('framework/testing/views/%d-%s', getmypid(), bin2hex(random_bytes(6))));
            (new Filesystem)->ensureDirectoryExists($path);
            register_shutdown_function(static function () use ($path): void {
                (new Filesystem)->deleteDirectory($path);
            });
            self::$compiledViewPath = $path;
        }

        $path = self::$compiledViewPath;
        $this->app['config']->set('view.compiled', $path);

        if ($this->app->resolved('blade.compiler')) {
            (function () use ($path): void {
                $this->cachePath = $path;
            })->call($this->app['blade.compiler']);
        }

        if ($this->app->resolved('livewire.compiler')) {
            $this->app['livewire.compiler']->cacheManager->cacheDirectory = $path.'/livewire';
        }
    }
}
