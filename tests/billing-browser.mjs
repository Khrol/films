import assert from 'node:assert/strict';
import path from 'node:path';
import { expect } from '@playwright/test';

export async function checkBilling(browser, instance, out) {
  const adminContext = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
  const personContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const errors = [];
  const run = async code => (await instance.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` })).text;
  try {
    const uid = Number(await run("echo wp_insert_user(array('user_login'=>'billing_browser','user_pass'=>'test-movie-night','user_email'=>'billing_browser@example.test','role'=>'subscriber'));"));
    assert.ok(Number.isSafeInteger(uid) && uid > 0);
    const owner = await adminContext.newPage();
    const person = await personContext.newPage();
    for (const [page, username] of [[owner, 'reviewer'], [person, 'billing_browser']]) {
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(instance.serverUrl);
      await page.getByLabel('Username or Email Address').fill(username);
      await page.getByLabel('Password', { exact: true }).fill('test-movie-night');
      await page.getByRole('button', { name: 'Open my diary' }).click();
      await expect(page.getByRole('heading', { name: 'Your life in movies.' })).toBeVisible();
    }
    await person.getByRole('button', { name: '＋ Log a film', exact: true }).click();
    await person.getByLabel('Film title', { exact: true }).fill('My film survives membership changes');
    await person.getByRole('button', { name: 'Save film ↗' }).click();
    await expect(person.getByRole('heading', { name: 'My film survives membership changes' })).toBeVisible();
    await owner.goto(`${instance.serverUrl}/wp-admin/options-general.php?page=reel-together`);
    await expect(owner.getByRole('heading', { name: 'Membership — €1 per person, per month' })).toBeVisible();
    await expect(owner.getByLabel('Stripe secret key')).toHaveValue('');
    await owner.getByLabel('Connection mode').selectOption('test');
    await owner.getByRole('button', { name: 'Connect and prepare Stripe' }).click();
    await expect(owner.getByText('Stripe connected. The €1/month plan and customer portal are ready in test mode.')).toBeVisible();
    await owner.getByRole('checkbox', { name: 'Open €1/month subscriptions and require paid or complimentary access.' }).check();
    await owner.getByRole('button', { name: 'Save membership access' }).click();
    await expect(owner.getByText('Paid membership is now open. Complimentary users retain free access.')).toBeVisible();
    await person.reload();
    await expect(person.getByRole('button', { name: 'Subscribe for €1/month' })).toBeVisible();
    await expect(person.getByRole('heading', { name: 'My film survives membership changes' })).toHaveCount(0);
    assert.equal(await person.evaluate(async () => (await fetch(window.ReelTogether.api + 'billing/checkout', { method: 'POST' })).status), 401, 'Checkout rejects cookie authentication without a REST nonce');
    assert.equal(await person.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Membership screen fits mobile');
    await person.screenshot({ path: path.join(out, 'membership-mobile.png'), fullPage: true });

    // Stripe-hosted pages and API calls are local fixtures; no live checkout or charge occurs.
    await personContext.route('https://checkout.stripe.com/**', route => route.fulfill({ contentType: 'text/html', body: '<h1>Stripe checkout fixture</h1>' }));
    await personContext.route('https://billing.stripe.com/**', route => route.fulfill({ contentType: 'text/html', body: '<h1>Stripe billing portal fixture</h1>' }));
    await person.getByRole('button', { name: 'Subscribe for €1/month' }).click();
    await expect(person.getByRole('heading', { name: 'Stripe checkout fixture' })).toBeVisible();
    const session = await run(`echo rt_mock_complete_checkout(${uid});`);
    await person.goto(`${instance.serverUrl}/?membership=1&billing_mode=live&checkout_session=${encodeURIComponent(session)}`);
    await expect(person.getByText('Your membership is active.', { exact: true })).toBeVisible();
    await expect(person.getByRole('button', { name: 'Subscribe for €1/month' })).toHaveCount(0);
    await person.getByRole('button', { name: 'Manage billing' }).click();
    await expect(person.getByRole('heading', { name: 'Stripe billing portal fixture' })).toBeVisible();
    await person.goto(instance.serverUrl);
    await expect(person.getByRole('heading', { name: 'My film survives membership changes' })).toBeVisible();
    await person.getByRole('link', { name: 'Membership', exact: true }).click();
    await expect(person.getByText('Your membership is active.', { exact: true })).toBeVisible();
    await person.screenshot({ path: path.join(out, 'membership-active-mobile.png'), fullPage: true });

    await run(`rt_mock_subscription(${uid}, 'live', array('status'=>'canceled')); RT_Stripe::sync(${uid}, 'live');`);
    await person.goto(instance.serverUrl);
    await expect(person.getByRole('button', { name: 'Subscribe for €1/month' })).toBeVisible();
    await owner.goto(`${instance.serverUrl}/wp-admin/user-edit.php?user_id=${uid}`);
    await owner.getByRole('checkbox', { name: 'Complimentary access — this person does not need a paid subscription.' }).check();
    await owner.getByRole('button', { name: 'Update User', exact: true }).click();
    await expect(owner.getByText('User updated.', { exact: true })).toBeVisible();
    await person.goto(instance.serverUrl);
    await expect(person.getByRole('heading', { name: 'My film survives membership changes' })).toBeVisible();
    await person.getByRole('link', { name: 'Membership', exact: true }).click();
    await expect(person.getByText('The site owner has given you complimentary access.')).toBeVisible();
    await expect(person.getByRole('button', { name: 'Manage billing' })).toBeVisible();
    await expect(person.getByRole('button', { name: 'Subscribe for €1/month' })).toHaveCount(0);
    await owner.getByRole('checkbox', { name: 'Complimentary access — this person does not need a paid subscription.' }).uncheck();
    await owner.getByRole('button', { name: 'Update User', exact: true }).click();
    await expect(owner.getByText('User updated.', { exact: true })).toBeVisible();
    await person.goto(instance.serverUrl);
    await expect(person.getByRole('button', { name: 'Subscribe for €1/month' })).toBeVisible();
    assert.deepEqual(errors, [], 'Membership flows have no JavaScript errors');
    console.log('PASS Browser: Stripe setup, payment screen, verified checkout return, billing portal, expiry, manual complimentary access and mobile layout (mocked Stripe)');
  } finally {
    await run("update_option('rt_membership_enabled', 'no');");
    await adminContext.close();
    await personContext.close();
  }
}
