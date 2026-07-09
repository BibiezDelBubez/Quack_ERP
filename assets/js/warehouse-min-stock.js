/**
 * warehouse-min-stock.js
 * Autosave per la griglia "Soglie di riordino" (warehouse/min-stock).
 * Stesso pattern già usato in products-suppliers.js (flat-grid-td/flat-grid-input,
 * icon-saving/icon-saved, Enter->blur, fetch POST + JSON {ok, row}) — non un
 * pattern nuovo, riciclato per coerenza visiva e di comportamento (Piano
 * Multi-Magazzino, Parte 5 §5.2).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.QkMinStock;
    const tbody = document.getElementById('soglieBody');
    if (!cfg || !tbody) return;

    tbody.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (e.target && typeof e.target.blur === 'function') {
          e.target.blur();
        }
      }
    });

    tbody.querySelectorAll('tr[data-prodotto-id]').forEach(function (tr) {
      const inp = tr.querySelector('.inp-scorta-minima');
      if (!inp) return;
      inp.addEventListener('change', function () {
        triggerSave(tr, inp);
      });
    });

    async function triggerSave(tr, inp) {
      const iconSaving = tr.querySelector('.icon-saving');
      const iconSaved = tr.querySelector('.icon-saved');
      if (iconSaving) iconSaving.classList.remove('d-none');
      if (iconSaved) iconSaved.classList.add('d-none');

      const fd = new FormData();
      fd.append('prodotto_id', tr.dataset.prodottoId);
      fd.append('magazzino_id', tr.dataset.magazzinoId);
      fd.append('scorta_minima', inp.value || '0');
      fd.append('csrf_token', cfg.csrfToken);

      try {
        const res = await fetch(cfg.apiSave, { method: 'POST', body: fd });
        const data = await res.json();

        if (iconSaving) iconSaving.classList.add('d-none');
        if (data.ok) {
          if (iconSaved) iconSaved.classList.remove('d-none');
          setTimeout(function () {
            if (iconSaved) iconSaved.classList.add('d-none');
          }, 2000);
        } else {
          console.error(data.error || 'Errore salvataggio scorta minima');
        }
      } catch (e) {
        if (iconSaving) iconSaving.classList.add('d-none');
        console.error(e);
      }
    }
  });
})();
