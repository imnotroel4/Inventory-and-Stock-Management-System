document.addEventListener('DOMContentLoaded', () => {
  const nav = document.getElementById('lpNav');
  const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 12);
  onScroll(); window.addEventListener('scroll', onScroll, { passive: true });

  // Close the mobile menu after choosing a link
  const menu = document.getElementById('lpMenu');
  menu.querySelectorAll('.nav-link').forEach(a => a.addEventListener('click', () => {
    if (menu.classList.contains('show')) bootstrap.Collapse.getOrCreateInstance(menu).hide();
  }));

  // Fade sections in as they scroll into view
  const items = document.querySelectorAll('.reveal');
  if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) {
    items.forEach(el => el.classList.add('in')); return;
  }
  const io = new IntersectionObserver(entries => entries.forEach(en => {
    if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
  }), { threshold: .15 });
  items.forEach(el => io.observe(el));
});
