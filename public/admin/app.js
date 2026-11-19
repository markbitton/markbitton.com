(function () {
  // Whole table row is clickable on the loan list.
  document.querySelectorAll('tr[data-href]').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a,button,form')) return;
      location.href = tr.getAttribute('data-href');
    });
  });

  // Confirm before deleting an entry.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Term (months) fills in the maturity date.
  document.querySelectorAll('form').forEach(function (f) {
    var start = f.querySelector('[data-start]'), term = f.querySelector('[data-term]'), mat = f.querySelector('[data-maturity]');
    if (!start || !term || !mat) return;
    function fill() {
      var m = parseInt(term.value, 10);
      if (!start.value || !(m > 0)) return;
      var p = start.value.split('-').map(Number);
      var d = new Date(Date.UTC(p[0], p[1] - 1 + m, 1));
      var last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate();
      d.setUTCDate(Math.min(p[2], last));
      mat.value = d.toISOString().slice(0, 10);
    }
    term.addEventListener('input', fill);
    start.addEventListener('change', fill);
  });

  // Payoff: fill the amount with the payoff as of the chosen date.
  var ef = document.getElementById('entryForm');
  if (ef) {
    var date = ef.querySelector('[name=entry_date]'), amt = ef.querySelector('[name=amount]'), hint = ef.querySelector('[data-payoff-hint]');
    var auto = false;
    function type() { var r = ef.querySelector('[name=type]:checked'); return r ? r.value : ''; }
    function update() {
      hint.textContent = '';
      if (type() !== 'payoff') { if (auto) { amt.value = ''; auto = false; } return; }
      if (!date.value) return;
      fetch(ef.getAttribute('data-quote-url') + encodeURIComponent(date.value), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (q) {
          if (q.error) return;
          if (amt.value === '' || auto) { amt.value = (q.payoff / 100).toFixed(2); auto = true; }
          hint.textContent = 'Full payoff on that date: ' + q.payoff_fmt + ' (includes ' + q.interest_fmt + ' interest).';
        }).catch(function () {});
    }
    amt.addEventListener('input', function () { auto = false; });
    ef.querySelectorAll('[name=type]').forEach(function (r) { r.addEventListener('change', update); });
    date.addEventListener('change', update);
    update();
  }
})();
(function () {
  var btn = document.querySelector('[data-copy-btn]'), inp = document.querySelector('[data-copy]');
  if (!btn || !inp) return;
  btn.addEventListener('click', function () {
    inp.select();
    (navigator.clipboard ? navigator.clipboard.writeText(inp.value) : Promise.reject()).catch(function () { document.execCommand('copy'); });
    btn.textContent = 'Copied';
    setTimeout(function () { btn.textContent = 'Copy link'; }, 1500);
  });
})();
(function () {
  // Print buttons
  document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
  // Confirm on specific submit buttons
  document.querySelectorAll('[data-confirm-click]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm-click'))) e.preventDefault(); });
  });
  // Signature pad
  document.querySelectorAll('[data-sigform]').forEach(function (form) {
    var c = form.querySelector('[data-sigpad]'), out = form.querySelector('[data-sigdata]'), clear = form.querySelector('[data-sigclear]');
    if (!c) return;
    var ctx = c.getContext('2d'), drawing = false, drawn = false, last = null;
    ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
    function pos(e) {
      var r = c.getBoundingClientRect();
      return { x: (e.clientX - r.left) * (c.width / r.width), y: (e.clientY - r.top) * (c.height / r.height) };
    }
    c.addEventListener('pointerdown', function (e) { drawing = true; last = pos(e); c.setPointerCapture(e.pointerId); e.preventDefault(); });
    c.addEventListener('pointermove', function (e) {
      if (!drawing) return;
      var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; drawn = true; e.preventDefault();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { c.addEventListener(t, function () { drawing = false; }); });
    clear.addEventListener('click', function () { ctx.clearRect(0, 0, c.width, c.height); drawn = false; out.value = ''; });
    form.addEventListener('submit', function () { out.value = drawn ? c.toDataURL('image/png') : ''; });
  });
})();
