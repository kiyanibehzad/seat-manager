export function mountTheme(root = document.documentElement) {
  let choice = 'system';
  try { const saved = localStorage.getItem('acc-appearance'); if (['system','light','dark'].includes(saved)) choice = saved; } catch {}
  function apply(value) {
    choice = value;
    root.dataset.theme = choice;
    for (const radio of document.querySelectorAll('[name="appearance"]')) radio.checked = radio.value === choice;
  }
  document.addEventListener('change', event => {
    if (!event.target.matches('[name="appearance"]') || !event.target.checked) return;
    apply(event.target.value);
    try { localStorage.setItem('acc-appearance', choice); } catch {}
  });
  apply(choice);
  return { sync: () => apply(choice) };
}
