/* Help Center page scripts: live search suggestions, live FAQ filter, tutorial completion marks, editor helpers. */
document.addEventListener('DOMContentLoaded', () => {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---- live suggestions under every search box (reads only published help content) ---- */
  document.querySelectorAll('.hlp-search').forEach(form => {
    const input = form.querySelector('input[name=q]'), box = form.querySelector('.hlp-suggest');
    if (!input || !box) return;
    let timer = null, seq = 0, sel = -1;
    const hide = () => { box.hidden = true; box.innerHTML = ''; sel = -1; };
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim(); if (q.length < 2) return hide();
      timer = setTimeout(async () => {
        const my = ++seq;
        try {
          const r = await fetch('help_api.php?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
          if (!r.ok) return hide(); const d = await r.json(); if (my !== seq) return;
          box.innerHTML = d.items.length ? d.items.map(i => `<a href="${esc(i.url)}"><i class="bi bi-${i.type === 'tutorial' ? 'play-circle' : i.type === 'faq' ? 'question-circle' : 'file-earmark-text'}"></i><span>${esc(i.title)}</span><small>${esc(i.type)}</small></a>`).join('') : '<div class="p-2 small text-secondary">No quick matches. Press Search for full results.</div>';
          box.hidden = false; sel = -1;
        } catch (e) { hide(); }
      }, 220);
    });
    input.addEventListener('keydown', e => {
      const links = [...box.querySelectorAll('a')];
      if (e.key === 'Escape') hide();
      else if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && links.length) { e.preventDefault(); sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length; links.forEach((a, i) => a.classList.toggle('sel', i === sel)); }
      else if (e.key === 'Enter' && sel >= 0 && links[sel]) { e.preventDefault(); location.href = links[sel].href; }
    });
    document.addEventListener('click', e => { if (!form.contains(e.target)) hide(); });
  });

  /* ---- FAQ live filter ---- */
  const ff = document.getElementById('faqFilter');
  if (ff) {
    const items = [...document.querySelectorAll('[data-faq]')], none = document.getElementById('faqEmpty');
    const run = () => { const q = ff.value.trim().toLowerCase(); let n = 0; items.forEach(it => { const hit = !q || it.textContent.toLowerCase().includes(q); it.hidden = !hit; if (hit) n++; }); if (none) none.classList.toggle('d-none', n > 0); };
    ff.addEventListener('input', run); run();
  }

  /* ---- tutorial completion marks (stored only in this browser) ---- */
  let done = []; try { done = JSON.parse(localStorage.getItem('hlp_done') || '[]'); } catch (e) { }
  document.querySelectorAll('[data-tut]').forEach(card => {
    if (done.includes(card.dataset.tut)) { card.classList.add('done'); const b = card.querySelector('[data-donebadge]'); if (b) b.hidden = false; }
  });
});
