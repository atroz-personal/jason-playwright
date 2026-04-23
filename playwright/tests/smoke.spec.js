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
    await page.goto(adminPath);
  }

  if (/wp-login\.php/.test(page.url())) {
    await completeWpAdminLogin(page);
    await page.goto(adminPath);
  }

  await expect(page).toHaveURL(readyPattern);
}

async function createFacturaElectronicaEmisor(page, testInfo, { shouldBeDefault }) {
  test.skip(
    !feApiUser || !feApiPassword || !feCertificatePath || !feCertificatePin,
    'Factura Electronica test requires FE_API_USERNAME, FE_API_PASSWORD, FE_CERTIFICATE_PATH, and FE_CERTIFICATE_PIN.'
  );

  const unique = Date.now();
  const emisorName = `${shouldBeDefault ? 'Emisor Playwright Default' : 'Emisor Playwright Secundario'} ${unique}`;
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

  const existingEmittersTable = page.locator('body');
  const existingEmittersCount = await existingEmittersTable
    .getByRole('link', { name: /Probar|Editar|Eliminar/i })
    .count()
    .catch(() => 0);

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

  if (shouldBeDefault && existingEmittersCount === 0 && !(await page.locator('#is_parent').isChecked())) {
    await page.check('#is_parent');
  }

  if (!shouldBeDefault && await page.locator('#is_parent').isChecked()) {
    await page.uncheck('#is_parent');
  }

  if (!(await page.locator('#active').isChecked())) {
    await page.check('#active');
  }

  await Promise.all([
    page.waitForURL(/page=wc-settings&tab=fe(?:&|$)/),
    page.getByRole('button', { name: 'Guardar Emisor' }).click(),
  ]);

  if (shouldBeDefault && await page.locator('body').getByText(/Ya existe un emisor padre/i).isVisible().catch(() => false)) {
    await page.goto('/wp-admin/admin.php?page=wc-settings&tab=fe');
    return;
  }

  await expect(page.locator('body')).toContainText(/Emisor creado correctamente\./i);
  await expect(page.locator('body')).toContainText(emisorName);
  await expect(page.locator('body')).toContainText(emisorId);
  await expect(page.locator('body')).toContainText(actividadEconomica);

  if (testInfo) {
    const screenshotPath = testInfo.outputPath('fe-emitters-full-page.png');

    await page.screenshot({ path: screenshotPath, fullPage: true });

    await testInfo.attach('fe-emitters-full-page', {
      path: screenshotPath,
      contentType: 'image/png',
    });
  }
}

async function getFacturaElectronicaEmitters(page) {
  await page.goto('/wp-admin/admin.php?page=wc-settings&tab=fe');

  if (/wp-login\.php/.test(page.url())) {
    await completeWpAdminLogin(page);
    await page.goto('/wp-admin/admin.php?page=wc-settings&tab=fe');
  }

  test.skip(
    /page=wc-settings$/.test(page.url()),
    'Factura Electronica plugin/settings are not available in this environment.'
  );

  await expect(page).toHaveURL(/page=wc-settings&tab=fe/);
  await expect(page.locator('body')).toContainText(/Configuración FE/i);

  const emitterRows = page.locator('tr[data-emisor-id]');
  const emitterCount = await emitterRows.count();

  if (emitterCount === 0) {
    return [];
  }

  return emitterRows.evaluateAll((rows) =>
    rows.map((row) => {
      const id = row.getAttribute('data-emisor-id') || '';
      const nameElement = row.querySelector('strong');
      return {
        id,
        name: (nameElement?.textContent || '').trim(),
        isDefault: Boolean(row.querySelector('.fe-woo-badge-parent')),
      };
    })
  );
}

async function ensureMinimumFacturaElectronicaEmitters(page, minimumCount) {
  const existingEmitters = await getFacturaElectronicaEmitters(page);
  const hasDefaultEmitter = existingEmitters.some((emitter) => emitter.isDefault);

  if (!hasDefaultEmitter) {
    await createFacturaElectronicaEmisor(page, null, { shouldBeDefault: true });
  }

  let currentEmitters = await getFacturaElectronicaEmitters(page);
  while (currentEmitters.length < minimumCount) {
    await createFacturaElectronicaEmisor(page, null, { shouldBeDefault: false });
    currentEmitters = await getFacturaElectronicaEmitters(page);
  }

  return currentEmitters;
}

async function ensureDefaultFacturaElectronicaEmisor(page) {
  test.skip(
    !feApiUser || !feApiPassword || !feCertificatePath || !feCertificatePin,
    'Factura Electronica test requires FE_API_USERNAME, FE_API_PASSWORD, FE_CERTIFICATE_PATH, and FE_CERTIFICATE_PIN.'
  );
  const emitters = await ensureMinimumFacturaElectronicaEmitters(page, 1);
  const hasDefaultEmisor = emitters.some((emitter) => emitter.isDefault);

  if (!hasDefaultEmisor) {
    throw new Error('A default Factura Electronica emisor could not be ensured for the test environment.');
  }
}

async function getExistingProductNames(page) {
  await gotoAdminPage(page, '/wp-admin/edit.php?post_type=product', /edit\.php\?post_type=product/);

  const productLinks = page.locator('.row-title');
  await expect(productLinks.first()).toBeVisible();

  const productNames = (await productLinks.evaluateAll((nodes) =>
    nodes
      .map((node) => node.textContent?.trim() || '')
      .filter(Boolean)
  ));

  if (productNames.length === 0) {
    throw new Error('No existing WooCommerce product was found to use in the order smoke test.');
  }

  return productNames;
}

async function addExistingProductToOrder(page, productName, quantity) {
  await page.getByRole('button', { name: /Add item\(s\)/i }).click();
  await page.getByRole('button', { name: /Add product\(s\)/i }).click();

  const modal = page.locator('.wc-backbone-modal-content').last();
  await expect(modal).toBeVisible();

  await modal.locator('.select2-selection--single').click();

  const productSearch = page.locator('.select2-container--open .select2-search__field').last();
  await expect(productSearch).toBeVisible();
  await productSearch.fill(productName);

  let productOption = page.locator('.select2-results__option').filter({ hasText: productName }).first();
  if (!(await productOption.isVisible().catch(() => false))) {
    const fallbackSearch = productName.split(' ').slice(0, 2).join(' ');
    await productSearch.fill(fallbackSearch);
    productOption = page
      .locator('.select2-results__option')
      .filter({ hasNotText: /No results found/i })
      .first();
  }

  await expect(productOption).toBeVisible({ timeout: 15_000 });
  const selectedProductLabel = ((await productOption.textContent()) || productName).trim();
  await productOption.click();

  const quantityField = modal.locator('input[name="item_qty"]').first();
  await quantityField.fill(String(quantity));

  await Promise.all([
    page.waitForLoadState('networkidle'),
    modal.locator('#btn-ok').click(),
  ]);

  const orderItemsBox = page.locator('#woocommerce-order-items');
  const orderItemName = orderItemsBox.locator('.item .name, .wc-order-item-name, td.name').first();
  await expect(orderItemName).toBeVisible({
    timeout: 15_000,
  });

  const addedProductName = ((await orderItemName.textContent()) || selectedProductLabel).trim();
  await expect(orderItemsBox).toContainText(addedProductName, { timeout: 15_000 });

  return addedProductName;
}

async function createCompletedFacturaOrder(page) {
  await ensureDefaultFacturaElectronicaEmisor(page);

  const productNames = await getExistingProductNames(page);
  const shuffledProductNames = [...productNames].sort(() => Math.random() - 0.5);
  const selectedProductCount = Math.min(shuffledProductNames.length, Math.floor(Math.random() * 3) + 1);
  const selectedProducts = shuffledProductNames.slice(0, selectedProductCount);
  const unique = Date.now();
  const cedulaFisica = '114440852';

  await gotoAdminPage(page, '/wp-admin/admin.php?page=wc-orders&action=new', /page=wc-orders&action=new/);
  await expect(page.locator('#order_status')).toBeVisible();
  await expect(page.locator('body')).toContainText(/Add new order|Order actions|Order data|Nueva orden/i);

  await page.locator('#order_status').selectOption('wc-completed');
  await expect(page.locator('#order_status')).toHaveValue('wc-completed');

  const requireFacturaCheckbox = page.locator('#fe_woo_require_factura');
  await expect(requireFacturaCheckbox).toBeVisible();
  if (!(await requireFacturaCheckbox.isChecked())) {
    await requireFacturaCheckbox.check();
  }

  const idTypeField = page.locator('#fe_woo_id_type');
  if (await idTypeField.isVisible().catch(() => false)) {
    const cedulaFisicaOption = await idTypeField.locator('option').evaluateAll((nodes) => {
      const match = nodes.find((node) => /Cédula Física/i.test(node.textContent || ''));
      return match ? match.value : '';
    });

    if (!cedulaFisicaOption) {
      throw new Error('Could not find the "Cédula Física" option in the FE identification type select.');
    }

    await idTypeField.selectOption(cedulaFisicaOption);
  }

  await page.locator('#fe_woo_id_number').fill(cedulaFisica);
  await page.locator('#fe_woo_invoice_email').fill(`playwright-order-${unique}@example.com`);
  await page.locator('#fe_woo_phone').fill('22223333');
  await page.locator('#fe_woo_activity_code').fill('1234.5');
  await expect(page.locator('#fe_woo_activity_code')).toHaveValue(/^\d{4}\.\d$/);

  const addedProducts = [];
  for (const productName of selectedProducts) {
    const quantity = Math.floor(Math.random() * 10) + 1;
    const addedProductName = await addExistingProductToOrder(page, productName, quantity);
    addedProducts.push({ name: addedProductName, quantity });
  }

  const createOrderButton = page.getByRole('button', { name: /^Create$/i }).last();
  await expect(createOrderButton).toBeVisible();
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    createOrderButton.click(),
  ]);

  await expect(page).toHaveURL(/page=wc-orders&action=edit&id=\d+/, { timeout: 20_000 });
  await expect(page.locator('#order_status')).toHaveValue('wc-completed');
  await expect(page.locator('#fe_woo_require_factura')).toBeChecked();
  for (const addedProduct of addedProducts) {
    await expect(page.locator('body')).toContainText(addedProduct.name);
  }

  return { productNames: addedProducts.map((product) => product.name) };
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

test('add factura electronica emisores from WooCommerce settings', async ({ page }, testInfo) => {
  const emitters = await ensureMinimumFacturaElectronicaEmitters(page, 3);

  const screenshotPath = testInfo.outputPath('fe-emitters-full-page.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });
  await testInfo.attach('fe-emitters-full-page', {
    path: screenshotPath,
    contentType: 'image/png',
  });

  expect(emitters.length).toBeGreaterThanOrEqual(3);
});

test('add product from wp-admin', async ({ page }, testInfo) => {
  test.setTimeout(120000);

  const emitters = await ensureMinimumFacturaElectronicaEmitters(page, 3);
  const productCount = Math.floor(Math.random() * 3) + 5;

  for (let index = 0; index < productCount; index += 1) {
    const unique = `${Date.now()}-${index + 1}`;
    const productName = `Smoke Product ${unique}`;
    const regularPrice = String(10 + index * 5);
    const randomEmitter = emitters[Math.floor(Math.random() * emitters.length)];

    await gotoAdminPage(page, '/wp-admin/post-new.php?post_type=product', /post-new\.php\?post_type=product/);
    await expect(page.locator('body')).toContainText(/Add new product|Create product|New product|Edit product/i);

    const titleField = page.locator('input[name="post_title"], .editor-post-title__input, h1[contenteditable="true"]').first();
    await titleField.click();
    await titleField.fill(productName);

    const descriptionField = page.locator('[aria-label="Add description"], [role="textbox"][contenteditable="true"]').first();
    if (await descriptionField.isVisible().catch(() => false)) {
      await descriptionField.click();
      await descriptionField.fill(`Product ${index + 1} created by Playwright smoke test.`);
    }

    const regularPriceField = page.locator('input[name="_regular_price"], #_regular_price').first();
    if (await regularPriceField.isVisible().catch(() => false)) {
      await regularPriceField.fill(regularPrice);
    }

    const pricingTabButton = page.getByRole('button', { name: /pricing|general/i }).first();
    if (!(await regularPriceField.isVisible().catch(() => false)) && await pricingTabButton.isVisible().catch(() => false)) {
      await pricingTabButton.click();
    }

    await expect(regularPriceField).toBeVisible({ timeout: 15_000 });
    await regularPriceField.fill(regularPrice);
    await expect(regularPriceField).toHaveValue(regularPrice);

    const productEmitterField = page.locator('#fe_woo_emisor_id').first();
    await expect(productEmitterField).toBeVisible({ timeout: 15_000 });
    await expect
      .poll(async () => productEmitterField.locator('option').count(), { timeout: 15_000 })
      .toBeGreaterThan(1);
    await expect(productEmitterField.locator(`option[value="${randomEmitter.id}"]`)).toHaveCount(1);
    await productEmitterField.selectOption(randomEmitter.id);
    await expect(productEmitterField).toHaveValue(randomEmitter.id);

    const publishButton = page.getByRole('button', { name: /publish/i }).last();
    await publishButton.scrollIntoViewIfNeeded();
    await expect(publishButton).toBeEnabled({ timeout: 15_000 });
    await publishButton.click();

    const confirmPublishButton = page.getByRole('button', { name: /publish/i }).last();
    if (await confirmPublishButton.isVisible().catch(() => false)) {
      await confirmPublishButton.scrollIntoViewIfNeeded();
      await expect(confirmPublishButton).toBeEnabled({ timeout: 15_000 });
      await confirmPublishButton.click();
    }

    if (/wp-login\.php/.test(page.url())) {
      await completeWpAdminLogin(page);
    }

    await expect(page.locator('body')).toContainText(/published|updated|Product published/i, { timeout: 20_000 });

    const permalinkField = page.locator('#sample-permalink a, .editor-post-permalink__link').first();
    if (await permalinkField.isVisible().catch(() => false)) {
      await expect(permalinkField).toContainText(/smoke-product/i);
    } else {
      await expect(titleField).toHaveValue(productName);
    }

    if (await regularPriceField.isVisible().catch(() => false)) {
      await expect(regularPriceField).toHaveValue(regularPrice);
    } else {
      await expect(page.locator('body')).toContainText(new RegExp(regularPrice));
    }

    await expect(productEmitterField).toHaveValue(randomEmitter.id);
  }

  await gotoAdminPage(page, '/wp-admin/edit.php?post_type=product', /edit\.php\?post_type=product/);
  await expect(page.locator('body')).toContainText(/Products|Todos los productos|Add new product/i);

  const screenshotPath = testInfo.outputPath('products-full-page.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });
  await testInfo.attach('products-full-page', {
    path: screenshotPath,
    contentType: 'image/png',
  });
});

test('add completed order with factura electronica from wp-admin', async ({ page }, testInfo) => {
  await createCompletedFacturaOrder(page);

  const screenshotPath = testInfo.outputPath('wc-order-full-page.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });
  await testInfo.attach('wc-order-full-page', {
    path: screenshotPath,
    contentType: 'image/png',
  });
});

test('execute factura electronica from order status box', async ({ page }, testInfo) => {
  test.setTimeout(180000);

  await createCompletedFacturaOrder(page);

  const ejecutarButton = page.locator('.fe-woo-ejecutar-factura').first();
  await expect(ejecutarButton).toBeVisible();

  await ejecutarButton.click();

  await page.waitForFunction(() => {
    return Boolean(
      document.querySelector('.fe-woo-notice') ||
      !document.querySelector('.fe-woo-ejecutar-factura')
    );
  }, { timeout: 20_000 });

  await page.waitForTimeout(2500).catch(() => null);
  await page.waitForLoadState('domcontentloaded').catch(() => null);

  const facturaStatusBox = page.locator('.postbox').filter({ hasText: 'Factura Electrónica Status' }).first();
  await expect(facturaStatusBox).toBeVisible();
  await expect(facturaStatusBox).not.toContainText(/La prueba de conexión no se ha completado exitosamente/i);
  await expect(facturaStatusBox).not.toContainText(/EN COLA/i);
  await expect(facturaStatusBox).toContainText(/Clave:/i);
  await expect(facturaStatusBox).toContainText(/Estado Local:/i);
  await expect(facturaStatusBox).toContainText(/Enviada/i);
  await expect(facturaStatusBox).toContainText(/Estado Hacienda:/i);
  await expect(facturaStatusBox).toContainText(/Procesando|Aceptada/i);
  await expect(facturaStatusBox).toContainText(/Factura Enviada Exitosamente/i);

  await facturaStatusBox.scrollIntoViewIfNeeded();

  const statusBoxScreenshotPath = testInfo.outputPath('wc-order-execute-status-box.png');
  await facturaStatusBox.screenshot({ path: statusBoxScreenshotPath });
  await testInfo.attach('wc-order-execute-status-box', {
    path: statusBoxScreenshotPath,
    contentType: 'image/png',
  });

  const fullPageScreenshotPath = testInfo.outputPath('wc-order-execute-full-page.png');
  await page.screenshot({ path: fullPageScreenshotPath, fullPage: true });
  await testInfo.attach('wc-order-execute-full-page', {
    path: fullPageScreenshotPath,
    contentType: 'image/png',
  });
});
