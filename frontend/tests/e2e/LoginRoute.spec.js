import { expect, test } from '@playwright/test'

test.describe('login route', () => {
    test('resolves and renders the login form', async ({ page }) => {
        await page.goto('/')

        await expect(page).toHaveTitle(/Laravel App/i)
        await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible()
        await expect(page.getByLabel('Email address')).toBeVisible()
        await expect(page.getByLabel('Password')).toBeVisible()
        await expect(page.getByRole('button', { name: 'Sign in' })).toBeEnabled()
    })
})
