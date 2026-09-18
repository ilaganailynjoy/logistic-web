import { chromium } from 'playwright-core';

/**
 * Browser verification for the multi-step Logistics Center application wizard.
 *
 * Requirements: a local Laravel server must be reachable at BASE_URL (default
 * http://127.0.0.1:8001) with the address dataset seeded and file storage
 * available. Uses the system-installed Chrome via the "chrome" channel.
 *
 * Run:   node center-wizard.spec.mjs
 */

const BASE_URL = process.env.WIZARD_BASE_URL || 'http://127.0.0.1:8001';
const APPLY = `${BASE_URL}/logistics-center/apply`;

const results = [];
let createdEmails = [];

const run = async (name, fn) => {
  const started = Date.now();
  try {
    await Promise.race([
      fn(),
      new Promise((_, reject) => setTimeout(() => reject(new Error('TIMED OUT after 60s')), 60000)),
    ]);
    console.log(`PASS  ${name}`);
    results.push({ ok: true, name, ms: Date.now() - started });
  } catch (e) {
    console.log(`FAIL  ${name}  -- ${String(e && e.message || e)}`);
    results.push({ ok: false, name, ms: Date.now() - started, error: String(e && e.message || e) });
  }
};

function uniqueEmail() {
  const email = `wizard-${Date.now()}-${Math.floor(Math.random() * 1e6)}@test.com`;
  createdEmails.push(email);
  return email;
}

function uniquePhone() {
  return '0919' + String(Math.floor(1000000 + Math.random() * 9000000));
}

const jpg = (name, bytes = 400) => ({
  name,
  mimeType: 'image/jpeg',
  buffer: Buffer.concat([Buffer.from([0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46, 0x49, 0x46, 0x00, 0x01]), Buffer.alloc(bytes, 0)]),
});

async function fillStep1(page, overrides = {}) {
  const base = {
    business_name: 'Browser Test Center',
    owner_name: 'Test Owner',
    email: uniqueEmail(),
    phone: uniquePhone(),
    ...overrides,
  };
  await page.fill('#business_name', base.business_name);
  await page.fill('#owner_name', base.owner_name);
  await page.fill('#email', base.email);
  await page.fill('#phone', base.phone);
  return base;
}

async function fillStep2(page, overrides = {}) {
  await page.fill('#house_number', overrides.house_number ?? '7');
  await page.fill('#street', overrides.street ?? 'Bonifacio St');
  return { ...overrides };
}

async function setDocs(page, extraFile = false) {
  await page.setInputFiles('#documents_valid_id', jpg('valid-id.jpg'));
  await page.setInputFiles('#documents_business_registration', jpg('business-registration.jpg'));
  if (extraFile) {
    await page.setInputFiles('#documents_other', jpg('extra-support.pdf', 500));
  }
}

const submitBtn = '#wizard-submit';
const nextBtn = '#wizard-next';
const backBtn = '#wizard-back';

async function gotoApply(page) {
  try {
    await page.goto(APPLY, { waitUntil: 'domcontentloaded' });
  } catch {
    await page.waitForTimeout(500);
    await page.goto(APPLY, { waitUntil: 'domcontentloaded' });
  }
  await page.waitForSelector('form[x-data]', { timeout: 15000 });
  // Any step may be the landing step (e.g. server-routing after validation
  // errors), so only require the first section to be attached.
  await page.waitForSelector('#wizard-step-1', { state: 'attached' });
}

async function clickNext(page) {
  const btn = page.locator(nextBtn);
  await btn.waitFor({ state: 'visible' });
  await btn.click();
}

async function clickBack(page) {
  const btn = page.locator(backBtn);
  await btn.waitFor({ state: 'visible' });
  await btn.click();
}

async function assertVisibleStep(page, index) {
  const heading = page.locator(`#wizard-step-${index + 1}`);
  await heading.waitFor({ state: 'visible' });
}

const launchOpts = { channel: 'chrome', headless: true };

const spec = async () => {
  // ── JavaScript-enabled context ────────────────────────────────────
  const browser = await chromium.launch(launchOpts);
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);

  await run('Step 1 renders correctly', async () => {
    await gotoApply(page);
    await page.waitForLoadState('domcontentloaded');
    await assertVisibleStep(page, 0);
    await page.waitForSelector('#business_name');
    const title = await page.locator('#wizard-step-1').textContent();
    if (!title.includes('center')) throw new Error(`Unexpected heading: ${title}`);
    await page.waitForSelector('form[x-data]');
  });

  await run('Client-side validation blocks Next with empty required fields', async () => {
    await gotoApply(page);
    await clickNext(page); // all step-1 fields empty -> must NOT advance
    await assertVisibleStep(page, 0);
    await page.waitForSelector('text=The center name is required.', { timeout: 5000 });
  });

  await run('Next moves to Step 2 after valid step 1', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await assertVisibleStep(page, 1);
  });

  await run('Back returns to Step 1 without losing values', async () => {
    await gotoApply(page);
    const data = await fillStep1(page);
    await clickNext(page);
    await assertVisibleStep(page, 1);
    await clickBack(page);
    await assertVisibleStep(page, 0);
    const name = await page.inputValue('#business_name');
    if (name !== data.business_name) throw new Error(`Value lost: ${name} !== ${data.business_name}`);
  });

  await run('Step 2 address dropdowns work (Province → Municipality → Barangay)', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await assertVisibleStep(page, 1);

    const province = page.locator('#province');
    await province.waitFor();
    await province.selectOption({ index: 1 });

    const municipality = page.locator('#municipality');
    await page.waitForFunction(() => {
      const el = document.getElementById('municipality');
      return el && !el.disabled && el.options.length > 1;
    });
    await municipality.selectOption({ index: 1 });

    const barangay = page.locator('#barangay');
    await page.waitForFunction(() => {
      const el = document.getElementById('barangay');
      return el && !el.disabled && el.options.length > 1;
    });
    await barangay.selectOption({ index: 1 });
  });

  await run('Next moves to Step 3', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await clickNext(page);
    await assertVisibleStep(page, 2);
  });

  await run('Document inputs remain functional', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await clickNext(page);
    await assertVisibleStep(page, 2);
    await setDocs(page);
    await page.waitForSelector('text=valid-id.jpg', { timeout: 5000 });
    await page.waitForSelector('text=business-registration.jpg', { timeout: 5000 });
  });

  await run('Review step displays entered information', async () => {
    await gotoApply(page);
    const data = await fillStep1(page);
    await clickNext(page);
    await fillStep2(page, { house_number: '42', street: 'Rizal Ave' });
    await clickNext(page);
    await setDocs(page);
    await clickNext(page); // Review & Submit
    await assertVisibleStep(page, 3);

    await page.waitForSelector(`text=${data.business_name}`);
    await page.locator('dd span.break-words', { hasText: 'valid-id.jpg' }).waitFor({ timeout: 5000 });
    await page.waitForSelector('text=42, Rizal Ave');
  });

  await run('Review updates when earlier values change', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await clickNext(page);
    await setDocs(page);
    await clickNext(page);
    await assertVisibleStep(page, 3);

    // Go back to step 1, change the center name, return to review.
    const editCount = await page.locator('button[type="button"]:has-text("Edit")').count();
    if (editCount !== 3) throw new Error(`Expected 3 Edit buttons, got ${editCount}`);
    await page.locator('button[type="button"]:has-text("Edit")').nth(0).click();
    await assertVisibleStep(page, 0);
    await page.fill('#business_name', 'Changed Center Name');
    await clickNext(page);
    await clickNext(page);
    await clickNext(page);
    await assertVisibleStep(page, 3);
    await page.waitForSelector('text=Changed Center Name');
  });

  await run('Edit buttons return to the correct step', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await clickNext(page);
    await setDocs(page);
    await clickNext(page);
    await assertVisibleStep(page, 3);

    // Edit (Location) → step 2
    await page.locator('button[type="button"]:has-text("Edit")').nth(1).click();
    await assertVisibleStep(page, 1);
  });

  await run('Submit available only on the final step', async () => {
    await gotoApply(page);
    await assertVisibleStep(page, 0);
    await page.waitForSelector(submitBtn, { state: 'attached' });
    if (await page.locator(submitBtn).isVisible()) throw new Error('Submit visible on step 1');
    if (!(await page.locator(nextBtn).isVisible())) throw new Error('Next not visible on step 1');

    await fillStep1(page);
    await clickNext(page);
    if (await page.locator(submitBtn).isVisible()) throw new Error('Submit visible on step 2');
    await clickNext(page); // step 3
    if (await page.locator(submitBtn).isVisible()) throw new Error('Submit visible on step 3');

    await setDocs(page);
    await clickNext(page); // review
    await assertVisibleStep(page, 3);
    await page.waitForSelector(submitBtn, { state: 'visible' });
    if (await page.locator(nextBtn).isVisible()) throw new Error('Next visible on review step');
  });

  await run('Repeated submission is prevented (double submit guard)', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await clickNext(page);
    await setDocs(page);
    await clickNext(page);
    await assertVisibleStep(page, 3);

    let postCount = 0;
    await page.route(`${BASE_URL}/**/logistics-center/apply`, (route) => {
      if (route.request().method() === 'POST') postCount += 1;
      return route.continue();
    });

    // Two rapid real clicks (like a user double-clicking). The form-level
    // x-on:submit guard must swallow the second submission attempt, so
    // exactly one POST reaches the server.
    const state = await page.evaluate(async () => {
      const btn = document.querySelector('button[type="submit"]');
      btn.click();
      btn.click();
      await new Promise((r) => setTimeout(r, 100));
      return { disabled: btn.disabled, label: btn.textContent.trim() };
    });

    if (state.disabled !== true) throw new Error('Submit button not disabled while submitting');
    if (!state.label.includes('Submitting')) throw new Error(`Bad submit label: ${state.label}`);

    // Successful submission redirects to the apply page with a success flash.
    await page.waitForSelector('text=Application submitted', { timeout: 15000 });
    await page.unroute(`${BASE_URL}/**/logistics-center/apply`);
    if (postCount !== 1) throw new Error(`Expected exactly 1 POST, received ${postCount}`);
  });

  await run('Successful submission shows success message', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await fillStep2(page);
    await clickNext(page);
    await setDocs(page);
    await clickNext(page);
    await assertVisibleStep(page, 3);
    await page.locator(submitBtn).click();
    await page.waitForSelector('text=Application submitted', { timeout: 15000 });
    await page.waitForSelector('text=track its progress', { timeout: 5000 });
  });

  await run('Invalid phone errors open Step 1 (server-authoritative routing)', async () => {
    // Direct POST (equivalent to a no-JS / crafted browser submission) to prove
    // the server routes phone errors to step 1 on reload.
    const data = {
      business_name: 'Phone Error Center',
      owner_name: 'Test Owner',
      email: uniqueEmail(),
      phone: '12345',
      house_number: '1', street: 'St', barangay: 'B', municipality: 'M', province: 'P',
    };
    await gotoApply(page);
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const form = new URLSearchParams({ _token: token, ...data });
    const resp = await context.request.post(`${BASE_URL}/logistics-center/apply`, {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      data: form.toString(),
      maxRedirects: 0,
    });
    if (resp.status() !== 302) throw new Error(`Expected 302, got ${resp.status()}`);

    await gotoApply(page);
    await page.waitForSelector('text=Fix in Step 1', { timeout: 10000 });
    const initial = await page.locator('form').getAttribute('data-wizard-initial-step');
    if (initial !== '0') throw new Error(`Expected initial step 0, got ${initial}`);
  });

  await run('Missing documents open Step 3 (server-authoritative routing)', async () => {
    const data = {
      business_name: 'No Docs Center',
      owner_name: 'Test Owner',
      email: uniqueEmail(),
      phone: uniquePhone(),
      house_number: '1', street: 'St', barangay: 'B', municipality: 'M', province: 'P',
    };
    await gotoApply(page);
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const form = new URLSearchParams({ _token: token, ...data });
    await context.request.post(`${BASE_URL}/logistics-center/apply`, {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      data: form.toString(),
      maxRedirects: 0,
    });

    await gotoApply(page);
    await page.waitForSelector('text=Fix in Step 3', { timeout: 10000 });
    const initial = await page.locator('form').getAttribute('data-wizard-initial-step');
    if (initial !== '2') throw new Error(`Expected initial step 2, got ${initial}`);
  });

  await run('Focus moves to the new step heading after Next', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await assertVisibleStep(page, 1);
    const focused = await page.evaluate(() => document.activeElement && document.activeElement.id);
    if (focused !== 'wizard-step-2') throw new Error(`Focus not on step 2 heading: ${focused}`);
  });

  await run('Keyboard users can operate the wizard (Enter on Next)', async () => {
    await gotoApply(page);
    await fillStep1(page);
    const next = page.locator(nextBtn);
    await next.focus();
    await page.keyboard.press('Enter');
    await assertVisibleStep(page, 1);
  });

  await run('Indicator raises aria-current on the active step', async () => {
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await assertVisibleStep(page, 1);
    const current = await page.locator('ol[aria-label="Application steps"] [aria-current="step"]').count();
    if (current !== 1) throw new Error(`Expected exactly 1 aria-current, got ${current}`);
  });

  await run('No horizontal overflow at critical widths', async () => {
    const widths = [320, 375, 414, 768, 1024, 1440];
    for (const width of widths) {
      await page.setViewportSize({ width, height: 900 });
      await gotoApply(page);
      await page.waitForLoadState('domcontentloaded');
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
      if (overflow) throw new Error(`Horizontal overflow at ${width}px`);
    }
    // Mobile landscape
    await page.setViewportSize({ width: 812, height: 375 });
    await gotoApply(page);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    if (overflow) throw new Error('Horizontal overflow at 812x375');
    await page.setViewportSize({ width: 1280, height: 900 });
  });

  await run('Long filenames and review content do not overflow', async () => {
    await page.setViewportSize({ width: 375, height: 800 });
    await gotoApply(page);
    await fillStep1(page);
    await clickNext(page);
    await clickNext(page);
    await assertVisibleStep(page, 2);
    await page.setInputFiles('#documents_valid_id', jpg('a-very-long-file-name-that-should-never-cause-the-step-3-to-overflow-on-small-screens-2026-extra-long-name.jpg'));
    await page.setInputFiles('#documents_business_registration', jpg('business-registration.jpg'));
    await clickNext(page);
    await assertVisibleStep(page, 3);
    await page.locator('dd span.break-words', { hasText: 'a-very-long-file-name' }).waitFor({ state: 'visible', timeout: 5000 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    if (overflow) throw new Error('Horizontal overflow with long filename at 375px');
    await page.setViewportSize({ width: 1280, height: 900 });
  });

  await browser.close();

  // ── JavaScript-disabled context (no-JS fallback) ──────────────────
  const browserNoJs = await chromium.launch(launchOpts);
  const contextNoJs = await browserNoJs.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
  const pageNoJs = await contextNoJs.newPage();

  await run('No-JS: whole form visible and submittable', async () => {
    await pageNoJs.goto(APPLY);
    await pageNoJs.waitForLoadState('domcontentloaded');
    const sections = await pageNoJs.locator('section').count();
    if (sections !== 4) throw new Error(`Expected 4 stacked sections, got ${sections}`);
    const submitVisible = await pageNoJs.locator(submitBtn).isVisible();
    if (!submitVisible) throw new Error('Submit button hidden without JS');
  });

  await run('No-JS: missing documents still route to Step 3 after server failure', async () => {
    await pageNoJs.goto(APPLY);
    await pageNoJs.fill('#business_name', 'NoJS Center');
    await pageNoJs.fill('#owner_name', 'NoJS Owner');
    await pageNoJs.fill('#email', uniqueEmail());
    await pageNoJs.fill('#phone', uniquePhone());
    // Do not attach documents — leave the required file inputs empty.
    await pageNoJs.locator(submitBtn).click();
    await pageNoJs.waitForLoadState('domcontentloaded');
    await pageNoJs.waitForTimeout(1500);
    await pageNoJs.waitForSelector('text=Fix in Step 3', { timeout: 10000 });
    const initial = await pageNoJs.locator('form').getAttribute('data-wizard-initial-step');
    if (initial !== '2') throw new Error(`Expected initial step 2, got ${initial}`);
  });

  await contextNoJs.close();
  await browserNoJs.close();
};

(async () => {
  try {
    await spec();
  } catch (e) {
    console.log(`FAIL  (context setup)  -- ${String(e && e.message || e)}`);
    results.push({ ok: false, name: '(context setup)', error: String(e && e.message || e) });
  }

  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} browser tests passed in ~${(results.reduce((s, r) => s + r.ms, 0) / 1000).toFixed(1)}s`);

  console.log('CREATED_EMAILS=' + createdEmails.join(','));

  process.exitCode = failed.length ? 1 : 0;
})();