(function () {
  var r = document.getElementById("qb-cursor-assist");
  if (!r) return;
  var api = r.getAttribute("data-api");
  var fab = document.getElementById("qb-cursor-fab");
  var panel = document.getElementById("qb-cursor-panel");
  var closeBtn = document.getElementById("qb-cursor-close");
  var form = document.getElementById("qb-cursor-form");
  var input = document.getElementById("qb-cursor-input");
  var msgs = document.getElementById("qb-cursor-messages");
  var busy = false;
  function msg(t, who) {
    var p = document.createElement("p");
    p.className = "qb-cursor-assist__msg qb-cursor-assist__msg--" + who;
    p.textContent = t;
    msgs.appendChild(p);
    msgs.scrollTop = msgs.scrollHeight;
    return p;
  }
  fab.onclick = function () { panel.hidden = !panel.hidden; if (!panel.hidden) input.focus(); };
  closeBtn.onclick = function () { panel.hidden = true; };
  form.onsubmit = function (e) {
    e.preventDefault();
    if (busy || !input.value.trim()) return;
    busy = true;
    var q = input.value.trim();
    input.value = "";
    msg(q, "user");
    var wait = msg("Thinking…", "bot");
    fetch(api, { method: "POST", headers: { "Content-Type": "application/json" }, credentials: "same-origin", body: JSON.stringify({ message: q, page: location.pathname }) })
      .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
      .then(function (x) { wait.remove(); msg(x.ok && x.j.reply ? x.j.reply : x.j.error || "Unavailable", "bot"); })
      .catch(function () { wait.remove(); msg("Network error", "bot"); })
      .finally(function () { busy = false; });
  };
})();
