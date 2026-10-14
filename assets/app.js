(() => {
  'use strict';
  const themeToggle = document.getElementById('theme-toggle');
  const updateThemeLabel = () => {
    const dark = document.documentElement.dataset.theme === 'dark';
    themeToggle?.setAttribute('aria-pressed', String(dark));
    themeToggle?.setAttribute('aria-label', dark ? 'Activer le thème clair' : 'Activer le thème sombre');
    if (themeToggle) {
      themeToggle.querySelector('span').textContent = dark ? 'Thème clair' : 'Thème sombre';
      themeToggle.dataset.mobileLabel = dark ? 'Clair' : 'Sombre';
    }
  };
  updateThemeLabel();
  themeToggle?.addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem('ominis.theme', theme); } catch (_) {}
    updateThemeLabel();
  });
  const seriesDrawer = document.getElementById('series-drawer');
  const seriesToggle = document.getElementById('open-series');
  seriesToggle?.addEventListener('click', () => {
    seriesDrawer.showModal();
    seriesToggle.setAttribute('aria-expanded', 'true');
  });
  document.getElementById('close-series')?.addEventListener('click', () => seriesDrawer.close());
  seriesDrawer?.addEventListener('click', event => {
    const rect = seriesDrawer.getBoundingClientRect();
    if (event.target === seriesDrawer && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) seriesDrawer.close();
  });
  seriesDrawer?.addEventListener('close', () => {
    seriesToggle.setAttribute('aria-expanded', 'false');
    seriesToggle.focus();
  });
  const initial = document.querySelector('.initial-loader');
  const navKey = 'ominis.internal-navigation';
  let internal = false;
  try { internal = sessionStorage.getItem(navKey) === '1'; sessionStorage.removeItem(navKey); } catch (_) {}
  // A fresh arrival has a one-second splash; ordinary page changes use the native transition.
  if (internal) initial?.remove();
  else window.setTimeout(() => initial?.remove(), 1200);
  const resetNavigation = () => {
    document.body.classList.remove('is-navigating');
    try { sessionStorage.removeItem(navKey); } catch (_) {}
    document.querySelectorAll('[data-submitting]').forEach(form => { delete form.dataset.submitting; form.removeAttribute('aria-busy'); });
  };
  window.addEventListener('pageshow', resetNavigation);
  let navigateTimer;
  const navigation = () => {
    try { sessionStorage.setItem(navKey, '1'); } catch (_) {}
    document.body.classList.add('is-navigating');
    clearTimeout(navigateTimer);
    navigateTimer = setTimeout(resetNavigation, 20000);
  };
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
    const target = new URL(link.href, location.href);
    if (target.origin !== location.origin || (target.pathname === location.pathname && target.search === location.search && target.hash)) return;
    navigation();
  });
  document.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => {
    if (form.dataset.submitting) { event.preventDefault(); return; }
    if (!form.checkValidity()) return;
    form.dataset.submitting = '1'; form.setAttribute('aria-busy', 'true'); navigation();
  }));
  const courseSearch = document.getElementById('course-search');
  const normalizeSearch = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr').trim();
  const courseRows = [...document.querySelectorAll('#course-table tbody tr:has(.item-checkbox)')];
  let coursePage = 1;
  const renderCourses = () => {
    if (!courseSearch) return;
    const terms = normalizeSearch(courseSearch.value).split(/\s+/).filter(Boolean);
    const matching = courseRows.filter(row => {
      const input = row.querySelector('.item-checkbox');
      const text = normalizeSearch(row.querySelector('label').textContent + ' ' + input.value + ' ' + (row.dataset.college || ''));
      return terms.every(term => text.includes(term));
    });
    const pages = Math.max(1, Math.ceil(matching.length / 7));
    coursePage = Math.min(coursePage, pages);
    courseRows.forEach(row => { row.hidden = true; });
    matching.slice((coursePage - 1) * 7, coursePage * 7).forEach(row => { row.hidden = false; });
    document.querySelectorAll('#course-table .college-heading').forEach(heading => {
      let row = heading.nextElementSibling, visible = false;
      while (row && !row.classList.contains('college-heading')) {
        if (row.querySelector('.item-checkbox') && !row.hidden) visible = true;
        row = row.nextElementSibling;
      }
      heading.hidden = !visible;
    });
    document.getElementById('course-search-empty').hidden = matching.length > 0 || terms.length === 0;
    document.getElementById('course-pagination').hidden = pages <= 1;
    document.getElementById('courses-page').textContent = coursePage + ' / ' + pages;
    document.getElementById('courses-previous').disabled = coursePage === 1;
    document.getElementById('courses-next').disabled = coursePage === pages;
    document.querySelector('.library-panel .program-table-wrap').scrollTop = 0;
  };
  courseSearch?.addEventListener('input', () => { coursePage = 1; renderCourses(); });
  document.getElementById('courses-previous')?.addEventListener('click', () => { coursePage--; renderCourses(); });
  document.getElementById('courses-next')?.addEventListener('click', () => { coursePage++; renderCourses(); });
  renderCourses();
  const rhythmPanel = document.querySelector('.settings-panel');
  const libraryPanel = document.querySelector('.library-panel');
  if (rhythmPanel && libraryPanel) {
    const syncLibraryHeight = () => libraryPanel.style.setProperty('--rhythm-height', rhythmPanel.getBoundingClientRect().height + 'px');
    new ResizeObserver(syncLibraryHeight).observe(rhythmPanel);
    syncLibraryHeight();
  }
  courseSearch?.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
  const all = document.getElementById('all-items');
  const items = [...document.querySelectorAll('.item-checkbox')];
  all?.addEventListener('change', () => { items.forEach(item => item.checked = all.checked); all.indeterminate = false; });
  items.forEach(item => item.addEventListener('change', () => {
    const selected = items.filter(input => input.checked).length;
    all.checked = selected === items.length; all.indeterminate = selected > 0 && selected < items.length;
  }));
  const range = document.getElementById('question-range');
  const countInput = document.getElementById('question-count');
  const updateCountLimit = () => {
    if (!range || !countInput) return;
    const total = items.filter(item => item.checked).reduce((sum, item) => sum + Number(item.dataset.total), 0);
    range.max = countInput.max = String(Math.max(1, total));
    const max = Math.max(1, total);
    const rawStep = max / 4;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const step = Math.max(1, [1, 2, 5, 10].find(value => value * magnitude >= rawStep) * magnitude);
    const ticks = [1];
    for (let value = step; value < max; value += step) if (value > 1 && (max - value) / Math.max(1, max - 1) > .1) ticks.push(value);
    if (max > 1) ticks.push(max);
    const tickLabels = document.getElementById('question-ticks');
    tickLabels.replaceChildren();
    ticks.forEach(value => {
      const label = document.createElement('span');
      label.textContent = value;
      label.style.left = ((value - 1) / Math.max(1, max - 1) * 100) + '%';
      tickLabels.appendChild(label);
    });
    tickLabels.hidden = total === 0;
    range.disabled = countInput.disabled = total === 0;
    countInput.value = String(Math.min(Math.max(1, Number(countInput.value) || 1), Math.max(1, total)));
    range.value = countInput.value;
    document.getElementById('question-limit').textContent = total + ' disponibles';
  };
  range?.addEventListener('input', () => { countInput.value = range.value; });
  countInput?.addEventListener('input', () => { if (countInput.validity.valid) range.value = countInput.value; });
  countInput?.addEventListener('change', updateCountLimit);
  all?.addEventListener('change', updateCountLimit);
  items.forEach(item => item.addEventListener('change', updateCountLimit));
  updateCountLimit();
  const dialog = document.getElementById('report-dialog');
  const select = document.getElementById('report-question');
  const reportMessage = document.getElementById('report-message');
  let returnFocus = null;
  const openReport = (source, question) => {
    if (question) select.value = question;
    returnFocus = source;
    if (!dialog.open) dialog.showModal();
    if (select.value) reportMessage.focus(); else select.focus();
  };
  document.getElementById('open-report')?.addEventListener('click', event => openReport(event.currentTarget));
  document.querySelectorAll('.report-context').forEach(button => button.addEventListener('click', () => openReport(button, button.dataset.question)));
  document.getElementById('close-report')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', event => {
    const rect = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
  });
  dialog?.addEventListener('close', () => returnFocus?.focus());

  const perfectAnswer = document.querySelector('[data-celebration]');
  if (perfectAnswer && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const celebrationKey = 'ominis.celebrated.' + perfectAnswer.dataset.celebration;
    let alreadyCelebrated = false;
    try { alreadyCelebrated = sessionStorage.getItem(celebrationKey) === '1'; } catch (_) {}
    if (!alreadyCelebrated) {
      try { sessionStorage.setItem(celebrationKey, '1'); } catch (_) {}
      window.setTimeout(() => {
        const canvas = document.createElement('canvas');
        canvas.className = 'confetti-canvas';
        canvas.setAttribute('aria-hidden', 'true');
        document.body.appendChild(canvas);
        const ctx = canvas.getContext('2d');
        if (!ctx) { canvas.remove(); return; }
        const width = window.innerWidth, height = window.innerHeight;
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = width * ratio; canvas.height = height * ratio;
        ctx.scale(ratio, ratio);
        const colors = ['#8dd4bb', '#f4ce68', '#f28e9b', '#8abded', '#c5a1ef', '#ffffff'];
        const particles = Array.from({ length: 110 }, () => ({
          x: width / 2, y: height * .45,
          vx: (Math.random() - .5) * Math.min(width * .045, 19),
          vy: -5 - Math.random() * 14,
          size: 4 + Math.random() * 5,
          angle: Math.random() * Math.PI,
          spin: (Math.random() - .5) * .3,
          color: colors[Math.floor(Math.random() * colors.length)]
        }));
        let start, previous;
        const frame = now => {
          start ??= now; previous ??= now;
          const elapsed = now - start, step = Math.min((now - previous) / 16.67, 2);
          previous = now;
          ctx.clearRect(0, 0, width, height);
          ctx.globalAlpha = Math.max(0, Math.min(1, (2600 - elapsed) / 600));
          particles.forEach(p => {
            p.x += p.vx * step; p.y += p.vy * step;
            p.vy += .22 * step; p.vx *= Math.pow(.99, step); p.angle += p.spin * step;
            ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.angle);
            ctx.fillStyle = p.color; ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * .55);
            ctx.restore();
          });
          if (elapsed < 2600 && !document.hidden) requestAnimationFrame(frame);
          else canvas.remove();
        };
        requestAnimationFrame(frame);
      }, document.querySelector('.initial-loader') ? 1250 : 0);
    }
  }

  // A saved draft must never reopen the dialog during question navigation.
  // It remains available when the student explicitly clicks "Signaler".
})();
