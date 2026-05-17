/**
 * Promo card DOM helpers (stats updates; optional future client render).
 * @param {HTMLElement} root
 * @param {{ view_count?: number, like_count?: number }} post
 */
export function applyPromoCardStats(root, post) {
  if (!root) return;
  const v = root.querySelector('[data-stat-views]');
  const l = root.querySelector('[data-stat-likes]');
  if (v && typeof post.view_count === 'number') v.textContent = String(post.view_count);
  if (l && typeof post.like_count === 'number') l.textContent = String(post.like_count);
}
