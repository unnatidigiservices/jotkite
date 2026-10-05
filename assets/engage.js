/* JotKite — likes, sharing and comments under a post (lib/engage.php). */
(function () {
  'use strict';
  const box = document.querySelector('.pb-engage[data-post]');
  if (!box) return;
  const api = box.dataset.api;
  const postId = box.dataset.post;
  const $ = (s, el) => (el || box).querySelector(s);
  const store = {
    get(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* private mode */ } },
  };
  // A random id for this browser: one like per post.
  let visitor = store.get('pb_vid');
  if (typeof visitor !== 'string' || !/^[a-f0-9]{32}$/.test(visitor)) {
    const a = new Uint8Array(16);
    crypto.getRandomValues(a);
    visitor = Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
    store.set('pb_vid', visitor);
  }
  let state = { user: null, csrf: null, mail: false };
  const send = (action, data, headers) => fetch(api + action, {
    method: 'POST', credentials: 'same-origin',
    headers: Object.assign({ Accept: 'application/json' }, headers || {}),
    body: new URLSearchParams(data),
  }).then((r) => r.json().catch(() => ({ error: 'Something went wrong. Please try again.' })));

  // ---- like ------------------------------------------------------------------
  const like = $('[data-like]');
  const paintLike = (liked, n) => {
    if (!like) return;
    like.setAttribute('aria-pressed', liked ? 'true' : 'false');
    like.classList.toggle('is-liked', !!liked);
    $('[data-like-label]').textContent = liked ? 'Liked' : 'Like';
    const num = $('[data-like-n]');
    num.textContent = n;
    num.hidden = !n;
  };
  if (like) like.addEventListener('click', () => {
    const was = like.getAttribute('aria-pressed') === 'true';
    const n = parseInt($('[data-like-n]').textContent, 10) || 0;
    paintLike(!was, Math.max(0, n + (was ? -1 : 1))); // feels instant; the answer corrects it
    send('like', { post: postId, v: visitor }).then((r) => { if (!r.error) paintLike(r.liked, r.likes); });
  });

  // ---- share -----------------------------------------------------------------
  const shareBtn = $('[data-share]');
  const menu = $('[data-share-menu]');
  const toggleMenu = (open) => { menu.hidden = !open; shareBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); };
  if (shareBtn) {
    shareBtn.addEventListener('click', () => {
      if (navigator.share && matchMedia('(pointer: coarse)').matches) { // phones: the system share sheet
        navigator.share({ title: box.dataset.title, url: box.dataset.url }).catch(() => {});
        return;
      }
      toggleMenu(menu.hidden);
    });
    document.addEventListener('click', (e) => { if (!menu.hidden && !e.target.closest('.pb-share')) toggleMenu(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) { toggleMenu(false); shareBtn.focus(); } });
    const copy = $('[data-copy]');
    copy.addEventListener('click', () => {
      const done = () => { copy.textContent = 'Link copied ✓'; setTimeout(() => { copy.textContent = 'Copy link'; }, 2000); };
      if (navigator.clipboard) navigator.clipboard.writeText(box.dataset.url).then(done, () => prompt('Copy this link:', box.dataset.url));
      else prompt('Copy this link:', box.dataset.url);
    });
  }

  // ---- comments --------------------------------------------------------------
  const form = $('[data-comment-form]');
  const list = $('[data-comments]');
  const msg = form ? $('[data-form-msg]', form) : null;
  const say = (text, kind) => { if (!msg) return; msg.innerHTML = ''; if (!text) return; const p = document.createElement('p'); p.className = 'pb-comment-msg is-' + (kind || 'ok'); p.setAttribute('role', 'status'); p.textContent = text; msg.appendChild(p); };
  const html = (s) => { const t = document.createElement('template'); t.innerHTML = s.trim(); return t.content.firstElementChild; };
  const countUp = () => {
    const h = $('.pb-comments-title');
    const n = (parseInt(h.dataset.count, 10) || 0) + 1;
    h.dataset.count = n;
    h.textContent = n + ' comment' + (n === 1 ? '' : 's');
    const empty = $('[data-empty]');
    if (empty) empty.remove();
  };
  const place = (li, parent) => {
    const where = parent ? $('[data-replies="' + parent + '"]') : list;
    (where || list).appendChild(li);
    return li;
  };
  // Pending comments: only their writer sees them, on this browser, for a week.
  const pendKey = 'pb_pending_' + postId;
  const pending = (store.get(pendKey) || []).filter((p) => p && p.at > Date.now() - 7 * 864e5);
  pending.forEach((p) => { if (!document.getElementById('comment-' + p.id)) place(html(p.html), p.parent); });

  const resetReply = () => {
    form.parent.value = '0';
    $('[data-replying]', form).hidden = true;
    $('[data-form-title]', form).hidden = false;
    box.appendChild(form);
  };
  box.addEventListener('click', (e) => {
    const r = e.target.closest('[data-reply]');
    if (!r || !form) return;
    const li = r.closest('.pb-comment');
    form.parent.value = r.dataset.reply;
    $('[data-replying-to]', form).textContent = 'Replying to ' + r.dataset.name;
    $('[data-replying]', form).hidden = false;
    $('[data-form-title]', form).hidden = true;
    li.appendChild(form);
    say('');
    form.body.focus();
  });
  if (form) $('[data-cancel-reply]', form).addEventListener('click', resetReply);

  // After a visitor's comment: offer email notifications.
  const offerEmail = (id, key) => {
    if (!state.mail || !key) return;
    const f = html('<form class="pb-notify" novalidate><label>Want an email when it\'s approved or someone replies?'
      + '<span class="pb-notify-row"><input type="email" name="email" autocomplete="email" placeholder="you@example.com" required>'
      + '<button type="submit" class="pb-btn">Notify me</button></span></label>'
      + '<p class="pb-small pb-muted">Only used for this. Never shown. One click to stop.</p></form>');
    msg.appendChild(f);
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      const email = f.email.value.trim();
      if (!/^\S+@\S+\.\S+$/.test(email)) { f.email.focus(); return; }
      f.querySelector('button').disabled = true;
      send('notify', { id: id, key: key, email: email }).then((r) => {
        if (r.error) { f.querySelector('button').disabled = false; alert(r.error); return; }
        f.replaceWith(html('<p class="pb-comment-msg is-ok" role="status">Done. We\'ll email ' + email.replace(/[<>&"]/g, '') + ' when it\'s approved and when someone replies.</p>'));
      });
    });
  };

  if (form) form.addEventListener('submit', (e) => {
    e.preventDefault();
    const body = form.body.value.trim();
    if (!body) { form.body.focus(); return; }
    if (!state.user && form.elements.name.value.trim().length < 2) { form.elements.name.focus(); say('Add your name first.', 'error'); return; }
    const btn = $('.pb-comment-submit', form);
    btn.disabled = true;
    say('Posting…', 'info');
    const data = new FormData(form);
    const parent = form.parent.value;
    send('comment', data, state.csrf ? { 'X-CSRF-Token': state.csrf } : null).then((r) => {
      btn.disabled = false;
      if (r.error) { say(r.error, 'error'); return; }
      form.body.value = '';
      const li = place(html(r.html), r.parent || 0);
      if (r.status === 'approved') {
        countUp();
        resetReply();
        say(r.removed ? 'Posted. Links and code were removed.' : '');
        li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      } else {
        pending.push({ id: r.id, html: r.html, parent: r.parent, at: Date.now() });
        store.set(pendKey, pending);
        resetReply();
        say('Thanks! Your comment will appear once it\'s approved.' + (r.removed ? ' Links, email addresses and code were removed.' : ''));
        offerEmail(r.id, r.key);
      }
    }).catch(() => { btn.disabled = false; say('Could not reach the site. Check your connection and try again.', 'error'); });
  });

  // ---- who's here: signed in? liked? ------------------------------------------
  fetch(api + 'state&post=' + encodeURIComponent(postId) + '&v=' + visitor, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then((r) => r.json()).then((s) => {
      state = s;
      paintLike(s.liked, s.likes);
      if (form && s.user) {
        const as = $('[data-as]', form);
        as.textContent = 'Commenting as ' + s.user.name;
        as.hidden = false;
        $('[data-name-field]', form).hidden = true;
        form.elements.name.required = false;
        form.body.removeAttribute('maxlength');
        $('[data-rules]', form).textContent = 'Your comment goes live straight away.';
      }
    }).catch(() => {});
})();
