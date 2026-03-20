class MultiCart {
  constructor({ nonce, ajaxUrl }) {
    this.nonce     = nonce;
    this.ajaxUrl   = ajaxUrl;
    this.switcher  = document.querySelector('.multicart-switcher');
    this.bindEvents();
  }

  bindEvents() {
    document.querySelectorAll('.multicart-btn').forEach((btn) => {
      if (!btn.classList.contains('multicart-btn--active')) {
        btn.addEventListener('click', () => this.switchCart(btn));
      }
      btn.querySelector('.multicart-btn__rename')
        ?.addEventListener('click', (e) => { e.stopPropagation(); this.renameCart(btn); });
      btn.querySelector('.multicart-btn__delete')
        ?.addEventListener('click', (e) => { e.stopPropagation(); this.deleteCart(btn); });
    });

    document.querySelector('.multicart-new')
      ?.addEventListener('click', () => this.createCart());
  }

  post(action, data) {
    const params = new URLSearchParams(data);
    params.append('action', action);
    params.append('nonce', this.nonce);
    return fetch(this.ajaxUrl, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:        params.toString(),
    })
    .then((r) => r.json())
    .catch((e) => console.error('MultiCart error', e));
  }

  setLoading(loading) {
    this.switcher?.classList.toggle('multicart-switcher--loading', loading);
    this.switcher?.querySelectorAll('button').forEach((btn) => {
      btn.disabled = loading;
    });

    const $form = jQuery('.woocommerce-cart-form');
    if (loading) {
      $form.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });
    } else {
      $form.unblock();
    }
  }

  // Optimistic: update DOM immediately, revert on failure
  renameCart(btn) {
    const label   = btn.querySelector('.multicart-btn__label');
    const oldName = label.textContent.trim();
    const name    = prompt('Neuer Name:', oldName);
    if (!name?.trim()) { return; }

    label.textContent = name.trim();

    this.post('multicart_rename', { cart_id: btn.dataset.cartId, name: name.trim() })
      .then((res) => {
        if (!res?.success) { label.textContent = oldName; }
      });
  }

  // Optimistic: hide button immediately, restore on failure
  deleteCart(btn) {
    if (!confirm('Warenkorb löschen?')) { return; }

    const next = btn.nextSibling;
    const parent = btn.parentNode;
    btn.remove();

    this.post('multicart_delete', { cart_id: btn.dataset.cartId })
      .then((res) => {
        if (res?.success) {
          if (res.data?.reloaded) { location.reload(); }
        } else {
          parent.insertBefore(btn, next);
          alert(res?.data || 'Fehler beim Löschen.');
        }
      });
  }

  // Requires reload — show loading state immediately
  switchCart(btn) {
    this.setLoading(true);
    btn.classList.add('multicart-btn--active');

    this.post('multicart_switch', { cart_id: btn.dataset.cartId })
      .then((res) => {
        if (res?.success) {
          location.reload();
        } else {
          btn.classList.remove('multicart-btn--active');
          this.setLoading(false);
        }
      });
  }

  // Requires reload — show loading state immediately
  createCart() {
    const count = document.querySelectorAll('.multicart-btn').length;
    const name  = prompt('Name des neuen Warenkorbs:', `Warenkorb ${count + 1}`);
    if (name === null) { return; }

    this.setLoading(true);

    this.post('multicart_create', { name: name.trim() || `Warenkorb ${count + 1}` })
      .then((res) => {
        if (res?.success) {
          location.reload();
        } else {
          this.setLoading(false);
        }
      });
  }
}

new MultiCart(multicartData);