/*! JotKite M1 theme · https://jotkite.com · SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial */
(function () {
  'use strict';
  var btn = document.querySelector('.m1-burger');
  var menu = document.getElementById('m1-menu');
  if (!btn || !menu) return;
  function set(open) {
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    menu.hidden = !open;
  }
  btn.addEventListener('click', function () { set(btn.getAttribute('aria-expanded') !== 'true'); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !menu.hidden) { set(false); btn.focus(); }
  });
  document.addEventListener('click', function (e) {
    if (!menu.hidden && !menu.contains(e.target) && !btn.contains(e.target)) set(false);
  });
})();
