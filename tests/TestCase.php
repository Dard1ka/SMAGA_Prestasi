<?php

namespace Tests;

use App\Domain\Letters\PdfRenderer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDisks;

abstract class TestCase extends BaseTestCase
{
    /** This test's parent folder of PdfRenderer sandboxes (instead of the shared storage/app/pdf-tmp). */
    protected string $pdfSandboxRoot;

    /**
     * Every Feature test gets its own empty `local` + `public` disk and PDF sandbox folder, so test runs at
     * the same time never see or delete each other's files (never Storage::fake(), see Tests\Support\TestDisks).
     */
    protected function setUp(): void
    {
        parent::setUp();

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
}
