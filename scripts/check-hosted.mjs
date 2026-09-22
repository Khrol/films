import { chromium, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { checkSearchLayout } from './check-search-layout.mjs';

const site = 'https://films.khroliz.com';
const browser = await chromium.launch({ headless: true, ...(process.platform === 'darwin' ? { channel: 'chrome' } : {}) });
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const response = await page.goto(`${site}/`, { waitUntil: 'domcontentloaded' });
  console.log('App page HTTP status:', response.status());
  await expect(page).toHaveURL(`${site}/`);
  if (response.status() !== 200) throw new Error('The homepage did not load successfully.');
  await expect(page.getByRole('heading', { name: 'Come on in.' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Open my diary' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'More sign-in options' })).toHaveAttribute('href', /wp-login\.php/);
  await expect(page.locator('.brand')).toHaveAttribute('href', `${site}/`);
  console.log('PASS Diary opens directly at /.');
  await page.locator('#request-invitation summary').click();
  await expect(page.getByLabel('Your name', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Email address', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Request an invitation', exact: true })).toBeVisible();
  // Inspect the live form without submitting a real invitation request.
  console.log('PASS Public invitation request form is available.');
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/hosted-login.png', fullPage: true });
  const api = await page.request.get(`${site}/wp-json/reel-together/v1/bootstrap`);
  console.log('Anonymous API HTTP status:', api.status());
  if (![401, 403].includes(api.status())) throw new Error('Anonymous API access did not reject the request.');
  const css = await page.request.get(`${site}/wp-content/plugins/reel-together/assets/app.css?ver=0.6.0`);
  if (!css.ok()) throw new Error('The deployed stylesheet did not load.');
  console.log('PASS Hosted page, login controls, stylesheet, and anonymous API protection.');
  const checkout = await page.request.post(`${site}/wp-json/reel-together/v1/billing/checkout`, { data: {} });
  if (checkout.status() !== 401) throw new Error('Anonymous checkout did not reject the request.');
  const webhook = await page.request.post(`${site}/wp-json/reel-together/v1/billing/webhook/live`, { data: { type: 'invoice.paid' } });
  if (webhook.status() !== 400) throw new Error('An unsigned billing event was not rejected.');
  const billing = await page.request.get(`${site}/wp-content/plugins/reel-together/assets/billing.js?ver=0.6.0`);
  if (!billing.ok()) throw new Error('The billing script did not load.');
  console.log('PASS Billing assets load; checkout requires login and payment events require a signature.');
  // Render the same collection control in this browser only to exercise the host's
  // complete theme/plugin CSS without creating a user or modifying hosted data.
  await page.evaluate(() => {
    document.querySelector('main').innerHTML = '<section class="filters"><form id="filter-form" role="search"><label class="sr-only" for="filter-query">Search your collection</label><input type="search" id="filter-query" placeholder="Find a film in your collection…"><button class="search-button" type="submit" aria-label="Search collection"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" stroke-width="2"/><path d="m16 16 5 5" stroke="currentColor" stroke-width="2"/></svg></button></form></section>';
  });
  if (process.argv.includes('--inspect-layout')) {
    console.log(await page.locator('#filter-form').evaluate(form => {
      const button = form.querySelector('button');
      const input = form.querySelector('input');
      const rules = [];
      const walk = list => { for (const rule of list) {
        if (rule.selectorText && button.matches(rule.selectorText)) rules.push({ selector: rule.selectorText, style: rule.style.cssText });
        else if (rule.cssRules) walk(rule.cssRules);
      } };
      for (const sheet of document.styleSheets) { try { walk(sheet.cssRules); } catch {} }
      return { input: input.getBoundingClientRect().toJSON(), button: button.getBoundingClientRect().toJSON(), padding: getComputedStyle(button).padding, rules };
    }));
  } else {
    await checkSearchLayout(page);
    await page.screenshot({ path: 'test-results/hosted-search-desktop.png' });
    await page.setViewportSize({ width: 390, height: 844 });
    await checkSearchLayout(page);
    await page.screenshot({ path: 'test-results/hosted-search-mobile.png' });
    const favicon = page.locator('link[rel="icon"][type="image/svg+xml"]');
    await expect(favicon).toHaveAttribute('href', /assets\/favicon\.svg\?ver=0\.6\.0/);
    if (!(await page.request.get(await favicon.getAttribute('href'))).ok()) throw new Error('The deployed favicon did not load.');
    console.log('PASS Hosted styles: search button fits its field on desktop and mobile; favicon loads.');
  }
} finally { await browser.close(); }
