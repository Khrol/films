import assert from 'node:assert/strict';

export async function checkSearchLayout(page) {
  const measurements = await page.locator('#filter-form').evaluate(form => {
    const input = form.querySelector('input').getBoundingClientRect();
    const button = form.querySelector('button').getBoundingClientRect();
    const style = getComputedStyle(form.querySelector('button'));
    return {
      contained: button.top >= input.top && button.bottom <= input.bottom && button.left >= input.left && button.right <= input.right,
      width: button.width, height: button.height, padding: style.padding,
      fitsViewport: document.documentElement.scrollWidth <= innerWidth,
    };
  });
  assert.equal(measurements.contained, true, `Search button must fit inside its field: ${JSON.stringify(measurements)}`);
  assert.ok(measurements.width >= 40 && measurements.width <= 44 && measurements.height >= 40 && measurements.height <= 44);
  assert.equal(measurements.fitsViewport, true, 'The page must fit its viewport');
  return measurements;
}
