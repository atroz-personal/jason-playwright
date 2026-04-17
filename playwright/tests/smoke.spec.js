const { test, expect } = require('@playwright/test');

test('homepage responds and shows WordPress content', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/Mi WordPress/i);
  await expect(page.locator('body')).toContainText(/Mi WordPress|Hello world!/i);
});

test('wp-admin login page is reachable', async ({ page }) => {
  await page.goto('/wp-login.php');
  await expect(page).toHaveURL(/wp-login\.php/);
  await expect(page.locator('#user_login')).toBeVisible();
  await expect(page.locator('#user_pass')).toBeVisible();
});
