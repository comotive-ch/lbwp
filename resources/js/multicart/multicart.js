class MultiCart {
  constructor({ nonce, ajaxUrl, i18n }) {
    this.nonce    = nonce;
    this.ajaxUrl  = ajaxUrl;
    this.i18n     = i18n;
    this.switcher = document.querySelector('.multicart-switcher');
    this.bindEvents();
    this.bindCartUpdates();
  }

  /**
   * WooCommerce replaces parts of the cart markup via AJAX (remove item, empty
   * cart). The injected HTML carries its own switcher while the original one
   * survives outside the replaced container, so drop the extra copies.
   */
  bindCartUpdates() {
    if (typeof jQuery === 'undefined') { return; }

    jQuery(document.body).on('updated_wc_div wc_cart_emptied updated_cart_totals', () => {
      this.removeDuplicateSwitchers();
    });
  }

  // Keep the first switcher (its listeners are still bound), remove the rest
  removeDuplicateSwitchers() {
    const switchers = document.querySelectorAll('.multicart-switcher');
    if (switchers.length < 2) { return; }

    switchers.forEach((el, index) => {
      if (index > 0) { el.remove(); }
    });

    this.switcher = switchers[0];
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
    const name    = prompt(this.i18n.promptRename, oldName);
    if (!name?.trim()) { return; }

    label.textContent = name.trim();

    this.post('multicart_rename', { cart_id: btn.dataset.cartId, name: name.trim() })
      .then((res) => {
        if (!res?.success) { label.textContent = oldName; }
      });
  }

  // Optimistic: hide button immediately, restore on failure
  deleteCart(btn) {
    if (!confirm(this.i18n.confirmDelete)) { return; }

    const next   = btn.nextSibling;
    const parent = btn.parentNode;
    btn.remove();

    this.post('multicart_delete', { cart_id: btn.dataset.cartId })
      .then((res) => {
        if (res?.success) {
          if (res.data?.reloaded) { location.reload(); }
        } else {
          parent.insertBefore(btn, next);
          alert(res?.data || this.i18n.errorDelete);
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
    const count       = document.querySelectorAll('.multicart-btn').length;
    const defaultName = `${this.i18n.defaultName} ${count + 1}`;
    const name        = prompt(this.i18n.promptNew, defaultName);
    if (name === null) { return; }

    this.setLoading(true);

    this.post('multicart_create', { name: name.trim() || defaultName })
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
