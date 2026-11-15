/* JotKite: dropdown menus (Settings → Navigation sub-items).
 * The ▾ button opens and closes a main link's sub-items; a tap elsewhere or Escape closes them.
 * On computers they also open on hover and keyboard focus, from CSS alone.
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial */
(function () {
  'use strict';
  const close = (except) => {
    document.querySelectorAll('.pb-dd-open').forEach((d) => {
      if (d === except) return;
      d.classList.remove('pb-dd-open');
      const b = d.querySelector('.pb-dd-btn');
      if (b) b.setAttribute('aria-expanded', 'false');
    });
  };
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.pb-dd-btn');
    if (!btn) { if (!e.target.closest('.pb-dd-menu')) close(null); return; }
    const dd = btn.parentNode;
    close(dd);
    const open = dd.classList.toggle('pb-dd-open');
    btn.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = document.querySelector('.pb-dd-open .pb-dd-btn');
    close(null);
    if (open) open.focus();
  });
})();
