document.addEventListener('DOMContentLoaded', () => {
  const btn = document.querySelector('[data-cw-nav-toggle]');
  const panel = document.querySelector('[data-cw-nav-panel]');
  const overlay = document.querySelector('[data-cw-nav-overlay]');
  const closeBtn = document.querySelector('[data-cw-nav-close]');
  if (!btn || !panel) return;

  let closingTimer = null;

  const setOpen = (open) => {
    if (closingTimer) {
      clearTimeout(closingTimer);
      closingTimer = null;
    }

    btn.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('cw-nav-open', open);

    if (overlay) {
      overlay.hidden = false;
      overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
      // next frame so opacity can transition
      requestAnimationFrame(() => {
        overlay.classList.toggle('is-open', open);
      });
      if (!open) {
        closingTimer = setTimeout(() => {
          if (!overlay.classList.contains('is-open')) {
            overlay.hidden = true;
          }
        }, 320);
      }
    }

    // Never use [hidden] while animating — display:none kills transform transitions.
    panel.hidden = false;
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');

    if (open) {
      // Paint off-canvas first, then slide in on the next frame.
      panel.classList.remove('is-open');
      void panel.offsetWidth;
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          panel.classList.add('is-open');
        });
      });
    } else {
      panel.classList.remove('is-open');
    }
  };

  btn.addEventListener('click', () => {
    const willOpen = !panel.classList.contains('is-open');
    setOpen(willOpen);
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', () => setOpen(false));
  }

  if (overlay) {
    overlay.addEventListener('click', () => setOpen(false));
  }

  panel.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => setOpen(false));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && panel.classList.contains('is-open')) {
      setOpen(false);
    }
  });
});
