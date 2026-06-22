/**
 * products-suppliers.js
 * ─────────────────────────────────────────────────────────────────────────────
 * DEFINITIVE STATE-OF-THE-ART SUPPLIER & PRICING MANAGEMENT
 * Flat Grid Implementation (Excel-like) for Purchase Prices.
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.QkPF;
    if (!cfg) return;

    const prodId = cfg.prodottoId;
    const CSRF = cfg.csrfToken || (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    const masterTbody = document.getElementById('master-fornitori-tbody');
    const btnAddFlat = document.getElementById('btn-add-fornitore-flat');

    if (!masterTbody) return;

    /* ── Stato locale ── */
    // Costruiamo una griglia piatta dai dati gerarchici
    let fornitori = [...(cfg.fornitori || [])];
    let prezziList = [...(cfg.tuttePrezzi || [])];
    let flatRows = [];
    let currentUomMode = 'stock';

    function buildFlatRows() {
      flatRows = [];
      fornitori.forEach(f => {
        const prezzi = prezziList.filter(p => p.prodotto_fornitore_id === f.id);
        if (prezzi.length === 0) {
          // Riga fornitore senza prezzi
          flatRows.push(createFlatRowObj(f, null));
        } else {
          // Una riga per ogni scaglione
          prezzi.forEach(p => {
            flatRows.push(createFlatRowObj(f, p));
          });
        }
      });
      // Sort by Fornitore Name, then Qta Min
      flatRows.sort((a, b) => {
        if (a.nome_fornitore_rs !== b.nome_fornitore_rs) {
          return (a.nome_fornitore_rs || '').localeCompare(b.nome_fornitore_rs || '');
        }
        return (a.quantita_minima || 0) - (b.quantita_minima || 0);
      });
    }

    function createFlatRowObj(f, p) {
      return {
        // ID virtuale per tracking UI
        _uid: Math.random().toString(36).substr(2, 9),
        // Dati Fornitore (Padre)
        prodotto_fornitore_id: f.id || null,
        fornitore_id: f.fornitore_id || null,
        nome_fornitore_rs: f.nome_fornitore_rs || '',
        sku_fornitore: f.sku_fornitore || '',
        nome_prodotto_fornitore: f.nome_prodotto_fornitore || '',
        lead_time_giorni: f.lead_time_giorni || 0,
        ordine_preferenza_fornitore: f.ordine_preferenza_fornitore || 2,
        unita_misura_acquisto_id: f.unita_misura_acquisto_id || cfg.uomStockId || null,
        // Dati Prezzo (Figlio)
        prezzo_id: p ? p.id : null,
        quantita_minima: p ? parseFloat(p.quantita_minima) : 0,
        prezzo: p ? parseFloat(p.prezzo) : null,
        sconto_perc: p ? parseFloat(p.sconto_perc) : 0,
        
        isDirty: false,
        isSaving: false
      };
    }

    buildFlatRows();
    renderMasterTable();

    btnAddFlat?.addEventListener('click', (e) => {
      e.preventDefault();
      flatRows.push({
        _uid: Math.random().toString(36).substr(2, 9),
        prodotto_fornitore_id: null,
        fornitore_id: null,
        nome_fornitore_rs: '',
        sku_fornitore: '',
        nome_prodotto_fornitore: '',
        lead_time_giorni: 0,
        ordine_preferenza_fornitore: 2,
        unita_misura_acquisto_id: currentUomMode === 'stock' ? (cfg.uomStockId || null) : (cfg.uomCommercialId || cfg.uomStockId || null),
        prezzo_id: null,
        quantita_minima: 0,
        prezzo: null,
        sconto_perc: 0,
        valido_dal: '',
        valido_al: '',
        isDirty: true,
        isNew: true
      });
      renderMasterTable();
    });

    function renderMasterTable() {
      masterTbody.innerHTML = '';
      
      const activeRows = flatRows.filter(r => !r._isDeletedSoft);
      if (activeRows.length === 0) {
        masterTbody.innerHTML = `<tr><td colspan="11" class="text-center py-4 text-muted">Nessun costo di acquisto impostato.</td></tr>`;
        return;
      }

      activeRows.forEach((row, index) => {
        const tr = document.createElement('tr');
        tr.dataset.uid = row._uid;
        
        const isPref = Number(row.ordine_preferenza_fornitore) === 1;

        // Perform display conversion if necessary
        let displayQta = row.quantita_minima;
        let displayPrezzo = row.prezzo;
        const qFactor = parseFloat(cfg.quantitaContenuto) || 1;
        const rowUomId = row.unita_misura_acquisto_id || cfg.uomStockId;
        
        if (currentUomMode === 'stock') {
          // If the row is commercial, convert Commercial -> Stock
          if (rowUomId == cfg.uomCommercialId && qFactor > 1) {
            const conv = window.qkConvertUom({ value: row.quantita_minima, price: row.prezzo, qFactor, targetMode: 'stock' });
            displayQta = conv.value;
            displayPrezzo = conv.price;
          }
        } else {
          // If the row is stock, convert Stock -> Commercial
          if (rowUomId == cfg.uomStockId && qFactor > 1) {
            const conv = window.qkConvertUom({ value: row.quantita_minima, price: row.prezzo, qFactor, targetMode: 'commercial' });
            displayQta = conv.value;
            displayPrezzo = conv.price;
          }
        }

        tr.innerHTML = `
          <td class="text-center flat-grid-td" data-col="pref">
            <i class="pref-star fa-star ${isPref ? 'fa-solid active text-warning' : 'fa-regular'}" style="cursor:pointer; padding: 8px;"></i>
          </td>
          <td class="flat-grid-td" data-col="fornitore">
            <div class="qk-m2o-wrap m2o-flat-${row._uid}" style="height: 100%;">
              <div class="qk-m2o-input-row" style="height: 100%;">
                <input type="text" class="qk-m2o-input flat-grid-input m2o-input-${row._uid}" placeholder="Cerca fornitore..." value="${esc(row.nome_fornitore_rs)}" style="padding-left: 8px;">
                <input type="hidden" class="m2o-hidden-${row._uid}" value="${row.fornitore_id || ''}">
              </div>
              <div class="qk-m2o-dropdown m2o-dd-${row._uid}"></div>
            </div>
          </td>
          <td class="flat-grid-td" data-col="nome_prod">
            <input type="text" class="flat-grid-input inp-nome-prod" value="${esc(row.nome_prodotto_fornitore || '')}" placeholder="Nome Prod. Forn.">
          </td>
          <td class="flat-grid-td" data-col="sku">
            <input type="text" class="flat-grid-input inp-sku font-monospace" value="${esc(row.sku_fornitore)}" placeholder="SKU">
          </td>
          <td class="flat-grid-td" data-col="prezzo">
             <input type="number" class="flat-grid-input text-end inp-prezzo fw-bold" value="${displayPrezzo !== null ? displayPrezzo : ''}" step="0.0001" min="0" placeholder="0.0000">
          </td>
          <td class="flat-grid-td" data-col="qta_min">
            <input type="number" class="flat-grid-input text-end inp-qta font-monospace" value="${displayQta}" min="0" step="0.001">
          </td>
          <td class="flat-grid-td text-center" data-col="uom" style="line-height: 40px; font-size: 0.85rem; color: var(--qk-text-primary);">
             <span>${currentUomMode === 'stock' ? esc(cfg.uomStock) : esc(cfg.uomCommercial || cfg.uomStock)}</span>
             <input type="hidden" class="inp-uom" value="${rowUomId}">
          </td>
          <td class="flat-grid-td" data-col="sconto">
             <input type="number" class="flat-grid-input text-end inp-sconto" value="${row.sconto_perc || 0}" step="0.01" min="0">
          </td>
          <td class="flat-grid-td" data-col="lead_time">
            <input type="number" class="flat-grid-input text-center inp-lead" value="${row.lead_time_giorni}" min="0">
          </td>
          <td class="flat-grid-td" data-col="valido_dal">
            <input type="date" class="flat-grid-input inp-vdal" value="${esc(row.valido_dal || '')}">
          </td>
          <td class="flat-grid-td" data-col="valido_al">
            <input type="date" class="flat-grid-input inp-val" value="${esc(row.valido_al || '')}">
          </td>
          <td class="text-center flat-grid-td" data-col="azioni" style="padding: 8px !important;">
            <button type="button" class="btn-qk btn-qk-danger btn-qk-xs btn-delete-row" title="Elimina scaglione">
              <i class="fa-solid fa-trash"></i>
            </button>
            <i class="fa-solid fa-spinner fa-spin d-none icon-saving ms-1 text-primary"></i>
            <i class="fa-solid fa-check d-none icon-saved ms-1 text-success"></i>
          </td>
        `;

        masterTbody.appendChild(tr);

        // Initialize M2O always so it's editable
        if (typeof QkM2O !== 'undefined') {
          setTimeout(() => {
            new QkM2O({
              inputEl: tr.querySelector(`.m2o-input-${row._uid}`),
              hiddenEl: tr.querySelector(`.m2o-hidden-${row._uid}`),
              dropdownEl: tr.querySelector(`.m2o-dd-${row._uid}`),
              apiUrl: cfg.apiSearchForn,
              labelKey: 'ragione_sociale',
              columns: [{ key: 'ragione_sociale', label: 'Ragione sociale' }],
              onSelect: (id, data) => {
                row.fornitore_id = id;
                row.nome_fornitore_rs = data.ragione_sociale;
                
                // AUTOFILL LOGIC
                const existing = flatRows.find(r => r.fornitore_id == id && r._uid !== row._uid);
                if (existing) {
                   row.prodotto_fornitore_id = existing.prodotto_fornitore_id;
                   row.sku_fornitore = existing.sku_fornitore;
                   row.nome_prodotto_fornitore = existing.nome_prodotto_fornitore;
                   row.lead_time_giorni = existing.lead_time_giorni;
                   row.unita_misura_acquisto_id = existing.unita_misura_acquisto_id;
                   tr.querySelector('.inp-sku').value = row.sku_fornitore || '';
                   tr.querySelector('.inp-nome-prod').value = row.nome_prodotto_fornitore || '';
                   tr.querySelector('.inp-lead').value = row.lead_time_giorni || 0;
                   const inpUom = tr.querySelector('.inp-uom');
                   if (inpUom) inpUom.value = row.unita_misura_acquisto_id || cfg.uomStockId;
                }
                
                triggerSave(row, tr);
              }
            });
          }, 0);
        }

        // Attach events
        tr.querySelector('.pref-star').addEventListener('click', () => setPreferito(row));
        tr.querySelector('.btn-delete-row').addEventListener('click', () => deleteRow(row));

        ['inp-sku', 'inp-nome-prod', 'inp-qta', 'inp-uom', 'inp-prezzo', 'inp-sconto', 'inp-lead', 'inp-vdal', 'inp-val'].forEach(cls => {
          const inp = tr.querySelector('.' + cls);
          if (inp) {
            inp.addEventListener('change', (e) => {
              const sku = tr.querySelector('.inp-sku').value;
              const nomeProd = tr.querySelector('.inp-nome-prod').value;
              const leadTime = parseInt(tr.querySelector('.inp-lead').value) || 0;
              const targetUomId = parseInt(tr.querySelector('.inp-uom').value) || cfg.uomStockId;

              // Sync metadata for all rows of this supplier (state & DOM)
              flatRows.forEach(r => {
                 if (row.fornitore_id && r.fornitore_id == row.fornitore_id) {
                    r.sku_fornitore = sku;
                    r.nome_prodotto_fornitore = nomeProd;
                    r.lead_time_giorni = leadTime;
                    r.unita_misura_acquisto_id = targetUomId;
                    
                    const otherTr = masterTbody.querySelector(`[data-uid="${r._uid}"]`);
                    if (otherTr && otherTr !== tr) {
                       const inpSku = otherTr.querySelector('.inp-sku');
                       if (inpSku) inpSku.value = sku;
                       const inpNome = otherTr.querySelector('.inp-nome-prod');
                       if (inpNome) inpNome.value = nomeProd;
                       const inpLead = otherTr.querySelector('.inp-lead');
                       if (inpLead) inpLead.value = leadTime;
                       const inpUom = otherTr.querySelector('.inp-uom');
                       if (inpUom) inpUom.value = targetUomId;
                    }
                 }
              });

              row.sconto_perc = parseFloat(tr.querySelector('.inp-sconto').value) || 0;
              row.valido_dal = tr.querySelector('.inp-vdal').value;
              row.valido_al = tr.querySelector('.inp-val').value;

              // Read inputs which are in visual mode
              let inputQta = parseFloat(tr.querySelector('.inp-qta').value) || 0;
              let inputPrezzo = parseFloat(tr.querySelector('.inp-prezzo').value);

              // Convert from visual mode to the row's native UoM
              if (currentUomMode === 'stock') {
                if (targetUomId == cfg.uomCommercialId && qFactor > 1) {
                  const conv = window.qkConvertUom({ value: inputQta, price: inputPrezzo, qFactor, targetMode: 'commercial' });
                  inputQta = conv.value;
                  inputPrezzo = conv.price;
                }
              } else {
                if (targetUomId == cfg.uomStockId && qFactor > 1) {
                  const conv = window.qkConvertUom({ value: inputQta, price: inputPrezzo, qFactor, targetMode: 'stock' });
                  inputQta = conv.value;
                  inputPrezzo = conv.price;
                }
              }

              row.quantita_minima = inputQta;
              row.prezzo = inputPrezzo;

              triggerSave(row, tr);
            });
          }
        });
      });

      // Prevent default form submission when pressing Enter in any input in this grid
      masterTbody.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (e.target && typeof e.target.blur === 'function') {
            e.target.blur();
          }
        }
      });
      
      applyColumnVisibility();
      if (typeof window.qkMakeColumnsResizable === 'function') {
        window.qkMakeColumnsResizable(document.getElementById('master-fornitori-table'));
      } else {
        document.addEventListener('DOMContentLoaded', () => {
          window.qkMakeColumnsResizable?.(document.getElementById('master-fornitori-table'));
        });
      }
    }

    async function triggerSave(row, trElement) {
      if (!row.fornitore_id) return; // Dati minimi: serve almeno il fornitore
      
      const wasNew = !!row.isNew;
      const iconSaving = trElement ? trElement.querySelector('.icon-saving') : null;
      const iconSaved = trElement ? trElement.querySelector('.icon-saved') : null;
      
      if (iconSaving) iconSaving.classList.remove('d-none');
      if (iconSaved) iconSaved.classList.add('d-none');
 
      // Salviamo prima i metadati del fornitore
      const fd = new FormData();
      fd.append('id', row.prodotto_fornitore_id ? String(row.prodotto_fornitore_id) : '');
      fd.append('prodotto_id', String(prodId));
      fd.append('fornitore_id', String(row.fornitore_id));
      fd.append('sku_fornitore', row.sku_fornitore || '');
      fd.append('nome_prodotto_fornitore', row.nome_prodotto_fornitore || '');
      fd.append('lead_time_giorni', String(row.lead_time_giorni || 0));
      fd.append('ordine_preferenza_fornitore', String(row.ordine_preferenza_fornitore));
      fd.append('unita_misura_acquisto_id', String(row.unita_misura_acquisto_id || cfg.uomStockId));
      fd.append('attivo', '1');
      fd.append('no_prezzi', '1');
      fd.append('csrf_token', CSRF);
 
      try {
        const res = await fetch(cfg.apiFornSave, { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data.ok) {
          row.prodotto_fornitore_id = data.row.id;
          row.isNew = false;
          
          // Ora salviamo il prezzo (solo se specificato ed è valido)
          if (row.prezzo !== null && !isNaN(row.prezzo)) {
            const sf = new FormData();
            sf.append('id', row.prezzo_id ? String(row.prezzo_id) : '');
            sf.append('prodotto_fornitore_id', String(row.prodotto_fornitore_id));
            sf.append('prodotto_id', String(prodId));
            sf.append('quantita_minima', String(row.quantita_minima));
            sf.append('prezzo', String(row.prezzo));
            sf.append('sconto_perc', String(row.sconto_perc));
            sf.append('valido_dal', row.valido_dal || '');
            sf.append('valido_al', row.valido_al || '');
            sf.append('note', row.note || '');
            sf.append('csrf_token', CSRF);
 
            const pres = await fetch(cfg.apiPrezzoSave, { method: 'POST', body: sf });
            const pdata = await pres.json();
            if (pdata.ok) {
               row.prezzo_id = pdata.row.id;
            }
          }
        }
        
        if (iconSaving) iconSaving.classList.add('d-none');
        if (iconSaved) iconSaved.classList.remove('d-none');
        setTimeout(() => {
          if (iconSaved) iconSaved.classList.add('d-none');
        }, 2000);
        
        // Re-render only if it was a new row to finalize its layout
        if (wasNew) {
           renderMasterTable();
        }
      } catch(e) {
        if (iconSaving) iconSaving.classList.add('d-none');
        console.error(e);
      }
    }

    async function deleteRow(row) {
      if (row.isNew) {
         flatRows = flatRows.filter(r => r._uid !== row._uid);
         renderMasterTable();
         return;
      }

      const tr = masterTbody.querySelector(`[data-uid="${row._uid}"]`);
      if (!tr) return;

      const isLastForSupplier = flatRows.filter(r => r.fornitore_id === row.fornitore_id).length <= 1;

      if (isLastForSupplier && row.prodotto_fornitore_id) {
        try {
           const df = new FormData();
           df.append('id', String(row.prodotto_fornitore_id));
           df.append('prodotto_id', String(prodId));
           df.append('csrf_token', CSRF);
           const res = await fetch(cfg.apiFornDelete, { method: 'POST', body: df });
           const data = await res.json();
           if (!data.ok) {
              if (typeof window.qkToast === 'function') {
                 window.qkToast(data.error || "Errore durante l'eliminazione.", "danger");
              } else {
                 alert(data.error || "Errore durante l'eliminazione.");
              }
              return;
           }
        } catch (err) {
           console.error(err);
           return;
        }
      } else if (row.prezzo_id) {
        try {
           const df = new FormData();
           df.append('id', String(row.prezzo_id));
           df.append('prodotto_id', String(prodId));
           df.append('csrf_token', CSRF);
           const res = await fetch(cfg.apiPrezzoDelete, { method: 'POST', body: df });
           const data = await res.json();
           if (!data.ok) {
              if (typeof window.qkToast === 'function') {
                 window.qkToast(data.error || "Errore durante l'eliminazione.", "danger");
              } else {
                 alert(data.error || "Errore durante l'eliminazione.");
              }
              return;
           }
        } catch (err) {
           console.error(err);
           return;
        }
      }

      row._isDeletedSoft = true;

      const originalData = { ...row };
      const visibleCols = Array.from(document.querySelectorAll('#master-fornitori-table thead th'))
                               .filter(th => th.style.display !== 'none').length || 12;

      window.qkSoftDeleteRow(tr, {
        colSpan: visibleCols,
        messageHtml: `<i class="fa-solid fa-trash-can text-danger me-2"></i>Costo di acquisto per <strong>${esc(originalData.nome_fornitore_rs)}</strong> rimosso. <a href="#" class="undo-delete-inline ms-3 fw-bold text-decoration-underline text-primary">Annulla</a>`,
        onUndo: async () => {
          row._isDeletedSoft = false;
          row.prezzo_id = null;
          if (isLastForSupplier) {
             row.prodotto_fornitore_id = null;
          }
          row.isNew = false;
          await triggerSave(row, tr);
          renderMasterTable();
          return false; // return false because triggerSave + renderMasterTable handles re-drawing the whole row/table
        },
        onDismiss: () => {
          flatRows = flatRows.filter(r => r._uid !== originalData._uid);
          if (flatRows.length === 0) {
             renderMasterTable();
          }
        }
      });
    }

    async function setPreferito(row) {
      flatRows.forEach(r => r.ordine_preferenza_fornitore = 2);
      row.ordine_preferenza_fornitore = 1;
      // Imposta tutti gli scaglioni dello stesso fornitore a 1
      flatRows.filter(r => r.fornitore_id === row.fornitore_id).forEach(r => r.ordine_preferenza_fornitore = 1);
      
      if (row.prodotto_fornitore_id) {
          const fd = new FormData();
          fd.append('id', String(row.prodotto_fornitore_id));
          fd.append('ordine_preferenza_fornitore', '1');
          fd.append('csrf_token', CSRF);
          await fetch(cfg.apiFornSave, { method: 'POST', body: fd });
      }
      renderMasterTable();
    }

    // Col toggle logic
    function applyColumnVisibility() {
      const tableEl = document.getElementById('master-fornitori-table');
      if (!tableEl) return;
      const togglerInputs = document.querySelectorAll('#col-settings-dropdown .col-toggle');
      if (!togglerInputs || togglerInputs.length === 0) return;
      
      togglerInputs.forEach(inp => {
        const colId = inp.value;
        const show = inp.checked;
        const cells = tableEl.querySelectorAll(`[data-col="${colId}"]`);
        cells.forEach(c => {
          c.style.display = show ? '' : 'none';
        });
      });
    }

    const togglers = document.querySelectorAll('#col-settings-dropdown .col-toggle');
    if (togglers) {
      togglers.forEach(inp => {
        inp.addEventListener('change', applyColumnVisibility);
      });
    }

    const btnColSettings = document.getElementById('btn-col-settings');
    const colSettingsDropdown = document.getElementById('col-settings-dropdown');
    if (btnColSettings && colSettingsDropdown) {
      btnColSettings.addEventListener('click', (e) => {
        e.stopPropagation();
        const display = colSettingsDropdown.style.display;
        colSettingsDropdown.style.display = (display === 'none' || !display) ? 'block' : 'none';
      });
      document.addEventListener('click', (e) => {
        if (!colSettingsDropdown.contains(e.target) && e.target !== btnColSettings) {
          colSettingsDropdown.style.display = 'none';
        }
      });
    }

    function esc(s) {
      if (!s) return '';
      return s.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    /* ── RRP Logic (Sales Tab) ── */
    let rrpRows = [];

    // Caricamento RRP
    async function loadRrp() {
       try {
           const res = await fetch(`${cfg.apiFornSave.replace('fornitori/save', 'rrp/load')}?id=${cfg.prodottoId}`);
           if (res.ok) {
               const data = await res.json();
               if (data.ok) {
                   rrpRows = data.data || [];
                   renderRrpTable();
               }
           }
       } catch (e) {
           console.error("Errore RRP", e);
       }
    }

    const btnAddRrp = document.getElementById('btn-add-rrp');
    const rrpTbody = document.getElementById('rrp-tbody');
    const rrpBadge = document.getElementById('rrp-badge-content');

    let currentDrawerUomMode = 'stock';

    btnAddRrp?.addEventListener('click', () => {
       rrpRows.push({
           id: null,
           fornitore_id: null,
           rrp_min: '',
           rrp_max: '',
           valido_dal: '',
           valido_al: '',
           valuta: 'EUR',
           isNew: true,
           _uid: Math.random().toString(36).substr(2, 9)
       });
       renderRrpTable();
     });

    const btnOpenRrp = document.getElementById('btn-open-rrp-drawer');
    const btnCloseRrp = document.getElementById('btn-close-rrp-drawer');
    const btnCancelRrp = document.getElementById('btn-cancel-rrp-drawer');
    const rrpDrawer = document.getElementById('rrp-drawer');
    const rrpDrawerOverlay = document.getElementById('rrp-drawer-overlay');
    const rrpBannerValue = document.getElementById('rrp-banner-value');
    const rrpDrawerAlert = document.getElementById('rrp-drawer-alert');

    function openRrpDrawer() {
        if(rrpDrawer) rrpDrawer.classList.add('open');
        if(rrpDrawerOverlay) rrpDrawerOverlay.classList.add('open');
    }
    function closeRrpDrawer() {
        if(rrpDrawer) rrpDrawer.classList.remove('open');
        if(rrpDrawerOverlay) rrpDrawerOverlay.classList.remove('open');
    }
    
    btnOpenRrp?.addEventListener('click', openRrpDrawer);
    btnCloseRrp?.addEventListener('click', closeRrpDrawer);
    btnCancelRrp?.addEventListener('click', closeRrpDrawer);
    rrpDrawerOverlay?.addEventListener('click', closeRrpDrawer);

    function renderRrpTable() {
       if (!rrpTbody) return;
       rrpTbody.innerHTML = '';
       
       const drawerUomModeSelect = document.getElementById('drawer-uom-mode');
       if (drawerUomModeSelect && !drawerUomModeSelect.dataset.hasListener) {
           drawerUomModeSelect.dataset.hasListener = '1';
           drawerUomModeSelect.addEventListener('change', function() {
               currentDrawerUomMode = this.value;
               renderRrpTable();
           });
       }

       if (rrpRows.length === 0) {
           rrpTbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">Nessun prezzo consigliato impostato.</td></tr>`;
           updateRrpBadge();
           return;
       }

       const uniqueSuppliers = [...new Map(flatRows.filter(f => f.fornitore_id).map(f => [f.fornitore_id, f])).values()];
       const qFactor = parseFloat(cfg.quantitaContenuto) || 1;
       const hasComm = !!cfg.uomCommercialId && qFactor > 1;

       rrpRows.forEach((row, i) => {
           let displayMin = row.rrp_min;
           let displayMax = row.rrp_max;
           if (currentDrawerUomMode === 'commercial' && hasComm) {
               if (row.rrp_min) displayMin = (parseFloat(row.rrp_min) / qFactor).toFixed(2);
               if (row.rrp_max) displayMax = (parseFloat(row.rrp_max) / qFactor).toFixed(2);
           }

           const tr = document.createElement('tr');
           tr.dataset.uid = row._uid || row.id;
           tr.innerHTML = `
              <td class="p-1">
                 <select class="form-select form-select-sm inp-fonte border-0 shadow-none">
                    <option value="">-- Mercato / Fonte Interna --</option>
                    ${uniqueSuppliers.map(f => `<option value="${f.fornitore_id}" ${row.fornitore_id == f.fornitore_id ? 'selected' : ''}>${esc(f.nome_fornitore_rs)}</option>`).join('')}
                 </select>
              </td>
              <td class="p-1" data-col="rrp-min"><input type="number" step="0.01" class="form-control form-control-sm text-end inp-rmin border-0 shadow-none fw-bold text-success" value="${displayMin || ''}" placeholder="Min"></td>
              <td class="p-1" data-col="rrp-max"><input type="number" step="0.01" class="form-control form-control-sm text-end inp-rmax border-0 shadow-none fw-bold text-danger" value="${displayMax || ''}" placeholder="Max"></td>
              <td class="p-1">
                 <select class="form-select form-select-sm inp-valuta border-0 shadow-none text-center">
                    <option value="EUR" ${row.valuta === 'EUR' ? 'selected' : ''}>EUR</option>
                    <option value="USD" ${row.valuta === 'USD' ? 'selected' : ''}>USD</option>
                 </select>
              </td>
              <td class="p-1 rrp-date-col" data-col="rrp-date"><input type="date" class="form-control form-control-sm inp-vdal border-0 shadow-none" value="${esc(row.valido_dal || '')}"></td>
              <td class="p-1 rrp-date-col" data-col="rrp-date"><input type="date" class="form-control form-control-sm inp-val border-0 shadow-none" value="${esc(row.valido_al || '')}"></td>
              <td class="p-1 text-center">
                 <button type="button" class="btn btn-sm btn-outline-danger border-0 btn-del-rrp"><i class="fa-solid fa-trash"></i></button>
              </td>
           `;
           rrpTbody.appendChild(tr);

           tr.querySelector('.btn-del-rrp').addEventListener('click', () => {
               rrpRows.splice(i, 1);
               saveRrpRows();
           });

           ['inp-fonte','inp-rmin','inp-rmax','inp-valuta','inp-vdal','inp-val'].forEach(c => {
               tr.querySelector('.'+c).addEventListener('change', () => {
                  row.fornitore_id = tr.querySelector('.inp-fonte').value || null;
                  
                  let valMin = tr.querySelector('.inp-rmin').value;
                  let valMax = tr.querySelector('.inp-rmax').value;
                  
                  if (currentDrawerUomMode === 'commercial' && hasComm) {
                      if (valMin) valMin = (parseFloat(valMin) * qFactor).toFixed(4);
                      if (valMax) valMax = (parseFloat(valMax) * qFactor).toFixed(4);
                  }
                  
                  row.rrp_min = valMin;
                  row.rrp_max = valMax;
                  row.valuta = tr.querySelector('.inp-valuta').value;
                  row.valido_dal = tr.querySelector('.inp-vdal').value;
                  row.valido_al = tr.querySelector('.inp-val').value;
                  saveRrpRows();
               });
           });
       });
       updateRrpBadge();
       applyRrpColumnVisibility();
    }

    async function saveRrpRows() {
       try {
           const fd = new FormData();
           fd.append('csrf_token', window.CSRF || '');
           fd.append('prodotto_id', cfg.prodottoId);
           fd.append('rrp_data', JSON.stringify(rrpRows));
           
           await fetch(cfg.apiFornSave.replace('fornitori/save', 'rrp/save'), { method: 'POST', body: fd });
           renderRrpTable();
       } catch (e) { console.error(e); }
    }

    function applyRrpColumnVisibility() {
       const drawer = document.getElementById('rrp-drawer');
       if (!drawer) return;
       const togglers = document.querySelectorAll('#rrp-col-settings-dropdown .col-toggle');
       togglers.forEach(inp => {
           const colId = inp.value;
           const show = inp.checked;
           const cells = drawer.querySelectorAll(`[data-col="rrp-${colId}"]`);
           cells.forEach(c => {
               c.style.display = show ? '' : 'none';
           });
       });
    }

    const btnRrpColSettings = document.getElementById('btn-rrp-col-settings');
    const rrpColSettingsDropdown = document.getElementById('rrp-col-settings-dropdown');
    if (btnRrpColSettings && rrpColSettingsDropdown) {
       btnRrpColSettings.addEventListener('click', (e) => {
           e.stopPropagation();
           const display = rrpColSettingsDropdown.style.display;
           rrpColSettingsDropdown.style.display = (display === 'none' || !display) ? 'block' : 'none';
       });
       document.addEventListener('click', (e) => {
           if (!rrpColSettingsDropdown.contains(e.target) && e.target !== btnRrpColSettings) {
               rrpColSettingsDropdown.style.display = 'none';
           }
       });
       document.querySelectorAll('#rrp-col-settings-dropdown .col-toggle').forEach(inp => {
           inp.addEventListener('change', applyRrpColumnVisibility);
       });
    }

    function updateRrpBadge() {
       const uniqueSuppliers = [...new Map(flatRows.filter(f => f.fornitore_id).map(f => [f.fornitore_id, f])).values()];
       
       if (uniqueSuppliers.length === 0) {
           if (rrpBannerValue) rrpBannerValue.innerHTML = '<span class="text-muted" style="font-size: 0.82rem;">Inserisci prima i fornitori per poter configurare i prezzi consigliati.</span>';
       } else if (rrpRows.length === 0) {
           if (rrpBannerValue) rrpBannerValue.innerHTML = '<span class="text-muted" style="font-size: 0.82rem;">Nessun prezzo consigliato configurato.</span>';
       } else {
           const active = rrpRows.filter(r => r.rrp_min || r.rrp_max);
           if (active.length === 0) {
               if (rrpBannerValue) rrpBannerValue.innerHTML = '<span class="text-muted" style="font-size: 0.82rem;">Nessun valore consigliato impostato.</span>';
           } else {
               let mins = active.map(r => parseFloat(r.rrp_min)).filter(n => !isNaN(n) && n > 0);
               let maxs = active.map(r => parseFloat(r.rrp_max)).filter(n => !isNaN(n) && n > 0);
               
               const hasMin = mins.length > 0;
               const hasMax = maxs.length > 0;
               
               let interMin = hasMin ? Math.max(...mins) : null;
               let interMax = hasMax ? Math.min(...maxs) : null;
               
               const currency = active[0]?.valuta || 'EUR';
               
               if (hasMin && hasMax && interMin > interMax) {
                   if (rrpBannerValue) rrpBannerValue.innerHTML = `<span class="badge bg-danger text-white me-1">Conflitto Prezzi</span> <span class="fw-bold text-danger">${Math.min(...mins).toFixed(2)} - ${Math.max(...maxs).toFixed(2)} ${currency}</span>`;
               } else if (hasMin && hasMax) {
                   if (rrpBannerValue) rrpBannerValue.innerHTML = `Safe Zone: <span class="fw-bold text-success">${interMin.toFixed(2)} - ${interMax.toFixed(2)} ${currency}</span>`;
               } else if (hasMin) {
                   if (rrpBannerValue) rrpBannerValue.innerHTML = `Safe Zone: <span class="fw-bold text-success">&gt;= ${interMin.toFixed(2)} ${currency}</span>`;
               } else if (hasMax) {
                   if (rrpBannerValue) rrpBannerValue.innerHTML = `Safe Zone: <span class="fw-bold text-success">&lt;= ${interMax.toFixed(2)} ${currency}</span>`;
               }
           }
       }

       if (!rrpDrawerAlert) return;
       const active = rrpRows.filter(r => r.rrp_min || r.rrp_max);
       if (active.length === 0) {
           rrpDrawerAlert.classList.add('d-none');
           return;
       }
       
       let mins = active.map(r => parseFloat(r.rrp_min)).filter(n => !isNaN(n) && n > 0);
       let maxs = active.map(r => parseFloat(r.rrp_max)).filter(n => !isNaN(n) && n > 0);
       
       const hasMin = mins.length > 0;
       const hasMax = maxs.length > 0;

       let unioneMin = hasMin ? Math.min(...mins) : null;
       let unioneMax = hasMax ? Math.max(...maxs) : null;
       let interMin = hasMin ? Math.max(...mins) : null;
       let interMax = hasMax ? Math.min(...maxs) : null;

       const qFactor = parseFloat(cfg.quantitaContenuto) || 1;
       const hasComm = !!cfg.uomCommercialId && qFactor > 1;
       if (currentDrawerUomMode === 'commercial' && hasComm) {
           if (hasMin) {
               unioneMin = unioneMin / qFactor;
               interMin = interMin / qFactor;
           }
           if (hasMax) {
               unioneMax = unioneMax / qFactor;
               interMax = interMax / qFactor;
           }
       }
       
       const unitSymbol = (currentDrawerUomMode === 'commercial' && hasComm) ? cfg.uomCommercial : cfg.uomStock;

       rrpDrawerAlert.classList.remove('d-none');
       
       if (hasMin && hasMax && interMin > interMax) {
           rrpDrawerAlert.className = 'qk-alert qk-alert-danger mb-3';
           rrpDrawerAlert.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Conflitto Prezzi (Range Totale: ${unioneMin.toFixed(2)} - ${unioneMax.toFixed(2)} / ${unitSymbol})`;
       } else if (!hasMin && !hasMax) {
           rrpDrawerAlert.classList.add('d-none');
       } else {
           rrpDrawerAlert.className = 'qk-alert qk-alert-success mb-3';
           if (hasMin && hasMax) {
               rrpDrawerAlert.innerHTML = `<i class="fa-solid fa-check-circle"></i> Safe Zone: ${interMin.toFixed(2)} - ${interMax.toFixed(2)} / ${unitSymbol} <span class="ms-2 fw-normal" style="font-size: 0.75rem;">(Unione: ${unioneMin.toFixed(2)} - ${unioneMax.toFixed(2)})</span>`;
           } else if (hasMin) {
               rrpDrawerAlert.innerHTML = `<i class="fa-solid fa-check-circle"></i> Safe Zone: &gt;= ${interMin.toFixed(2)} / ${unitSymbol} <span class="ms-2 fw-normal" style="font-size: 0.75rem;">(Unione: &gt;= ${unioneMin.toFixed(2)})</span>`;
           } else {
               rrpDrawerAlert.innerHTML = `<i class="fa-solid fa-check-circle"></i> Safe Zone: &lt;= ${interMax.toFixed(2)} / ${unitSymbol} <span class="ms-2 fw-normal" style="font-size: 0.75rem;">(Unione: &lt;= ${unioneMax.toFixed(2)})</span>`;
           }
       }
    }

    // Initialize RRP se si clicca sul tab Listini
    document.getElementById('ptab-btn-listini')?.addEventListener('shown.bs.tab', () => {
        if (!rrpTbody?.dataset.loaded) {
            loadRrp();
            if(rrpTbody) rrpTbody.dataset.loaded = '1';
        }
    });

    // UoM selector listener for Purchase grid
    const purchaseUomModeSelect = document.getElementById('purchase-uom-mode');
    if (purchaseUomModeSelect) {
      purchaseUomModeSelect.value = currentUomMode;
      purchaseUomModeSelect.addEventListener('change', function() {
        currentUomMode = this.value;
        renderMasterTable();
      });
    }

  });
})();
