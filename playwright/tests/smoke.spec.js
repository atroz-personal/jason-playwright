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
const feApiUser = process.env.FE_API_USERNAME || envFile.FE_API_USERNAME || '';
const feApiPassword = process.env.FE_API_PASSWORD || envFile.FE_API_PASSWORD || '';
const feCertificatePath = process.env.FE_CERTIFICATE_PATH || envFile.FE_CERTIFICATE_PATH || '';
const feCertificatePin = process.env.FE_CERTIFICATE_PIN || envFile.FE_CERTIFICATE_PIN || '';

async function completeWpAdminLogin(page) {
  await expect(page.locator('#loginform')).toBeVisible();

  await page.context().clearCookies();
  await page.reload();
  await expect(page.locator('#loginform')).toBeVisible();

  const hasTestCookie = await page
    .context()
    .cookies()
    .then((cookies) => cookies.some((cookie) => cookie.name === 'wordpress_test_cookie'));

  if (!hasTestCookie) {
    throw new Error('WordPress test cookie was not set before login.');
  }

  await page.locator('#user_login').fill(adminUser);
  await page.locator('#user_pass').fill(adminPassword);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#wp-submit').click(),
  ]);

  try {
    await page.waitForURL(/wp-admin/, { timeout: 15_000 });
  } catch (error) {
    const loginError = page.locator('#login_error, .message');
    const message = (await loginError.first().textContent().catch(() => null)) || 'No login error message was rendered.';
    throw new Error(
      `WordPress login did not reach wp-admin. Current URL: ${page.url()}. Login message: ${message}`
    );
  }
}

async function loginToWpAdmin(page) {
  await page.goto('/wp-login.php');
  await completeWpAdminLogin(page);
}

async function gotoAdminPage(page, adminPath, readyPattern) {
  await page.goto(adminPath);

  if (/wp-login\.php/.test(page.url())) {
    await completeWpAdminLogin(page);
  }

  await expect(page).toHaveURL(readyPattern);
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

  await gotoAdminPage(page, '/wp-admin/post-new.php?post_type=product', /post-new\.php\?post_type=product/);
  await expect(page.locator('body')).toContainText(/Add new product|Create product|New product|Edit product/i);

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

  const permalinkField = page.locator('#sample-permalink a, .editor-post-permalink__link').first();
  if (await permalinkField.isVisible().catch(() => false)) {
    await expect(permalinkField).toContainText(/smoke-product/i);
  } else {
    await expect(titleField).toHaveValue(productName);
  }
});

test('add factura electronica emisor from WooCommerce settings', async ({ page }) => {
  test.skip(
    !feApiUser || !feApiPassword || !feCertificatePath || !feCertificatePin,
    'Factura Electronica test requires FE_API_USERNAME, FE_API_PASSWORD, FE_CERTIFICATE_PATH, and FE_CERTIFICATE_PIN.'
  );

  const unique = Date.now();
  const emisorName = `Emisor Playwright ${unique}`;
  const emisorTradeName = `Comercial ${unique}`;
  const emisorId = `3101${String(unique).slice(-6)}`;
  const actividadEconomica = '1234.5';
  const emisorEmail = `playwright+${unique}@example.com`;

  await page.goto('/wp-admin/admin.php?page=wc-settings&tab=fe&fe_action=new_emisor');

  if (/wp-login\.php/.test(page.url())) {
    await completeWpAdminLogin(page);
    await page.goto('/wp-admin/admin.php?page=wc-settings&tab=fe&fe_action=new_emisor');
  }

  test.skip(
    /page=wc-settings$/.test(page.url()),
    'Factura Electronica plugin/settings are not available in this environment.'
  );

  await expect(page).toHaveURL(/page=wc-settings&tab=fe&fe_action=new_emisor/);
  await expect(page.locator('body')).toContainText(/Configuración FE|Agregar Nuevo Emisor/i);

  await page.fill('#nombre_legal', emisorName);
  await page.fill('#cedula_juridica', emisorId);
  await page.fill('#nombre_comercial', emisorTradeName);
  await page.fill('#api_username', feApiUser);
  await page.fill('#api_password', feApiPassword);
  await page.setInputFiles('#certificate_file', feCertificatePath);
  await page.fill('#certificate_pin', feCertificatePin);
  await page.fill('#actividad_economica', actividadEconomica);
  await expect(page.locator('#actividad_economica')).toHaveValue(/^\d{4}\.\d$/);
  await page.fill('#codigo_provincia', '1');
  await page.fill('#codigo_canton', '01');
  await page.fill('#codigo_distrito', '01');
  await page.fill('#codigo_barrio', '01');
  await page.fill('#direccion', 'Direccion de prueba Playwright');
  await page.fill('#telefono', '22223333');
  await page.fill('#email', emisorEmail);

  if (!(await page.locator('#active').isChecked())) {
    await page.check('#active');
  }

  await Promise.all([
    page.waitForURL(/page=wc-settings&tab=fe(?:&|$)/),
    page.getByRole('button', { name: 'Guardar Emisor' }).click(),
  ]);

  await expect(page.locator('body')).toContainText(/Emisor creado correctamente\./i);
  await expect(page.locator('body')).toContainText(emisorName);
  await expect(page.locator('body')).toContainText(emisorId);
  await expect(page.locator('body')).toContainText(actividadEconomica);
});
