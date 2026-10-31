(() => {
  'use strict';
  document.querySelectorAll('.afr-form').forEach(form => {
    const comment = form.querySelector('.afr-comment');
    const textarea = form.querySelector('textarea');
    const button = form.querySelector('button[type="submit"]');
    const status = form.querySelector('.afr-status');
    let pending = false;
    const update = () => {
      const chosen = form.querySelector('input[name="rating"]:checked');
      const show = chosen && Number(chosen.value) < 5;
      comment.hidden = !show;
      textarea.disabled = !show;
    };
    form.addEventListener('change', update);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (pending || !form.reportValidity()) return;
      pending = true;
      button.disabled = true;
      form.setAttribute('aria-busy', 'true');
      status.textContent = 'در حال ارسال…';
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 20000);
      try {
        const tokenBody = new URLSearchParams({ action: 'afr_token' });
        const tokenResponse = await fetch(form.dataset.endpoint, { method: 'POST', body: tokenBody, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        const token = await tokenResponse.json();
        if (!tokenResponse.ok || !token.success) throw new Error('دریافت اعتبار فرم ناموفق بود.');
        const data = new FormData(form);
        data.set('action', 'afr_submit');
        data.set('nonce', token.data.nonce);
        const response = await fetch(form.dataset.endpoint, { method: 'POST', body: data, credentials: 'same-origin', signal: controller.signal });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.data?.message || 'ارسال ناموفق بود.');
        status.textContent = result.data.message;
        form.reset();
        update();
      } catch (error) {
        status.textContent = error.name === 'AbortError' ? 'پاسخ سرور نرسید؛ پیش از ارسال مجدد کمی صبر کنید.' : (error.message || 'اتصال برقرار نشد؛ دوباره تلاش کنید.');
      } finally {
        clearTimeout(timeout);
        pending = false;
        button.disabled = false;
        form.removeAttribute('aria-busy');
      }
    });
    update();
  });
})();
