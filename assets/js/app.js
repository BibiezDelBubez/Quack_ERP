/* =============================================================================
   APP.JS — comportamento interattivo dell'ERP Quack
============================================================================= */

(function () {
  'use strict';

  /* ── Costanti ─────────────────────────────────────────────────────────── */
  const LS_SIDEBAR_COLLAPSED = 'qk_sidebar_collapsed';
  const LS_THEME             = 'qk_theme';
  const BREAKPOINT_MOBILE    = 992; // px

  /* ── DOM ready ────────────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {
    cleanModalState();
    initSidebar();
    initScrollTitle();
    /* ── Rimuovi blocco transizioni dopo il primo frame renderizzato ─────── */
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        document.documentElement.classList.remove('no-sb-transition');
      });
    });

    initTheme();
    initThemeSwitcher();
    initAlertAutoDismiss();
    initDropdownActionLinks();
    initFormValidation();
  });

  /* Scatta anche su navigazione back/forward dalla cache del browser */
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) { cleanModalState(); }
  });

  /* ────────────────────────────────────────────────────────────────────────
     MODAL STATE CLEANUP
     Rimuove backdrop orfani e la classe modal-open dal body quando la pagina
     viene caricata dopo un redirect PHP da dentro un modale (form POST in modal).
     Senza questo, il backdrop rimane nel DOM e blocca tutti i click successivi.
  ──────────────────────────────────────────────────────────────────────── */
  function cleanModalState() {
    /* Rimuovi tutti i .modal-backdrop residui */
    document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
    /* Ripristina scroll e classe modal-open sul body */
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    /* Chiudi eventuali modali rimasti aperti senza backdrop (edge case) */
    document.querySelectorAll('.modal.show').forEach(function (modal) {
      modal.classList.remove('show');
      modal.style.display = '';
      modal.setAttribute('aria-hidden', 'true');
      modal.removeAttribute('aria-modal');
    });
  }

  /* ────────────────────────────────────────────────────────────────────────
     SIDEBAR
  ──────────────────────────────────────────────────────────────────────── */
  function initSidebar() {
    const body      = document.documentElement; // classe su <html> per anti-flicker
    const sidebar   = document.getElementById('sidebar');
    const toggle    = document.getElementById('sidebarToggle');
    const backdrop  = document.getElementById('sidebarBackdrop');

    if (!sidebar || !toggle) return;

    const isMobile = () => window.innerWidth < BREAKPOINT_MOBILE;

    // Restore collapsed state on desktop
    if (!isMobile() && localStorage.getItem(LS_SIDEBAR_COLLAPSED) === '1') {
      body.classList.add('sidebar-collapsed');
    }

    // Toggle button
    toggle.addEventListener('click', function () {
      if (isMobile()) {
        toggleMobileSidebar();
      } else {
        toggleDesktopSidebar();
      }
    });

    // Backdrop closes sidebar on mobile
    if (backdrop) {
      backdrop.addEventListener('click', function () {
        closeMobileSidebar();
      });
    }

    // Close on resize to desktop
    window.addEventListener('resize', function () {
      if (!isMobile()) {
        closeMobileSidebar();
      }
    });

    // Submenu collapses (Bootstrap collapse is used, but we track aria)
    document.querySelectorAll('.qk-nav-sub').forEach(function (sub) {
      sub.addEventListener('show.bs.collapse', function () {
        const btn = document.querySelector('[data-bs-target="#' + sub.id + '"]');
        if (btn) btn.setAttribute('aria-expanded', 'true');
      });
      sub.addEventListener('hide.bs.collapse', function () {
        const btn = document.querySelector('[data-bs-target="#' + sub.id + '"]');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      });
    });

    // Se la sidebar è collassata e clicco su un trigger di sottomenu, navigo al primo link del sottomenu
    document.querySelectorAll('.qk-nav-item > button[data-bs-toggle="collapse"]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        if (body.classList.contains('sidebar-collapsed')) {
          const navItem = btn.closest('.qk-nav-item');
          const firstLink = navItem ? navItem.querySelector('.qk-nav-sub a') : null;
          if (firstLink) {
            e.preventDefault();
            e.stopPropagation();
            window.location.href = firstLink.href;
          }
        }
      });
    });

    function toggleDesktopSidebar() {
      body.classList.toggle('sidebar-collapsed');
      localStorage.setItem(LS_SIDEBAR_COLLAPSED,
        body.classList.contains('sidebar-collapsed') ? '1' : '0');
    }

    function toggleMobileSidebar() {
      if (sidebar.classList.contains('is-open')) {
        closeMobileSidebar();
      } else {
        sidebar.classList.add('is-open');
        if (backdrop) backdrop.classList.add('active');
        document.body.style.overflow = 'hidden'; // scroll lock su body (non html)
      }
    }

    function closeMobileSidebar() {
      sidebar.classList.remove('is-open');
      if (backdrop) backdrop.classList.remove('active');
      document.body.style.overflow = '';
    }
  }

  /* ────────────────────────────────────────────────────────────────────────
     SCROLL-AWARE TOPBAR TITLE
     Mostra il titolo nella topbar solo quando il page header h1 è uscito
     dal viewport (come Gmail con il titolo dell'email).
  ──────────────────────────────────────────────────────────────────────── */
  function initScrollTitle() {
    const html   = document.documentElement;
    const header = document.querySelector('.qk-page-header');
    if (!header) return; // pagine senza page header (es. login): niente da fare

    let ticking = false;

    function update() {
      const bottom = header.getBoundingClientRect().bottom;
      // Il titolo appare quando il fondo del page-header supera la topbar
      html.classList.toggle('qk-scrolled', bottom < 0);
      ticking = false;
    }

    window.addEventListener('scroll', function () {
      if (!ticking) {
        requestAnimationFrame(update);
        ticking = true;
      }
    }, { passive: true });

    update(); // stato iniziale corretto anche senza scroll
  }

  /* ────────────────────────────────────────────────────────────────────────
     THEME
  ──────────────────────────────────────────────────────────────────────── */
  function initTheme() {
    // QK_THEMES e QK_THEME_LEGACY sono iniettati da header.php (config.php).
    // Non modificare qui: aggiungere temi e alias SOLO in config.php.
    const allowed   = (typeof QK_THEMES       !== 'undefined') ? QK_THEMES       : [];
    const legacyMap = (typeof QK_THEME_LEGACY !== 'undefined') ? QK_THEME_LEGACY : {};
    let saved = localStorage.getItem(LS_THEME);
    if (saved && legacyMap[saved]) {
      saved = legacyMap[saved];
      localStorage.setItem(LS_THEME, saved);
    }
    if (saved && allowed.indexOf(saved) === -1) {
      localStorage.removeItem(LS_THEME);
      saved = null;
    }
    if (saved) {
      document.documentElement.setAttribute('data-theme', saved);
      
      // Se il tema salvato nel browser differisce da quello della sessione PHP, sincronizza
      if (typeof QK_SESSION_THEME !== 'undefined' && saved !== QK_SESSION_THEME) {
        // Aggiorna la sessione PHP in background
        fetch(QK_BASE + '/theme/set', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'theme=' + encodeURIComponent(saved)
        }).catch(function () {});

        // Sincronizza gli elementi visuali renderizzati inizialmente da PHP
        document.querySelectorAll('[data-theme-set]').forEach(function (btn) {
          btn.classList.toggle('active', btn.getAttribute('data-theme-set') === saved);
        });

        const infoThemeEl = document.getElementById('qk-active-theme-info');
        if (infoThemeEl) {
          infoThemeEl.textContent = saved;
        }
      }
    } else if (typeof QK_SESSION_THEME !== 'undefined') {
      localStorage.setItem(LS_THEME, QK_SESSION_THEME);
    }
  }

  function initThemeSwitcher() {
    document.querySelectorAll('[data-theme-set]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        const theme = el.getAttribute('data-theme-set');
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(LS_THEME, theme);

        // Persist via AJAX/form
        fetch(QK_BASE + '/theme/set', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'theme=' + encodeURIComponent(theme)
        }).catch(function () {}); // silently ignore if not available

        // Update active checkmark
        document.querySelectorAll('[data-theme-set]').forEach(function (btn) {
          btn.classList.toggle('active', btn.getAttribute('data-theme-set') === theme);
        });
      });
    });
  }

  /* ────────────────────────────────────────────────────────────────────────
     ALERT AUTO-DISMISS
  ──────────────────────────────────────────────────────────────────────── */
  function initAlertAutoDismiss() {
    document.querySelectorAll('[data-auto-dismiss]').forEach(function (el) {
      const delay = parseInt(el.getAttribute('data-auto-dismiss'), 10) || 4000;
      setTimeout(function () {
        el.style.transition = 'opacity .4s';
        el.style.opacity    = '0';
        setTimeout(function () { el.remove(); }, 420);
      }, delay);
    });

    // Manual close buttons
    document.querySelectorAll('.qk-alert-close').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const alert = btn.closest('.qk-alert');
        if (alert) {
          alert.style.transition = 'opacity .3s';
          alert.style.opacity    = '0';
          setTimeout(function () { alert.remove(); }, 320);
        }
      });
    });
  }

  /* ────────────────────────────────────────────────────────────────────────
     DROPDOWN ACTION LINKS (confirm delete)
  ──────────────────────────────────────────────────────────────────────── */
  function initDropdownActionLinks() {
    document.addEventListener('click', function (e) {
      const el = e.target.closest('[data-confirm]');
      if (!el) return;
      const msg = el.getAttribute('data-confirm') || 'Sei sicuro?';
      if (!confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
  }

  /* ────────────────────────────────────────────────────────────────────────
     FORM: highlight required fields, prevent double-submit
  ──────────────────────────────────────────────────────────────────────── */
  function initFormValidation() {
    document.querySelectorAll('form[data-qk-form]').forEach(function (form) {
      // Prevent double submit
      form.addEventListener('submit', function () {
        const btn = form.querySelector('[type="submit"]');
        if (btn) {
          btn.disabled = true;
          btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Salvataggio…';
        }
      });
    });
  }

  /* ────────────────────────────────────────────────────────────────────────
     UTILITY GLOBAL
  ──────────────────────────────────────────────────────────────────────── */
  window.qkConvertUom = function ({ value, price, qFactor, targetMode }) {
    qFactor = parseFloat(qFactor) || 1;
    const hasValue = (value !== undefined && value !== null && value !== '');
    const hasPrice = (price !== undefined && price !== null && price !== '');
    
    let convertedValue = hasValue ? parseFloat(value) : null;
    let convertedPrice = hasPrice ? parseFloat(price) : null;

    if (qFactor <= 0 || qFactor === 1) {
      return {
        value: convertedValue,
        price: convertedPrice
      };
    }

    if (targetMode === 'commercial') {
      // Stock -> Commercial (Quantity multiplied by content factor, Price divided)
      if (hasValue && !isNaN(convertedValue)) convertedValue = convertedValue * qFactor;
      if (hasPrice && !isNaN(convertedPrice)) convertedPrice = convertedPrice / qFactor;
    } else if (targetMode === 'stock') {
      // Commercial -> Stock (Quantity divided, Price multiplied)
      if (hasValue && !isNaN(convertedValue)) convertedValue = convertedValue / qFactor;
      if (hasPrice && !isNaN(convertedPrice)) convertedPrice = convertedPrice * qFactor;
    }

    return {
      value: (hasValue && !isNaN(convertedValue)) ? parseFloat(convertedValue.toFixed(3)) : null,
      price: (hasPrice && !isNaN(convertedPrice)) ? parseFloat(convertedPrice.toFixed(4)) : null
    };
  };

  window.qkDebounce = function (fn, ms) {
    let t;
    return function(...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
  };

  window.qkEscapeHtml = function (s) {
    if (!s) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  window.qkFormatEuro = function (n) {
    return '€ ' + (parseFloat(n) || 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  window.qkAutocomplete = function ({ input, dropdown, fetchFn, onSelect, onCreate, createLabel }) {
    let activeIdx = -1;
    let timer = null;

    function close() {
      dropdown.innerHTML = '';
      dropdown.style.display = 'none';
      activeIdx = -1;
    }

    function open(results) {
      if (!results || results.length === 0) {
        if (onCreate && createLabel) {
          dropdown.innerHTML = `<div class="qk-ac-item qk-ac-create text-primary fw-semibold" style="cursor:pointer; padding:6px 12px; font-size:0.85rem;"><i class="fa-solid fa-plus me-1"></i> ${window.qkEscapeHtml(createLabel)}</div>`;
          dropdown.style.display = 'block';
          dropdown.querySelector('.qk-ac-create').onclick = function() {
            onCreate();
            close();
          };
        } else {
          close();
        }
        return;
      }

      let html = '';
      results.forEach((item, index) => {
        const displayLabel = item.label || item.nome || '';
        const skuLabel = (item.sku || item.sku_interno) ? ` <span class="text-muted small">(${item.sku || item.sku_interno})</span>` : '';
        html += `<div class="qk-ac-item" data-index="${index}" style="padding:6px 12px; cursor:pointer; font-size:0.85rem;">${window.qkEscapeHtml(displayLabel)}${skuLabel}</div>`;
      });

      if (onCreate && createLabel) {
        html += `<div class="qk-ac-item qk-ac-create text-primary fw-semibold" style="cursor:pointer; padding:6px 12px; border-top:1px solid var(--qk-border); font-size:0.85rem;"><i class="fa-solid fa-plus me-1"></i> ${window.qkEscapeHtml(createLabel)}</div>`;
      }

      dropdown.innerHTML = html;
      dropdown.style.display = 'block';

      const items = dropdown.querySelectorAll('.qk-ac-item:not(.qk-ac-create)');
      items.forEach(el => {
        el.onmouseenter = () => {
          items.forEach(i => i.classList.remove('active'));
          el.classList.add('active');
          activeIdx = parseInt(el.dataset.index);
        };
        el.onclick = () => {
          const selItem = results[parseInt(el.dataset.index)];
          onSelect(selItem);
          close();
        };
      });

      if (onCreate && createLabel) {
        const createBtn = dropdown.querySelector('.qk-ac-create');
        if (createBtn) {
          createBtn.onclick = () => {
            onCreate();
            close();
          };
        }
      }
    }

    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 1) { close(); return; }

      timer = setTimeout(async () => {
        try {
          const data = await fetchFn(q);
          open(data);
        } catch (e) {
          console.error(e);
          close();
        }
      }, 250);
    });

    input.addEventListener('keydown', (e) => {
      const items = dropdown.querySelectorAll('.qk-ac-item:not(.qk-ac-create)');
      if (dropdown.style.display === 'none' || items.length === 0) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIdx = (activeIdx + 1) % items.length;
        items.forEach((item, idx) => {
          item.classList.toggle('active', idx === activeIdx);
        });
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIdx = (activeIdx - 1 + items.length) % items.length;
        items.forEach((item, idx) => {
          item.classList.toggle('active', idx === activeIdx);
        });
      } else if (e.key === 'Enter') {
        if (activeIdx >= 0 && activeIdx < items.length) {
          e.preventDefault();
          items[activeIdx].click();
        }
      } else if (e.key === 'Escape') {
        close();
      }
    });

    document.addEventListener('click', (e) => {
      if (!input.contains(e.target) && !dropdown.contains(e.target)) {
        close();
      }
    });
  };

  window.qkToast = function (message, type) {
    type = type || 'info';
    const container = document.getElementById('qk-toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'qk-alert qk-alert-' + type;
    toast.setAttribute('data-auto-dismiss', '3500');
    toast.innerHTML =
      '<i class="fa-solid fa-circle-' + (type === 'success' ? 'check' : type === 'danger' ? 'xmark' : 'info') + '"></i>' +
      '<span>' + message + '</span>' +
      '<button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>';
    container.appendChild(toast);
    initAlertAutoDismiss();
  };

})();
