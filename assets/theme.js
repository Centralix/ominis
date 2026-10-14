(() => {
  let theme;
  try { theme = localStorage.getItem('ominis.theme'); } catch (_) {}
  document.documentElement.dataset.theme = theme === 'dark' || (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
})();
