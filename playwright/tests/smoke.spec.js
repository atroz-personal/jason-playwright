const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

function readEnvFile() {
  const envPath = path.resolve(process.cwd(), '.env');

  if (!fs.existsSync(envPath)) {
    return {};
  }

  return Object.fromEntries(
    fs
      .readFileSync(envPath, 'utf8')
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line && !line.startsWith('#') && line.includes('='))
      .map((line) => {
        const separatorIndex = line.indexOf('=');
        const key = line.slice(0, separatorIndex).trim();
        const value = line.slice(separatorIndex + 1).trim();
        return [key, value];
      })
  );
}

const envFile = readEnvFile();
const adminUser = process.env.WORDPRESS_ADMIN_USER || envFile.WORDPRESS_ADMIN_USER || 'admin';
const adminPassword = process.env.WORDPRESS_ADMIN_PASSWORD || envFile.WORDPRESS_ADMIN_PASSWORD || 'changeme123';

async function loginToWpAdmin(page) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(adminUser);
  await page.locator('#user_pass').fill(adminPassword);
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/wp-admin/);
}

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

test('add product from wp-admin', async ({ page }) => {
  const productName = `Smoke Product ${Date.now()}`;

  await loginToWpAdmin(page);
  await page.goto('/wp-admin/post-new.php?post_type=product');

  await expect(page.locator('body')).toContainText(/Add new product|Create product|New product/i);

  const titleField = page.locator('input[name="post_title"], .editor-post-title__input, h1[contenteditable="true"]').first();
  await titleField.click();
  await titleField.fill(productName);

  const descriptionField = page.locator('[aria-label="Add description"], [role="textbox"][contenteditable="true"]').first();
  if (await descriptionField.isVisible().catch(() => false)) {
    await descriptionField.click();
    await descriptionField.fill('Product created by Playwright smoke test.');
  }

  const publishButton = page.getByRole('button', { name: /publish/i }).last();
  await publishButton.click();

  const confirmPublishButton = page.getByRole('button', { name: /publish/i }).last();
  if (await confirmPublishButton.isVisible().catch(() => false)) {
    await confirmPublishButton.click();
  }

  await expect(page.locator('body')).toContainText(/published|updated|Product published/i);
  await expect(page.locator('body')).toContainText(productName);
});
