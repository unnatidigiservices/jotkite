/*! JotKite — gallery photo viewer · https://jotkite.com
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 * Loaded only on pages with a gallery. Tap a photo: full screen, swipe or use the
 * arrow keys to move, Esc or ✕ to close. No dependencies, about 2 KB. */
(function () {
  'use strict';
  var galleries = document.querySelectorAll('.pb-gallery');
  if (!galleries.length) return;
  var box, img, count, list = [], at = 0, startX = null, lastFocus = null;

  function build() {
    box = document.createElement('div');
    box.className = 'pb-viewer';
    box.hidden = true;
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Photo viewer');
    box.innerHTML = '<img alt=""><button type="button" class="pb-viewer-prev" aria-label="Previous photo">&#8249;</button>'
      + '<button type="button" class="pb-viewer-next" aria-label="Next photo">&#8250;</button>'
      + '<button type="button" class="pb-viewer-close" aria-label="Close">&#10005;</button><span class="pb-viewer-count"></span>';
    document.body.appendChild(box);
    img = box.querySelector('img');
    count = box.querySelector('.pb-viewer-count');
    box.querySelector('.pb-viewer-prev').addEventListener('click', function (e) { e.stopPropagation(); go(-1); });
    box.querySelector('.pb-viewer-next').addEventListener('click', function (e) { e.stopPropagation(); go(1); });
    box.querySelector('.pb-viewer-close').addEventListener('click', close);
    box.addEventListener('click', function (e) { if (e.target === box) close(); });
    box.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) {
      if (startX === null) return;
      var dx = e.changedTouches[0].clientX - startX;
      startX = null;
      if (Math.abs(dx) > 40) go(dx < 0 ? 1 : -1);
    });
    document.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'Escape') close();
      else if (e.key === 'ArrowRight') go(1);
      else if (e.key === 'ArrowLeft') go(-1);
    });
  }
  function show() {
    var p = list[at];
    img.src = p.currentSrc || p.src;
    img.alt = p.alt;
    count.textContent = list.length > 1 ? (at + 1) + ' / ' + list.length : '';
    box.querySelector('.pb-viewer-prev').hidden = list.length < 2;
    box.querySelector('.pb-viewer-next').hidden = list.length < 2;
  }
  function go(step) { at = (at + step + list.length) % list.length; show(); }
  function open(gallery, photo) {
    if (!box) build();
    list = Array.prototype.slice.call(gallery.querySelectorAll('img'));
    at = Math.max(0, list.indexOf(photo));
    lastFocus = document.activeElement;
    box.hidden = false;
    document.documentElement.style.overflow = 'hidden';
    show();
    box.querySelector('.pb-viewer-close').focus();
  }
  function close() {
    box.hidden = true;
    document.documentElement.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  Array.prototype.forEach.call(galleries, function (g) {
    Array.prototype.forEach.call(g.querySelectorAll('img'), function (p) {
      p.setAttribute('tabindex', '0');
      p.setAttribute('role', 'button');
      p.addEventListener('click', function () { open(g, p); });
      p.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(g, p); } });
    });
  });
})();
