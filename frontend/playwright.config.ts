import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45_000,
  expect: {
    timeout: 10_000
  },
  outputDir: 'test-results',
  reporter: [['line']],
  use: {
    ...devices['Desktop Chrome'],
    baseURL: process.env.E2E_BASE_URL ?? 'http://nginx',
    screenshot: 'only-on-failure',
    trace: 'off',
    video: 'off'
  }
})
