/**
 * products-suppliers.js
 * ─────────────────────────────────────────────────────────────────────────────
 * DEFINITIVE STATE-OF-THE-ART SUPPLIER & PRICING MANAGEMENT
 * Master List + Lateral Drawer for purchases and advanced RRP integration on sales matrix.
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.QkPF;
    if (!cfg) return;

    const prodId = cfg.prodottoId;
    const CSRF = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    // Riferimenti DOM
    const masterTbody  = document.getElementById('master-fornitori-tbody');
    const drawer       = document.getElementById('test-fornitore-drawer');
    const overlay      = document.getElementById('test-drawer-overlay');
    const closeBtn     = document.getElementById('btn-close-drawer');
    const cancelBtn    = document.getElementById('btn-cancel-drawer');
    const saveBtn      = document.getElementById('btn-save-drawer');
    const alertBox     = document.getElementById('drawer-alert');

    // Elementi Form Drawer
    const dwId          = document.getElementById('dw-id');
    const dwFornId      = document.getElementById('dw-fornitore-id');
    const dwFornLabel   = document.getElementById('dw-fornitore-label');
    const dwSku         = document.getElementById('dw-sku');
    const dwNomeProd    = document.getElementById('dw-nome-prod-forn');
    const dwQtaMin      = document.getElementById('dw-qta-min');
    const dwLeadTime    = document.getElementById('dw-lead-time');
    const dwAttivo      = document.getElementById('dw-attivo');
    const dwRrpMin      = document.getElementById('dw-rrp-min');
    const dwRrpMax      = document.getElementById('dw-rrp-max');
    const dwNote        = document.getElementById('dw-note');
    const scaglioniTbody = document.getElementById('drawer-scaglioni-tbody');
    const addScaglioneBtn = document.getElementById('dw-btn-add-scaglione');

    // Riferimenti DOM per Vendite (RRP Banner)
    const rrpBannerContent = document.getElementById('rrp-banner-content');
    const btnToggleAllRrp   = document.getElementById('btn-toggle-all-rrp');
    const allRrpDetails     = document.getElementById('all-rrp-details');
    const allRrpTbody       = document.getElementById('all-rrp-tbody');

    // Matrice Vendite
    const matrixTable = document.getElementById('pricing-matrix-table');

    /* ── Stato locale ── */
    let fornitori = [...(cfg.fornitori || [])];
    let prezziList = [...(cfg.tuttePrezzi || [])]; // tutti gli scaglioni d'acquisto dei fornitori
    let listiniAcquisto = [];

    // Carica l'elenco dei listini di acquisto per il dropdown
    async function loadListiniAcquisto(fornitoreId) {
      if (!fornitoreId) return [];
      try {
        const res = await fetch(`${cfg.apiFornListini}?fornitore_id=${encodeURIComponent(fornitoreId)}`);
        const data = await res.json();
        return data.ok ? (data.listini || []) : [];
      } catch (e) {
        console.error(e);
        return [];
      }
    }

    /* ── Inizializzazione ── */
    if (masterTbody) {
      renderMasterTable();
      updateRrpBanner();
      validateMatrixPrices();
    }

    // Eventi Drawer
    closeBtn?.addEventListener('click', closeDrawer);
    cancelBtn?.addEventListener('click', closeDrawer);
    overlay?.addEventListener('click', closeDrawer);

    // Salva Drawer
    saveBtn?.addEventListener('click', async () => {
      if (!alertBox) return;
      alertBox.classList.add('d-none');
      
      const id = parseInt(dwId.value) || 0;
      const fornitoreId = parseInt(dwFornId.value);
      const sku = dwSku.value.trim();
      const nomeProd = dwNomeProd.value.trim();
      const qtaMin = parseFloat(dwQtaMin.value) || 0;
      const leadTime = parseInt(dwLeadTime.value) || 0;
      const attivo = dwAttivo.checked;
      const rrpMin = dwRrpMin.value ? parseFloat(dwRrpMin.value) : null;
      const rrpMax = dwRrpMax.value ? parseFloat(dwRrpMax.value) : null;
      const note = dwNote.value.trim();

      // Leggi scaglioni dal drawer
      const scaglioni = [];
      const rows = scaglioniTbody.querySelectorAll('tr.sc-row');
      for (const row of rows) {
        const listinoSelect = row.querySelector('.sc-listino');
        const qtaInput = row.querySelector('.sc-qta');
        const prezzoInput = row.querySelector('.sc-prezzo');
        const scontoInput = row.querySelector('.sc-sconto');
        const extraRow = row.nextElementSibling;
        const dalInput = extraRow?.querySelector('.sc-dal');
        const alInput = extraRow?.querySelector('.sc-al');
        const noteInput = extraRow?.querySelector('.sc-note');

        const lid = listinoSelect?.value || null;
        const qta = parseFloat(qtaInput?.value);
        const prezzo = parseFloat(prezzoInput?.value);
        const sconto = parseFloat(scontoInput?.value) || 0;
        const dal = dalInput?.value || '';
        const al = alInput?.value || '';
        const snote = noteInput?.value || '';

        if (isNaN(qta) || isNaN(prezzo)) {
          alertBox.textContent = "Quantità e Prezzo d'acquisto devono essere numeri validi.";
          alertBox.classList.remove('d-none');
          return;
        }
        scaglioni.push({
          id: parseInt(row.dataset.rowId) || 0,
          listino_acquisto_id: lid,
          quantita_minima: qta,
          prezzo: prezzo,
          sconto_perc: sconto,
          valido_dal: dal,
          valido_al: al,
          note: snote
        });
      }

      // 1. Salva metadati fornitore via AJAX
      const fd = new FormData();
      fd.append('id', id ? String(id) : '');
      fd.append('prodotto_id', String(prodId));
      fd.append('fornitore_id', String(fornitoreId));
      fd.append('sku_fornitore', sku);
      fd.append('nome_prodotto_fornitore', nomeProd);
      fd.append('quantita_minima', String(qtaMin));
      fd.append('lead_time_giorni', String(leadTime));
      fd.append('rrp_min', rrpMin !== null ? String(rrpMin) : '');
      fd.append('rrp_max', rrpMax !== null ? String(rrpMax) : '');
      fd.append('ordine_preferenza_fornitore', id ? String(fornitori.find(f => f.id === id).ordine_preferenza_fornitore) : String(fornitori.length + 1));
      fd.append('attivo', attivo ? '1' : '0');
      fd.append('note', note);
      fd.append('no_prezzi', '1');
      fd.append('csrf_token', CSRF);

      try {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Salvataggio...';

        const res = await fetch(cfg.apiFornSave, { method: 'POST', body: fd });
        const resData = await res.json();
        if (!resData.ok) {
          alertBox.textContent = resData.error || "Errore nel salvataggio del fornitore.";
          alertBox.classList.remove('d-none');
          saveBtn.disabled = false;
          saveBtn.innerHTML = 'Salva Modifiche';
          return;
        }

        const savedFornId = resData.row.id;

        // 2. Salva o rimuovi gli scaglioni di prezzo via AJAX
        const currentDbPrezzi = prezziList.filter(p => p.prodotto_fornitore_id === savedFornId);
        
        // Trova quelli da eliminare (esistenti in DB ma non nel form)
        const toDelete = currentDbPrezzi.filter(dp => !scaglioni.some(s => s.id === dp.id));
        for (const dp of toDelete) {
          const df = new FormData();
          df.append('id', String(dp.id));
          df.append('prodotto_id', String(prodId));
          df.append('csrf_token', CSRF);
          await fetch(cfg.apiPrezzoDelete, { method: 'POST', body: df });
        }

        // Pulisci locale prezziList per questo fornitore
        prezziList = prezziList.filter(p => p.prodotto_fornitore_id !== savedFornId);

        // Salva/Inserisci gli scaglioni
        for (const sc of scaglioni) {
          const sf = new FormData();
          sf.append('id', String(sc.id));
          sf.append('prodotto_fornitore_id', String(savedFornId));
          sf.append('prodotto_id', String(prodId));
          sf.append('listino_acquisto_id', sc.listino_acquisto_id || '');
          sf.append('quantita_minima', String(sc.quantita_minima));
          sf.append('prezzo', String(sc.prezzo));
          sf.append('sconto_perc', String(sc.sconto_perc));
          sf.append('valido_dal', sc.valido_dal);
          sf.append('valido_al', sc.valido_al);
          sf.append('note', sc.note);
          sf.append('csrf_token', CSRF);

          const pres = await fetch(cfg.apiPrezzoSave, { method: 'POST', body: sf });
          const pdata = await pres.json();
          if (pdata.ok && pdata.row) {
            prezziList.push(pdata.row);
          }
        }

        // Rinfresca stato locale del fornitore
        const checkFornIdx = fornitori.findIndex(f => f.id === id);
        if (checkFornIdx > -1) {
          fornitori[checkFornIdx] = resData.row;
        } else {
          fornitori.push(resData.row);
        }

        closeDrawer();
        renderMasterTable();
        updateRrpBanner();
        validateMatrixPrices();

      } catch (e) {
        alertBox.textContent = e.message || "Errore di rete.";
        alertBox.classList.remove('d-none');
      } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = 'Salva Modifiche';
      }
    });

    addScaglioneBtn?.addEventListener('click', () => {
      addScaglioneRow(null);
    });

    btnToggleAllRrp?.addEventListener('click', () => {
      const isCollapsed = !allRrpDetails.classList.contains('show');
      if (isCollapsed) {
        allRrpTbody.innerHTML = '';
        fornitori.forEach(f => {
          const tr = document.createElement('tr');
          const isPref = (Number(f.ordine_preferenza_fornitore) === 1);
          tr.innerHTML = `
            <td><strong>${esc(f.nome_fornitore_rs)}</strong></td>
            <td>${f.rrp_min !== null ? parseFloat(f.rrp_min).toFixed(4) + ' €' : '—'}</td>
            <td>${f.rrp_max !== null ? parseFloat(f.rrp_max).toFixed(4) + ' €' : '—'}</td>
            <td>${isPref ? '<i class="fa-solid fa-star text-warning"></i>' : '—'}</td>
          `;
          allRrpTbody.appendChild(tr);
        });
        allRrpDetails.classList.add('show');
        btnToggleAllRrp.textContent = "Nascondi tutti i fornitori";
      } else {
        allRrpDetails.classList.remove('show');
        btnToggleAllRrp.textContent = "Mostra tutti i fornitori";
      }
    });

    /* ── Funzioni rendering ed interazioni ── */

    function renderMasterTable() {
      if (!masterTbody) return;
      masterTbody.innerHTML = '';
      if (fornitori.length === 0) {
        masterTbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">Nessun fornitore associato.</td></tr>`;
        return;
      }

      // Ordina per ordine_preferenza_fornitore ASC
      fornitori.sort((a,b) => (Number(a.ordine_preferenza_fornitore) || 99) - (Number(b.ordine_preferenza_fornitore) || 99));

      fornitori.forEach(f => {
        const tr = document.createElement('tr');
        const isPref = (Number(f.ordine_preferenza_fornitore) === 1);
        
        // Trova il prezzo base (quantita_minima = 0)
        const prezziForn = prezziList.filter(p => p.prodotto_fornitore_id === f.id);
        const basePrezzoRow = prezziForn.find(p => parseFloat(p.quantita_minima) === 0);
        const baseCosto = basePrezzoRow ? parseFloat(basePrezzoRow.prezzo).toFixed(4) + ' €' : '—';

        tr.innerHTML = `
          <td style="text-align:center;">
            <i class="pref-star fa-star ${isPref ? 'fa-solid active' : 'fa-regular'}" data-id="${f.id}" style="cursor:pointer;"></i>
          </td>
          <td>
            <div class="d-flex flex-column">
              <strong>${esc(f.nome_fornitore_rs)}</strong>
              ${f.nome_prodotto_fornitore ? `<span class="text-muted small">${esc(f.nome_prodotto_fornitore)}</span>` : ''}
            </div>
          </td>
          <td class="font-monospace">${esc(f.sku_fornitore || '—')}</td>
          <td>${baseCosto}</td>
          <td>${f.lead_time_giorni ? f.lead_time_giorni + ' gg' : '—'}</td>
          <td class="text-end">
            <button type="button" class="btn-qk btn-qk-secondary btn-qk-xs btn-edit-forn" data-id="${f.id}">
              <i class="fa-solid fa-pen-to-square"></i> Dettagli & Prezzi
            </button>
            <button type="button" class="btn-qk btn-qk-danger btn-qk-xs btn-delete-forn" data-id="${f.id}">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        `;

        tr.querySelector('.pref-star').addEventListener('click', () => setPreferito(f.id));
        tr.querySelector('.btn-edit-forn').addEventListener('click', () => openDrawer(f));
        tr.querySelector('.btn-delete-forn').addEventListener('click', () => deleteFornitore(f.id));

        masterTbody.appendChild(tr);
      });
    }

    async function setPreferito(fornId) {
      // Imposta localmente la stella
      fornitori.forEach(f => {
        f.ordine_preferenza_fornitore = (f.id === fornId) ? 1 : 2;
      });

      // Salva l'ordine di preferenza nel DB per tutti i fornitori associati
      for (const f of fornitori) {
        const fd = new FormData();
        fd.append('id', String(f.id));
        fd.append('prodotto_id', String(prodId));
        fd.append('fornitore_id', String(f.fornitore_id));
        fd.append('ordine_preferenza_fornitore', String(f.ordine_preferenza_fornitore));
        fd.append('attivo', String(f.attivo));
        fd.append('no_prezzi', '1');
        fd.append('csrf_token', CSRF);
        await fetch(cfg.apiFornSave, { method: 'POST', body: fd });
      }

      renderMasterTable();
      updateRrpBanner();
      validateMatrixPrices();
    }

    async function deleteFornitore(fornId) {
      if (!confirm("Sei sicuro di voler rimuovere l'associazione con questo fornitore e cancellare tutti i suoi scaglioni prezzo?")) return;

      const fd = new FormData();
      fd.append('id', String(fornId));
      fd.append('prodotto_id', String(prodId));
      fd.append('csrf_token', CSRF);

      try {
        const res = await fetch(cfg.apiFornDelete, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          fornitori = fornitori.filter(f => f.id !== fornId);
          prezziList = prezziList.filter(p => p.prodotto_fornitore_id !== fornId);
          renderMasterTable();
          updateRrpBanner();
          validateMatrixPrices();
        } else {
          alert("Errore nell'eliminazione: " + (data.error || "sconosciuto"));
        }
      } catch (e) {
        alert("Errore di rete.");
      }
    }

    async function openDrawer(f) {
      if (!alertBox) return;
      alertBox.classList.add('d-none');
      dwId.value = f.id;
      dwFornId.value = f.fornitore_id;
      dwFornLabel.value = f.nome_fornitore_rs;
      dwSku.value = f.sku_fornitore || '';
      dwNomeProd.value = f.nome_prodotto_fornitore || '';
      dwQtaMin.value = f.quantita_minima || '';
      dwLeadTime.value = f.lead_time_giorni || '';
      dwAttivo.checked = String(f.attivo) === '1';
      dwRrpMin.value = f.rrp_min !== null ? f.rrp_min : '';
      dwRrpMax.value = f.rrp_max !== null ? f.rrp_max : '';
      dwNote.value = f.note || '';

      // Carica i listini del fornitore
      listiniAcquisto = await loadListiniAcquisto(f.fornitore_id);

      // Popola scaglioni
      scaglioniTbody.innerHTML = '';
      const prezziForn = prezziList.filter(p => p.prodotto_fornitore_id === f.id);
      
      // Assicura che ci sia almeno lo scaglione 0
      if (prezziForn.length === 0 || !prezziForn.find(p => parseFloat(p.quantita_minima) === 0)) {
        addScaglioneRow({ id: 0, quantita_minima: 0, prezzo: 0, sconto_perc: 0 });
      }

      prezziForn.sort((a,b) => parseFloat(a.quantita_minima) - parseFloat(b.quantita_minima));
      prezziForn.forEach(p => {
        addScaglioneRow(p);
      });

      drawer?.classList.add('open');
      overlay?.classList.add('open');
    }

    function closeDrawer() {
      drawer?.classList.remove('open');
      overlay?.classList.remove('open');
    }

    function addScaglioneRow(p = null) {
      const tr = document.createElement('tr');
      tr.className = 'sc-row';
      const isBase = p && parseFloat(p.quantita_minima) === 0;
      
      const rowId = p ? p.id : 0;
      tr.dataset.rowId = rowId;

      const qta = p ? p.quantita_minima : 0;
      const prezzo = p ? p.prezzo : 0;
      const sconto = p ? p.sconto_perc : 0;
      const listinoId = p ? p.listino_acquisto_id : '';
      const dal = p ? p.valido_dal : '';
      const al = p ? p.valido_al : '';
      const note = p ? p.note : '';

      // Opzioni del select listini
      let optsHtml = '<option value="">Base (nessun listino)</option>';
      listiniAcquisto.forEach(l => {
        optsHtml += `<option value="${l.id}" ${String(l.id) === String(listinoId) ? 'selected' : ''}>${esc(l.nome_listino)}</option>`;
      });

      tr.innerHTML = `
        <td>
          <select class="form-select form-select-sm sc-listino" style="font-size:0.75rem;">
            ${optsHtml}
          </select>
        </td>
        <td>
          <input type="number" class="form-control form-control-sm sc-qta font-monospace" 
                 value="${qta}" min="0" step="0.001" ${isBase ? 'readonly' : ''} style="width:75px; font-size:0.75rem;">
        </td>
        <td>
          <input type="number" class="form-control form-control-sm sc-prezzo font-monospace" 
                 value="${prezzo}" min="0" step="0.0001" style="width:90px; font-size:0.75rem;">
        </td>
        <td>
          <input type="number" class="form-control form-control-sm sc-sconto font-monospace" 
                 value="${sconto}" min="0" max="100" step="0.01" style="width:65px; font-size:0.75rem;" placeholder="0">
        </td>
        <td style="text-align:right; white-space:nowrap;">
          <button type="button" class="btn btn-xs btn-outline-secondary btn-toggle-extra" style="padding:1px 5px; font-size:0.7rem;"><i class="fa-solid fa-ellipsis"></i></button>
          ${isBase ? '' : '<button type="button" class="btn btn-xs btn-link text-danger btn-del-scaglione" style="padding:1px 3px;"><i class="fa-solid fa-trash"></i></button>'}
        </td>
      `;

      // Riga extra collassata per date e note
      const extraTr = document.createElement('tr');
      extraTr.className = 'sc-extra-row d-none';
      extraTr.innerHTML = `
        <td colspan="5" class="bg-light p-2" style="border-top:none;">
          <div class="row g-2">
            <div class="col-4">
              <label class="small text-muted mb-0" style="font-size:0.65rem;">Valido dal</label>
              <input type="date" class="form-control form-control-sm sc-dal" value="${esc(dal)}" style="font-size:0.7rem; padding: 2px 4px;">
            </div>
            <div class="col-4">
              <label class="small text-muted mb-0" style="font-size:0.65rem;">Valido al</label>
              <input type="date" class="form-control form-control-sm sc-al" value="${esc(al)}" style="font-size:0.7rem; padding: 2px 4px;">
            </div>
            <div class="col-4">
              <label class="small text-muted mb-0" style="font-size:0.65rem;">Note</label>
              <input type="text" class="form-control form-control-sm sc-note" value="${esc(note)}" placeholder="Note..." style="font-size:0.7rem; padding: 2px 4px;">
            </div>
          </div>
        </td>
      `;

      tr.querySelector('.btn-toggle-extra').addEventListener('click', () => {
        extraTr.classList.toggle('d-none');
      });

      if (!isBase) {
        tr.querySelector('.btn-del-scaglione').addEventListener('click', () => {
          tr.remove();
          extraTr.remove();
        });
      }

      scaglioniTbody.appendChild(tr);
      scaglioniTbody.appendChild(extraTr);
    }

    function updateRrpBanner() {
      if (!rrpBannerContent) return;

      const prefForn = fornitori.find(f => Number(f.ordine_preferenza_fornitore) === 1);
      if (!prefForn) {
        rrpBannerContent.innerHTML = `<span class="text-danger">Nessun fornitore preferito impostato. Stella [★] richiesta per identificare il costo base.</span>`;
        return;
      }

      const minVal = prefForn.rrp_min !== null ? parseFloat(prefForn.rrp_min).toFixed(4) + ' €' : 'Non specificato';
      const maxVal = prefForn.rrp_max !== null ? parseFloat(prefForn.rrp_max).toFixed(4) + ' €' : 'Non specificato';

      rrpBannerContent.innerHTML = `
        <strong>${esc(prefForn.nome_fornitore_rs)}</strong> ➔ 
        Min RRP: <span class="badge bg-light text-dark border font-monospace">${minVal}</span> | 
        Max RRP: <span class="badge bg-light text-dark border font-monospace">${maxVal}</span>
      `;
    }

    function validateMatrixPrices() {
      if (!matrixTable) return;
      const cells = matrixTable.querySelectorAll('.matrix-cell-wrapper');
      cells.forEach(cell => {
        validateSingleCell(cell);
      });
    }

    function validateSingleCell(cell) {
      const input = cell.querySelector('.cell-price-input');
      if (!input) return;

      // Reset classi
      cell.classList.remove('warning', 'error-cost');
      cell.querySelectorAll('.test-warning-tooltip').forEach(el => el.remove());

      const val = parseFloat(input.value);
      if (isNaN(val)) return;

      const prefForn = fornitori.find(f => Number(f.ordine_preferenza_fornitore) === 1);
      
      // Costo preferito di acquisto a Qty 0+
      let costoPreferito = 0;
      if (prefForn) {
        const prezziForn = prezziList.filter(p => p.prodotto_fornitore_id === prefForn.id);
        const basePrezzoRow = prezziForn.find(p => parseFloat(p.quantita_minima) === 0);
        if (basePrezzoRow) {
          costoPreferito = parseFloat(basePrezzoRow.prezzo);
        }
      }

      // 1. Controllo Sotto Costo (Errore Matematico) - su fornitore preferenziale
      if (val <= costoPreferito) {
        cell.classList.add('error-cost');
        addTooltip(cell, `Sotto costo! Costo acquisto preferito: ${costoPreferito.toFixed(4)} €`, 'error-cost-tooltip');
        return;
      }

      // Ottieni tutti i vincoli RRP di tutti i fornitori per la Trasparenza Contestuale
      const rrpConstraints = fornitori.map(f => ({
        nome: f.nome_fornitore_rs,
        min: f.rrp_min !== null ? parseFloat(f.rrp_min) : null,
        max: f.rrp_max !== null ? parseFloat(f.rrp_max) : null
      })).filter(c => c.min !== null || c.max !== null);

      // 2. Controllo RRP (Soft Warnings) - contro TUTTI i fornitori
      for (const c of rrpConstraints) {
        if (c.min !== null && val < c.min) {
          cell.classList.add('warning');
          addTooltip(cell, `Sotto il Min RRP consigliato da ${c.nome} (${c.min.toFixed(4)} €)`, 'test-warning-tooltip');
          break; // Mostra il primo warning che capita
        }
        if (c.max !== null && val > c.max) {
          cell.classList.add('warning');
          addTooltip(cell, `Sopra il Max RRP consigliato da ${c.nome} (${c.max.toFixed(4)} €)`, 'test-warning-tooltip');
          break;
        }
      }
    }

    if (matrixTable) {
      matrixTable.addEventListener('input', (e) => {
        const input = e.target.closest('.cell-price-input');
        if (input) {
          validateSingleCell(input.closest('.matrix-cell-wrapper'));
        }
      });
      matrixTable.addEventListener('change', (e) => {
        const input = e.target.closest('.cell-price-input');
        if (input) {
          validateSingleCell(input.closest('.matrix-cell-wrapper'));
        }
      });
    }

    function addTooltip(cell, text, className) {
      const tooltip = document.createElement('div');
      tooltip.className = `test-warning-tooltip ${className}`;
      tooltip.style.position = 'absolute';
      tooltip.style.bottom = '100%';
      tooltip.style.left = '50%';
      tooltip.style.transform = 'translateX(-50%)';
      tooltip.style.background = className === 'error-cost-tooltip' ? '#ef4444' : '#f97316';
      tooltip.style.color = '#fff';
      tooltip.style.fontSize = '0.7rem';
      tooltip.style.padding = '2px 6px';
      tooltip.style.borderRadius = '4px';
      tooltip.style.whiteSpace = 'nowrap';
      tooltip.style.zIndex = '10';
      tooltip.style.marginBottom = '4px';
      tooltip.style.pointerEvents = 'none';
      tooltip.textContent = text;
      cell.appendChild(tooltip);
    }

    function esc(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    // Ascolta il custom event qk:forn-saved (generato dal modale autocomplete)
    document.addEventListener('qk:forn-saved', function (e) {
      const row = e.detail?.row;
      if (!row) return;

      const pfId = Number(row.id);
      const idx  = fornitori.findIndex(f => Number(f.id) === pfId);

      if (idx >= 0) {
        fornitori[idx] = row;
      } else {
        fornitori.push(row);
      }
      
      renderMasterTable();
      updateRrpBanner();
      validateMatrixPrices();

      // Apri immediatamente il Drawer per configurare prezzi/RRP
      openDrawer(row);
    });

    // Ascolta variazioni sul prezzo di acquisto/preferred per aggiornare la validazione matrice
    document.addEventListener('qk:forn-deleted', function (e) {
      const id = Number(e.detail?.id);
      if (!id) return;
      fornitori = fornitori.filter(f => Number(f.id) !== id);
      prezziList = prezziList.filter(p => p.prodotto_fornitore_id !== id);
      renderMasterTable();
      updateRrpBanner();
      validateMatrixPrices();
    });

  });
})();
