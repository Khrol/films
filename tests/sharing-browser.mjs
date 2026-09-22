import assert from 'node:assert/strict';
import path from 'node:path';
import { expect } from '@playwright/test';

export async function checkSharing(browser, url, out) {
  const ownerContext = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
  const memberContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const errors = [];
  try {
    const owner = await ownerContext.newPage();
    const member = await memberContext.newPage();
    for (const [page, username] of [[owner, 'sharing_browser'], [member, 'linked_browser']]) {
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(url);
      await page.getByLabel('Username or Email Address').fill(username);
      await page.getByLabel('Password', { exact: true }).fill('test-movie-night');
      await page.getByRole('button', { name: 'Open my diary' }).click();
      await expect(page.getByRole('heading', { name: 'Your life in movies.' })).toBeVisible();
    }
    await owner.getByRole('button', { name: /Our household/ }).click();
    await owner.getByLabel('Household name').fill('Linked browser family');
    await owner.getByRole('button', { name: 'Create household ↗' }).click();
    await owner.getByRole('button', { name: 'Create invitation code ↗' }).click();
    const code = await owner.getByLabel('Share this code').inputValue();
    await member.getByRole('button', { name: /Our household/ }).click();
    await member.getByLabel('Invitation code').fill(code);
    await member.getByRole('button', { name: 'Join household ↗' }).click();
    await expect(member.getByRole('heading', { name: 'Linked browser family' })).toBeVisible();

    await owner.getByRole('button', { name: /Film diary/ }).click();
    await owner.getByRole('button', { name: '＋ Log a film', exact: true }).click();
    await owner.getByLabel('Film title', { exact: true }).fill('Our earlier private viewing');
    await owner.getByRole('dialog').getByLabel('Your rating').selectOption('4');
    await owner.getByRole('dialog').getByLabel('Watched with', { exact: true }).selectOption('companions');
    await owner.getByRole('button', { name: '＋ My wife', exact: true }).click();
    await expect(owner.getByRole('checkbox', { name: 'My wife', exact: true })).toBeChecked();
    await owner.getByLabel('A little note').fill('A note shared only by explicit choice.');
    await owner.getByRole('button', { name: 'Save film ↗' }).click();
    await expect(owner.getByRole('heading', { name: 'Our earlier private viewing' })).toBeVisible();
    await owner.getByRole('button', { name: /Watching companions/ }).click();
    const form = owner.locator('.companion-edit');
    await form.getByLabel('Family account (optional)').selectOption({ label: 'Linked_browser' });
    await expect(form.getByRole('checkbox')).not.toBeChecked();
    await form.getByRole('button', { name: 'Save companion' }).click();
    await expect(owner.locator('#toast')).toContainText('Companion updated');
    await expect(form.getByRole('button', { name: 'Save companion' })).toBeEnabled();
    await member.getByRole('button', { name: /Film diary/ }).click();
    await expect(member.getByRole('heading', { name: 'Your first memory starts here.' })).toBeVisible();

    await form.getByRole('checkbox').check();
    await form.getByRole('button', { name: 'Save companion' }).click();
    await expect(owner.locator('#toast')).toContainText('1 earlier viewing(s) shared');
    await expect(form.getByRole('checkbox')).not.toBeChecked();
    await owner.screenshot({ path: path.join(out, 'linked-companions-desktop.png'), fullPage: true });
    await owner.setViewportSize({ width: 390, height: 844 });
    assert.equal(await owner.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Linked companion form fits mobile');
    await owner.screenshot({ path: path.join(out, 'linked-companions-mobile.png'), fullPage: true });
    await member.reload();
    await expect(member.getByRole('button', { name: 'My viewings', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(member.getByText('A note shared only by explicit choice.')).toBeVisible();
    await expect(member.getByText('You watched this', { exact: true })).toBeVisible();
    await expect(member.getByLabel('Sharing_browser’s rating: 4 out of 5 stars')).toBeVisible();
    await expect(member.getByRole('button', { name: 'Edit entry ↗' })).toHaveCount(0);
    await member.screenshot({ path: path.join(out, 'linked-diary-mobile.png'), fullPage: true });

    await owner.getByRole('button', { name: /Film diary/ }).click();
    await owner.getByRole('button', { name: 'Edit entry ↗' }).click();
    await expect(owner.getByLabel('Who can see this?')).toHaveValue('linked');
    await expect(owner.locator('#sharing-choices').getByRole('checkbox')).toBeChecked();
    await owner.getByLabel('Who can see this?').selectOption('personal');
    await owner.getByRole('button', { name: 'Save film ↗' }).click();
    await expect(owner.getByRole('dialog')).not.toBeVisible();
    await member.reload();
    await expect(member.getByRole('heading', { name: 'Your first memory starts here.' })).toBeVisible();

    await owner.getByRole('button', { name: 'Edit entry ↗' }).click();
    await owner.getByLabel('Who can see this?').selectOption('linked');
    await expect(owner.locator('#sharing-choices').getByRole('checkbox')).not.toBeChecked();
    await owner.locator('#sharing-choices').getByRole('checkbox').check();
    await owner.screenshot({ path: path.join(out, 'linked-sharing-mobile.png'), fullPage: true });
    await owner.getByRole('button', { name: 'Save film ↗' }).click();
    await expect(owner.getByRole('dialog')).not.toBeVisible();
    await member.reload();
    await expect(member.getByRole('heading', { name: 'Our earlier private viewing' })).toBeVisible();
    await owner.getByRole('button', { name: /Watching companions/ }).click();
    await form.getByLabel('Family account (optional)').selectOption('0');
    await form.getByRole('button', { name: 'Save companion' }).click();
    await expect(owner.locator('#toast')).toContainText('Companion updated');
    await member.reload();
    await expect(member.getByRole('heading', { name: 'Your first memory starts here.' })).toBeVisible();
    assert.deepEqual(errors, [], 'Linked account flows have no JavaScript errors');
    console.log('PASS Browser: two family accounts, private-by-default linking, explicit historical and individual sharing, author attribution, mobile layouts and revocation');
  } finally {
    await ownerContext.close();
    await memberContext.close();
  }
}
