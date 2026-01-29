(function () {
  // Toggle sidebar (burger)
  const btn = document.getElementById('odToggleSidebar');
  if (btn) {
    btn.addEventListener('click', () => document.body.classList.toggle('od-sidebar-collapsed'));
  }

  // Custom dropdown (tanpa hash URL)
  const parents = document.querySelectorAll('[data-od-toggle="collapse"]');

  function closePanelExcept(exceptId) {
    parents.forEach(p => {
      const targetSel = p.getAttribute('data-od-target');
      const el = targetSel ? document.querySelector(targetSel) : null;
      if (!el) return;

      if (targetSel !== exceptId) {
        el.classList.remove('is-open');
        p.setAttribute('aria-expanded', 'false');
      }
    });
  }

  parents.forEach(parent => {
    parent.addEventListener('click', function (e) {
      e.preventDefault();

      const targetSel = parent.getAttribute('data-od-target');
      const target = targetSel ? document.querySelector(targetSel) : null;
      if (!target) return;

      const isOpen = target.classList.contains('is-open');

      // OneSearch feel: kalau buka satu, tutup yang lain
      closePanelExcept(targetSel);

      if (isOpen) {
        target.classList.remove('is-open');
        parent.setAttribute('aria-expanded', 'false');
      } else {
        target.classList.add('is-open');
        parent.setAttribute('aria-expanded', 'true');
      }
    });
  });
})();
