// Smoke test of the running stack (backlog #22): public home, demo student login, siswa beranda, logout.
// Demo data = DemoSeeder (APP_ENV=local, fictional data of DESIGN-SPEC-v2 §5). The student account is prepared by
// tests/E2E/support/prepare-student.php with E2E_STUDENT_PASSWORD, so the initial password is never used here.
import { expect, test } from '@playwright/test';

const nis = process.env.E2E_STUDENT_NIS ?? '225226201';
const password = process.env.E2E_STUDENT_PASSWORD ?? '';

test('public home answers 200', async ({ page }) => {
    const response = await page.goto('/');

    expect(response?.status()).toBe(200);
    await expect(page.getByRole('link', { name: 'Masuk' }).first()).toBeVisible();
});

test('demo student logs in, sees the beranda and logs out', async ({ page }) => {
    expect(password, 'E2E_STUDENT_PASSWORD must be set (see tests/E2E/compose.e2e.yaml)').not.toBe('');

    await page.goto('/login');
    await page.getByLabel('NIS / NISN').fill(nis);
    await page.getByLabel('Kata sandi', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();

    await expect(page).toHaveURL(/\/siswa\/?$/);

    // First visit: the menu tour (Siswa\TurMenu) opens as a modal; "Lewati" stores tour_seen_at via Livewire.
    const tour = page.getByRole('dialog', { name: 'Tur singkat menu' });
    await expect(tour).toBeVisible();
    await tour.getByRole('button', { name: 'Lewati' }).click();
    await expect(tour).toBeHidden();

    await expect(page.locator('#h-halo')).toHaveText(/^Halo, Nadia\b/);

    await page.getByRole('button', { name: /^Akun / }).click();
    await page.getByRole('button', { name: 'Keluar' }).click();
    await expect(page).not.toHaveURL(/\/siswa/);

    // The session is gone: the student area sends us back to the login page.
    await page.goto('/siswa');
    await expect(page).toHaveURL(/\/login$/);
});
