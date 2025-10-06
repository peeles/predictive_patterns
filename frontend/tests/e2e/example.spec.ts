import { test, expect } from '@playwright/test'

test.describe('Smoke test', () => {
  test('loads landing page', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveTitle(/Predictive/i)
  })
})
