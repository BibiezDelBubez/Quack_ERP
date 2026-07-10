/**
 * sales-shipment-new.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Logica interattiva per la pagina dedicata Pick & Pack.
 *
 * Dipende da window.QkPickPack iniettato dalla view:
 *   .apiLotti        (string)  — URL GET /sales/orders/api/lotti-disponibili
 *   .totRighe        (int)     — numero totale righe
 *   .righeOk         (int)     — righe già allocate correttamente (da PHP)
 *   .includiScaduti  (bool)    — stato corrente del toggle "Includi lotti scaduti" (Tappa 4.4)
 *   .reloadUrlBase   (string)  — URL pagina corrente (senza includi_scaduti) per il toggle
 */

/* global QkPickPack, bootstrap */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    const cfg    = window.QkPickPack;
    if (!cfg) return;

    const form   = document.getElementById('formNuovaSpedizione');
    const btnOk  = document.getElementById('pp-btn-conferma');
    const note   = document.getElementById('pp-submit-note');
    const noteT  = document.getElementById('pp-submit-note-text');

    /* ── Ricalcola stato globale (progress bar + riepilogo) ─────────────── */
    function aggiornaStatoGlobale() {
      let pronte = 0;
      document.querySelectorAll('.qk-pp-riga').forEach(rigaEl => {
        const rigaId     = rigaEl.dataset.rigaId;
        const traccia    = rigaEl.dataset.tracciaLotti === '1';
        const qtaResidua = parseFloat(rigaEl.dataset.qtaResidua);

        if (!traccia) {
          const inp = rigaEl.querySelector('input[name^="quantita_no_lotto"]');
          const val = parseFloat(inp?.value || 0);
          if (Math.abs(val - qtaResidua) < 0.0005) pronte++;
        } else {
          const tot = getTotaleRiga(rigaEl);
          if (Math.abs(tot - qtaResidua) < 0.0005) pronte++;
        }
      });

      const tot = cfg.totRighe;
      const fill = document.getElementById('pp-progress-fill');
      const count = document.getElementById('pp-righe-pronte');
      const pr = document.getElementById('riepilogo-pronte');
      const pw = document.getElementById('riepilogo-problemi');
      const rowWarn = document.getElementById('riepilogo-row-warn');

      if (fill)   fill.style.width = (tot > 0 ? Math.round((pronte/tot)*100) : 0) + '%';
      if (count)  count.textContent = pronte + ' / ' + tot;
      if (pr)     pr.textContent = pronte;
      if (pw)     pw.textContent = (tot - pronte);
      if (rowWarn) rowWarn.style.display = (pronte < tot) ? '' : 'none';
    }

    /* ── Calcola totale assegnato per una riga ──────────────────────────── */
    function getTotaleRiga(rigaEl) {
      let tot = 0;
      rigaEl.querySelectorAll('.qk-pp-lotto-qta').forEach(inp => {
        tot += parseFloat(inp.value) || 0;
      });
      return tot;
    }

    /* ── Aggiorna display totale riga + colorazione ─────────────────────── */
    function aggiornaTotaleRiga(rigaId) {
      const rigaEl  = document.querySelector(`.qk-pp-riga[data-riga-id="${rigaId}"]`);
      if (!rigaEl) return;
      const qtaRes  = parseFloat(rigaEl.dataset.qtaResidua);
      const tot     = getTotaleRiga(rigaEl);
      const valEl   = document.getElementById('pp-tot-val-' + rigaId);
      const totEl   = document.getElementById('pp-tot-' + rigaId);

      if (valEl) valEl.textContent = tot.toFixed(3).replace('.', ',');

      if (totEl) {
        totEl.classList.remove('ok', 'over');
        if (Math.abs(tot - qtaRes) < 0.0005) totEl.classList.add('ok');
        else if (tot > qtaRes + 0.0005)      totEl.classList.add('over');
      }

      // Aggiorna stato bordo card
      rigaEl.classList.remove('ok', 'partial', 'nostock');
      if (tot >= qtaRes - 0.0005 && tot <= qtaRes + 0.0005) rigaEl.classList.add('ok');
      else if (tot > 0)    rigaEl.classList.add('partial');
      else                 rigaEl.classList.add('nostock');

      aggiornaStatoGlobale();
    }

    /* ── Validazione singolo input quantità lotto ───────────────────────── */
    function validaQta(inp) {
      const disp = parseFloat(inp.dataset.lottoDisp || inp.max || 999999);
      const val  = parseFloat(inp.value) || 0;
      inp.classList.toggle('is-over', val > disp + 0.0005);
      inp.title = val > disp + 0.0005 ? `Max disponibile: ${disp}` : '';
      return val <= disp + 0.0005;
    }

    /* ── Costruisce una riga lotto DOM ──────────────────────────────────── */
    function buildLottoRow(rigaId, lotto, qtaDefault, uom, tuttiLotti) {
      const div = document.createElement('div');
      div.className = 'qk-pp-lotto-row';
      div.dataset.lottoId = lotto.id;

      const disp = parseFloat(lotto.quantita_disponibile);

      // Lotto label / select
      let lottoHtml;
      const badgeScaduto = ` <span class="qk-pp-scaduto-tag">SCADUTO</span>`;
      if (tuttiLotti && tuttiLotti.length > 1) {
        const opts = tuttiLotti.map(l => {
          const d = parseFloat(l.quantita_disponibile);
          const scad = l.data_scadenza ? ` — scad. ${l.data_scadenza}` : '';
          const scadutoTxt = l.scaduto ? ' [SCADUTO]' : '';
          return `<option value="${l.id}" data-disp="${d}" ${l.id === lotto.id ? 'selected' : ''}>
                    ${esc(l.codice)} — disp. ${d}${scad}${scadutoTxt}
                  </option>`;
        }).join('');
        lottoHtml = `<span class="qk-pp-lotto-codice">
                       <select class="qk-form-control qk-pp-lotto-select"
                               data-riga-id="${rigaId}"
                               style="font-size:.8rem;padding:.2rem .35rem;height:auto">
                         ${opts}
                       </select>
                       ${lotto.scaduto ? badgeScaduto : ''}
                     </span>`;
      } else {
        lottoHtml = `<span class="qk-pp-lotto-codice">${esc(lotto.codice)}${lotto.scaduto ? badgeScaduto : ''}</span>`;
      }

      const scadStr = lotto.data_scadenza
        ? new Date(lotto.data_scadenza).toLocaleDateString('it-IT')
        : '—';

      div.innerHTML = `
        ${lottoHtml}
        <span class="qk-pp-lotto-disp">${disp.toLocaleString('it-IT',{minimumFractionDigits:3})} ${esc(uom)}</span>
        <span class="qk-pp-lotto-scad">${scadStr}</span>
        <div class="qk-pp-lotto-qty-wrap">
          <input type="number"
                 name="lotti[${rigaId}][${lotto.id}]"
                 class="qk-form-control qk-pp-lotto-qta"
                 step="0.001" min="0" max="${disp}"
                 value="${qtaDefault.toFixed(3)}"
                 data-riga-id="${rigaId}"
                 data-lotto-disp="${disp}">
          <span class="qk-pp-uom">${esc(uom)}</span>
        </div>
        <button type="button" class="btn-qk btn-qk-ghost btn-qk-xs qk-pp-btn-rimuovi"
                data-riga-id="${rigaId}" title="Rimuovi">
          <i class="fa-solid fa-xmark"></i>
        </button>`;

      return div;
    }

    /* ── Fetch lotti disponibili ────────────────────────────────────────── */
    async function fetchLotti(prodId) {
      try {
        const includi = cfg.includiScaduti ? '1' : '0';
        const res  = await fetch(cfg.apiLotti + '?prodotto_id=' + prodId + '&includi_scaduti=' + includi);
        const data = await res.json();
        return data.lotti || [];
      } catch {
        return [];
      }
    }

    /* ── Toggle "Includi lotti scaduti" — ricarica la pagina con il nuovo stato ── */
    const toggleScaduti = document.getElementById('pp-toggle-scaduti');
    if (toggleScaduti && cfg.reloadUrlBase) {
      toggleScaduti.addEventListener('change', function () {
        const sep = cfg.reloadUrlBase.includes('?') ? '&' : '?';
        window.location.href = cfg.reloadUrlBase + sep + 'includi_scaduti=' + (this.checked ? '1' : '0');
      });
    }

    /* ── Event: cambio quantità lotto ───────────────────────────────────── */
    document.addEventListener('input', function (e) {
      const inp = e.target.closest('.qk-pp-lotto-qta');
      if (!inp) return;
      validaQta(inp);
      aggiornaTotaleRiga(inp.dataset.rigaId);
    });

    /* ── Event: cambio input senza lotti ────────────────────────────────── */
    document.addEventListener('input', function (e) {
      const inp = e.target.closest('input[name^="quantita_no_lotto"]');
      if (!inp) return;
      aggiornaStatoGlobale();
    });

    /* ── Event: cambio select lotto (rinomina hidden input) ─────────────── */
    document.addEventListener('change', function (e) {
      const sel = e.target.closest('.qk-pp-lotto-select');
      if (!sel) return;
      const rigaId = sel.dataset.rigaId;
      const newId  = sel.value;
      const newDisp = parseFloat(sel.options[sel.selectedIndex].dataset.disp);
      const row    = sel.closest('.qk-pp-lotto-row');
      const inp    = row.querySelector('.qk-pp-lotto-qta');
      // Aggiorna name, max e dataset
      inp.name              = `lotti[${rigaId}][${newId}]`;
      inp.max               = newDisp;
      inp.dataset.lottoDisp = newDisp;
      // Aggiorna display disponibilità
      const dispEl = row.querySelector('.qk-pp-lotto-disp');
      if (dispEl) dispEl.textContent = newDisp.toLocaleString('it-IT', {minimumFractionDigits:3}) + ' ' + row.closest('.qk-pp-riga').dataset.uom;
      validaQta(inp);
      aggiornaTotaleRiga(rigaId);
    });

    /* ── Event: rimuovi riga lotto ──────────────────────────────────────── */
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.qk-pp-btn-rimuovi');
      if (!btn) return;
      const rigaId = btn.dataset.rigaId;
      btn.closest('.qk-pp-lotto-row').remove();
      aggiornaTotaleRiga(rigaId);
    });

    /* ── Event: aggiungi lotto manuale ──────────────────────────────────── */
    document.addEventListener('click', async function (e) {
      const btn = e.target.closest('.qk-pp-btn-aggiungi');
      if (!btn) return;
      const rigaId  = btn.dataset.rigaId;
      const prodId  = btn.dataset.prodottoId;
      const uom     = btn.dataset.uom;
      const container = document.getElementById('lotti-rows-' + rigaId);
      if (!container) return;

      btn.disabled = true;
      const lotti = await fetchLotti(prodId);
      btn.disabled = false;

      if (!lotti.length) {
        showToast('Nessun lotto disponibile per questo prodotto.', 'warning');
        return;
      }
      const row = buildLottoRow(rigaId, lotti[0], 0, uom, lotti);
      container.appendChild(row);
      aggiornaTotaleRiga(rigaId);
    });

    /* ── Validazione submit ─────────────────────────────────────────────── */
    if (form) {
      form.addEventListener('submit', function (e) {
        let errori = [];

        // Controlla ogni riga
        document.querySelectorAll('.qk-pp-riga').forEach(rigaEl => {
          const rigaId  = rigaEl.dataset.rigaId;
          const traccia = rigaEl.dataset.tracciaLotti === '1';

          if (!traccia) {
            const inp = rigaEl.querySelector('input[name^="quantita_no_lotto"]');
            const max = parseFloat(inp?.max || 999999);
            const val = parseFloat(inp?.value || 0);
            if (val < 0 || val > max + 0.0005) {
              errori.push(`Quantità non valida per la riga ${rigaId}.`);
            }
            return;
          }

          // Controlla ogni lotto
          rigaEl.querySelectorAll('.qk-pp-lotto-qta').forEach(inp => {
            if (!validaQta(inp)) {
              errori.push(`Quantità superiore alla disponibilità del lotto.`);
            }
          });
        });

        if (errori.length) {
          e.preventDefault();
          if (note && noteT) {
            noteT.textContent = errori[0];
            note.style.display = 'flex';
            note.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        }
      });
    }

    /* ── Toast semplice ─────────────────────────────────────────────────── */
    function showToast(msg, type) {
      if (note && noteT) {
        noteT.textContent = msg;
        note.style.display = 'flex';
        setTimeout(() => { note.style.display = 'none'; }, 4000);
      }
    }

    /* ── Helper escaping ────────────────────────────────────────────────── */
    function esc(str) {
      return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── Init: ricalcola totali di tutte le righe già renderizzate ──────── */
    document.querySelectorAll('.qk-pp-riga[data-traccia-lotti="1"]').forEach(rigaEl => {
      aggiornaTotaleRiga(rigaEl.dataset.rigaId);
    });

  });
})();
