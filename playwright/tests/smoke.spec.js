const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// Lee el .env local para que estos smoke también sirvan fuera de CI sin tanta configuración manual.
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

// Nos ayuda a construir regex seguras cuando buscamos nombres dinámicos en la UI.
function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// Hace el login completo al wp-admin y valida que la sesión realmente quede arriba.
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

// Shortcut simple para abrir el login del admin cuando hace falta empezar desde cero.
async function loginToWpAdmin(page) {
  await page.goto('/wp-login.php');
  await completeWpAdminLogin(page);
}

// Navega a una página del admin y se recupera solo si WordPress nos manda de vuelta al login.
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

// Crea un emisor FE listo para usarse, incluyendo credenciales, certificado y datos fiscales mínimos.
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

// Lee la tabla de emisores FE para saber qué hay disponible en el entorno actual.
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

// Garantiza una base mínima de emisores para que los smoke no dependan del estado previo del ambiente.
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

// Este helper solo se preocupa por asegurar que exista un emisor por defecto utilizable.
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

// Toma los nombres de productos existentes para poder armar órdenes sin depender de fixtures externos.
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

// Crea un producto nuevo y lo deja amarrado a un emisor FE específico desde la pantalla de edición.
async function createProductWithFacturaEmitter(page, { productName, regularPrice, emitterId, description }) {
  await gotoAdminPage(page, '/wp-admin/post-new.php?post_type=product', /post-new\.php\?post_type=product/);
  await expect(page.locator('body')).toContainText(/Add new product|Create product|New product|Edit product/i);

  const titleField = page.locator('input[name="post_title"], .editor-post-title__input, h1[contenteditable="true"]').first();
  await titleField.click();
  await titleField.fill(productName);

  const descriptionField = page.locator('[aria-label="Add description"], [role="textbox"][contenteditable="true"]').first();
  if (description && await descriptionField.isVisible().catch(() => false)) {
    await descriptionField.click();
    await descriptionField.fill(description);
  }

  const regularPriceField = page.locator('input[name="_regular_price"], #_regular_price').first();
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
  await expect(productEmitterField.locator(`option[value="${emitterId}"]`)).toHaveCount(1);
  await productEmitterField.selectOption(emitterId);
  await expect(productEmitterField).toHaveValue(emitterId);

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

  await gotoAdminPage(
    page,
    `/wp-admin/edit.php?post_type=product&s=${encodeURIComponent(productName)}`,
    /edit\.php\?post_type=product/
  );

  const productRowLink = page.locator('.row-title', { hasText: productName }).first();
  await expect(productRowLink).toBeVisible({ timeout: 20_000 });

  const productRow = page.locator('#the-list tr').filter({ has: productRowLink }).first();
  const editLink = await productRowLink.getAttribute('href');
  const productRowText = (await productRow.textContent().catch(() => '')) || '';

  if (/Draft|Borrador/i.test(productRowText) && editLink) {
    await page.goto(editLink);
    await expect(page.locator('body')).toContainText(/Edit product|Editar producto|Update|Publish/i);

    const statusSelect = page.locator('#post_status').first();
    if (await statusSelect.isVisible().catch(() => false)) {
      await statusSelect.selectOption('publish').catch(() => null);
    }

    const finalizeButton = page.getByRole('button', { name: /publish|update/i }).last();
    await finalizeButton.scrollIntoViewIfNeeded();
    await expect(finalizeButton).toBeEnabled({ timeout: 15_000 });
    await finalizeButton.click();

    await expect(page.locator('input[name="_regular_price"], #_regular_price').first()).toHaveValue(regularPrice);

    await gotoAdminPage(
      page,
      `/wp-admin/edit.php?post_type=product&s=${encodeURIComponent(productName)}`,
      /edit\.php\?post_type=product/
    );
  }

  const refreshedProductRowLink = page.locator('.row-title', { hasText: productName }).first();
  await expect(refreshedProductRowLink).toBeVisible({ timeout: 20_000 });
  const refreshedProductRow = page.locator('#the-list tr').filter({ has: refreshedProductRowLink }).first();
  const refreshedRowText = (await refreshedProductRow.textContent().catch(() => '')) || '';
  if (!/Draft|Borrador/i.test(refreshedRowText)) {
    await expect(refreshedProductRow).toContainText(new RegExp(`${Number(regularPrice).toFixed(2)}`));
  }

  return {
    productName,
    editLink,
  };
}

// Agrega un producto ya existente a la orden y ajusta la cantidad desde el modal de WooCommerce.
async function addExistingProductToOrder(page, productName, quantity) {
  await page.getByRole('button', { name: /Add item\(s\)/i }).click();
  await page.getByRole('button', { name: /Add product\(s\)/i }).click();

  const modal = page.locator('.wc-backbone-modal-content').last();
  await expect(modal).toBeVisible();

  await modal.locator('.select2-selection--single').click();

  const productSearch = page.locator('.select2-container--open .select2-search__field').last();
  await expect(productSearch).toBeVisible();
  await productSearch.fill(productName);

  let productOption = page
    .locator('.select2-results__option')
    .filter({ hasText: new RegExp(`^\\s*${escapeRegExp(productName)}\\s*$`) })
    .first();
  if (!(await productOption.isVisible().catch(() => false))) {
    const fallbackSearch = productName.split(' ').slice(-1).join(' ');
    await productSearch.fill(fallbackSearch);
    productOption = page
      .locator('.select2-results__option')
      .filter({ hasText: new RegExp(escapeRegExp(fallbackSearch)) })
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

// Arma una orden completada con FE usando productos ya existentes y una cantidad de items configurable.
async function createCompletedFacturaOrder(page, options = {}) {
  const { itemCount, minItemCount, maxItemCount } = options;
  await ensureDefaultFacturaElectronicaEmisor(page);

  const productNames = await getExistingProductNames(page);
  const randomItemCount = Math.floor(Math.random() * ((maxItemCount || 3) - (minItemCount || 1) + 1)) + (minItemCount || 1);
  const selectedProductCount = Math.min(
    productNames.length,
    itemCount || randomItemCount
  );
  const shuffledProductNames = [...productNames].sort(() => Math.random() - 0.5);
  const unique = Date.now();
  const cedulaFisica = '114440852';
  const selectedProducts = shuffledProductNames.slice(0, selectedProductCount);

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

// Este flujo fuerza una orden con mezcla real de emisor default y no-default para cubrir la multi-factura.
async function createCompletedFacturaOrderWithMixedEmitters(page) {
  const emitters = await ensureMinimumFacturaElectronicaEmitters(page, 2);
  const defaultEmitter = emitters.find((emitter) => emitter.isDefault);
  const nonDefaultEmitters = emitters.filter((emitter) => !emitter.isDefault);

  if (!defaultEmitter || nonDefaultEmitters.length === 0) {
    throw new Error('Could not prepare mixed FE emitters for the execute smoke test.');
  }

  const unique = Date.now();
  const productDefinitions = [
    {
      productName: `Execute Default ${unique}-A`,
      regularPrice: '15',
      emitterId: defaultEmitter.id,
      description: `Execute factura product using default emitter ${defaultEmitter.name}.`,
    },
    {
      productName: `Execute Secondary ${unique}-B`,
      regularPrice: '20',
      emitterId: nonDefaultEmitters[0].id,
      description: `Execute factura product using non-default emitter ${nonDefaultEmitters[0].name}.`,
    },
  ];

  for (const productDefinition of productDefinitions) {
    const createdProduct = await createProductWithFacturaEmitter(page, productDefinition);
    productDefinition.editLink = createdProduct.editLink;
  }

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
  for (const productDefinition of productDefinitions) {
    const quantity = Math.floor(Math.random() * 10) + 1;
    const addedProductName = await addExistingProductToOrder(page, productDefinition.productName, quantity);
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

  const orderUrl = page.url();

  return {
    productNames: addedProducts.map((product) => product.name),
    defaultEmitterName: defaultEmitter.name,
    nonDefaultEmitterName: nonDefaultEmitters[0].name,
    productDefinitions,
    orderUrl,
  };
}

// Centraliza la validación del metabox FE para aceptar tanto el flujo simple como el de varias facturas.
async function expectFacturaElectronicaExecutionSuccess(facturaStatusBox) {
  await expect(facturaStatusBox).toBeVisible();
  await expect(facturaStatusBox).not.toContainText(/La prueba de conexión no se ha completado exitosamente/i);
  await expect(facturaStatusBox).toContainText(/Clave:|\d+\s+Factura(?:s)?\s+Generada(?:s)?/i);
  await expect(facturaStatusBox).toContainText(/Estado Local:/i);
  await expect(facturaStatusBox).toContainText(/Enviada/i);
  await expect(facturaStatusBox).toContainText(/Estado Hacienda:/i);
  await expect(facturaStatusBox).toContainText(/Procesando|Aceptada/i);
  await expect(facturaStatusBox).toContainText(/Factura Enviada Exitosamente|\d+\s+Factura(?:s)?\s+Generada(?:s)?/i);
}

// La versión nueva puede dejar FE en cola; para smoke aceptamos encolado correcto y, si alcanza, transición a ejecutada.
async function waitForFacturaElectronicaExecutionState(page, timeoutMs = 120_000) {
  const startedAt = Date.now();
  let lastVisibleStatusBox = null;

  while (Date.now() - startedAt < timeoutMs) {
    const facturaStatusBox = page.locator('.postbox').filter({ hasText: 'Factura Electrónica Status' }).first();
    await expect(facturaStatusBox).toBeVisible({ timeout: 15_000 });
    lastVisibleStatusBox = facturaStatusBox;

    const statusText = ((await facturaStatusBox.textContent().catch(() => '')) || '').replace(/\s+/g, ' ');

    if (!/La prueba de conexión no se ha completado exitosamente/i.test(statusText)) {
      const isQueued = /Estado de Cola:|En Cola|cola de procesamiento/i.test(statusText);
      const hasExecutionState =
        /Clave:|\d+\s+Factura(?:s)?\s+Generada(?:s)?/i.test(statusText) &&
        /Estado Local:/i.test(statusText) &&
        /Enviada/i.test(statusText) &&
        /Estado Hacienda:/i.test(statusText) &&
        /Procesando|Aceptada/i.test(statusText) &&
        /Factura Enviada Exitosamente|\d+\s+Factura(?:s)?\s+Generada(?:s)?/i.test(statusText);

      if (!isQueued && hasExecutionState) {
        await expectFacturaElectronicaExecutionSuccess(facturaStatusBox);
        return facturaStatusBox;
      }

      if (isQueued) {
        await expect(facturaStatusBox).toContainText(/Estado de Cola:|En Cola|cola de procesamiento/i);
        await expect(facturaStatusBox).toContainText(/Facturas a Generar|FACTURA 1|EJECUTAR/i);
        return facturaStatusBox;
      }
    }

    await page.waitForTimeout(5000);
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => null);
  }

  if (lastVisibleStatusBox) {
    const lastStatusText = ((await lastVisibleStatusBox.textContent().catch(() => '')) || '').replace(/\s+/g, ' ');
    if (/Estado de Cola:|En Cola|cola de procesamiento/i.test(lastStatusText)) {
      return lastVisibleStatusBox;
    }
  }

  throw new Error('Factura Electronica did not reach a valid queued or executed state in time.');
}

// Ejecuta FE sobre la orden actual y valida el estado final esperado en el metabox.
async function executeFacturaOnCurrentOrder(page) {
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

  return waitForFacturaElectronicaExecutionState(page);
}

// Deja una orden en estado cancelado pero con FE ya generada para probar notas de crédito arriba de datos reales.
async function prepareCancelledOrderWithGeneratedFactura(page) {
  await createCompletedFacturaOrder(page, { minItemCount: 1, maxItemCount: 10 });
  await executeFacturaOnCurrentOrder(page);

  const statusSelect = page.locator('#order_status');
  await expect(statusSelect).toBeVisible();
  await statusSelect.selectOption('wc-cancelled');
  await expect(statusSelect).toHaveValue('wc-cancelled');

  const updateButton = page.getByRole('button', { name: /^Update$/i }).first();
  await expect(updateButton).toBeVisible();
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    updateButton.click(),
  ]);

  await expect(page.locator('#order_status')).toHaveValue('wc-cancelled');
  await expect(page.locator('body')).toContainText(/Order updated|orden actualizada/i);
  return page.locator('.postbox').filter({ hasText: 'Factura Electrónica Status' }).first();
}

// Espera la versión ya renderizada del bloque de NC usando la razón como marcador estable en el metabox.
async function waitForRenderedCreditNotes(page, expectedCount, reasonText, timeoutMs = 60_000) {
  const startedAt = Date.now();

  while (Date.now() - startedAt < timeoutMs) {
    const facturaStatusBox = page.locator('.postbox').filter({ hasText: 'Factura Electrónica Status' }).first();
    await expect(facturaStatusBox).toBeVisible({ timeout: 15_000 });

    const statusText = ((await facturaStatusBox.textContent().catch(() => '')) || '').replace(/\s+/g, ' ');
    const renderedCreditNotes = reasonText
      ? statusText.split(reasonText).length - 1
      : (statusText.match(/Nota de Crédito/gi) || []).length;

    if (renderedCreditNotes >= expectedCount) {
      return;
    }

    await page.waitForTimeout(5000);
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => null);
  }

  throw new Error(`Rendered credit notes did not appear in time. Expected ${expectedCount} rendered notes for reason "${reasonText}".`);
}

// Cierra u oculta overlays flotantes del admin para que no tapen la evidencia visual.
async function dismissFloatingAdminOverlays(page) {
  const closeButtons = [
    page.locator('button[aria-label="Dismiss this notice"]').first(),
    page.locator('button[aria-label="Close dialog"]').first(),
    page.locator('.woocommerce-task-list__dismiss-button').first(),
    page.locator('.components-modal__header button').first(),
  ];

  for (const button of closeButtons) {
    if (await button.isVisible().catch(() => false)) {
      await button.click().catch(() => null);
    }
  }

  await page.evaluate(() => {
    const selectors = [
      '.woocommerce-layout__activity-panel-wrapper',
      '.woocommerce-embedded-layout__primary .components-snackbar-list',
      '.components-modal__screen-overlay',
      '.components-modal__frame',
      '[style*="position: fixed"] .woocommerce-tour-kit-step',
    ];

    for (const selector of selectors) {
      document.querySelectorAll(selector).forEach((element) => {
        element.remove();
      });
    }
  }).catch(() => null);
}

// Busca una orden completada que ya tenga evidencia de FE generada para reutilizarla en flujos de cambio de estado.
async function findCompletedOrderWithGeneratedFactura(page) {
  await gotoAdminPage(page, '/wp-admin/edit.php?post_type=shop_order', /edit\.php\?post_type=shop_order|page=wc-orders/i);

  const orderRows = page.locator('table.wp-list-table tbody tr, #the-list tr');
  const rowCount = await orderRows.count();
  if (rowCount === 0) {
    throw new Error('No WooCommerce orders were found in the admin list.');
  }

  const candidateIndexes = Array.from({ length: rowCount }, (_, index) => index)
    .sort(() => Math.random() - 0.5);

  for (const rowIndex of candidateIndexes) {
    const row = orderRows.nth(rowIndex);
    const rowText = await row.textContent().catch(() => '');

    if (!/Completed|Completado/i.test(rowText || '')) {
      continue;
    }

    const orderHref = await row.locator('a').evaluateAll((links) => {
      const match = links.find((link) => {
        const href = link.getAttribute('href') || '';
        return /(?:page=wc-orders&action=edit&id=\d+|post=\d+&action=edit)/.test(href);
      });
      return match ? match.getAttribute('href') : '';
    }).catch(() => '');

    if (!orderHref) {
      continue;
    }

    await page.goto(orderHref);
    await expect(page).toHaveURL(/page=wc-orders&action=edit&id=\d+|post=\d+&action=edit/);

    const facturaStatusBox = page.locator('.postbox').filter({ hasText: 'Factura Electrónica Status' }).first();
    if (!(await facturaStatusBox.isVisible().catch(() => false))) {
      continue;
    }

    const statusText = await facturaStatusBox.textContent().catch(() => '');
    if (/Clave:|\d+\s+Factura(?:s)?\s+Generada(?:s)?/i.test(statusText || '')) {
      return { orderUrl: page.url(), facturaStatusBox };
    }
  }

  throw new Error('Could not find a completed WooCommerce order with generated Factura Electronica data.');
}

// Confirma que el sitio base levanta y que WordPress responde con contenido visible.
test('homepage responds and shows WordPress content', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/Mi WordPress/i);
  await expect(page.locator('body')).toContainText(/Mi WordPress|Hello world!/i);
});

// Verifica que la pantalla de login del admin esté accesible antes de tocar flujos más pesados.
test('wp-admin login page is reachable', async ({ page }) => {
  await page.goto('/wp-login.php');
  await expect(page).toHaveURL(/wp-login\.php/);
  await expect(page.locator('#user_login')).toBeVisible();
  await expect(page.locator('#user_pass')).toBeVisible();
});

// Deja listos varios emisores FE para que el resto de los smoke tengan datos reales con qué trabajar.
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

// Crea un lote de productos con precio y emisor FE aleatorio para poblar el catálogo del entorno de prueba.
test('add product from wp-admin', async ({ page }, testInfo) => {
  test.setTimeout(120000);

  const emitters = await ensureMinimumFacturaElectronicaEmitters(page, 3);
  const productCount = Math.floor(Math.random() * 3) + 5;

  for (let index = 0; index < productCount; index += 1) {
    const unique = `${Date.now()}-${index + 1}`;
    const productName = `Smoke Product ${unique}`;
    const regularPrice = String(10 + index * 5);
    const randomEmitter = emitters[Math.floor(Math.random() * emitters.length)];
    await createProductWithFacturaEmitter(page, {
      productName,
      regularPrice,
      emitterId: randomEmitter.id,
      description: `Product ${index + 1} created by Playwright smoke test.`,
    });
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

// Arma una orden completada con FE usando productos existentes para validar el flujo base de creación.
test('add completed order with factura electronica from wp-admin', async ({ page }, testInfo) => {
  await createCompletedFacturaOrder(page);

  const screenshotPath = testInfo.outputPath('wc-order-full-page.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });
  await testInfo.attach('wc-order-full-page', {
    path: screenshotPath,
    contentType: 'image/png',
  });
});

// Ejecuta FE sobre una orden normal y deja evidencia del estado que reporta el metabox en la orden.
test('execute factura electronica from order status box', async ({ page }, testInfo) => {
  test.setTimeout(180000);

  await createCompletedFacturaOrder(page, { minItemCount: 1, maxItemCount: 10 });
  const facturaStatusBox = await executeFacturaOnCurrentOrder(page);

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

// Fuerza una orden con productos ligados a emisor default y no-default para revisar el comportamiento mixto.
test('execute factura electronica with default and non-default emitters', async ({ page }, testInfo) => {
  test.setTimeout(180000);

  const orderInfo = await createCompletedFacturaOrderWithMixedEmitters(page);

  for (const [index, productDefinition] of orderInfo.productDefinitions.entries()) {
    if (!productDefinition.editLink) {
      continue;
    }

    await page.goto(productDefinition.editLink);
    await expect(page.locator('body')).toContainText(/Edit product|Editar producto|Product data/i);
    await expect(page.locator('#fe_woo_emisor_id')).toHaveValue(productDefinition.emitterId);

    const productEditScreenshotPath = testInfo.outputPath(`wc-order-execute-mixed-emitter-product-${index + 1}.png`);
    await page.screenshot({ path: productEditScreenshotPath, fullPage: true });
    await testInfo.attach(`wc-order-execute-mixed-emitter-product-${index + 1}`, {
      path: productEditScreenshotPath,
      contentType: 'image/png',
    });
  }

  await page.goto(orderInfo.orderUrl);
  await expect(page).toHaveURL(/page=wc-orders&action=edit&id=\d+/);
  const facturaStatusBox = await executeFacturaOnCurrentOrder(page);

  for (const productName of orderInfo.productNames) {
    await expect(page.locator('body')).toContainText(productName);
  }

  const statusBoxScreenshotPath = testInfo.outputPath('wc-order-execute-mixed-emitters-status-box.png');
  await facturaStatusBox.screenshot({ path: statusBoxScreenshotPath });
  await testInfo.attach('wc-order-execute-mixed-emitters-status-box', {
    path: statusBoxScreenshotPath,
    contentType: 'image/png',
  });

  const fullPageScreenshotPath = testInfo.outputPath('wc-order-execute-mixed-emitters-full-page.png');
  await page.screenshot({ path: fullPageScreenshotPath, fullPage: true });
  await testInfo.attach('wc-order-execute-mixed-emitters-full-page', {
    path: fullPageScreenshotPath,
    contentType: 'image/png',
  });
});

// Reutiliza una orden completada con FE ya generada y valida que todavía podamos moverla a cancelada.
test('cancel a completed order with generated factura electronica', async ({ page }, testInfo) => {
  test.setTimeout(120000);

  await prepareCancelledOrderWithGeneratedFactura(page);

  const screenshotPath = testInfo.outputPath('wc-order-cancelled-full-page.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });
  await testInfo.attach('wc-order-cancelled-full-page', {
    path: screenshotPath,
    contentType: 'image/png',
  });
});

// Toma una orden cancelada con FE generada y dispara una nota de crédito manual desde el metabox de facturas.
test('generate credit note for cancelled order with generated factura electronica', async ({ page }, testInfo) => {
  test.setTimeout(180000);
  const creditNoteReason = 'Generación de NC por pruebas smoke';

  const facturaStatusBox = await prepareCancelledOrderWithGeneratedFactura(page);
  await facturaStatusBox.scrollIntoViewIfNeeded();
  await dismissFloatingAdminOverlays(page);

  const beforeScreenshotPath = testInfo.outputPath('wc-order-credit-note-before.png');
  await page.screenshot({ path: beforeScreenshotPath, fullPage: true });
  await testInfo.attach('wc-order-credit-note-before', {
    path: beforeScreenshotPath,
    contentType: 'image/png',
  });

  const beforeStatusBoxScreenshotPath = testInfo.outputPath('wc-order-credit-note-before-status-box.png');
  await facturaStatusBox.screenshot({ path: beforeStatusBoxScreenshotPath });
  await testInfo.attach('wc-order-credit-note-before-status-box', {
    path: beforeStatusBoxScreenshotPath,
    contentType: 'image/png',
  });

  const initialNotaForms = await facturaStatusBox
    .locator('details')
    .filter({ hasText: /Generar nota|Generar nueva nota/i })
    .count();
  expect(initialNotaForms).toBeGreaterThan(0);

  for (let noteIndex = 0; noteIndex < initialNotaForms; noteIndex += 1) {
    const currentFacturaStatusBox = page.locator('.postbox').filter({ hasText: 'Factura Electrónica Status' }).first();
    const notaDetails = currentFacturaStatusBox
      .locator('details')
      .filter({ hasText: /Generar nota|Generar nueva nota/i })
      .nth(noteIndex);

    await expect(notaDetails).toBeVisible();
    await notaDetails.evaluate((element) => { element.open = true; });

    const notaContainer = notaDetails.locator('.fe-woo-nota-form-container').first();
    await expect(notaContainer).toBeVisible();

    const referenceCodeSelect = notaContainer.locator('.fe-woo-reference-code').first();
    const availableReferenceCodes = await referenceCodeSelect.locator('option').evaluateAll((options) =>
      options.map((option) => option.value).filter(Boolean)
    );
    const randomReferenceCode = availableReferenceCodes[Math.floor(Math.random() * availableReferenceCodes.length)];

    await notaContainer.locator('.fe-woo-note-type').first().selectOption('nota_credito');
    await referenceCodeSelect.selectOption(randomReferenceCode);
    await notaContainer.locator('.fe-woo-note-reason').first().fill(creditNoteReason);

    const generateNoteButton = notaContainer.locator('.fe-woo-generate-note').first();
    await expect(generateNoteButton).toBeVisible();
    await generateNoteButton.click();

    const noteMessage = notaContainer.locator('.fe-woo-note-message').first();
    await expect(noteMessage).toBeVisible({ timeout: 20_000 });
    await expect(noteMessage).toContainText(/Nota de Crédito|Nota de Crédito generada|Crédito generada/i, { timeout: 20_000 });

    await page.waitForTimeout(3000).catch(() => null);
    await page.waitForLoadState('domcontentloaded').catch(() => null);
    await expect(page.locator('#order_status')).toHaveValue('wc-cancelled');
  }

  await waitForRenderedCreditNotes(page, initialNotaForms, creditNoteReason);
  await dismissFloatingAdminOverlays(page);

  const refreshedFacturaStatusBox = page.locator('.postbox').filter({ hasText: 'Factura Electrónica Status' }).first();
  await refreshedFacturaStatusBox.scrollIntoViewIfNeeded();

  const afterScreenshotPath = testInfo.outputPath('wc-order-credit-note-after.png');
  await page.screenshot({ path: afterScreenshotPath, fullPage: true });
  await testInfo.attach('wc-order-credit-note-after', {
    path: afterScreenshotPath,
    contentType: 'image/png',
  });

  const afterStatusBoxScreenshotPath = testInfo.outputPath('wc-order-credit-note-after-status-box.png');
  await refreshedFacturaStatusBox.screenshot({ path: afterStatusBoxScreenshotPath });
  await testInfo.attach('wc-order-credit-note-after-status-box', {
    path: afterStatusBoxScreenshotPath,
    contentType: 'image/png',
  });
});
