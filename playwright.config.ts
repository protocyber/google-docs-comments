import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/Browser',
    timeout: 30_000,
    fullyParallel: true,
    reporter: [['list']],
    use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } },
});
