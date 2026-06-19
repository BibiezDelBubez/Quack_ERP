/**
 * products-suppliers-test.js
 * ─────────────────────────────────────────────────────────────────────────────
 * LAB TEST UX: Gestione Master-Detail con Drawer Laterale per Fornitori/Acquisto
 * ed integrazione/validazione dei prezzi consigliati (RRP) sulla matrice di vendita.
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.QkPF;
    if (!cfg || !cfg.isEdit) return; // solo in modifica

    const prodId = cfg.prodottoId;
    const CSRF = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    // Riferimenti DOM per Fornitori (Drawer)
    const masterTbody  = document.getElementById('master-fornitori-tbody');
    const drawer       = document.getElementById('test-fornitore-drawer');
    const overlay      = document.getElementById('test-drawer-overlay');
    const closeBtn     = document.getElementById('btn-close-drawer');
    const cancelBtn    = document.getElementById('btn-cancel-drawer');
    const saveBtn      = document.getElementById('btn-save-drawer');
    const alertBox     = document.getElementById('drawer-alert');
    const addFornBtn   = document.getElementById('btn-test-add-fornitore');

    // Elementi Form Drawer
    const formForn     = document.getElementById('drawer-fornitore-form');
    const dwId         = document.getElementById('dw-id');
    const dwFornId     = document.getElementById('dw-fornitore-id');
    const dwFornLabel  = document.getElementById('dw-fornitore-label');
    const dwSku        = document.getElementById('dw-sku');
    const dwLeadTime   = document.getElementById('dw-lead-time');
    const dwRrpMin     = document.getElementById('dw-rrp-min');
    const dwRrpMax     = document.getElementById('dw-rrp-max');
    const scaglioniTbody = document.getElementById('drawer-scaglioni-tbody');
    const addScaglioneBtn = document.getElementById('dw-btn-add-scaglione');

    // Modale di Associazione Fornitore
    const modalSelectForn = new bootstrap.Modal(document.getElementById('modal-select-fornitore'));
    const confirmAddFornBtn = document.getElementById('btn-confirm-add-fornitore');
    const selNewFornitore = document.getElementById('sel-new-fornitore-id');

    // Riferimenti DOM per Vendite (RRP Banner)
    const rrpBannerContent = document.getElementById('rrp-banner-content');
    const btnToggleAllRrp   = document.getElementById('btn-toggle-all-rrp');
    const allRrpDetails     = document.getElementById('all-rrp-details');
    const allRrpTbody       = document.getElementById('all-rrp-tbody');

    // Matrice Vendite
    const matrixTable = document.getElementById('pricing-matrix-table');

    /* ── Stato locale ── */
    let fornitori = [...(cfg.fornitori || [])];
    let prezziList = [...(cfg.tuttePrezzi || [])]; // scaglioni d'acquisto dei fornitori

    /* ── Inizializzazione ── */
    renderMasterTable();
    updateRrpBanner();
    validateMatrixPrices();

    // Eventi Drawer
    closeBtn.addEventListener('click', closeDrawer);
    cancelBtn.addEventListener('click', closeDrawer);
    overlay.addEventListener('click', closeDrawer);

    // Evento aggiunta fornitore
    addFornBtn.addEventListener('click', () => {
      selNewFornitore.value = "";
      modalSelectForn.show();
    });

    confirmAddFornBtn.addEventListener('click', async () => {
      const selectedFornId = selNewFornitore.value;
      if (!selectedFornId) return;

      const option = selNewFornitore.options[selNewFornitore.selectedIndex];
      const ragioneSociale = option.text;

      modalSelectForn.hide();

      // Apri Drawer per nuova associazione
      openDrawer({
        id: 0,
        fornitore_id: parseInt(selectedFornId),
        ragione_sociale: ragioneSociale,
        sku_fornitore: '',
        lead_time: 7,
        ordine_preferenza: fornitori.length + 1,
        attivo: 1,
        rrp_min: null,
        rrp_max: null
      });
    });

    // Salva Drawer
    saveBtn.addEventListener('click', async () => {
      alertBox.classList.add('d-none');
      const id = parseInt(dwId.value) || 0;
      const fornitoreId = parseInt(dwFornId.value);
      const sku = dwSku.value.trim();
      const leadTime = parseInt(dwLeadTime.value) || 0;
      const rrpMin = dwRrpMin.value ? parseFloat(dwRrpMin.value) : null;
      const rrpMax = dwRrpMax.value ? parseFloat(dwRrpMax.value) : null;

      // Leggi scaglioni dal drawer
      const scaglioni = [];
      const rows = scaglioniTbody.querySelectorAll('tr');
      for (const row of rows) {
        const qtaInput = row.querySelector('.sc-qta');
        const prezzoInput = row.querySelector('.sc-prezzo');
        const qta = parseFloat(qtaInput.value);
        const prezzo = parseFloat(prezzoInput.value);

        if (isNaN(qta) || isNaN(prezzo)) {
          alertBox.textContent = "Quantità e Prezzo d'acquisto devono essere numeri validi.";
          alertBox.classList.remove('d-none');
          return;
        }
        scaglioni.push({ quantita_minima: qta, prezzo_unitario: prezzo });
      }

      // 1. Salva metadati fornitore via AJAX
      const fd = new FormData();
      fd.append('id', id ? String(id) : '');
      fd.append('prodotto_id', String(prodId));
      fd.append('fornitore_id', String(fornitoreId));
      fd.append('sku_fornitore', sku);
      fd.append('lead_time', String(leadTime));
      fd.append('rrp_min', rrpMin !== null ? String(rrpMin) : '');
      fd.append('rrp_max', rrpMax !== null ? String(rrpMax) : '');
      fd.append('ordine_preferenza', id ? String(fornitori.find(f => f.id === id).ordine_preferenza) : String(fornitori.length + 1));
      fd.append('attivo', '1');
      fd.append('csrf_token', CSRF);

      try {
        const res = await fetch(cfg.apiFornSave, { method: 'POST', body: fd });
        const resData = await res.json();
        if (!resData.ok) {
          alertBox.textContent = resData.error || "Errore nel salvataggio del fornitore.";
          alertBox.classList.remove('d-none');
          return;
        }

        const savedFornId = resData.id;

        // 2. Salva o rimuovi gli scaglioni di prezzo via AJAX
        // Rimuovi vecchi prezzi locali
        const oldPrezzi = prezziList.filter(p => p.prodotto_fornitore_id === savedFornId);
        for (const op of oldPrezzi) {
          const df = new FormData();
          df.append('riga_id', String(op.id));
          df.append('csrf_token', CSRF);
          await fetch(cfg.apiPrezzoDelete, { method: 'POST', body: df });
        }
        prezziList = prezziList.filter(p => p.prodotto_fornitore_id !== savedFornId);

        // Inserisci i nuovi scaglioni
        for (const sc of scaglioni) {
          const sf = new FormData();
          sf.append('prodotto_fornitore_id', String(savedFornId));
          sf.append('quantita_minima', String(sc.quantita_minima));
          sf.append('prezzo_unitario', String(sc.prezzo_unitario));
          sf.append('csrf_token', CSRF);

          const pres = await fetch(cfg.apiPrezzoSave, { method: 'POST', body: sf });
          const pdata = await pres.json();
          if (pdata.ok && pdata.prezzo) {
            prezziList.push(pdata.prezzo);
          }
        }

        // Ricarica i dati dei fornitori per riflettere le modifiche
        // In un ERP reale caricheremmo via GET, qui aggiorniamo lo stato locale simulato
        const checkFornIdx = fornitori.findIndex(f => f.id === id);
        const updatedFornObject = {
          id: savedFornId,
          fornitore_id: fornitoreId,
          ragione_sociale: dwFornLabel.value,
          sku_fornitore: sku,
          lead_time: leadTime,
          ordine_preferenza: id ? fornitori[checkFornIdx].ordine_preferenza : fornitori.length + 1,
          attivo: 1,
          rrp_min: rrpMin,
          rrp_max: rrpMax
        };

        if (checkFornIdx > -1) {
          fornitori[checkFornIdx] = updatedFornObject;
        } else {
          fornitori.push(updatedFornObject);
        }

        closeDrawer();
        renderMasterTable();
        updateRrpBanner();
        validateMatrixPrices();

      } catch (e) {
        alertBox.textContent = e.message || "Errore di rete.";
        alertBox.classList.remove('d-none');
      }
    });

    // Aggiungi riga scaglione nel drawer
    addScaglioneBtn.addEventListener('click', () => {
      addScaglioneRow(0, 0);
    });

    // Toggle all RRP details
    btnToggleAllRrp.addEventListener('click', () => {
      const isCollapsed = !allRrpDetails.classList.contains('show');
      if (isCollapsed) {
        // Popola la tabella comparativa
        allRrpTbody.innerHTML = '';
        fornitori.forEach(f => {
          const tr = document.createElement('tr');
          const isPref = (f.ordine_preferenza === 1);
          tr.innerHTML = `
            <td><strong>${esc(f.ragione_sociale)}</strong></td>
            <td>${f.rrp_min !== null ? parseFloat(f.rrp_min).toFixed(2) + ' €' : '—'}</td>
            <td>${f.rrp_max !== null ? parseFloat(f.rrp_max).toFixed(2) + ' €' : '—'}</td>
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
      masterTbody.innerHTML = '';
      if (fornitori.length === 0) {
        masterTbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">Nessun fornitore associato.</td></tr>`;
        return;
      }

      // Ordina per ordine_preferenza ASC
      fornitori.sort((a,b) => a.ordine_preferenza - b.ordine_preferenza);

      fornitori.forEach(f => {
        const tr = document.createElement('tr');
        const isPref = (f.ordine_preferenza === 1);
        
        // Trova il prezzo base (quantita_minima = 0)
        const prezziForn = prezziList.filter(p => p.prodotto_fornitore_id === f.id);
        const basePrezzoRow = prezziForn.find(p => parseFloat(p.quantita_minima) === 0);
        const baseCosto = basePrezzoRow ? parseFloat(basePrezzoRow.prezzo_unitario).toFixed(2) + ' €' : '—';

        tr.innerHTML = `
          <td style="text-align:center;">
            <i class="pref-star fa-star ${isPref ? 'fa-solid active' : 'fa-regular'}" data-id="${f.id}"></i>
          </td>
          <td><strong>${esc(f.ragione_sociale)}</strong></td>
          <td class="font-monospace">${esc(f.sku_fornitore || '—')}</td>
          <td>${baseCosto}</td>
          <td>${f.lead_time} gg</td>
          <td class="text-end">
            <button type="button" class="btn-qk btn-qk-secondary btn-qk-xs btn-edit-forn" data-id="${f.id}">
              <i class="fa-solid fa-pen-to-square"></i> Modifica Prezzi
            </button>
            <button type="button" class="btn-qk btn-qk-danger btn-qk-xs btn-delete-forn" data-id="${f.id}">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        `;

        // Associa eventi
        tr.querySelector('.pref-star').addEventListener('click', () => setPreferito(f.id));
        tr.querySelector('.btn-edit-forn').addEventListener('click', () => openDrawer(f));
        tr.querySelector('.btn-delete-forn').addEventListener('click', () => deleteFornitore(f.id));

        masterTbody.appendChild(tr);
      });
    }

    async function setPreferito(fornId) {
      // Imposta localmente la stella
      fornitori.forEach(f => {
        f.ordine_preferenza = (f.id === fornId) ? 1 : 2;
      });

      // Salva l'ordine di preferenza nel DB per tutti i fornitori associati
      // In un'app reale invieremmo una chiamata bulk o singole chiamate API
      for (const f of fornitori) {
        const fd = new FormData();
        fd.append('id', String(f.id));
        fd.append('prodotto_id', String(prodId));
        fd.append('fornitore_id', String(f.fornitore_id));
        fd.append('ordine_preferenza', String(f.ordine_preferenza));
        fd.append('attivo', '1');
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

    function openDrawer(f) {
      alertBox.classList.add('d-none');
      dwId.value = f.id;
      dwFornId.value = f.fornitore_id;
      dwFornLabel.value = f.ragione_sociale;
      dwSku.value = f.sku_fornitore || '';
      dwLeadTime.value = f.lead_time || 7;
      dwRrpMin.value = f.rrp_min !== null ? f.rrp_min : '';
      dwRrpMax.value = f.rrp_max !== null ? f.rrp_max : '';

      // Popola scaglioni
      scaglioniTbody.innerHTML = '';
      const prezziForn = prezziList.filter(p => p.prodotto_fornitore_id === f.id);
      
      // Assicura che ci sia almeno lo scaglione 0
      if (prezziForn.length === 0 || !prezziForn.find(p => parseFloat(p.quantita_minima) === 0)) {
        addScaglioneRow(0, 0);
      }

      prezziForn.sort((a,b) => parseFloat(a.quantita_minima) - parseFloat(b.quantita_minima));
      prezziForn.forEach(p => {
        addScaglioneRow(parseFloat(p.quantita_minima), parseFloat(p.prezzo_unitario));
      });

      drawer.classList.add('open');
      overlay.classList.add('open');
    }

    function closeDrawer() {
      drawer.classList.remove('open');
      overlay.classList.remove('open');
    }

    function addScaglioneRow(qta = 0, prezzo = 0) {
      const tr = document.createElement('tr');
      const isBase = (qta === 0);

      tr.innerHTML = `
        <td>
          <input type="number" class="form-control form-control-sm sc-qta font-monospace" 
                 value="${qta}" min="0" step="0.001" ${isBase ? 'readonly' : ''} style="width:100px;">
        </td>
        <td>
          <input type="number" class="form-control form-control-sm sc-prezzo font-monospace" 
                 value="${prezzo}" min="0" step="0.0001" style="width:120px;">
        </td>
        <td style="text-align:right;">
          ${isBase ? '' : '<button type="button" class="btn btn-xs btn-link text-danger btn-del-scaglione"><i class="fa-solid fa-trash"></i></button>'}
        </td>
      `;

      if (!isBase) {
        tr.querySelector('.btn-del-scaglione').addEventListener('click', () => tr.remove());
      }

      scaglioniTbody.appendChild(tr);
    }

    function updateRrpBanner() {
      if (!rrpBannerContent) return;

      const prefForn = fornitori.find(f => f.ordine_preferenza === 1);
      if (!prefForn) {
        rrpBannerContent.innerHTML = `<span class="text-danger">Nessun fornitore preferito impostato. Stella [★] richiesta.</span>`;
        return;
      }

      const minVal = prefForn.rrp_min !== null ? parseFloat(prefForn.rrp_min).toFixed(2) + ' €' : 'Non specificato';
      const maxVal = prefForn.rrp_max !== null ? parseFloat(prefForn.rrp_max).toFixed(2) + ' €' : 'Non specificato';

      rrpBannerContent.innerHTML = `
        <strong>${esc(prefForn.ragione_sociale)}</strong> ➔ 
        Min RRP: <span class="badge bg-light text-dark border font-monospace">${minVal}</span> | 
        Max RRP: <span class="badge bg-light text-dark border font-monospace">${maxVal}</span>
      `;
    }

    function validateMatrixPrices() {
      if (!matrixTable) return;

      const prefForn = fornitori.find(f => f.ordine_preferenza === 1);
      
      // Estrai costo preferito di acquisto a Qty 0+
      let costoPreferito = 0;
      if (prefForn) {
        const prezziForn = prezziList.filter(p => p.prodotto_fornitore_id === prefForn.id);
        const basePrezzoRow = prezziForn.find(p => parseFloat(p.quantita_minima) === 0);
        if (basePrezzoRow) {
          costoPreferito = parseFloat(basePrezzoRow.prezzo_unitario);
        }
      }

      // Ottieni limiti RRP dal preferito
      const rrpMin = prefForn && prefForn.rrp_min !== null ? parseFloat(prefForn.rrp_min) : null;
      const rrpMax = prefForn && prefForn.rrp_max !== null ? parseFloat(prefForn.rrp_max) : null;

      // Seleziona tutte le celle input dei listini
      const cells = matrixTable.querySelectorAll('.matrix-cell-wrapper');
      cells.forEach(cell => {
        const input = cell.querySelector('.cell-price-input');
        if (!input) return;

        // Reset classi
        cell.classList.remove('warning', 'error-cost');

        // Validazione al cambio valore (oppure inline)
        const validateCell = () => {
          const val = parseFloat(input.value);
          if (isNaN(val)) return;

          // Rimuovi vecchi warning prima del controllo
          cell.classList.remove('warning', 'error-cost');
          const oldTooltip = cell.querySelector('.test-warning-tooltip');
          if (oldTooltip) oldTooltip.remove();

          // 1. Controllo Margine (Sotto costo)
          if (val <= costoPreferito) {
            cell.classList.add('error-cost');
            addTooltip(cell, `Prezzo sotto costo! Costo acquisto preferito: ${costoPreferito.toFixed(2)} €`, 'error-cost-tooltip');
            return;
          }

          // 2. Controllo RRP
          if (rrpMin !== null && val < rrpMin) {
            cell.classList.add('warning');
            addTooltip(cell, `Prezzo inferiore al Min RRP consigliato dal fornitore preferito (${rrpMin.toFixed(2)} €)`, 'test-warning-tooltip');
          } else if (rrpMax !== null && val > rrpMax) {
            cell.classList.add('warning');
            addTooltip(cell, `Prezzo superiore al Max RRP consigliato dal fornitore preferito (${rrpMax.toFixed(2)} €)`, 'test-warning-tooltip');
          }
        };

        input.addEventListener('input', validateCell);
        input.addEventListener('change', validateCell);
        validateCell(); // esegui al caricamento
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
  });
})();
