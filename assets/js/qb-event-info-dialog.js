/**
 * Double-click any [data-qb-event] (JSON) to open a centered native <dialog>.
 */
(function () {
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function row(label, val) {
    if (val == null || String(val).trim() === '') return '';
    return (
      '<div class="qb-event-info-dialog__row">' +
      '<span class="qb-event-info-dialog__k">' +
      esc(label) +
      '</span>' +
      '<span class="qb-event-info-dialog__v">' +
      esc(val) +
      '</span></div>'
    );
  }

  function showFromEl(el) {
    var raw = el.getAttribute('data-qb-event');
    if (!raw) return;
    var d;
    try {
      d = JSON.parse(raw);
    } catch (e) {
      return;
    }
    var dlg = document.getElementById('qb-event-info-dialog');
    var titleEl = document.getElementById('qb-event-info-dialog-title');
    var bodyEl = document.getElementById('qb-event-info-dialog-body');
    if (!dlg || !titleEl || !bodyEl) return;

    titleEl.textContent = d.name || 'Event';

    var html = '';
    html += row('Status', d.status);
    html += row('Venue', d.venue);
    html += row('City', d.city);
    html += row('Starts', d.start);
    html += row('Ends', d.end);
    html += row('Organizers', d.organizers);
    html += row('Notes', d.notes);
    html += row('Products', d.products);
    html += row('Attendance', d.attendance);
    if ((d.status || '').toLowerCase() === 'ended') {
      if (String(d.leaderboard || '').trim() !== '') {
        html += row('Leaderboard', d.leaderboard);
      }
      html += row('Total products sold', d.products_sold);
      html += row('Total completed orders', d.orders_completed);
      html += row('Total completed payments', d.payments_completed);
    }
    bodyEl.innerHTML = html || '<p class="text-muted text-sm">No extra details.</p>';

    if (typeof dlg.showModal === 'function') {
      dlg.showModal();
    }
  }

  document.addEventListener('dblclick', function (e) {
    var el = e.target.closest('[data-qb-event]');
    if (el) {
      e.preventDefault();
      showFromEl(el);
    }
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-qb-event-dialog-close]')) {
      var dlg = document.getElementById('qb-event-info-dialog');
      if (dlg && typeof dlg.close === 'function') dlg.close();
    }
  });
})();
