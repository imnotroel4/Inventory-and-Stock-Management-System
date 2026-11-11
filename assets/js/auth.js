document.addEventListener('DOMContentLoaded', () => {
  // Show / hide password
  document.querySelectorAll('[data-toggle-password]').forEach(btn => btn.addEventListener('click', () => {
    const input = document.querySelector(btn.dataset.togglePassword);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.querySelector('i').className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
  }));

  const pw = document.getElementById('password'), confirm = document.getElementById('confirm');
  const bar = document.getElementById('strengthBar'), txt = document.getElementById('strengthText');

  // Password strength hint (registration page only; the server enforces the real rules)
  if (pw && bar) pw.addEventListener('input', () => {
    const v = pw.value;
    let score = 0;
    if (v.length >= 8) score++;
    if (/[A-Za-z]/.test(v) && /\d/.test(v)) score++;
    if (v.length >= 12) score++;
    if (/[^A-Za-z0-9]/.test(v) && v.length >= 8) score++;
    bar.style.width = (v ? Math.max(score, 1) * 25 : 0) + '%';
    bar.className = v ? 's' + Math.max(score, 1) : '';
    txt.textContent = !v ? 'At least 8 characters, with a letter and a number.' : ['', 'Weak', 'Fair', 'Good', 'Strong'][Math.max(score, 1)] + ' password';
  });

  // Confirm password must match
  const sync = () => { if (confirm) confirm.setCustomValidity(confirm.value !== pw.value ? 'mismatch' : ''); };
  if (pw && confirm) { pw.addEventListener('input', sync); confirm.addEventListener('input', sync); }

  // Bootstrap validation messages on submit
  document.querySelectorAll('.needs-validation').forEach(form => form.addEventListener('submit', ev => {
    sync();
    if (!form.checkValidity()) { ev.preventDefault(); ev.stopPropagation(); }
    form.classList.add('was-validated');
  }));
});
