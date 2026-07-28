(function() {
  'use strict';

  const backdrop = document.getElementById('qk-drawer-backdrop');
  const drawer   = document.getElementById('qk-lots-drawer');
  const titleEl  = document.getElementById('qk-drawer-title');
  const bodyEl   = document.getElementById('qk-drawer-body');

  window.openLotsDrawer = async function(prodottoId, prodottoNome) {
    if (!window.QkWhStockConfig || !window.QkWhStockConfig.apiUrl) {
      console.error("QkWhStockConfig.apiUrl non definita.");
      return;
    }

    titleEl.textContent = `Lotti di: ${prodottoNome}`;
    bodyEl.innerHTML = '<div class="qk-text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Caricamento lotti in corso...</div>';

    if (backdrop && drawer) {
      backdrop.classList.add('show');
      drawer.classList.add('open');
    }

    try {
      // DRY 27/07/2026 (PIANO qkApiFetch): validazione HTTP/content-type
      // delegata a window.qkApiFetch() (assets/js/app.js).
      const data = await window.qkApiFetch(`${window.QkWhStockConfig.apiUrl}?id=${prodottoId}`);

      if (!data.ok) {
        bodyEl.innerHTML = `<div class="text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Errore: ${data.error}</div>`;
        return;
      }

      const lotti = data.lotti || [];
      const disponibili = lotti.filter(l => l.quantita_disponibile > 0);

      if (disponibili.length === 0) {
        bodyEl.innerHTML = '<div class="qk-empty-state"><i class="fa-solid fa-box-open"></i><p>Nessun lotto con giacenza disponibile.</p></div>';
        return;
      }

      bodyEl.innerHTML = disponibili.map(l => {
        const scadenzaStr = l.data_scadenza ? new Date(l.data_scadenza).toLocaleDateString('it-IT') : 'Nessuna scadenza';
        const isScaduto = l.data_scadenza && new Date(l.data_scadenza) < new Date();
        const badgeScaduto = isScaduto ? '<span class="badge bg-danger text-white ms-2">Scaduto</span>' : '';

        return `
          <div class="qk-drawer-lot-card">
            <div class="qk-drawer-lot-code">
              <i class="fa-solid fa-tag text-muted"></i> ${l.codice_lotto_interno}
            </div>
            ${l.codice_lotto_fornitore ? `<div style="font-size:0.75rem;color:var(--qk-text-muted)">Cod. Fornitore: ${l.codice_lotto_fornitore}</div>` : ''}
            <div class="qk-drawer-lot-meta">
              <span>Disponibile: <strong>${l.quantita_disponibile}</strong></span>
              <span class="${isScaduto ? 'text-danger fw-bold' : ''}">
                <i class="fa-solid fa-calendar-days"></i> ${scadenzaStr}${badgeScaduto}
              </span>
            </div>
          </div>
        `;
      }).join('');

    } catch (err) {
      bodyEl.innerHTML = `<div class="text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Errore di caricamento.</div>`;
    }
  };

  window.closeLotsDrawer = function() {
    if (backdrop && drawer) {
      backdrop.classList.remove('show');
      drawer.classList.remove('open');
    }
  };

  if (backdrop) {
    backdrop.addEventListener('click', closeLotsDrawer);
  }
})();
