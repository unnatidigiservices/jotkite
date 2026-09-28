/*! JotKite — paste & Markdown engine · https://jotkite.com
 * SPDX-License-Identifier: AGPL-3.0-or-later OR LicenseRef-JotKite-Commercial
 *
 * "Write anywhere, paste without pain." Turns whatever a writer copies —
 * Word, Google Docs, WordPress (Gutenberg) blocks, Notion, web pages,
 * Markdown from any text editor, or plain text — into the small, clean HTML
 * the JotKite editor uses. Pure functions, no DOM side effects, so it is
 * easy to test (window.PBPaste). The server sanitizer still has the final say.
 */
(function (root) {
  'use strict';

  const BLOCKS = 'P,H1,H2,H3,H4,H5,H6,UL,OL,LI,TABLE,BLOCKQUOTE,PRE,FIGURE,DIV,SECTION,ARTICLE,HEADER,FOOTER,MAIN,ASIDE,HR';
  const KEEP = new Set(['P', 'BR', 'H2', 'H3', 'H4', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'SUB', 'SUP', 'MARK', 'A', 'UL', 'OL', 'LI',
    'BLOCKQUOTE', 'PRE', 'CODE', 'IMG', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'HR', 'FIGURE', 'FIGCAPTION', 'IFRAME']);
  const DROP = new Set(['SCRIPT', 'STYLE', 'META', 'LINK', 'TITLE', 'OBJECT', 'EMBED', 'SVG', 'FORM', 'INPUT', 'BUTTON', 'SELECT',
    'TEXTAREA', 'TEMPLATE', 'NOSCRIPT', 'XML', 'HEAD', 'COLGROUP', 'COL', 'O:P', 'W:SDT', 'V:SHAPE', 'V:IMAGEDATA']);
  const RENAME = { H1: 'H2', H5: 'H4', H6: 'H4', CENTER: 'P', DEL: 'S', STRIKE: 'S', INS: 'U', DIV: 'P', SECTION: 'P', ARTICLE: 'P' };
  const UNWRAP_ALWAYS = new Set(['SPAN', 'FONT', 'HEADER', 'FOOTER', 'MAIN', 'ASIDE', 'NAV', 'LABEL', 'SMALL', 'BIG', 'ABBR', 'CITE', 'TIME', 'DFN', 'Q', 'KBD', 'VAR', 'SAMP', 'CAPTION']);

  // ---------------------------------------------------------------- helpers
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const isBlock = (el) => el && el.nodeType === 1 && BLOCKS.split(',').indexOf(el.tagName) !== -1;
  function unwrap(el) {
    const parent = el.parentNode;
    if (!parent) return;
    while (el.firstChild) parent.insertBefore(el.firstChild, el);
    parent.removeChild(el);
  }
  function rename(el, tag) {
    const n = el.ownerDocument.createElement(tag);
    while (el.firstChild) n.appendChild(el.firstChild);
    el.parentNode.replaceChild(n, el);
    return n;
  }
  function safeUrl(u) {
    u = String(u || '').trim();
    if (!u) return '';
    const probe = u.replace(/[\u0000- \u007f]+/g, '');
    const m = probe.match(/^([a-z][a-z0-9+.\-]*):/i);
    if (m && ['http', 'https', 'mailto', 'tel'].indexOf(m[1].toLowerCase()) === -1) return '';
    return u;
  }
  // Google Docs and some email clients wrap links: https://www.google.com/url?q=REAL&sa=...
  function unwrapRedirect(u) {
    const m = u.match(/^https?:\/\/(?:www\.)?google\.[a-z.]+\/url\?(?:.*&)?q=([^&]+)/i);
    if (m) { try { return decodeURIComponent(m[1]); } catch (e) { return u; } }
    return u;
  }
  function videoEmbedUrl(u) {
    u = String(u || '').trim();
    let m = u.match(/^(?:https?:)?\/\/(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/|live\/|v\/)|youtu\.be\/)([\w-]{11})/i);
    if (m) return 'https://www.youtube.com/embed/' + m[1];
    m = u.match(/^(?:https?:)?\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?(\d+)/i);
    if (m) return 'https://player.vimeo.com/video/' + m[1];
    return null;
  }
  function embedHtml(src) {
    return '<figure class="pb-embed"><iframe src="' + esc(src) + '" title="Video" width="560" height="315" '
      + 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '
      + 'referrerpolicy="strict-origin-when-cross-origin" allowfullscreen loading="lazy"></iframe></figure>';
  }
  function makeEmbed(doc, src) {
    const t = doc.createElement('template');
    t.innerHTML = embedHtml(src);
    return t.content.firstChild;
  }
  function styleOf(el) { return (el.getAttribute && el.getAttribute('style') || '').toLowerCase(); }

  // ------------------------------------------------------ source detection
  function detectSource(html) {
    if (/<!--\s*wp:|class="[^"]*wp-block-/i.test(html)) return 'wordpress';
    if (/docs-internal-guid/i.test(html)) return 'gdocs';
    if (/urn:schemas-microsoft-com:office|class=["']?Mso|mso-[a-z-]+:/i.test(html)) return 'word';
    if (/<meta[^>]+LibreOffice/i.test(html)) return 'libreoffice';
    if (/notion-/i.test(html)) return 'notion';
    return 'html';
  }

  // ----------------------------------------------- source-specific passes
  // Google Docs: a <b id="docs-internal-guid-…"> wraps everything (not bold!);
  // real bold/italic are spans with font-weight / font-style.
  function fixGoogleDocs(body) {
    body.querySelectorAll('[id^="docs-internal-guid"]').forEach(unwrap);
  }
  // Styled spans (Google Docs, LibreOffice, web pages) -> real <strong>/<em>/<s>.
  function stylesToTags(body) {
    Array.from(body.querySelectorAll('span,font,p,li,td')).forEach((el) => {
      const st = styleOf(el);
      if (!st) return;
      const inHeading = !!el.closest('h1,h2,h3,h4,h5,h6');
      const wrapWith = [];
      const w = st.match(/font-weight\s*:\s*(bold|bolder|[6-9]00)/);
      if (w && !inHeading) wrapWith.push('strong');
      if (/font-style\s*:\s*italic/.test(st)) wrapWith.push('em');
      if (/text-decoration[^;]*line-through/.test(st)) wrapWith.push('s');
      if (/vertical-align\s*:\s*super/.test(st)) wrapWith.push('sup');
      if (/vertical-align\s*:\s*sub/.test(st)) wrapWith.push('sub');
      if (!wrapWith.length || !el.textContent.trim()) return;
      if (el.closest('a') && wrapWith.length === 1 && wrapWith[0] === 'u') return;
      let inner = el.ownerDocument.createDocumentFragment();
      while (el.firstChild) inner.appendChild(el.firstChild);
      wrapWith.reverse().forEach((tag) => {
        const t = el.ownerDocument.createElement(tag);
        t.appendChild(inner);
        inner = t;
      });
      el.appendChild(inner);
    });
  }
  // Word lists are paragraphs styled "mso-list:l0 level2 lfo1" whose bullet is
  // a text span marked mso-list:Ignore. Rebuild them as real (nested) lists.
  function fixWordLists(body) {
    const doc = body.ownerDocument;
    const paras = Array.from(body.querySelectorAll('p')).filter((p) => /mso-list\s*:/.test(styleOf(p)) || /MsoListParagraph/i.test(p.className));
    if (!paras.length) return;
    const groups = [];
    paras.forEach((p) => {
      const last = groups[groups.length - 1];
      let prev = p.previousSibling;
      while (prev && prev.nodeType === 3 && !prev.textContent.trim()) prev = prev.previousSibling;
      if (last && prev === last[last.length - 1]) last.push(p); else groups.push([p]);
    });
    groups.forEach((group) => {
      const stack = []; // [{level, list}]
      let first = null;
      group.forEach((p) => {
        const lm = styleOf(p).match(/level(\d+)/);
        const level = lm ? parseInt(lm[1], 10) : 1;
        let marker = '';
        p.querySelectorAll('span').forEach((s) => {
          if (/mso-list\s*:\s*ignore/.test(styleOf(s))) { marker += s.textContent; s.remove(); }
        });
        // Some Word versions leave the bullet as a leading text run instead.
        if (!marker) {
          const t = p.firstChild && p.firstChild.nodeType === 3 ? p.firstChild : null;
          const mm = t && t.textContent.match(/^\s*([·•§o▪◦\-–]|\(?[0-9a-zA-Z]{1,3}[.)])\s+/);
          if (mm) { marker = mm[1]; t.textContent = t.textContent.slice(mm[0].length); }
        }
        const ordered = /^\s*\(?[0-9a-zA-Z]{1,3}[.)]/.test(marker.replace(/ /g, ' ').trim()) && !/^\s*o\s*$/.test(marker);
        const li = doc.createElement('li');
        while (p.firstChild) li.appendChild(p.firstChild);
        while (stack.length && stack[stack.length - 1].level > level) stack.pop();
        if (!stack.length || stack[stack.length - 1].level < level) {
          const list = doc.createElement(ordered ? 'ol' : 'ul');
          if (stack.length) {
            const parentList = stack[stack.length - 1].list;
            (parentList.lastElementChild || parentList.appendChild(doc.createElement('li'))).appendChild(list);
          } else {
            first = list;
            p.parentNode.insertBefore(list, p);
          }
          stack.push({ level, list });
        }
        stack[stack.length - 1].list.appendChild(li);
        p.remove();
      });
      return first;
    });
  }
  // WordPress block editor (and any web page): embeds, images, galleries, buttons.
  function fixFigures(body, report) {
    const doc = body.ownerDocument;
    // Gutenberg embeds are a <figure> holding only the URL as text.
    body.querySelectorAll('figure.wp-block-embed, figure[class*="wp-block-embed"]').forEach((fig) => {
      const url = (fig.querySelector('.wp-block-embed__wrapper') || fig).textContent.trim();
      const src = videoEmbedUrl(url);
      if (src) { fig.replaceWith(makeEmbed(doc, src)); report.embeds++; return; }
      const p = doc.createElement('p');
      const a = doc.createElement('a');
      a.href = url; a.textContent = url;
      p.appendChild(a);
      fig.replaceWith(p);
    });
    // Existing iframes (copied from a web page) — keep YouTube/Vimeo only.
    body.querySelectorAll('iframe').forEach((f) => {
      const src = videoEmbedUrl(f.getAttribute('src') || '');
      if (src) {
        const holder = f.closest('figure,div,p') && f.closest('figure,div,p') !== body ? f.closest('figure,div,p') : f;
        holder.replaceWith(makeEmbed(doc, src));
        report.embeds++;
      } else f.remove();
    });
    // A bare YouTube/Vimeo URL alone in a paragraph becomes a video (as in WordPress).
    body.querySelectorAll('p').forEach((p) => {
      const t = p.textContent.trim();
      if (!t || /\s/.test(t) || p.querySelector('img,iframe')) return;
      const src = videoEmbedUrl(t);
      if (src) { p.replaceWith(makeEmbed(doc, src)); report.embeds++; }
    });
    // Tables/quotes/galleries wrapped in figures: keep the content, lose the wrapper.
    body.querySelectorAll('figure.wp-block-table, figure.wp-block-pullquote, figure.wp-block-gallery, figure.wp-block-quote').forEach((fig) => {
      const cap = fig.querySelector(':scope > figcaption');
      if (cap) { const p = doc.createElement('p'); p.innerHTML = cap.innerHTML; cap.replaceWith(p); }
      if (fig.classList.contains('wp-block-pullquote') && !fig.querySelector('blockquote')) { rename(fig, 'blockquote'); return; }
      unwrap(fig);
    });
    // Every image becomes a JotKite figure (wrap + size + caption preserved).
    Array.from(body.querySelectorAll('img')).forEach((img) => {
      if (img.closest('figure.pb-figure')) return;
      const host = img.closest('figure') || null;
      const alignFrom = (el) => {
        const c = el ? (' ' + (el.className || '') + ' ' + (el.parentElement ? el.parentElement.className || '' : '') + ' ') : '';
        const st = el ? styleOf(el) : '';
        if (/\salignleft\s|\sleft\s|float\s*:\s*left/.test(c + st)) return 'left';
        if (/\salignright\s|\sright\s|float\s*:\s*right/.test(c + st)) return 'right';
        if (/\saligncenter\s/.test(c)) return 'center';
        return '';
      };
      const align = alignFrom(host) || alignFrom(img);
      const fig = doc.createElement('figure');
      fig.className = 'pb-figure ' + (align ? 'pb-align-' + align + ' ' : '') + (align === 'left' || align === 'right' ? 'pb-w-m' : 'pb-w-full');
      const capEl = host ? host.querySelector('figcaption') : null;
      const link = img.parentElement && img.parentElement.tagName === 'A' ? img.parentElement : null;
      fig.appendChild(img.cloneNode(false));
      if (capEl && capEl.textContent.trim()) {
        const cap = doc.createElement('figcaption');
        cap.textContent = capEl.textContent.trim();
        fig.appendChild(cap);
      }
      // Place the figure at block level: replace the figure/paragraph that held it.
      let anchor = host || link || img;
      let block = anchor.parentElement;
      while (block && block !== body && !isBlock(block)) { anchor = block; block = block.parentElement; }
      if (host) {
        host.replaceWith(fig);
      } else if (block && block !== body && block.tagName !== 'LI' && block.tagName !== 'TD' && block.tagName !== 'TH' && block.textContent.trim() === '') {
        block.replaceWith(fig); // image was alone in its paragraph
      } else if (block && block !== body && ['LI', 'TD', 'TH'].indexOf(block.tagName) === -1) {
        block.parentNode.insertBefore(fig, block.nextSibling);
        (link || img).remove();
      } else {
        anchor.replaceWith(fig);
      }
      report.images++;
    });
    // Quote attributions (<cite> in WordPress/web quotes) become their own "— Name" line.
    body.querySelectorAll('blockquote cite, blockquote footer').forEach((c) => {
      const p = doc.createElement('p');
      p.textContent = '— ' + c.textContent.trim().replace(/^[—–-]\s*/, '');
      c.replaceWith(p);
    });
    // Leftover figures without our class (no image inside): unwrap.
    body.querySelectorAll('figure:not(.pb-figure):not(.pb-embed)').forEach(unwrap);
    // Gutenberg buttons/columns/groups/covers are layout, not content.
    body.querySelectorAll('.wp-block-buttons,.wp-block-button,.wp-block-columns,.wp-block-column,.wp-block-group,.wp-block-cover,.wp-block-cover__inner-container,.wp-block-media-text,.wp-block-media-text__content').forEach(unwrap);
  }

  // ----------------------------------------------------------- the walker
  function cleanTree(node, report) {
    Array.from(node.childNodes).forEach((n) => {
      if (n.nodeType === 8) { n.remove(); return; }            // comments (incl. Word conditionals, wp: block markers)
      if (n.nodeType === 3) { // Word/Docs pad text with non-breaking spaces; keep one only where it is the whole node
        if (!/^ +$/.test(n.textContent)) n.textContent = n.textContent.replace(/ /g, ' ');
        return;
      }
      if (n.nodeType !== 1) { n.remove(); return; }
      let el = n;
      let tag = el.tagName.toUpperCase();
      if (DROP.has(tag) || tag.indexOf(':') !== -1) { el.remove(); return; }
      if (tag === 'FIGURE' && /\bpb-(figure|embed)\b/.test(el.className)) {
        Array.from(el.attributes).forEach((a) => { if (a.name !== 'class') el.removeAttribute(a.name); });
        el.className = el.className.split(/\s+/).filter((c) => /^pb-(figure|embed|align-(left|right|center)|w-(s|m|l|full))$/.test(c)).join(' ');
        el.querySelectorAll('img').forEach((img) => cleanAttrs(img, 'IMG', report));
        el.querySelectorAll('figcaption').forEach((c) => { c.textContent = c.textContent; Array.from(c.attributes).forEach((a) => c.removeAttribute(a.name)); });
        return; // already built by us
      }
      cleanTree(el, report);
      if (UNWRAP_ALWAYS.has(tag)) { unwrap(el); return; }
      if (RENAME[tag]) {
        if (['DIV', 'SECTION', 'ARTICLE'].indexOf(tag) !== -1 && Array.from(el.children).some(isBlock)) { unwrap(el); return; }
        el = rename(el, RENAME[tag]);
        tag = RENAME[tag];
      }
      if (tag === 'B') { el = rename(el, 'strong'); tag = 'STRONG'; }
      if (tag === 'I') { el = rename(el, 'em'); tag = 'EM'; }
      if (!KEEP.has(tag) || tag === 'FIGURE' || tag === 'FIGCAPTION' || tag === 'IFRAME') { unwrap(el); return; }
      cleanAttrs(el, tag, report);
    });
  }
  function cleanAttrs(el, tag, report) {
    const allowed = { A: ['href'], IMG: ['src', 'alt', 'width', 'height'], TH: ['colspan', 'rowspan'], TD: ['colspan', 'rowspan'] }[tag] || [];
    Array.from(el.attributes).forEach((a) => { if (allowed.indexOf(a.name.toLowerCase()) === -1) el.removeAttribute(a.name); });
    if (tag === 'A') {
      const href = safeUrl(unwrapRedirect(el.getAttribute('href') || ''));
      if (href) el.setAttribute('href', href); else el.removeAttribute('href');
    }
    if (tag === 'IMG') {
      const src = el.getAttribute('src') || '';
      if (/^(file|blob|cid|webkit-fake-url):/i.test(src) || !src) {
        const fig = el.closest('figure');
        (fig || el).remove();
        report.skipped++;
        return;
      }
      if (/^data:image\//i.test(src) || /^https?:\/\//i.test(src) || /^\/\//.test(src)) el.setAttribute('data-pb-import', '1');
      ['width', 'height'].forEach((d) => { if (el.hasAttribute(d) && !/^\d+$/.test(el.getAttribute(d))) el.removeAttribute(d); });
    }
  }
  // Final tidy: no empty paragraphs, no <p> inside <li>, no bold inside headings, no trailing <br>.
  function tidy(body) {
    // Loose inline content between blocks (e.g. a WordPress button link) gets its own paragraph.
    // A paste that is only inline (a phrase) stays inline so it flows into the current line.
    if (Array.from(body.children).some(isBlock)) {
      let run = null;
      Array.from(body.childNodes).forEach((n) => {
        const blank = n.nodeType === 3 && !n.textContent.trim();
        const inline = (n.nodeType === 3 && !blank) || (n.nodeType === 1 && !isBlock(n));
        if (inline || (blank && run)) {
          if (!run) { run = body.ownerDocument.createElement('p'); body.insertBefore(run, n); }
          run.appendChild(n);
        } else if (blank) {
          n.remove();
        } else {
          run = null;
        }
      });
    }
    body.querySelectorAll('li > p').forEach((p) => {
      if (p.nextSibling && p.nextElementSibling && p.nextElementSibling.tagName === 'P') p.appendChild(body.ownerDocument.createElement('br'));
      unwrap(p);
    });
    body.querySelectorAll('h2 strong, h3 strong, h4 strong').forEach(unwrap);
    body.querySelectorAll('strong strong, em em').forEach(unwrap);
    body.querySelectorAll('p,h2,h3,h4,li,blockquote').forEach((el) => {
      while (el.lastChild && el.lastChild.nodeName === 'BR') el.lastChild.remove();
    });
    body.querySelectorAll('p,h2,h3,h4').forEach((el) => {
      if (!el.textContent.replace(/[\s ​]/g, '') && !el.querySelector('img,iframe')) el.remove();
    });
    body.querySelectorAll('strong,em,s,u,sub,sup,mark,code').forEach((el) => { if (!el.textContent && !el.querySelector('img')) el.remove(); });
    body.normalize();
  }

  /** Clean pasted HTML from any source. Returns {html, source, images, embeds, skipped}. */
  function cleanHtml(html) {
    const source = detectSource(html);
    const report = { source, images: 0, embeds: 0, skipped: 0 };
    const doc = new DOMParser().parseFromString(String(html), 'text/html');
    const body = doc.body;
    if (source === 'gdocs') fixGoogleDocs(body);
    if (source === 'word') fixWordLists(body);
    stylesToTags(body);
    fixFigures(body, report);
    cleanTree(body, report);
    tidy(body);
    report.html = body.innerHTML.trim();
    return report;
  }

  // ============================================================ MARKDOWN
  function inlineMd(text) {
    const codes = [];
    let s = esc(text).replace(/`([^`\n]+)`/g, (m, c) => { codes.push('<code>' + c + '</code>'); return '\u0000' + (codes.length - 1) + '\u0000'; });
    s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;[^&]*&quot;)?\)/g, (m, alt, src) => {
      const u = safeUrl(src.replace(/&amp;/g, '&'));
      return u ? '<img src="' + esc(u) + '" alt="' + alt + '">' : m;
    });
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;[^&]*&quot;)?\)/g, (m, label, href) => {
      const u = safeUrl(href.replace(/&amp;/g, '&'));
      return u ? '<a href="' + esc(u) + '">' + label + '</a>' : label;
    });
    s = s.replace(/&lt;(https?:\/\/[^\s&]+)&gt;/g, '<a href="$1">$1</a>');
    s = s.replace(/(^|[\s(])(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, '$1<a href="$2">$2</a>');
    s = s.replace(/(\*\*|__)(?=\S)([\s\S]*?\S)\1/g, '<strong>$2</strong>');
    s = s.replace(/(^|[^*\w])\*(?=\S)([^*\n]*?\S)\*(?![*\w])/g, '$1<em>$2</em>');
    s = s.replace(/(^|[^_\w])_(?=\S)([^_\n]*?\S)_(?![_\w])/g, '$1<em>$2</em>');
    s = s.replace(/~~(?=\S)([\s\S]*?\S)~~/g, '<s>$1</s>');
    s = s.replace(/==(?=\S)([^=\n]*?\S)==/g, '<mark>$1</mark>');
    return s.replace(/\u0000(\d+)\u0000/g, (m, i) => codes[+i]);
  }
  const RE_LIST = /^(\s*)([-*+]|\d{1,3}[.)])\s+(.*)$/;
  const RE_HR = /^\s{0,3}([-*_])(\s*\1){2,}\s*$/;
  const RE_FENCE = /^\s{0,3}(`{3,}|~{3,})\s*([\w+-]*)\s*$/;
  const RE_TABLE_SEP = /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/;

  function mdToHtml(md) {
    const lines = String(md).replace(/\r\n?/g, '\n').replace(/\t/g, '    ').split('\n');
    // Map the shallowest heading used to <h2> (the post title is the <h1>).
    let minLevel = 7;
    let inFence = false;
    lines.forEach((l) => {
      if (RE_FENCE.test(l)) inFence = !inFence;
      const m = !inFence && l.match(/^\s{0,3}(#{1,6})\s+\S/);
      if (m) minLevel = Math.min(minLevel, m[1].length);
    });
    const hTag = (n) => 'h' + Math.min(4, 2 + (n - minLevel));
    return blocks(lines, hTag);
  }
  function blocks(lines, hTag) {
    const out = [];
    let i = 0;
    let para = [];
    const flush = () => {
      if (!para.length) return;
      const text = para.join('\n').trim();
      para = [];
      if (!text) return;
      const alone = text.match(/^!\[([^\]]*)\]\(([^)\s]+)\)$/);
      if (alone && safeUrl(alone[2])) {
        out.push('<figure class="pb-figure pb-w-full"><img src="' + esc(alone[2]) + '" alt="' + esc(alone[1]) + '"></figure>');
        return;
      }
      const vid = !/\s/.test(text) && videoEmbedUrl(text.replace(/^<|>$/g, ''));
      if (vid) { out.push(embedHtml(vid)); return; }
      out.push('<p>' + text.split('\n').map(inlineMd).join('<br>') + '</p>');
    };
    while (i < lines.length) {
      const line = lines[i];
      let m;
      if ((m = line.match(RE_FENCE))) {
        flush();
        const fence = m[1];
        const code = [];
        i++;
        while (i < lines.length && lines[i].trim().indexOf(fence) !== 0) code.push(lines[i++]);
        i++;
        out.push('<pre><code>' + esc(code.join('\n')) + '</code></pre>');
        continue;
      }
      if (!line.trim()) { flush(); i++; continue; }
      if ((m = line.match(/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/))) {
        flush();
        const t = hTag(m[1].length);
        out.push('<' + t + '>' + inlineMd(m[2]) + '</' + t + '>');
        i++;
        continue;
      }
      if (RE_HR.test(line) && !para.length) { flush(); out.push('<hr>'); i++; continue; }
      if (/^\s{0,3}>/.test(line)) {
        flush();
        const q = [];
        while (i < lines.length && /^\s{0,3}>/.test(lines[i])) q.push(lines[i++].replace(/^\s{0,3}>\s?/, ''));
        out.push('<blockquote>' + blocks(q, hTag) + '</blockquote>');
        continue;
      }
      if (line.indexOf('|') !== -1 && i + 1 < lines.length && RE_TABLE_SEP.test(lines[i + 1])) {
        flush();
        const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => inlineMd(c.trim()));
        let html = '<table><thead><tr>' + cells(line).map((c) => '<th>' + c + '</th>').join('') + '</tr></thead><tbody>';
        i += 2;
        while (i < lines.length && lines[i].indexOf('|') !== -1 && lines[i].trim()) {
          html += '<tr>' + cells(lines[i]).map((c) => '<td>' + c + '</td>').join('') + '</tr>';
          i++;
        }
        out.push(html + '</tbody></table>');
        continue;
      }
      if (RE_LIST.test(line) && !(para.length && /^\d/.test(line.trim()) && !/^1[.)]/.test(line.trim()))) {
        flush();
        const items = [];
        while (i < lines.length) {
          const l = lines[i];
          const lm = l.match(RE_LIST);
          if (lm) { items.push({ indent: lm[1].length, ordered: /\d/.test(lm[2]), text: lm[3] }); i++; continue; }
          if (l.trim() && /^\s{2,}\S/.test(l) && items.length) { items[items.length - 1].text += '\n' + l.trim(); i++; continue; }
          break;
        }
        out.push(listHtml(items));
        continue;
      }
      para.push(line);
      i++;
    }
    flush();
    return out.join('');
  }
  function listHtml(items) {
    let html = '';
    const stack = [];
    items.forEach((it, idx) => {
      while (stack.length && it.indent < stack[stack.length - 1].indent) { html += '</li></' + stack.pop().tag + '>'; }
      const tag = it.ordered ? 'ol' : 'ul';
      if (!stack.length || it.indent > stack[stack.length - 1].indent) {
        html += '<' + tag + '>';
        stack.push({ indent: it.indent, tag });
      } else {
        html += '</li>';
      }
      let text = it.text;
      const task = text.match(/^\[([ xX])\]\s+(.*)$/s);
      if (task) text = (task[1] === ' ' ? '☐ ' : '☑ ') + task[2];
      html += '<li>' + text.split('\n').map(inlineMd).join('<br>');
      if (idx === items.length - 1) while (stack.length) html += '</li></' + stack.pop().tag + '>';
    });
    return html;
  }

  /** Does this plain text look like Markdown (vs. an ordinary note)? */
  function looksLikeMarkdown(text) {
    const t = String(text);
    let score = 0;
    if (/^\s{0,3}#{1,6}\s+\S/m.test(t)) score += 2;
    if (/^\s{0,3}(`{3,}|~{3,})/m.test(t)) score += 2;
    if ((t.match(/^\s*[-*+]\s+\S/gm) || []).length >= 2) score += 1;
    if ((t.match(/^\s*\d{1,3}[.)]\s+\S/gm) || []).length >= 2) score += 1;
    if (/\*\*[^*\n]+\*\*|__[^_\n]+__/.test(t)) score += 2;
    if (/\[[^\]\n]+\]\([^)\s]+\)/.test(t)) score += 2;
    if (/^\s{0,3}>\s/m.test(t)) score += 1;
    if (/^\s*\|.+\|\s*$/m.test(t) && /^\s*\|?\s*:?-{3,}/m.test(t)) score += 2;
    if (/`[^`\n]+`/.test(t)) score += 1;
    return score >= 2;
  }
  /** Plain text (email, notes app, a text file): paragraphs, line breaks, clickable links, video URLs. */
  function plainToHtml(text) {
    return String(text).replace(/\r\n?/g, '\n').split(/\n{2,}/).map((chunk) => {
      const c = chunk.trim();
      if (!c) return '';
      const vid = !/\s/.test(c) && videoEmbedUrl(c);
      if (vid) return embedHtml(vid);
      return '<p>' + c.split('\n').map((l) => esc(l).replace(/(^|\s)(https?:\/\/[^\s<]+[^\s<.,;:!?)'"])/g, '$1<a href="$2">$2</a>')).join('<br>') + '</p>';
    }).join('');
  }
  /** Plain-text clipboard: Markdown if it looks like Markdown, otherwise tidy paragraphs. */
  function textToHtml(text) {
    if (/^\s*<!--\s*wp:/.test(text)) return cleanHtml(text);         // Gutenberg "Copy block" puts block markup in text/plain
    const md = looksLikeMarkdown(text);
    const r = cleanHtml(md ? mdToHtml(text) : plainToHtml(text));
    r.source = md ? 'markdown' : 'text';
    return r;
  }
  /** Some apps (VS Code, Notepad++, terminals) put colourised code in text/html. If that HTML
   *  has no real document structure and the text is Markdown, the Markdown is what the writer meant. */
  function preferText(html, text) {
    if (!text) return false;
    if (/^\s*<!--\s*wp:/.test(text)) return true;
    if (!looksLikeMarkdown(text)) return false;
    return !/<(h[1-6]|ul|ol|li|strong|b|em|i|a|table|img|blockquote)[\s>]/i.test(html);
  }

  root.PBPaste = { cleanHtml, mdToHtml, textToHtml, plainToHtml, looksLikeMarkdown, preferText, videoEmbedUrl, embedHtml, detectSource, inlineMd };
})(window);
