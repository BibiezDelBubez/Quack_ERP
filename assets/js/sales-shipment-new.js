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
 *   .magazzinoId     (int)     — magazzino di spedizione corrente (Revisione Gestione Magazzino e Lotti §3)
 *   .reloadUrlBase   (string)  — URL pagina corrente (solo order_id) per il toggle/selettore magazzino
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
        const url = cfg.apiLotti + '?prodotto_id=' + prodId + '&includi_scaduti=' + includi + '&magazzino_id=' + cfg.magazzinoId;
        const res  = await fetch(url);
        const data = await res.json();
        return data.lotti || [];
      } catch {
        return [];
      }
    }

    /* ── Ricarica la pagina preservando lo stato di entrambi i controlli
       (toggle scaduti + selettore magazzino), qualunque dei due sia cambiato —
       altrimenti cambiare l'uno resetterebbe silenziosamente l'altro. ── */
    function ricaricaConStato() {
      if (!cfg.reloadUrlBase) return;
      const includiEl   = document.getElementById('pp-toggle-scaduti');
      const magazzinoEl = document.getElementById('pp-select-magazzino');
      const includi     = includiEl ? (includiEl.checked ? '1' : '0') : (cfg.includiScaduti ? '1' : '0');
      const magazzinoId = magazzinoEl ? magazzinoEl.value : cfg.magazzinoId;
      const sep = cfg.reloadUrlBase.includes('?') ? '&' : '?';
      window.location.href = cfg.reloadUrlBase + sep + 'includi_scaduti=' + includi + '&magazzino_id=' + magazzinoId;
    }

    /* ── Toggle "Includi lotti scaduti" — ricarica la pagina con il nuovo stato ── */
    const toggleScaduti = document.getElementById('pp-toggle-scaduti');
    if (toggleScaduti) {
      toggleScaduti.addEventListener('change', ricaricaConStato);
    }

    /* ── Selettore magazzino di spedizione (Revisione Gestione Magazzino e Lotti §3)
       — cambiarlo ricarica la pagina per riproporre i lotti FEFO della nuova sede. ── */
    const selectMagazzino = document.getElementById('pp-select-magazzino');
    if (selectMagazzino) {
      selectMagazzino.addEventListener('change', ricaricaConStato);
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
      if (dispEl) dispEl.textContent = newDisp.toLocaleString('it-IT', {minimumFractionDigits:3}) + ' ' + row.closest('.qk-pp-riga').dataset.uomStock;
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
        let superamenti = []; // righe che superano il residuo ordinato — non è un errore
                               // fisico (max disponibilità lotto), è un caso che nel settore
                               // chimico è normale (es. confezioni fisse: ordinati 950kg, si
                               // spediscono 5 fusti da 200kg = 1000kg) — quindi non un blocco
                               // secco, ma una conferma esplicita (vedi Problema 3 della
                               // REVISIONE Ciclo Vendite, §3bis.S1).

        // Controlla ogni riga
        document.querySelectorAll('.qk-pp-riga').forEach(rigaEl => {
          const rigaId    = rigaEl.dataset.rigaId;
          const traccia   = rigaEl.dataset.tracciaLotti === '1';
          const qtaResidua = parseFloat(rigaEl.dataset.qtaResidua);
          const nomeProd  = rigaEl.querySelector('.qk-pp-riga-nome')?.textContent?.trim() || `riga ${rigaId}`;

          let totRiga;
          if (!traccia) {
            const inp = rigaEl.querySelector('input[name^="quantita_no_lotto"]');
            const val = parseFloat(inp?.value || 0);
            if (val < 0) {
              errori.push(`Quantità non valida per "${nomeProd}".`);
              return;
            }
            totRiga = val;
          } else {
            // Controlla ogni lotto: qui il vincolo è fisico (non si può prelevare più
            // di quanto un lotto contenga davvero) — resta sempre un blocco secco.
            let bloccata = false;
            rigaEl.querySelectorAll('.qk-pp-lotto-qta').forEach(inp => {
              if (!validaQta(inp)) {
                errori.push(`Quantità superiore alla disponibilità del lotto per "${nomeProd}".`);
                bloccata = true;
              }
            });
            if (bloccata) return;
            totRiga = getTotaleRiga(rigaEl);
          }

          if (totRiga > qtaResidua + 0.0005) {
            superamenti.push({ nomeProd, totRiga, qtaResidua });
          }
        });

        if (errori.length) {
          e.preventDefault();
          if (note && noteT) {
            noteT.textContent = errori[0];
            note.style.display = 'flex';
            note.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
          return;
        }

        // Il campo di conferma non viene mai portato avanti da un tentativo di invio
        // al successivo: ogni submit ricalcola i superamenti da zero sui valori
        // ATTUALI dei campi. Se l'utente conferma e poi modifica una quantità prima
        // di inviare di nuovo, questo blocco rientra e richiede una nuova conferma
        // sul valore nuovo — non esiste uno stato "confermato" che sopravvive a una
        // modifica successiva.
        const confermaField = document.getElementById('pp-conferma-superamento');
        if (confermaField) confermaField.value = '';

        if (superamenti.length) {
          e.preventDefault();
          const dettaglio = superamenti
            .map(s => `- "${s.nomeProd}": stai spedendo ${s.totRiga.toFixed(3)}, ma il residuo ordinato è ${s.qtaResidua.toFixed(3)}`)
            .join('\n');
          const confermato = window.confirm(
            `Attenzione: alcune righe superano la quantità ancora ordinata:\n\n${dettaglio}\n\n` +
            `Può essere corretto (es. confezioni a formato fisso), ma verifica prima di continuare.\n\n` +
            `Confermare e procedere comunque con la spedizione?`
          );
          if (confermato) {
            // window.confirm() è bloccante e modale: il DOM non può essere toccato
            // finché l'utente non risponde, quindi i valori riletti sopra sono
            // ancora quelli correnti. form.submit() invia il form senza far
            // ripartire questo stesso listener 'submit' (a differenza di
            // requestSubmit() o del click sul bottone), evitando un loop.
            if (confermaField) confermaField.value = '1';
            form.submit();
          }
          // Se annulla: non si invia nulla, l'utente resta sul form e può correggere.
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
