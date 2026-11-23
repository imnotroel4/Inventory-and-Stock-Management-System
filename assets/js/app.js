document.addEventListener('DOMContentLoaded', () => {
  const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Stagger index for entrance animations (the CSS reads --i)
  document.querySelectorAll('.page .panel, .page > .alert').forEach((el, i) => el.style.setProperty('--i', i));
  document.querySelectorAll('.table tbody').forEach(tb => [...tb.rows].forEach((tr, i) => tr.style.setProperty('--i', i)));
  document.querySelectorAll('.list-group-item').forEach((li, i) => li.style.setProperty('--i', i));

  // Count-up for dashboard figures such as 1,234 or ₱1,234.50 (keeps prefix, commas and decimals)
  if (!calm) document.querySelectorAll('.stat .v').forEach(el => {
    const m = el.textContent.trim().match(/^(\D*?)(\d[\d,]*(?:\.(\d+))?)(\D*)$/);
    if (!m) return;
    const end = parseFloat(m[2].replace(/,/g, '')), dec = (m[3] || '').length, comma = m[2].includes(',');
    if (!end) return;
    const fmt = n => m[1] + n.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec, useGrouping: comma }) + m[4];
    const t0 = performance.now(), dur = 900;
    const tick = now => {
      const p = Math.min(1, (now - t0) / dur);
      el.textContent = fmt(end * (1 - Math.pow(1 - p, 4)));
      if (p < 1) requestAnimationFrame(tick);
    };
    el.textContent = fmt(0);
    requestAnimationFrame(tick);
  });

  const sb = document.getElementById('sidebar');
  document.getElementById('menuBtn')?.addEventListener('click', () => sb.classList.toggle('open'));

  document.querySelectorAll('form[data-confirm]').forEach(f =>
    f.addEventListener('submit', ev => { if (!confirm(f.dataset.confirm)) ev.preventDefault(); }));

  // Live table search: <input data-filter="#tableId">
  document.querySelectorAll('[data-filter]').forEach(inp => inp.addEventListener('input', () => {
    const q = inp.value.toLowerCase();
    document.querySelectorAll(inp.dataset.filter + ' tbody tr').forEach(tr => tr.hidden = !tr.textContent.toLowerCase().includes(q));
  }));

  // Add/edit modals: button[data-edit-modal="#modal"][data-row='{json}'] fills form fields by name
  document.querySelectorAll('[data-edit-modal]').forEach(btn => btn.addEventListener('click', () => {
    const m = document.querySelector(btn.dataset.editModal), form = m.querySelector('form');
    form.reset();
    const row = btn.dataset.row ? JSON.parse(btn.dataset.row) : {};
    Object.entries(row).forEach(([k, v]) => {
      const el = form.elements[k];
      if (el && el.type !== 'file' && el.type !== 'checkbox') el.value = v ?? '';
    });
    form.elements['id'].value = row.id || '';
    if (form.elements['must_change']) form.elements['must_change'].checked = row.must_change == 1;
    if (form.elements['track_expiry'] && row.id) form.elements['track_expiry'].checked = row.track_expiry == 1;
    m.querySelector('.modal-title').textContent = row.id ? btn.dataset.titleEdit : btn.dataset.titleNew;
    form.querySelectorAll('[data-new-only]').forEach(el => el.classList.toggle('d-none', !!row.id));
    const img = form.querySelector('[data-img]');
    if (img) { const f = row.image || row.avatar; img.src = f ? 'uploads/' + f : ''; img.classList.toggle('d-none', !f); }
    new bootstrap.Modal(m).show();
  }));

  document.querySelectorAll('input[type=file][data-preview]').forEach(i => i.addEventListener('change', () => {
    const img = document.querySelector(i.dataset.preview);
    if (i.files[0]) { img.src = URL.createObjectURL(i.files[0]); img.classList.remove('d-none'); }
  }));

  // Stock forms: show available quantity and default price for the chosen product
  const sel = document.getElementById('productSelect');
  if (sel) {
    const upd = () => {
      const o = sel.selectedOptions[0]; if (!o || !o.value) return;
      const av = document.getElementById('available'); if (av) av.textContent = o.dataset.qty + ' on hand';
      const pr = document.getElementById('unitPrice'); if (pr && !pr.dataset.touched) pr.value = o.dataset.price;
      const qty = document.getElementById('qty'); if (qty && qty.dataset.max === '1') qty.max = o.dataset.qty;
    };
    sel.addEventListener('change', upd);
    document.getElementById('unitPrice')?.addEventListener('input', e => e.target.dataset.touched = 1);
    upd();
  }

  // Dashboard product table filter: dropdown items carry data-status (all | in | low | out)
  document.querySelectorAll('[data-status-filter] [data-status]').forEach(a => a.addEventListener('click', ev => {
    ev.preventDefault();
    const s = a.dataset.status;
    document.querySelectorAll('#invTable tbody tr[data-status]').forEach(tr => tr.hidden = s !== 'all' && tr.dataset.status !== s);
    const lbl = document.getElementById('filterLabel'); if (lbl) lbl.textContent = s === 'all' ? 'Filter' : a.textContent;
  }));
});
