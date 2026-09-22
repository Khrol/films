(() => {
  'use strict';
  document.querySelectorAll('[data-billing-action]').forEach(button => button.addEventListener('click', async () => {
    const error = document.getElementById('billing-error');
    button.disabled = true;
    error.textContent = '';
    try {
      const url = new URL(window.ReelTogether.api);
      const route = `billing/${button.dataset.billingAction}`;
      if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route') + route);
      else url.pathname += route;
      const response = await fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-WP-Nonce': window.ReelTogether.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ mode: button.dataset.mode }) });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || 'Could not open billing. Please try again.');
      const target = new URL(result.url);
      if (target.protocol !== 'https:' || !['checkout.stripe.com', 'billing.stripe.com'].includes(target.hostname) || target.username || target.password) throw new Error('Could not open billing. Please try again.');
      window.location.assign(target.href);
    } catch (failure) { error.textContent = failure.message; button.disabled = false; }
  }));
})();
