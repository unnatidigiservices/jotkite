/*! JotKite — admin script · https://jotkite.com
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 * A deliberately small rich-text editor: contenteditable + a toolbar. The
 * server re-sanitizes everything on save (lib/postbase.php), so this file
 * only has to keep the editing experience tidy, not enforce security. */
(function () {
  'use strict';
  const PB = window.PB || {};
  const $ = (s, r) => (r || document).querySelector(s);
  const $all = (s, r) => Array.from((r || document).querySelectorAll(s));
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const bubble = (el) => el.dispatchEvent(new Event('input', { bubbles: true }));

  // Confirm dangerous buttons.
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });

  // On phones the menu and the editor toolbar scroll sideways, which people
  // don't expect. A small animated › at the right edge says "more this way".
  // At the dead end it turns into ‹ on the left, to go back. Tapping either scrolls.
  $all('.pb-sidebar nav, .pb-toolbar').forEach((box) => {
    const make = (cls, text, dir) => {
      const h = document.createElement('span');
      h.className = 'pb-more ' + cls;
      h.setAttribute('aria-hidden', 'true');
      h.textContent = text;
      h.addEventListener('mousedown', (e) => e.preventDefault()); // keep the editor's selection
      h.addEventListener('click', () => box.scrollBy({ left: dir * box.clientWidth * 0.6, behavior: 'smooth' }));
      return h;
    };
    box.insertBefore(make('pb-more-left', '‹', -1), box.firstChild);
    box.appendChild(make('pb-more-right', '›', 1));
    const update = () => {
      const more = box.scrollWidth - box.clientWidth - box.scrollLeft > 6;
      box.classList.toggle('pb-can-scroll', more);
      box.classList.toggle('pb-at-end', !more && box.scrollLeft > 6);
    };
    box.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  });

  // ---- "Put JotKite on your home screen" ----
  // Android/Chrome can install with one tap (beforeinstallprompt); iPhone can't be
  // prompted by a web page, so it gets the Safari steps instead. Shown on phones
  // only, never inside the installed app, and not again once dismissed.
  if ('serviceWorker' in navigator && PB.adminUrl && (location.protocol === 'https:' || /^(localhost|127\.0\.0\.1)$/.test(location.hostname))) {
    navigator.serviceWorker.register(PB.adminUrl + '?sw=1', { scope: PB.adminUrl }).catch(() => { /* optional */ });
  }
  const store = { get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } } };
  const inApp = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  const ua = navigator.userAgent;
  const isIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isPhone = isIOS || /Android|Mobi/i.test(ua);
  const installBox = $('#pbInstall');
  let installEvent = null;
  const hideInstall = (remember) => { if (installBox) installBox.hidden = true; if (remember) store.set('pb_install_dismissed', '1'); };
  if (installBox && isPhone && !inApp && store.get('pb_install_dismissed') !== '1') {
    if (isIOS) {
      $('[data-install-how]', installBox).innerHTML = 'Tap <span class="pb-share-ico" aria-label="Share"></span> <b>Share</b> in Safari, then <b>Add to Home Screen</b>. It opens straight on Write.';
    }
    installBox.hidden = false;
    $('#pbInstallClose').addEventListener('click', () => hideInstall(true));
  }
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // we show our own button instead of the browser's mini bar
    installEvent = e;
    $all('[data-install-btn]').forEach((b) => { b.hidden = false; });
    if (installBox && !installBox.hidden) $('[data-install-how]', installBox).textContent = 'One tap. It opens straight on Write and stays signed in.';
  });
  $all('[data-install-btn]').forEach((b) => b.addEventListener('click', () => {
    if (!installEvent) return;
    installEvent.prompt();
    installEvent.userChoice.then((c) => { if (c.outcome === 'accepted') hideInstall(true); }).catch(() => {});
    installEvent = null;
    $all('[data-install-btn]').forEach((x) => { x.hidden = true; });
  }));
  window.addEventListener('appinstalled', () => hideInstall(true));

  // Character counters for SEO fields.
  $all('.pb-count').forEach((c) => {
    const f = document.getElementById(c.dataset.for);
    if (!f) return;
    const max = Number(c.dataset.max);
    const update = () => { const n = f.value.length; c.textContent = n + '/' + max; c.classList.toggle('over', n > max); };
    f.addEventListener('input', update);
    update();
  });

  // Phone photos are 3–15 MB and 4000+ px wide. Shrinking them in the browser
  // first makes uploads fast on mobile data, keeps them under the size limit,
  // and applies the camera's rotation. The photo's details (location, camera,
  // date) are carried over unless Settings → General removes them. GIFs
  // (animation) and small images are sent untouched; any failure sends the original.
  function shrink(file) {
    const maxPx = PB.maxPx || 1600;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.URL || !URL.createObjectURL) return Promise.resolve(file);
    // An <img> (unlike createImageBitmap in older browsers) always honours the EXIF rotation.
    return new Promise((res, rej) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => { URL.revokeObjectURL(url); res(img); };
      img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('decode')); };
      img.src = url;
    }).then((img) => {
      const iw = img.naturalWidth, ih = img.naturalHeight;
      const big = iw > maxPx;
      if (!iw || (!big && file.size <= 1048576)) return file;
      const w = big ? maxPx : iw;
      const h = Math.round(ih * w / iw);
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
      const ctx = canvas.getContext('2d');
      if (type === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h); } // transparent WebP
      ctx.drawImage(img, 0, 0, w, h);
      const keep = PB.keepMeta && file.type === 'image/jpeg' && file.slice
        ? file.slice(0, 131072).arrayBuffer().then(exifSegment).catch(() => null) : Promise.resolve(null);
      // High quality here: the server re-encodes to WebP once, and that's the only real compression.
      return Promise.all([new Promise((res) => canvas.toBlob(res, type, 0.92)), keep]).then(([blob, exif]) => {
        if (!blob || (blob.size >= file.size && !big)) return file;
        // The canvas drops the camera's metadata; carry it over (location, camera,
        // date), marked upright because the canvas already turned the pixels.
        if (exif && type === 'image/jpeg') blob = new Blob([blob.slice(0, 2), upright(exif), blob.slice(2)], { type });
        const name = file.name.replace(/\.[a-z0-9]+$/i, '') + (type === 'image/png' ? '.png' : '.jpg');
        return new File([blob], name, { type });
      });
    }).catch(() => file);
  }

  // The EXIF (APP1) segment of a JPEG, or null. Same walk as lib/media.php.
  function exifSegment(buf) {
    const v = new DataView(buf);
    if (v.byteLength < 4 || v.getUint16(0) !== 0xFFD8) return null;
    let i = 2;
    while (i + 4 <= v.byteLength && v.getUint8(i) === 0xFF) {
      const m = v.getUint8(i + 1);
      if (m === 0xFF) { i++; continue; }
      if (m === 0xDA || m === 0xD9) break;
      if (m === 0x01 || (m >= 0xD0 && m <= 0xD7)) { i += 2; continue; }
      const len = v.getUint16(i + 2);
      if (m === 0xE1 && i + 10 <= v.byteLength && v.getUint32(i + 4) === 0x45786966 && v.getUint16(i + 8) === 0) {
        return i + 2 + len <= v.byteLength ? new Uint8Array(buf.slice(i, i + 2 + len)) : null;
      }
      i += 2 + len;
    }
    return null;
  }
  // Sets the EXIF orientation tag (0x0112) to 1 = upright.
  function upright(seg) {
    const v = new DataView(seg.buffer);
    const t = 10;
    if (seg.length < t + 8) return seg;
    const le = v.getUint16(t) === 0x4949;
    const ifd = t + v.getUint32(t + 4, le);
    if (ifd + 2 > seg.length) return seg;
    const n = v.getUint16(ifd, le);
    for (let k = 0; k < n; k++) {
      const e = ifd + 2 + k * 12;
      if (e + 12 > seg.length) break;
      if (v.getUint16(e, le) === 0x0112) v.setUint16(e + 8, 1, le);
    }
    return seg;
  }

  // keepFormat: the server stores everything as WebP except these (favicon, social image).
  function upload(original, keepFormat) {
    return shrink(original).then((f) => send(f, keepFormat));
  }

  function send(file, keepFormat) {
    if (file.size > (PB.maxMb || 5) * 1048576) return Promise.reject(new Error('Images must be ' + (PB.maxMb || 5) + ' MB or smaller.'));
    const fd = new FormData();
    fd.append('do', 'upload');
    fd.append('_csrf', PB.csrf);
    if (keepFormat) fd.append('keep_format', '1');
    fd.append('file', file);
    return fetch(PB.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': PB.csrf } })
      .then((r) => r.json().catch(() => ({ error: 'Upload failed (' + r.status + ').' })).then((j) => {
        if (!r.ok || j.error) throw new Error(j.error || 'Upload failed.');
        return j;
      }));
  }

  // ---- image fields (featured image, social image, favicon) ----
  $all('[data-imgfield]').forEach((f) => {
    const val = $('[data-img-value]', f);
    const prev = $('[data-img-preview]', f);
    const up = $('[data-img-upload]', f);
    const clr = $('[data-img-clear]', f);
    const file = $('[data-img-file]', f);
    if (!up || !file) return;
    up.addEventListener('click', () => file.click());
    file.addEventListener('change', () => {
      const chosen = file.files[0];
      file.value = '';
      if (!chosen) return;
      up.disabled = true;
      up.textContent = 'Uploading…';
      upload(chosen, f.hasAttribute('data-img-keep')).then((r) => {
        val.value = r.url;
        prev.innerHTML = '<img src="' + esc(r.url) + '" alt="">';
        clr.hidden = false;
        bubble(val);
      }).catch((err) => window.alert(err.message)).finally(() => {
        up.disabled = false;
        up.textContent = val.value ? 'Replace' : 'Upload';
      });
    });
    clr.addEventListener('click', () => {
      val.value = '';
      prev.innerHTML = '';
      clr.hidden = true;
      up.textContent = 'Upload';
      bubble(val);
    });
    const lib = $('[data-img-library]', f);
    if (lib) lib.addEventListener('click', () => openMediaPicker((item) => {
      val.value = item.url;
      prev.innerHTML = '<img src="' + esc(item.url) + '" alt="">';
      clr.hidden = false;
      up.textContent = 'Replace';
      bubble(val);
    }, f.hasAttribute('data-img-keep')));
  });

  // ---- media library picker: choose an uploaded image, or upload new ones ----
  // Single mode (image fields): a tap picks the image; onPick gets {url, width, height}.
  // Multi mode (the editor): taps select images in order (numbered), several files
  // can be uploaded at once (they arrive selected), and "Insert" hands onPick an array.
  let picker = null;
  let pickerDone = null;
  let pickerKeep = false; // favicon/social image: don't convert to WebP
  let pickerMulti = false;
  let pickerSel = [];     // selected items, in tap order
  let pickerPage = 1;
  let pickerTimer = 0;
  const tileHtml = (it) => '<button type="button" class="pb-picker-tile" data-url="' + esc(it.url) + '" data-w="' + Number(it.width) + '" data-h="' + Number(it.height) + '" title="' + esc(it.name || '') + '">'
    + '<img src="' + esc(it.url) + '" alt="" loading="lazy" decoding="async"><span>' + esc(it.name || it.url.split('/').pop()) + '</span><i class="pb-picker-num" aria-hidden="true"></i></button>';
  function buildPicker() {
    picker = document.createElement('div');
    picker.className = 'pb-picker';
    picker.hidden = true;
    picker.setAttribute('role', 'dialog');
    picker.setAttribute('aria-modal', 'true');
    picker.setAttribute('aria-label', 'Choose images');
    picker.innerHTML = '<div class="pb-picker-box">'
      + '<div class="pb-picker-head"><h3 class="pb-h3" data-pick="title">Choose an image</h3><button type="button" class="pb-lightbox-close" data-pick="close" aria-label="Close">✕</button></div>'
      + '<div class="pb-picker-bar"><button type="button" class="pb-btn pb-btn-primary" data-pick="upload">⬆ Upload new</button>'
      + '<input type="search" placeholder="Search your images…" aria-label="Search images" data-pick="q"></div>'
      + '<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden data-pick="file">'
      + '<div class="pb-picker-grid" data-pick="grid"></div>'
      + '<div class="pb-picker-foot"><button type="button" class="pb-btn pb-btn-sm" data-pick="more" hidden>Load more</button>'
      + '<span class="pb-small pb-muted" data-pick="hint" hidden>Tap photos in the order you want them.</span>'
      + '<button type="button" class="pb-btn pb-btn-primary" data-pick="insert" hidden disabled>Insert</button></div></div>';
    document.body.appendChild(picker);
    const q = $('[data-pick="q"]', picker);
    const file = $('[data-pick="file"]', picker);
    const up = $('[data-pick="upload"]', picker);
    q.addEventListener('input', () => { clearTimeout(pickerTimer); pickerTimer = setTimeout(() => loadPicker(true), 220); });
    $('[data-pick="more"]', picker).addEventListener('click', () => loadPicker(false));
    $('[data-pick="close"]', picker).addEventListener('click', closePicker);
    $('[data-pick="insert"]', picker).addEventListener('click', () => { if (pickerSel.length) choosePicked(pickerSel.slice()); });
    picker.addEventListener('click', (e) => {
      if (e.target === picker) { closePicker(); return; }
      const tile = e.target.closest('[data-url]');
      if (!tile) return;
      const item = { url: tile.dataset.url, width: Number(tile.dataset.w), height: Number(tile.dataset.h) };
      if (!pickerMulti) { choosePicked(item); return; }
      const at = pickerSel.findIndex((s) => s.url === item.url);
      if (at >= 0) pickerSel.splice(at, 1); else pickerSel.push(item);
      paintSelection();
    });
    picker.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); closePicker(); } });
    up.addEventListener('click', () => file.click());
    file.addEventListener('change', () => {
      const files = Array.from(file.files || []);
      file.value = '';
      if (!files.length) return;
      up.disabled = true;
      const grid = $('[data-pick="grid"]', picker);
      let failed = 0;
      // One at a time: each photo is shrunk on the phone first, so a slow connection never gets 8 big uploads at once.
      const next = (i) => {
        if (i >= files.length) {
          up.disabled = false;
          up.textContent = '⬆ Upload new';
          if (failed) toast(failed + ' of ' + files.length + ' images could not be uploaded.', true);
          return;
        }
        up.textContent = files.length > 1 ? 'Uploading ' + (i + 1) + ' of ' + files.length + '…' : 'Uploading…';
        upload(files[i], pickerKeep).then((r) => {
          if (!pickerMulti) { choosePicked(r); return; }
          if (!$('.pb-picker-tile', grid)) grid.innerHTML = '';
          grid.insertAdjacentHTML('afterbegin', tileHtml({ url: r.url, width: r.width, height: r.height, name: files[i].name }));
          pickerSel.push({ url: r.url, width: r.width, height: r.height });
          paintSelection();
          next(i + 1);
        }).catch((err) => {
          failed++;
          if (!pickerMulti || files.length === 1) { window.alert(err.message); up.disabled = false; up.textContent = '⬆ Upload new'; return; }
          next(i + 1);
        });
      };
      next(0);
    });
  }
  function paintSelection() {
    $all('.pb-picker-tile', picker).forEach((t) => {
      const n = pickerSel.findIndex((s) => s.url === t.dataset.url);
      t.classList.toggle('is-picked', n >= 0);
      $('.pb-picker-num', t).textContent = n >= 0 ? String(n + 1) : '';
    });
    const ins = $('[data-pick="insert"]', picker);
    ins.disabled = !pickerSel.length;
    ins.textContent = pickerSel.length > 1 ? 'Insert ' + pickerSel.length + ' as a gallery' : 'Insert';
  }
  function loadPicker(reset) {
    const grid = $('[data-pick="grid"]', picker);
    const more = $('[data-pick="more"]', picker);
    if (reset) { pickerPage = 1; grid.innerHTML = '<p class="pb-muted pb-small">Loading…</p>'; } else pickerPage++;
    const q = $('[data-pick="q"]', picker).value.trim();
    fetch(PB.adminUrl + '?ajax=media&pg=' + pickerPage + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then((r) => r.json()).then((j) => {
        const html = (j.items || []).map(tileHtml).join('');
        if (reset) grid.innerHTML = html || '<p class="pb-muted pb-small">' + (q ? 'No images match.' : 'No images yet. Upload some.') + '</p>';
        else grid.insertAdjacentHTML('beforeend', html);
        more.hidden = !j.more;
        paintSelection();
      }).catch(() => { if (reset) grid.innerHTML = '<p class="pb-muted pb-small">Could not load the library.</p>'; });
  }
  function openMediaPicker(onPick, keepFormat, multi) {
    if (!picker) buildPicker();
    pickerDone = onPick;
    pickerKeep = !!keepFormat;
    pickerMulti = !!multi;
    pickerSel = [];
    $('[data-pick="file"]', picker).multiple = pickerMulti;
    $('[data-pick="insert"]', picker).hidden = !pickerMulti;
    $('[data-pick="hint"]', picker).hidden = !pickerMulti;
    $('[data-pick="title"]', picker).textContent = pickerMulti ? 'Choose images' : 'Choose an image';
    picker.hidden = false;
    document.body.classList.add('pb-noscroll');
    $('[data-pick="q"]', picker).value = '';
    loadPicker(true);
    $('[data-pick="upload"]', picker).focus();
  }
  function closePicker() {
    picker.hidden = true;
    document.body.classList.remove('pb-noscroll');
  }
  function choosePicked(item) {
    const done = pickerDone;
    closePicker();
    if (done) done(item);
  }

  // ---- colour fields: picker <-> hex text, empty = inherit ----
  $all('[data-colorfield]').forEach((f) => {
    const picker = $('[data-color-picker]', f);
    const text = $('[data-color-text]', f);
    picker.addEventListener('input', () => { text.value = picker.value; bubble(text); });
    text.addEventListener('input', () => { if (/^#[0-9a-f]{6}$/i.test(text.value)) picker.value = text.value; });
    $('[data-color-clear]', f).addEventListener('click', () => { text.value = ''; bubble(text); });
  });

  // ---- Settings → Design live preview ----
  const designForm = $('#pbDesignForm');
  const preview = $('.pbp-page');
  if (designForm && preview) {
    const update = () => {
      const val = (n) => (designForm.elements[n] ? designForm.elements[n].value.trim() : '');
      const stack = (n) => { const s = designForm.elements[n]; return s && s.selectedOptions[0] ? s.selectedOptions[0].dataset.stack : ''; };
      const set = (prop, v) => { if (v) preview.style.setProperty(prop, v); else preview.style.removeProperty(prop); };
      const hex = (n) => (/^#[0-9a-f]{6}$/i.test(val(n)) ? val(n) : '');
      set('--pbp-accent', hex('design_accent'));
      set('--pbp-text', hex('design_text'));
      set('--pbp-bg', hex('design_bg'));
      set('--pbp-surface', hex('design_surface'));
      set('--pbp-font', stack('design_font_body'));
      set('--pbp-heading', stack('design_font_heading'));
      set('--pbp-size', val('design_font_size') ? val('design_font_size') + 'px' : '');
    };
    designForm.addEventListener('input', update);
    designForm.addEventListener('change', update);
    update();
  }

  // ---- Settings → Navigation rows ----
  const navForm = $('#pbNavForm');
  if (navForm) {
    const rows = $('#pbNavRows');
    const addRow = (label, url) => {
      const tr = $('#pbNavTpl').content.firstElementChild.cloneNode(true);
      $('[data-k="label"]', tr).value = label || '';
      $('[data-k="url"]', tr).value = url || '';
      rows.appendChild(tr);
      $('[data-k="label"]', tr).focus();
    };
    $('#pbNavAdd').addEventListener('click', () => addRow());
    $('#pbNavQuick').addEventListener('change', function () {
      const o = this.selectedOptions[0];
      if (o && o.value) addRow(o.dataset.label, o.value);
      this.value = '';
    });
    rows.addEventListener('click', (e) => {
      const tr = e.target.closest('[data-nav-row]');
      if (!tr) return;
      if (e.target.closest('[data-nav-remove]')) tr.remove();
      const mv = e.target.closest('[data-nav-move]');
      if (mv) {
        if (mv.dataset.navMove === '-1' && tr.previousElementSibling) rows.insertBefore(tr, tr.previousElementSibling);
        if (mv.dataset.navMove === '1' && tr.nextElementSibling) rows.insertBefore(tr.nextElementSibling, tr);
      }
    });
    navForm.addEventListener('submit', () => {
      $all('[data-nav-row]', rows).forEach((tr, i) => {
        $all('[data-k]', tr).forEach((inp) => { inp.name = 'nav[' + i + '][' + inp.dataset.k + ']'; });
      });
    });
  }

  // ======================================================================
  // MEDIA MANAGER — thumbnails, popup viewer, copy link, multi-select delete
  // ======================================================================
  const mediaGrid = $('#pbMedia');
  const mediaUploadBtn = $('#pbMediaUploadBtn');
  if (mediaUploadBtn) {
    const input = $('#pbMediaFiles');
    mediaUploadBtn.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
      const files = Array.from(input.files || []);
      input.value = '';
      if (!files.length) return;
      mediaUploadBtn.disabled = true;
      let done = 0;
      let failed = 0;
      const next = (i) => {
        if (i >= files.length) {
          toast(done + ' image' + (done === 1 ? '' : 's') + ' uploaded' + (failed ? ', ' + failed + ' failed' : '') + ' ✓', !!failed && !done);
          setTimeout(() => location.reload(), 700);
          return;
        }
        mediaUploadBtn.textContent = 'Uploading ' + (i + 1) + ' of ' + files.length + '…';
        upload(files[i]).then(() => { done++; }).catch((err) => { failed++; toast(files[i].name + ': ' + err.message, true); }).finally(() => next(i + 1));
      };
      next(0);
    });
  }
  function copyText(text, label) {
    const ok = () => toast((label || 'Link') + ' copied ✓');
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(ok, () => fallbackCopy(text, ok));
    } else {
      fallbackCopy(text, ok);
    }
  }
  function fallbackCopy(text, ok) { // http:// sites and older browsers
    const t = document.createElement('textarea');
    t.value = text;
    t.style.position = 'fixed';
    t.style.opacity = '0';
    document.body.appendChild(t);
    t.select();
    let copied = false;
    try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
    t.remove();
    if (copied) { ok(); return; }
    // Clipboard blocked (some browsers/embedded views): select the visible link so Ctrl+C works at once.
    const field = $all('.pb-copy-row input').find((i) => i.value === text);
    if (field) { field.focus(); field.select(); }
    toast('Your browser blocked copying — the link is selected, press Ctrl+C.', true);
  }
  if (mediaGrid) {
    const items = $all('.pb-media-item', mediaGrid);
    const lb = $('#pbLightbox');
    const delForm = $('#pbMediaDelete');
    const delBtn = $('#pbMediaDeleteBtn');
    const allBox = $('#pbMediaAll');
    let cur = -1;
    const boxes = () => $all('input[name="paths[]"]', mediaGrid);
    const syncSelection = () => {
      const picked = boxes().filter((b) => b.checked);
      items.forEach((it) => { const b = $('input[name="paths[]"]', it); it.classList.toggle('is-selected', !!(b && b.checked)); });
      if (delBtn) { delBtn.disabled = !picked.length; $('span', delBtn).textContent = picked.length; }
      if (allBox) allBox.checked = picked.length > 0 && picked.length === boxes().length;
    };
    mediaGrid.addEventListener('change', syncSelection);
    if (allBox) allBox.addEventListener('change', () => { boxes().forEach((b) => { b.checked = allBox.checked; }); syncSelection(); });
    if (delForm) {
      delForm.addEventListener('submit', (e) => {
        const picked = boxes().filter((b) => b.checked);
        if (!picked.length) { e.preventDefault(); return; }
        const inUse = picked.filter((b) => Number(b.dataset.used) > 0).length;
        const msg = 'Delete ' + picked.length + ' image' + (picked.length === 1 ? '' : 's') + '?'
          + (inUse ? '\n\n⚠ ' + inUse + ' of them ' + (inUse === 1 ? 'is' : 'are') + ' used in a post, page or setting and will show as broken there.' : '')
          + '\n\nThis cannot be undone.';
        if (!window.confirm(msg)) e.preventDefault();
      });
    }

    const show = (i) => {
      if (i < 0 || i >= items.length) return;
      cur = i;
      const d = items[i].dataset;
      const img = $('#pbLbImg');
      img.src = d.url;
      img.alt = d.name;
      $('#pbLbName').textContent = d.name;
      $('#pbLbUrl').value = d.url;
      $('#pbLbFull').value = d.full;
      const meta = () => { $('#pbLbMeta').textContent = (img.naturalWidth ? img.naturalWidth + ' × ' + img.naturalHeight + ' px · ' : '') + d.size + ' · ' + d.date; };
      meta();
      img.onload = meta;
      // Photo details kept from the camera (EXIF): shows the metadata survived the upload.
      let photo = null;
      try { photo = d.photo ? JSON.parse(d.photo) : null; } catch (e) { /* ignore */ }
      const pInfo = $('#pbLbPhoto');
      pInfo.hidden = !photo;
      if (photo) {
        const bits = [];
        if (photo.camera) bits.push('📷 ' + esc(photo.camera));
        if (photo.taken) bits.push('🕒 Taken ' + esc(photo.taken));
        if (photo.lat !== null && photo.lng !== null) {
          bits.push('📍 <a href="https://www.google.com/maps?q=' + encodeURIComponent(photo.lat + ',' + photo.lng) + '" target="_blank" rel="noopener">'
            + esc(photo.lat.toFixed(5) + ', ' + photo.lng.toFixed(5)) + '</a>');
        }
        pInfo.innerHTML = bits.join('<br>');
      }
      let used = [];
      try { used = JSON.parse(d.used || '[]'); } catch (e) { /* ignore */ }
      $('#pbLbUsed').innerHTML = used.length
        ? '<strong>Used in:</strong><ul>' + used.map((u) => '<li>' + (u.kind === 'setting'
            ? 'Setting: ' + esc(u.title)
            : '<a href="' + esc(PB.adminUrl + '?view=edit&id=' + u.id) + '">' + (u.kind === 'page' ? 'Page: ' : 'Post: ') + esc(u.title) + '</a>') + '</li>').join('') + '</ul>'
        : '<span class="pb-muted">Not used in any post, page or setting yet.</span>';
      $('.pb-lightbox-prev', lb).disabled = i === 0;
      $('.pb-lightbox-next', lb).disabled = i === items.length - 1;
      lb.hidden = false;
      document.body.classList.add('pb-noscroll');
    };
    const hide = () => { lb.hidden = true; document.body.classList.remove('pb-noscroll'); if (items[cur]) $('.pb-media-thumb', items[cur]).focus(); };
    mediaGrid.addEventListener('click', (e) => {
      const t = e.target.closest('.pb-media-thumb');
      if (t) show(Number(t.closest('.pb-media-item').dataset.index));
    });
    lb.addEventListener('click', (e) => {
      const act = e.target.closest('[data-lb]');
      if (e.target === lb) { hide(); return; }
      if (!act) return;
      const d = items[cur] ? items[cur].dataset : {};
      switch (act.dataset.lb) {
        case 'close': hide(); break;
        case 'prev': show(cur - 1); break;
        case 'next': show(cur + 1); break;
        case 'copy': copyText(d.url, 'Link'); break;
        case 'copyfull': copyText(d.full, 'Full URL'); break;
        case 'delete':
          boxes().forEach((b) => { b.checked = b.value === d.rel; });
          syncSelection();
          if (delForm.requestSubmit) delForm.requestSubmit(); else delForm.submit();
          break;
      }
    });
    document.addEventListener('keydown', (e) => {
      if (lb.hidden) return;
      if (e.key === 'Escape') hide();
      if (e.key === 'ArrowLeft') show(cur - 1);
      if (e.key === 'ArrowRight') show(cur + 1);
    });
  }

  // ======================================================================
  // POST EDITOR
  // ======================================================================
  const form = $('#pbPostForm');
  if (!form) return;
  const editor = $('#pbEditor');
  const body = $('#pbBody');
  const then = $('#pbThen');
  let dirty = false;
  let sourceMode = false;
  let savedRange = null;

  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older browsers */ }

  // Editor-only state (selection outline, non-editable figures) never reaches the saved HTML.
  function cleanHtml() {
    const clone = editor.cloneNode(true);
    $all('.pb-selected', clone).forEach((el) => el.classList.remove('pb-selected'));
    $all('[contenteditable]', clone).forEach((el) => el.removeAttribute('contenteditable'));
    $all('.pb-importing', clone).forEach((el) => el.classList.remove('pb-importing'));
    $all('[data-pb-import]', clone).forEach((el) => el.removeAttribute('data-pb-import'));
    $all('p.pb-pad', clone).forEach((p) => { if (p.textContent.trim() === '' && !p.querySelector('img')) p.remove(); else p.classList.remove('pb-pad', 'is-here'); });
    // An empty line the writer added but never used, at the very end, isn't worth saving.
    while (clone.lastElementChild && clone.lastElementChild.tagName === 'P' && clone.lastElementChild.textContent.trim() === '' && !clone.lastElementChild.querySelector('img')
           && clone.lastElementChild.previousElementSibling && clone.lastElementChild.previousElementSibling.tagName === 'FIGURE') clone.lastElementChild.remove();
    $all('[class=""]', clone).forEach((el) => el.removeAttribute('class'));
    return clone.innerHTML.replace(/​/g, ''); // caret markers from Markdown shortcuts
  }

  form.addEventListener('input', () => { dirty = true; });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  $all('[data-then]', form).forEach((b) => b.addEventListener('click', () => { then.value = b.dataset.then; }));
  form.addEventListener('submit', () => {
    if (!sourceMode && editor) body.value = cleanHtml();
    dirty = false;
  });
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && $('[data-then="save"]', form)) {
      e.preventDefault();
      then.value = 'save';
      if (form.requestSubmit) form.requestSubmit(); else { body.value = sourceMode ? body.value : cleanHtml(); dirty = false; form.submit(); }
    }
  });

  // Phones pin Save/Publish to the bottom of the screen (admin.css). While the
  // on-screen keyboard is open the writer needs that room, so the bar steps
  // aside and comes back as soon as the keyboard closes. The keyboard is
  // detected by the visible viewport shrinking, which works on iOS and Android.
  if ($('.pb-actions', form)) {
    document.body.classList.add('pb-has-actions');
    const vv = window.visualViewport;
    const viewH = () => (vv ? vv.height : window.innerHeight);
    let fullH = viewH();
    const typingIn = (el) => !!el && (el.isContentEditable || el.matches('#pbTitle, textarea, .pb-source'));
    const check = () => {
      const h = viewH();
      if (h > fullH) fullH = h;
      document.body.classList.toggle('pb-typing', fullH - h > 150 && typingIn(document.activeElement));
    };
    (vv || window).addEventListener('resize', check);
    form.addEventListener('focusin', () => setTimeout(check, 350));
    form.addEventListener('focusout', () => setTimeout(check, 60));
    window.addEventListener('orientationchange', () => setTimeout(() => { fullH = 0; check(); }, 400));
  }

  // New post: suggest a URL slug from the title until the slug is edited by hand.
  const title = $('#pbTitle');
  const slug = $('#pbSlug');
  if (form.dataset.new === '1' && title && slug) {
    let touched = slug.value !== '';
    slug.addEventListener('input', () => { touched = true; });
    title.addEventListener('input', () => {
      if (touched) return;
      slug.value = title.value.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
    });
  }

  // Post ⇄ Page: pages have no category and can't be pinned.
  const typeSel = $('#pbType');
  if (typeSel) {
    typeSel.addEventListener('change', () => {
      const page = typeSel.value === 'page';
      ['#pbPinWrap', '#pbCatWrap', '#pbCommentsWrap'].forEach((s) => { const el = $(s); if (el) el.hidden = page; });
      if (title) title.placeholder = page ? 'Page title (e.g. About)' : 'Your next post…';
    });
  }

  if (!editor || !editor.isContentEditable) return;

  // Images and videos are handled as whole blocks: clicking selects them and
  // shows the options bar, instead of putting a caret inside them.
  // A non-editable block can't take the cursor next to it, so an image at the very
  // top, two images in a row, or an image at the end would leave nowhere to type.
  // Keep an empty line (p.pb-pad) in each of those places; unused ones are dropped
  // on save (cleanHtml), so they never add gaps to the published post.
  function padLine() {
    const p = document.createElement('p');
    p.className = 'pb-pad';
    p.appendChild(document.createElement('br'));
    return p;
  }
  function isBlock(el) { return !!el && el.nodeType === 1 && el.tagName === 'FIGURE'; }
  const blankP = (el) => !!el && el.tagName === 'P' && el.textContent.trim() === '' && !el.querySelector('img,a,iframe');
  function ensurePads() {
    $all(':scope > figure', editor).forEach((f) => {
      // An empty line already next to an image gets the same hint (and is dropped if unused).
      [f.previousElementSibling, f.nextElementSibling].forEach((s) => { if (blankP(s)) s.classList.add('pb-pad'); });
      if (!f.previousElementSibling || isBlock(f.previousElementSibling)) f.before(padLine());
      if (!f.nextElementSibling) f.after(padLine());
    });
  }
  function prepareFigures() {
    $all('figure', editor).forEach((f) => f.setAttribute('contenteditable', 'false'));
    ensurePads();
  }
  prepareFigures();
  // The hint on an empty line hides while the cursor is in it.
  document.addEventListener('selectionchange', () => {
    const sel = window.getSelection();
    const node = sel.rangeCount ? sel.anchorNode : null;
    const here = node && editor.contains(node) ? (node.nodeType === 1 ? node : node.parentElement).closest('p.pb-pad') : null;
    $all('p.pb-pad.is-here', editor).forEach((p) => { if (p !== here) p.classList.remove('is-here'); });
    if (here) here.classList.add('is-here');
  });
  // A line typed into a pad becomes an ordinary paragraph.
  editor.addEventListener('input', () => {
    $all('p.pb-pad', editor).forEach((p) => { if (p.textContent.trim() !== '' || p.querySelector('img,a')) p.classList.remove('pb-pad', 'is-here'); });
    ensurePads(); // e.g. the line after the last image was deleted
  });
  // Put the cursor in a (new) empty line just above or below a figure.
  function caretInto(p) {
    editor.focus();
    const r = document.createRange();
    r.setStart(p, 0);
    r.collapse(true);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(r);
    savedRange = r.cloneRange();
  }
  function lineBeside(fig, where) {
    const sib = where === 'above' ? fig.previousElementSibling : fig.nextElementSibling;
    const empty = sib && sib.tagName === 'P' && sib.textContent.trim() === '' && !sib.querySelector('img');
    const p = empty ? sib : padLine();
    if (!empty) { if (where === 'above') fig.before(p); else fig.after(p); }
    p.classList.remove('pb-pad', 'is-here'); // the writer asked for it: keep it even if left empty for now
    dirty = true;
    caretInto(p);
    return p;
  }

  function saveSelection() {
    const sel = window.getSelection();
    if (sel.rangeCount && editor.contains(sel.getRangeAt(0).commonAncestorContainer)) savedRange = sel.getRangeAt(0).cloneRange();
  }
  function restoreSelection() {
    editor.focus();
    if (!savedRange) return;
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
  }
  function insertHtml(html) {
    restoreSelection();
    document.execCommand('insertHTML', false, html);
    prepareFigures();
    dirty = true;
  }

  // ---- toolbar ----
  const toolbar = $('.pb-toolbar');
  toolbar.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); }); // keep the selection
  editor.addEventListener('keyup', saveSelection);
  editor.addEventListener('mouseup', saveSelection);
  // Light up the alignment of the paragraph the cursor is in.
  const alignBtns = $all('[data-cmd^="justify"]', toolbar);
  document.addEventListener('selectionchange', () => {
    const sel = window.getSelection();
    if (!sel.rangeCount || !editor.contains(sel.anchorNode)) return;
    alignBtns.forEach((b) => { let on = false; try { on = document.queryCommandState(b.dataset.cmd); } catch (err) { /* unsupported */ } b.classList.toggle('on', on); });
  });
  toolbar.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-cmd]');
    if (!b) return;
    const cmd = b.dataset.cmd;
    if (cmd === 'source') { toggleSource(b); return; }
    if (sourceMode) return;
    editor.focus();
    switch (cmd) {
      case 'h2':
      case 'h3':
      case 'blockquote': {
        const current = String(document.queryCommandValue('formatBlock') || '').toLowerCase().replace(/[<>]/g, '');
        document.execCommand('formatBlock', false, current === cmd ? '<p>' : '<' + cmd + '>');
        break;
      }
      case 'link':
        openLinkDialog();
        return;
      case 'image':
        saveSelection();
        openMediaPicker((picked) => {
          const imgs = Array.isArray(picked) ? picked : [picked];
          const size = (im) => (im.width ? ' width="' + Number(im.width) + '" height="' + Number(im.height) + '"' : '');
          if (imgs.length === 1) {
            const alt = window.prompt('Describe this image (alt text, helps accessibility and Google):', '') || '';
            insertHtml('<figure class="pb-figure pb-w-full"><img src="' + esc(imgs[0].url) + '" alt="' + esc(alt) + '"' + size(imgs[0]) + '></figure><p><br></p>');
            return;
          }
          // Several photos: one gallery block, one question (each photo gets "…, photo 2 of 8").
          const alt = (window.prompt('Describe these ' + imgs.length + ' photos (alt text), e.g. "Café Goodluck, Pune":', '') || '').trim();
          insertHtml('<figure class="pb-gallery">' + imgs.map((im, i) => '<img src="' + esc(im.url) + '" alt="'
            + esc(alt ? alt + ', photo ' + (i + 1) + ' of ' + imgs.length : '') + '"' + size(im) + '>').join('') + '</figure><p><br></p>');
        }, false, true);
        return;
      case 'video':
        saveSelection();
        insertVideo();
        return;
      case 'removeFormat':
        document.execCommand('removeFormat');
        document.execCommand('unlink');
        break;
      default:
        document.execCommand(cmd);
    }
    dirty = true;
  });

  $('#pbImageFile').addEventListener('change', function () {
    const file = this.files[0];
    this.value = '';
    if (!file) return;
    editor.classList.add('pb-uploading');
    upload(file).then((r) => {
      const alt = window.prompt('Describe this image (alt text, helps accessibility and Google):', '') || '';
      insertHtml('<figure class="pb-figure pb-w-full"><img src="' + esc(r.url) + '" alt="' + esc(alt) + '" width="' + Number(r.width) + '" height="' + Number(r.height) + '"></figure><p><br></p>');
    }).catch((err) => window.alert(err.message)).finally(() => editor.classList.remove('pb-uploading'));
  });

  // ---- link dialog (WordPress-style) ----
  // One box for both kinds of link: paste an address, or type to search this
  // blog's published posts and pages. When the selection touches an existing
  // link (even partly), the dialog edits it and offers Unlink.
  const linkDlg = $('#pbLinkDlg');
  let linkTargets = [];   // <a> elements the selection touches
  let linkPick = null;    // chosen internal result {title, url}
  let linkTimer = 0;
  let linkIndex = -1;

  function linksInSelection(range) {
    const found = [];
    const add = (n) => { const a = n && (n.nodeType === 1 ? n : n.parentElement); const l = a && a.closest('a'); if (l && editor.contains(l) && !found.includes(l)) found.push(l); };
    add(range.startContainer);
    add(range.endContainer);
    $all('a', editor).forEach((a) => { if (!found.includes(a) && range.intersectsNode(a)) found.push(a); });
    return found;
  }
  function openLinkDialog() {
    if (!linkDlg) return;
    saveSelection();
    if (!savedRange) { editor.focus(); saveSelection(); }
    linkTargets = savedRange ? linksInSelection(savedRange) : [];
    linkPick = null;
    const a = linkTargets[0];
    const url = $('#pbLinkUrl');
    url.value = a ? a.getAttribute('href') || '' : '';
    $('#pbLinkNewTab').checked = !!(a && a.getAttribute('target') === '_blank');
    $('#pbLinkUnlink').hidden = !linkTargets.length;
    $('#pbLinkTitle').textContent = a ? 'Edit link' : 'Insert link';
    linkDlg.hidden = false;
    document.body.classList.add('pb-noscroll');
    url.focus();
    url.select();
    searchLinks(a ? '' : url.value);
  }
  function closeLinkDialog() {
    linkDlg.hidden = true;
    document.body.classList.remove('pb-noscroll');
    restoreSelection();
  }
  function looksLikeUrl(v) {
    return /^(https?:\/\/|\/|#|mailto:|tel:|www\.)/i.test(v) || /^[\w-]+(\.[\w-]+)+(\/|$)/.test(v);
  }
  function searchLinks(q) {
    clearTimeout(linkTimer);
    const list = $('#pbLinkResults');
    if (looksLikeUrl(q)) { list.innerHTML = ''; linkIndex = -1; return; }
    linkTimer = setTimeout(() => {
      fetch(PB.adminUrl + '?ajax=links&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then((r) => r.json()).then((items) => {
          linkIndex = -1;
          list.innerHTML = items.length ? items.map((it, i) => '<li role="option" data-i="' + i + '" data-url="' + esc(it.url) + '" data-title="' + esc(it.title) + '">'
            + '<strong>' + esc(it.title) + '</strong><span>' + (it.type === 'page' ? 'Page' : 'Post') + (it.date ? ' · ' + esc(it.date) : '') + '</span></li>').join('')
            : '<li class="pb-link-none">' + (q ? 'No published post or page matches.' : 'No published posts yet.') + '</li>';
        }).catch(() => { list.innerHTML = ''; });
    }, q ? 180 : 0);
  }
  function pickResult(li) {
    linkPick = { url: li.dataset.url, title: li.dataset.title };
    $('#pbLinkUrl').value = li.dataset.url;
    $all('#pbLinkResults li').forEach((x) => x.classList.toggle('on', x === li));
  }
  function applyLink() {
    let href = $('#pbLinkUrl').value.trim();
    if (!href) { unlinkSelection(); return; }
    if (/^\s*(javascript|data|vbscript):/i.test(href)) { toast('That kind of link isn\'t allowed.', true); return; }
    if (/^www\./i.test(href) || (/^[\w-]+(\.[\w-]+)+(\/|$)/.test(href) && !/^\//.test(href))) href = 'https://' + href;
    const newTab = $('#pbLinkNewTab').checked;
    const setAttrs = (a) => {
      a.setAttribute('href', href);
      if (newTab) { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener'); } else { a.removeAttribute('target'); a.removeAttribute('rel'); }
    };
    linkDlg.hidden = true;
    document.body.classList.remove('pb-noscroll');
    restoreSelection();
    if (linkTargets.length) {
      linkTargets.forEach(setAttrs); // edit the existing link(s)
    } else if (!savedRange || savedRange.collapsed) {
      // Nothing selected: insert the link text itself (the post title, or the address).
      const text = linkPick && linkPick.url === href ? linkPick.title : href.replace(/^https?:\/\//, '');
      document.execCommand('insertHTML', false, '<a href="' + esc(href) + '"' + (newTab ? ' target="_blank" rel="noopener"' : '') + '>' + esc(text) + '</a>&nbsp;');
    } else {
      const marker = 'pb-link-' + Date.now();
      document.execCommand('createLink', false, marker);
      $all('a[href="' + marker + '"]', editor).forEach(setAttrs);
    }
    dirty = true;
  }
  function unlinkSelection() {
    linkDlg.hidden = true;
    document.body.classList.remove('pb-noscroll');
    linkTargets.forEach((a) => { // unwrap: keep the text, drop the link
      while (a.firstChild) a.parentNode.insertBefore(a.firstChild, a);
      a.remove();
    });
    restoreSelection();
    dirty = true;
  }
  if (linkDlg) {
    linkDlg.addEventListener('input', (e) => e.stopPropagation()); // searching isn't editing the post
    $('#pbLinkUrl').addEventListener('input', function () { linkPick = null; searchLinks(this.value.trim()); });
    $('#pbLinkUrl').addEventListener('keydown', (e) => {
      const items = $all('#pbLinkResults li[data-url]');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!items.length) return;
        e.preventDefault();
        linkIndex = (linkIndex + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        pickResult(items[linkIndex]);
        items[linkIndex].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') {
        e.preventDefault();
        // Typed a search word and pressed Enter: take the top match.
        if (!looksLikeUrl(e.target.value.trim()) && !linkPick && items.length) pickResult(items[0]);
        applyLink();
      }
    });
    $('#pbLinkResults').addEventListener('click', (e) => { const li = e.target.closest('li[data-url]'); if (li) pickResult(li); });
    $('#pbLinkResults').addEventListener('dblclick', (e) => { const li = e.target.closest('li[data-url]'); if (li) { pickResult(li); applyLink(); } });
    $('#pbLinkApply').addEventListener('click', applyLink);
    $('#pbLinkUnlink').addEventListener('click', unlinkSelection);
    $all('[data-link-close]').forEach((b) => b.addEventListener('click', closeLinkDialog));
    linkDlg.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); closeLinkDialog(); } });
    editor.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openLinkDialog(); }
    });
  }

  function videoEmbedUrl(u) {
    let m = u.match(/(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/|live\/|v\/)|youtu\.be\/)([\w-]{11})/);
    if (m) return 'https://www.youtube.com/embed/' + m[1];
    m = u.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    if (m) return 'https://player.vimeo.com/video/' + m[1];
    return null;
  }
  function insertVideo() {
    const url = window.prompt('Paste a YouTube or Vimeo link');
    if (!url) return;
    const src = videoEmbedUrl(url.trim());
    if (!src) { window.alert('That doesn\'t look like a YouTube or Vimeo link.'); return; }
    insertHtml('<figure class="pb-embed"><iframe src="' + esc(src) + '" title="Video" width="560" height="315" '
      + 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '
      + 'referrerpolicy="strict-origin-when-cross-origin" allowfullscreen loading="lazy"></iframe></figure><p><br></p>');
  }

  function toggleSource(btn) {
    hideImgBar();
    if (!sourceMode) {
      body.value = cleanHtml();
      body.hidden = false;
      editor.hidden = true;
    } else {
      editor.innerHTML = body.value;
      prepareFigures();
      body.hidden = true;
      editor.hidden = false;
    }
    sourceMode = !sourceMode;
    btn.classList.toggle('on', sourceMode);
    $all('button[data-cmd]', toolbar).forEach((b) => { if (b !== btn) b.disabled = sourceMode; });
  }

  // ---- image options bar: wrap left/right/centre, size S/M/L/full, alt, caption ----
  const bar = $('#pbImgBar');
  const card = editor.closest('.pb-editor-card');
  let current = null; // selected <figure>

  // Older posts have bare <img> inside a <p>; give them a figure so they can wrap.
  function ensureFigure(img) {
    const existing = img.closest('figure.pb-figure');
    if (existing) return existing;
    const fig = document.createElement('figure');
    fig.className = 'pb-figure pb-w-full';
    const p = img.parentElement;
    const alone = p && p !== editor && p.tagName === 'P' && p.textContent.trim() === '' && p.querySelectorAll('img').length === 1;
    if (alone) { p.replaceWith(fig); } else if (p && p !== editor) { p.after(fig); } else { img.before(fig); }
    fig.appendChild(img);
    fig.setAttribute('contenteditable', 'false');
    dirty = true;
    return fig;
  }
  function figAlign(fig) { const m = fig.className.match(/pb-align-(left|right|center)/); return m ? m[1] : ''; }
  function figSize(fig) { const m = fig.className.match(/pb-w-(s|m|l|full)/); return m ? m[1] : 'full'; }
  function setClass(fig, prefix, value) {
    fig.className.split(/\s+/).filter((c) => c.indexOf(prefix) === 0).forEach((c) => fig.classList.remove(c));
    if (value) fig.classList.add(prefix + value);
  }
  function placeBar() {
    if (!current) return;
    const c = card.getBoundingClientRect();
    const f = current.getBoundingClientRect();
    bar.style.left = Math.max(8, Math.min(f.left - c.left, c.width - bar.offsetWidth - 8)) + 'px';
    let top = f.top - c.top - bar.offsetHeight - 8;
    if (top < 8) top = f.top - c.top + Math.min(f.height, 60) + 8;
    bar.style.top = top + 'px';
  }
  function showImgBar(fig) {
    if (current && current !== fig) current.classList.remove('pb-selected');
    current = fig;
    fig.classList.add('pb-selected');
    const isImage = fig.classList.contains('pb-figure');
    const isGallery = fig.classList.contains('pb-gallery'); // a gallery: caption and remove only
    $all('[data-align],[data-size],[data-img-act="alt"],[data-img-act="caption"],.pb-imgbar-label,.pb-imgbar-sep', bar).forEach((el) => { el.hidden = !isImage; });
    if (isGallery) $('[data-img-act="caption"]', bar).hidden = false;
    if (isImage) {
      $all('[data-align]', bar).forEach((b) => b.classList.toggle('on', b.dataset.align === figAlign(fig)));
      $all('[data-size]', bar).forEach((b) => b.classList.toggle('on', b.dataset.size === figSize(fig)));
    }
    bar.hidden = false;
    placeBar();
  }
  function hideImgBar() {
    if (current) current.classList.remove('pb-selected');
    current = null;
    bar.hidden = true;
  }
  editor.addEventListener('click', (e) => {
    const img = e.target.closest('img');
    const fig = e.target.closest('figure');
    if (fig && fig.classList.contains('pb-gallery') && editor.contains(fig)) { showImgBar(fig); return; } // the whole gallery, not one photo
    if (img && editor.contains(img)) { showImgBar(ensureFigure(img)); return; }
    if (fig && editor.contains(fig)) { showImgBar(fig); return; }
    hideImgBar();
  });
  document.addEventListener('mousedown', (e) => {
    if (current && !bar.contains(e.target) && !editor.contains(e.target)) hideImgBar();
  });
  // With an image selected: Enter adds a line below it, Shift+Enter above, Esc lets go.
  document.addEventListener('keydown', (e) => {
    if (!current || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === 'Enter') {
      e.preventDefault();
      const fig = current;
      hideImgBar();
      lineBeside(fig, e.shiftKey ? 'above' : 'below');
    } else if (e.key === 'Escape') {
      hideImgBar();
    }
  });
  window.addEventListener('resize', placeBar);
  window.addEventListener('scroll', placeBar, true);
  bar.addEventListener('mousedown', (e) => e.preventDefault());
  bar.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || !current) return;
    const fig = current;
    if (b.dataset.align !== undefined) {
      setClass(fig, 'pb-align-', b.dataset.align);
      // Wrapping a full-width image makes no sense — default it to 500px.
      if ((b.dataset.align === 'left' || b.dataset.align === 'right') && figSize(fig) === 'full') setClass(fig, 'pb-w-', 'm');
    } else if (b.dataset.size) {
      setClass(fig, 'pb-w-', b.dataset.size);
    } else if (b.dataset.imgAct === 'alt') {
      const img = $('img', fig);
      const alt = window.prompt('Alt text (describe the image):', img.getAttribute('alt') || '');
      if (alt !== null) img.setAttribute('alt', alt.trim());
    } else if (b.dataset.imgAct === 'caption') {
      let cap = $('figcaption', fig);
      const text = window.prompt('Caption (leave empty to remove):', cap ? cap.textContent : '');
      if (text === null) return;
      if (text.trim() === '') { if (cap) cap.remove(); } else {
        if (!cap) { cap = document.createElement('figcaption'); fig.appendChild(cap); }
        cap.textContent = text.trim();
      }
    } else if (b.dataset.imgAct === 'above' || b.dataset.imgAct === 'below') {
      hideImgBar();
      lineBeside(fig, b.dataset.imgAct);
      return;
    } else if (b.dataset.imgAct === 'remove') {
      hideImgBar();
      fig.remove();
      ensurePads();
      dirty = true;
      return;
    }
    dirty = true;
    showImgBar(fig);
  });

  // ======================================================================
  // PASTE FROM ANYWHERE — conversion lives in assets/paste.js (window.PBPaste)
  // ======================================================================
  let toastTimer = null;
  function toast(msg, isError) {
    let t = $('#pbToast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'pbToast';
      t.className = 'pb-toast';
      t.setAttribute('role', 'status');
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.toggle('pb-toast-error', !!isError);
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, isError ? 8000 : 3500);
  }
  const SOURCE_LABEL = { word: 'Word', gdocs: 'Google Docs', wordpress: 'WordPress', libreoffice: 'LibreOffice', notion: 'Notion', markdown: 'Markdown' };

  function insertCleaned(result) {
    if (!result || !result.html) return;
    document.execCommand('insertHTML', false, result.html);
    prepareFigures();
    dirty = true;
    const bits = [];
    if (result.images) bits.push(result.images + ' image' + (result.images > 1 ? 's' : ''));
    if (result.embeds) bits.push(result.embeds + ' video' + (result.embeds > 1 ? 's' : ''));
    if (SOURCE_LABEL[result.source] || bits.length) {
      toast('Pasted' + (SOURCE_LABEL[result.source] ? ' from ' + SOURCE_LABEL[result.source] : '') + (bits.length ? ' with ' + bits.join(' and ') : '') + ' ✓');
    }
    if (result.skipped) toast(result.skipped + ' image' + (result.skipped > 1 ? 's' : '') + ' couldn\'t come along (they only exist on your computer). Add them with 🖼 Image.', true);
    importImages();
  }

  // Pasted images still point at Google/WordPress/other servers, or are data:
  // blobs. Copy each into this blog's uploads so the post never breaks later.
  let importing = 0;
  function importImages() {
    $all('img[data-pb-import]', editor).forEach((img) => {
      const src = img.getAttribute('src') || '';
      img.removeAttribute('data-pb-import');
      if (src.indexOf(location.origin + '/') === 0 || (src.charAt(0) === '/' && src.charAt(1) !== '/')) return; // already ours
      importing++;
      img.classList.add('pb-importing');
      let job;
      if (/^data:image\//i.test(src)) {
        job = fetch(src).then((r) => r.blob()).then((b) => upload(new File([b], 'pasted-image.' + ((b.type.split('/')[1] || 'png').replace('jpeg', 'jpg')), { type: b.type })));
      } else {
        const fd = new FormData();
        fd.append('do', 'import_image');
        fd.append('_csrf', PB.csrf);
        fd.append('url', src);
        job = fetch(PB.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': PB.csrf } })
          .then((r) => r.json().catch(() => ({ error: 'Import failed (' + r.status + ').' })).then((j) => {
            if (!r.ok || j.error) throw new Error(j.error || 'Import failed.');
            return j;
          }));
      }
      job.then((r) => {
        img.setAttribute('src', r.url);
        if (r.width) { img.setAttribute('width', r.width); img.setAttribute('height', r.height); }
        dirty = true;
      }).catch((err) => {
        toast('An image is still linked to its original website — ' + err.message, true);
      }).finally(() => {
        img.classList.remove('pb-importing');
        if (!img.getAttribute('class')) img.removeAttribute('class');
        importing--;
        if (!importing) toast('Pasted images saved to your blog ✓');
      });
    });
  }
  // Don't save while images are still being copied in.
  form.addEventListener('submit', (e) => {
    if (importing) { e.preventDefault(); e.stopImmediatePropagation(); toast('Still saving pasted images — try again in a moment.', true); }
  }, true);

  function insertFiles(files) {
    files.forEach((file) => {
      editor.classList.add('pb-uploading');
      upload(file).then((r) => {
        insertHtml('<figure class="pb-figure pb-w-full"><img src="' + esc(r.url) + '" alt="" width="' + Number(r.width) + '" height="' + Number(r.height) + '"></figure><p><br></p>');
        toast('Image added ✓ — click it to set alt text, wrap and size.');
      }).catch((err) => toast(err.message, true)).finally(() => editor.classList.remove('pb-uploading'));
    });
  }

  editor.addEventListener('paste', (e) => {
    const cd = e.clipboardData;
    if (!cd || !window.PBPaste) return;
    const html = cd.getData('text/html') || '';
    const text = cd.getData('text/plain') || '';
    const files = Array.from(cd.files || []).filter((f) => /^image\//.test(f.type));
    e.preventDefault();
    // A screenshot or copied image file.
    if (files.length && !text.trim()) { saveSelection(); insertFiles(files); return; }
    if (!html && !text) return;
    // Inside a code block, everything is literal text.
    const sel = window.getSelection();
    if (sel.rangeCount && sel.anchorNode && (sel.anchorNode.nodeType === 3 ? sel.anchorNode.parentElement : sel.anchorNode).closest('pre')) {
      document.execCommand('insertText', false, text);
      return;
    }
    const useText = !html || window.PBPaste.preferText(html, text);
    // A word or a phrase: flow it into the current paragraph as plain text.
    if (useText && text.indexOf('\n') === -1 && !window.PBPaste.looksLikeMarkdown(text) && !window.PBPaste.videoEmbedUrl(text.trim())) {
      document.execCommand('insertText', false, text);
      return;
    }
    insertCleaned(useText ? window.PBPaste.textToHtml(text) : window.PBPaste.cleanHtml(html));
  });

  // Drag an image file from the desktop straight into the post.
  editor.addEventListener('dragover', (e) => {
    if (e.dataTransfer && Array.from(e.dataTransfer.types || []).indexOf('Files') !== -1) e.preventDefault();
  });
  editor.addEventListener('drop', (e) => {
    const files = Array.from((e.dataTransfer && e.dataTransfer.files) || []).filter((f) => /^image\//.test(f.type));
    if (!files.length) return;
    e.preventDefault();
    const r = document.caretRangeFromPoint ? document.caretRangeFromPoint(e.clientX, e.clientY) : null;
    if (r && editor.contains(r.startContainer)) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    saveSelection();
    insertFiles(files);
  });

  // ======================================================================
  // MARKDOWN SHORTCUTS WHILE TYPING
  //   "## " heading · "- " list · "1. " numbered · "> " quote
  //   **bold** · *italic* · _italic_ · `code` · ~~strike~~ · "---"+Enter line · "```"+Enter code block
  // ======================================================================
  const BLOCK_SEL = 'p,h2,h3,h4,li,blockquote,div,pre';
  function currentBlock(node) {
    let el = node && node.nodeType === 3 ? node.parentElement : node;
    while (el && el !== editor && !el.matches(BLOCK_SEL)) el = el.parentElement;
    return el && el !== editor ? el : null;
  }
  function placeCaret(node, offset) {
    const r = document.createRange();
    r.setStart(node, offset);
    r.collapse(true);
    const s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
  }
  const INLINE_RULES = [
    { re: /(\*\*|__)([^\s*_](?:[^*_]*[^\s*_])?)\1$/, tag: 'strong', text: 2, pre: 0 },
    { re: /(^|[^*\w])\*([^\s*](?:[^*]*[^\s*])?)\*$/, tag: 'em', text: 2, pre: 1 },
    { re: /(^|[^_\w])_([^\s_](?:[^_]*[^\s_])?)_$/, tag: 'em', text: 2, pre: 1 },
    { re: /`([^`]+)`$/, tag: 'code', text: 1, pre: 0 },
    { re: /~~([^~]+)~~$/, tag: 's', text: 1, pre: 0 },
  ];
  editor.addEventListener('input', (e) => {
    if (e.inputType !== 'insertText' || sourceMode) return;
    const sel = window.getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return;
    const node = sel.anchorNode;
    if (!node || node.nodeType !== 3 || node.parentElement.closest('pre,code')) return;
    const offset = sel.anchorOffset;

    if (e.data === ' ') {
      const block = currentBlock(node);
      if (block && ['P', 'DIV'].indexOf(block.tagName) !== -1) {
        const r = document.createRange();
        r.selectNodeContents(block);
        r.setEnd(node, offset);
        const lead = r.toString().replace(/ /g, ' ');
        const m = lead.match(/^(#{1,4}|[-*+]|1[.)]|>) $/);
        // Only when the marker sits at the start of this text node (the usual case when typing).
        if (m && node.textContent.slice(0, offset).replace(/ /g, ' ') === lead) {
          node.deleteData(0, offset); // remove just the typed marker
          if (!node.textContent) node.remove();
          const mk = m[1];
          // Plain DOM, not execCommand: a nested execCommand inside an input event is
          // ignored by Chrome (IMEs, autocorrect and scripted input all hit that).
          const tag = mk.charAt(0) === '#' ? (mk.length <= 2 ? 'h2' : (mk.length === 3 ? 'h3' : 'h4'))
                    : mk === '>' ? 'blockquote' : (/^\d/.test(mk) ? 'ol' : 'ul');
          let target = document.createElement(tag === 'ul' || tag === 'ol' ? 'li' : tag);
          while (block.firstChild) target.appendChild(block.firstChild);
          if (tag === 'ul' || tag === 'ol') {
            const list = document.createElement(tag);
            list.appendChild(target);
            block.replaceWith(list);
          } else {
            block.replaceWith(target);
          }
          if (!target.firstChild || (target.firstChild.nodeType === 3 && !target.textContent)) target.appendChild(document.createElement('br'));
          if (target.firstChild.nodeType === 3) placeCaret(target.firstChild, 0); else placeCaret(target, 0);
          dirty = true;
          return;
        }
      }
    }
    if ('*_`~'.indexOf(e.data) === -1) return;
    const before = node.textContent.slice(0, offset);
    for (const rule of INLINE_RULES) {
      const m = before.match(rule.re);
      if (!m) continue;
      const prefix = rule.pre ? (m[rule.pre] || '') : '';
      const r = document.createRange();
      r.setStart(node, offset - m[0].length + prefix.length);
      r.setEnd(node, offset);
      r.deleteContents();
      const el = document.createElement(rule.tag);
      el.textContent = m[rule.text];
      r.insertNode(el);
      const after = document.createTextNode('​'); // caret lands outside the new <strong>/<em>
      el.after(after);
      placeCaret(after, 1);
      dirty = true;
      return;
    }
  });
  editor.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.shiftKey || sourceMode) return;
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    const block = currentBlock(sel.anchorNode);
    if (!block || ['P', 'DIV'].indexOf(block.tagName) === -1) return;
    const t = block.textContent.replace(/[ ​]/g, ' ').trim();
    const para = () => { const p = document.createElement('p'); p.appendChild(document.createElement('br')); return p; };
    if (/^(-{3,}|\*{3,}|_{3,})$/.test(t)) {
      e.preventDefault();
      const hr = document.createElement('hr');
      const p = para();
      block.replaceWith(hr);
      hr.after(p);
      placeCaret(p, 0);
      dirty = true;
    } else if (/^(`{3,}|~{3,})[\w+-]*$/.test(t)) {
      e.preventDefault();
      const pre = document.createElement('pre');
      const code = document.createElement('code');
      code.appendChild(document.createTextNode('​'));
      pre.appendChild(code);
      block.replaceWith(pre);
      pre.after(para());
      placeCaret(code.firstChild, 1);
      dirty = true;
    }
  });
})();
