/**
 * sales-orders-shipment.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Logica Pick & Pack per il modal "Nuova Spedizione" nell'ordine cliente.
 *
 * Dipendenze
 * ──────────
 *   window.QkShipment — iniettato da sales/orders/_detail.php
 *     .apiLotti  (string) — URL GET /sales/orders/api/lotti-disponibili
 *
 * Comportamento
 * ─────────────
 *   - All'apertura del modal: auto-allocazione FEFO/FIFO per ogni riga con lotti
 *   - Supporto split multi-lotto: aggiungi/rimuovi righe lotto dinamicamente
 *   - Validazione client-side: blocca submit se quantità > disponibilità
 *   - Totale assegnato per riga aggiornato in tempo reale
 */

/* global QkShipment, bootstrap */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    const cfg = window.QkShipment;
    if (!cfg) return;

    const modalEl  = document.getElementById('modalNuovaSpedizione');
    if (!modalEl) return;

    const bsModal  = new bootstrap.Modal(modalEl);
    const btnOpen  = document.getElementById('btnNuovaSpedizione');
    const form     = document.getElementById('formNuovaSpedizione');

    /* ── Apertura modal ──────────────────────────────────────────────────── */
    if (btnOpen) {
      btnOpen.addEventListener('click', function () {
        bsModal.show();
        // Auto-alloca lotti per tutte le righe tracciabili
        document.querySelectorAll('.qk-pp-riga[data-traccia-lotti="1"]').forEach(riga => {
          autoAlloca(riga);
        });
      });
    }

    /* ── Auto-allocazione FEFO/FIFO ─────────────────────────────────────── */
    async function autoAlloca(rigaEl) {
      const rigaId    = rigaEl.dataset.rigaId;
      const prodId    = rigaEl.dataset.prodottoId;
      const qtaRes    = parseFloat(rigaEl.dataset.qtaResidua);
      const uom       = rigaEl.dataset.uom;
      const container = document.getElementById('lotti-rows-' + rigaId);
      if (!container) return;

      container.innerHTML = '<div class="text-muted small p-2"><i class="fa-solid fa-spinner fa-spin me-1"></i> Caricamento lotti…</div>';

      const lotti = await fetchLotti(prodId);
      container.innerHTML = '';

      if (lotti.length === 0) {
        container.innerHTML = alertHtml('danger', 'Nessun lotto disponibile in magazzino per questo prodotto.');
        return;
      }

      // Distribuisce qtaResidua tra i lotti in ordine FEFO
      let rimasto = qtaRes;
      for (const lotto of lotti) {
        if (rimasto <= 0) break;
        const assegna = Math.min(rimasto, parseFloat(lotto.quantita_disponibile));
        rimasto -= assegna;
        appendLottoRow(container, rigaId, lotto, assegna, uom, lotti);
      }

      if (rimasto > 0.0005) {
        container.insertAdjacentHTML('beforeend',
          alertHtml('warning', `Stock insufficiente: mancano ancora <strong>${rimasto.toFixed(3)} ${esc(uom)}</strong>.`)
        );
      }

      aggiornaToaleRiga(rigaId);
    }

    /* ── Fetch lotti disponibili ────────────────────────────────────────── */
    async function fetchLotti(prodId) {
      try {
        const res  = await fetch(cfg.apiLotti + '?prodotto_id=' + prodId);
        const data = await res.json();
        return data.lotti || [];
      } catch (e) {
        return [];
      }
    }

    /* ── Bottone "Aggiungi lotto" (delegato) ────────────────────────────── */
    modalEl.addEventListener('click', async function (e) {
      const btn = e.target.closest('.btn-pp-aggiungi-lotto');
      if (!btn) return;

      const rigaId    = btn.dataset.rigaId;
      const prodId    = btn.dataset.prodottoId;
      const uom       = btn.dataset.uom;
      const container = document.getElementById('lotti-rows-' + rigaId);

      const lotti = await fetchLotti(prodId);
      if (lotti.length === 0) { alert('Nessun lotto disponibile.'); return; }

      appendLottoRow(container, rigaId, lotti[0], 0, uom, lotti);
      aggiornaToaleRiga(rigaId);
    });

    /* ── Bottone "Rimuovi riga lotto" (delegato) ────────────────────────── */
    modalEl.addEventListener('click', function (e) {
      const btn = e.target.closest('.btn-pp-rimuovi-lotto');
      if (!btn) return;
      const rigaId = btn.dataset.rigaId;
      btn.closest('.qk-pp-lotto-row').remove();
      aggiornaToaleRiga(rigaId);
    });

    /* ── Input quantità lotto — validazione live (delegato) ─────────────── */
    modalEl.addEventListener('input', function (e) {
      const inp = e.target.closest('.qk-pp-lotto-qta');
      if (!inp) return;
      validaQta(inp);
      aggiornaToaleRiga(inp.dataset.rigaId);
    });

    /* ── Select cambio lotto (delegato) ─────────────────────────────────── */
    modalEl.addEventListener('change', function (e) {
      const sel = e.target.closest('.qk-pp-lotto-select');
      if (!sel) return;
      const rigaId = sel.dataset.rigaId;
      const newId  = sel.value;
      const disp   = parseFloat(sel.options[sel.selectedIndex].dataset.disp);
      // Aggiorna il name dell'input quantità e il suo max
      const row  = sel.closest('.qk-pp-lotto-row');
      const inp  = row.querySelector('.qk-pp-lotto-qta');
      inp.name             = `lotti[${rigaId}][${newId}]`;
      inp.max              = disp;
      inp.dataset.lottoDisp = disp;
      validaQta(inp);
      aggiornaToaleRiga(rigaId);
    });

    /* ── Costruisce una riga lotto nel container ─────────────────────────── */
    function appendLottoRow(container, rigaId, lotto, qtaDefault, uom, tuttiLotti) {
      const div = document.createElement('div');
      div.className = 'qk-pp-lotto-row d-flex align-items-center gap-2 mb-2 p-2 rounded';
      div.style.background = 'var(--qk-bg-subtle)';

      // Selector o label
      let selectorHtml;
      if (tuttiLotti && tuttiLotti.length > 1) {
        const opts = tuttiLotti.map(l => {
          const disp = parseFloat(l.quantita_disponibile);
          const scad = l.data_scadenza ? ` — scad. ${l.data_scadenza}` : '';
          return `<option value="${l.id}" data-disp="${disp}" ${l.id === lotto.id ? 'selected' : ''}>
                    ${esc(l.codice)} — disp. ${disp} ${esc(uom)}${scad}
                  </option>`;
        }).join('');
        selectorHtml = `<select class="qk-form-control qk-pp-lotto-select"
                                data-riga-id="${rigaId}"
                                style="flex:1;min-width:180px;font-size:.82rem">${opts}</select>`;
      } else {
        const scad = lotto.data_scadenza ? `<small class="text-muted ms-1">scad. ${esc(lotto.data_scadenza)}</small>` : '';
        selectorHtml = `<span style="flex:1;font-size:.82rem;font-weight:600">
                          ${esc(lotto.codice)}
                          <small class="text-muted ms-1">disp. ${parseFloat(lotto.quantita_disponibile)} ${esc(uom)}</small>
                          ${scad}
                        </span>`;
      }

      div.innerHTML = `
        ${selectorHtml}
        <div class="d-flex align-items-center gap-1">
          <input type="number"
                 name="lotti[${rigaId}][${lotto.id}]"
                 class="qk-form-control qk-pp-lotto-qta"
                 style="width:110px;font-size:.85rem"
                 step="0.001" min="0"
                 max="${parseFloat(lotto.quantita_disponibile)}"
                 value="${qtaDefault.toFixed(3)}"
                 data-riga-id="${rigaId}"
                 data-lotto-disp="${parseFloat(lotto.quantita_disponibile)}">
          <span class="text-muted" style="font-size:.82rem">${esc(uom)}</span>
        </div>
        <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm btn-pp-rimuovi-lotto"
                data-riga-id="${rigaId}" title="Rimuovi">
          <i class="fa-solid fa-xmark"></i>
        </button>`;

      container.appendChild(div);
    }

    /* ── Aggiorna contatore totale assegnato per riga ───────────────────── */
    function aggiornaToaleRiga(rigaId) {
      const rigaEl  = document.querySelector(`.qk-pp-riga[data-riga-id="${rigaId}"]`);
      if (!rigaEl) return;
      const qtaRes  = parseFloat(rigaEl.dataset.qtaResidua);
      let tot = 0;
      rigaEl.querySelectorAll('.qk-pp-lotto-qta').forEach(inp => { tot += parseFloat(inp.value) || 0; });

      const valEl = document.getElementById('pp-tot-val-' + rigaId);
      const totEl = document.getElementById('pp-tot-' + rigaId);
      if (valEl) valEl.textContent = tot.toFixed(3);
      if (totEl) {
        if (tot > qtaRes + 0.0005)       totEl.style.color = 'var(--qk-danger)';
        else if (Math.abs(tot - qtaRes) < 0.0005) totEl.style.color = 'var(--qk-success)';
        else                              totEl.style.color = 'var(--qk-text-muted)';
      }
    }

    /* ── Validazione singolo input quantità lotto ───────────────────────── */
    function validaQta(inp) {
      const disp = parseFloat(inp.dataset.lottoDisp || inp.max || 999999);
      const val  = parseFloat(inp.value) || 0;
      inp.style.borderColor = val > disp ? 'var(--qk-danger)' : '';
      inp.title = val > disp ? `Max disponibile: ${disp}` : '';
    }

    /* ── Blocca submit se ci sono errori ────────────────────────────────── */
    if (form) {
      form.addEventListener('submit', function (e) {
        let errore = null;
        form.querySelectorAll('.qk-pp-lotto-qta').forEach(inp => {
          const disp = parseFloat(inp.dataset.lottoDisp || inp.max || 999999);
          if ((parseFloat(inp.value) || 0) > disp) {
            errore = 'Una o più quantità superano la disponibilità del lotto. Correggile prima di confermare.';
          }
        });
        if (errore) { e.preventDefault(); alert(errore); }
      });
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */
    function esc(str) {
      return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function alertHtml(type, msg) {
      return `<div class="qk-alert qk-alert-${type} mt-1" style="font-size:.82rem;padding:.4rem .75rem">
                <i class="fa-solid fa-triangle-exclamation"></i> <span>${msg}</span>
              </div>`;
    }

  });
})();
