function qs(root, sel) {
  return root.querySelector(sel);
}

function qsa(root, sel) {
  return Array.from(root.querySelectorAll(sel));
}

function postJson(url, body) {
  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
  }).then(async (r) => {
    let j = {};
    try {
      j = await r.json();
    } catch {
      j = {};
    }
    if (!r.ok && !j.error) j.error = r.statusText;
    return j;
  });
}

function trapFocus(container) {
  const focusable = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
  const nodes = () => qsa(container, focusable).filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
  function onKey(e) {
    if (e.key !== 'Tab') return;
    const list = nodes();
    if (list.length === 0) return;
    const first = list[0];
    const last = list[list.length - 1];
    if (e.shiftKey) {
      if (document.activeElement === first) {
        e.preventDefault();
        last.focus();
      }
    } else if (document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }
  return { onKey, focusFirst: () => nodes()[0]?.focus() };
}

function applyPromoCardStats(root, post) {
  if (!root) return;
  const v = root.querySelector('[data-stat-views]');
  const l = root.querySelector('[data-stat-likes]');
  if (v && typeof post.view_count === 'number') v.textContent = String(post.view_count);
  if (l && typeof post.like_count === 'number') l.textContent = String(post.like_count);
}

export function initPromoFeed(section) {
  if (!section) return;
  const feedUrl = section.getAttribute('data-feed-url') || '';
  const csrf = section.getAttribute('data-csrf') || '';
  const skeleton = qs(section, '[data-promo-feed-skeleton]');
  const viewport = qs(section, '[data-promo-feed-viewport]');
  const track = qs(section, '[data-promo-feed-track]');
  const chrome = qs(section, '[data-promo-feed-chrome]');
  const modal = qs(section, '[data-promo-video-modal]');
  const stage = qs(section, '[data-promo-modal-stage]');
  const reportDlg = qs(section, '[data-promo-report-dialog]');
  let reportPostId = 0;
  let modalTrap = null;
  let prevFocus = null;

  const hasCards = track && qsa(track, '[data-promo-card]').length > 0;
  if (skeleton) skeleton.hidden = hasCards;
  if (viewport) viewport.hidden = !hasCards;
  if (chrome) chrome.hidden = !hasCards || qsa(track, '[data-promo-card]').length < 2;

  /** @type {HTMLElement|null} */
  let openCard = null;

  function closeModal() {
    if (!modal || !stage) return;
    stage.querySelectorAll('video').forEach((v) => {
      try {
        v.pause();
      } catch {}
    });
    stage.innerHTML = '';
    if (typeof modal.close === 'function') modal.close();
    else modal.removeAttribute('open');
    if (modalTrap) {
      modal.removeEventListener('keydown', modalTrap.onKey);
      modalTrap = null;
    }
    if (prevFocus && typeof prevFocus.focus === 'function') {
      try {
        prevFocus.focus();
      } catch {}
    }
    prevFocus = null;
  }

  qs(section, '[data-close-promo-modal]')?.addEventListener('click', closeModal);
  modal?.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal?.open) closeModal();
  });

  modal?.addEventListener('cancel', (e) => {
    e.preventDefault();
    closeModal();
  });

  section.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open-promo-video]');
    if (!btn || !modal || !stage) return;
    const card = btn.closest('[data-promo-card]');
    const tpl = card?.querySelector('template[data-video-template]');
    if (!tpl) return;
    openCard = card;
    prevFocus = document.activeElement;
    stage.innerHTML = '';
    stage.appendChild(tpl.content.cloneNode(true));
    if (typeof modal.showModal === 'function') modal.showModal();
    else modal.setAttribute('open', 'open');
    modalTrap = trapFocus(modal);
    modal.addEventListener('keydown', modalTrap.onKey);
    modalTrap.focusFirst();
    const v = stage.querySelector('video');
    if (v) {
      try {
        v.play().catch(() => {});
      } catch {}
    }
  });

  qsa(section, '[data-like-promo]').forEach((b) => {
    b.addEventListener('click', async () => {
      if (b.disabled) return;
      const id = parseInt(b.getAttribute('data-post-id') || '0', 10);
      if (!id) return;
      const j = await postJson(feedUrl, { action: 'like', post_id: id, csrf });
      if (!j.success) {
        alert(j.error || 'Could not like');
        return;
      }
      b.classList.toggle('is-liked', !!j.liked);
      const lab = b.querySelector('[data-like-label]');
      if (lab) lab.textContent = j.liked ? 'Liked' : 'Like';
      const card = b.closest('[data-promo-card]');
      if (card && typeof j.like_count === 'number') applyPromoCardStats(card, { like_count: j.like_count });
    });
  });

  const seen = new Set();
  const trackView = (card) => {
    const id = parseInt(card.getAttribute('data-post-id') || '0', 10);
    if (!id || seen.has(id)) return;
    seen.add(id);
    postJson(feedUrl, { action: 'view', post_id: id }).then((j) => {
      if (j.success && typeof j.view_count === 'number') applyPromoCardStats(card, { view_count: j.view_count });
    });
  };
  const cardsForViews = qsa(section, '[data-promo-card]').filter((c) => parseInt(c.getAttribute('data-post-id') || '0', 10) > 0);
  if ('IntersectionObserver' in window) {
    const obs = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            trackView(entry.target);
            obs.unobserve(entry.target);
          }
        });
      },
      { root: viewport || null, threshold: 0.55 }
    );
    cardsForViews.forEach((card) => obs.observe(card));
  } else {
    cardsForViews.forEach(trackView);
  }

  /* Report promo */
  function closeReport() {
    if (reportDlg && typeof reportDlg.close === 'function') reportDlg.close();
    else if (reportDlg) reportDlg.removeAttribute('open');
  }
  section.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-report-promo]');
    if (!btn || !reportDlg) return;
    reportPostId = parseInt(btn.getAttribute('data-post-id') || '0', 10);
    if (!reportPostId) return;
    if (typeof reportDlg.showModal === 'function') reportDlg.showModal();
    else reportDlg.setAttribute('open', 'open');
    qs(section, '[data-promo-report-reason]')?.focus();
  });
  qs(section, '[data-promo-report-cancel]')?.addEventListener('click', closeReport);
  qs(section, '[data-promo-report-submit]')?.addEventListener('click', async () => {
    const reason = qs(section, '[data-promo-report-reason]')?.value || 'other';
    const body = qs(section, '[data-promo-report-body]')?.value || '';
    const j = await postJson(feedUrl, {
      action: 'report',
      post_id: reportPostId,
      reason,
      body,
      csrf,
    });
    if (!j.success) {
      alert(j.error || 'Could not report');
      return;
    }
    closeReport();
    alert(j.message || 'Thanks — report received.');
  });
  if (!track || !viewport) return;
  const cards = qsa(track, '[data-promo-card]');
  if (cards.length < 2) return;

  let idx = 0;
  const scrollToIdx = () => {
    const c = cards[idx];
    if (c) c.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
  };
  qs(section, '.qb-promo-feed__arrow--prev')?.addEventListener('click', () => {
    idx = (idx - 1 + cards.length) % cards.length;
    scrollToIdx();
  });
  qs(section, '.qb-promo-feed__arrow--next')?.addEventListener('click', () => {
    idx = (idx + 1) % cards.length;
    scrollToIdx();
  });

  // Autoscroll intentionally disabled: users navigate with arrows/swipe only.
}

document.querySelectorAll('[data-promo-feed]').forEach((el) => initPromoFeed(el));
