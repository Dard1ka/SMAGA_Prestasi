// Playwright config for the STH smoke tests (tests/E2E). Runs inside the Playwright container of
// tests/E2E/compose.e2e.yaml against the `web` service of the isolated E2E compose project.
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './specs',
    // Generous: the dev stack is not optimised (no OPcache tuning, bind mounts on Docker Desktop).
    timeout: 120_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    forbidOnly: Boolean(process.env.CI),
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    outputDir: 'test-results',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://web',
        locale: 'id-ID',
        timezoneId: 'Asia/Jakarta',
        navigationTimeout: 60_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } }],
});
