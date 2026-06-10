/**
 * assets/js/sales-quotes.js — Gestione interattiva preventivi vendita
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const CSRF = window.QK_QUOTES_CONFIG.csrf;
  const PRELOADED_RIGHE = window.QK_QUOTES_CONFIG.preloadedRighe;
  const quoteStato = window.QK_QUOTES_CONFIG.quoteStato || 'bozza';
  const BASE = (typeof QK_BASE !== 'undefined') ? QK_BASE : '';

  // Global settings references
  const btnColSettings = document.getElementById('btn-col-settings');
  const colSettingsDropdown = document.getElementById('col-settings-dropdown');
  const chkShowConf = document.getElementById('chk-show-conf');
  const chkShowSconto = document.getElementById('chk-show-sconto');
  const chkShowIva = document.getElementById('chk-show-iva');
  const chkShowRange = document.getElementById('chk-show-range');

  /* ─────────────────────────────────────────────────────────────────────────
     UTILITÀ & AC FETCH
     ───────────────────────────────────────────────────────────────────────── */
  async function fetchClienti(q) {
    try {
      const res  = await fetch(BASE + '/sales/quotes/api/clienti?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
      return await res.json();
    } catch (e) { return []; }
  }

  async function fetchProdotti(q) {
    try {
      const res  = await fetch(BASE + '/sales/quotes/api/prodotti?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
      return await res.json();
    } catch (e) { return []; }
  }

  /* ─────────────────────────────────────────────────────────────────────────
     GESTIONE CLIENTE
     ───────────────────────────────────────────────────────────────────────── */
  const elClienteSearch       = document.getElementById('cliente_search');
  const elClienteId           = document.getElementById('cliente_id');
  const elClienteLabel        = document.getElementById('cliente_label');
  const elClienteDrop         = document.getElementById('ac-clienti-drop');
  const elBtnClearInline      = document.getElementById('btn-clear-cliente-inline');
  const elHelperText          = document.getElementById('cliente-helper-text');
  const elHelperContent       = document.getElementById('cliente-helper-content');

  function selectCliente(item) {
    elClienteId.value    = item.id;
    elClienteLabel.value = item.label;
    elClienteSearch.value = item.label;
    if (elBtnClearInline) elBtnClearInline.classList.remove('d-none');
    
    const parts = [item.email, item.telefono].filter(Boolean);
    if (elHelperText && elHelperContent && parts.length > 0) {
      elHelperContent.textContent = parts.join(' · ');
      elHelperText.style.display = '';
    } else if (elHelperText) {
      elHelperText.style.display = 'none';
    }

    checkAllRowsCompliance();
    loadClienteDettagli();
  }

  function clearCliente() {
    elClienteId.value    = '';
    elClienteLabel.value = '';
    elClienteSearch.value = '';
    if (elBtnClearInline) elBtnClearInline.classList.add('d-none');
    
    if (elHelperText && elHelperContent) {
      elHelperContent.textContent = '';
      elHelperText.style.display = 'none';
    }

    checkAllRowsCompliance();
    
    const spedSelect = document.getElementById('quote-indirizzo-spedizione-id');
    const contSelect = document.getElementById('quote-contatto-id');
    if (spedSelect) spedSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
    if (contSelect) contSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
  }

  if (elBtnClearInline) {
    elBtnClearInline.addEventListener('click', clearCliente);
  }

  if (elClienteSearch) {
    elClienteSearch.addEventListener('input', () => {
      if (!elClienteSearch.value.trim()) clearCliente();
    });

    window.qkAutocomplete({
      input:       elClienteSearch,
      dropdown:    elClienteDrop,
      fetchFn:     fetchClienti,
      onSelect:    selectCliente,
      onCreate:    () => openModal('modal-nuovo-cliente'),
      createLabel: 'Crea nuovo cliente',
    });
  }

  /* ─────────────────────────────────────────────────────────────────────────
     RIGHE PRODOTTO
     ───────────────────────────────────────────────────────────────────────── */
  let righeCount = 0;
  let currentUomMode = document.getElementById('sales-uom-mode')?.value || 'commercial';

  function syncRowMetadata(tr, metadata) {
    if (metadata.uomStock !== undefined) {
      tr.dataset.uomStock = metadata.uomStock;
      tr.querySelector('.inp-uom-stock').value = metadata.uomStock;
    }
    if (metadata.uomStockId !== undefined) {
      tr.dataset.uomStockId = metadata.uomStockId;
      tr.querySelector('.inp-uom-stock-id').value = metadata.uomStockId;
    }
    if (metadata.uomCommercial !== undefined) {
      tr.dataset.uomCommercial = metadata.uomCommercial;
      tr.querySelector('.inp-uom-commercial').value = metadata.uomCommercial;
    }
    if (metadata.uomCommercialId !== undefined) {
      tr.dataset.uomCommercialId = metadata.uomCommercialId;
      tr.querySelector('.inp-uom-commercial-id').value = metadata.uomCommercialId;
    }
    if (metadata.quantitaContenuto !== undefined) {
      tr.dataset.quantitaContenuto = metadata.quantitaContenuto;
      tr.querySelector('.inp-quantita-contenuto').value = metadata.quantitaContenuto;
    }
    if (metadata.sku !== undefined) {
      tr.querySelector('.inp-sku').value = metadata.sku;
      const skuDiv = tr.querySelector('.riga-sku');
      if (skuDiv) skuDiv.textContent = metadata.sku ? 'SKU: ' + metadata.sku : '';
    }
    if (metadata.quantitaConfezione !== undefined) {
      tr.querySelector('.inp-quantita-confezione').value = metadata.quantitaConfezione;
      const confInput = tr.querySelector('.riga-conf');
      if (confInput) confInput.value = metadata.quantitaConfezione;
    }
    if (metadata.costoBase !== undefined) {
      tr.dataset.costoBase = metadata.costoBase;
    }
    if (metadata.rrpMin !== undefined) {
      tr.dataset.rrpMin = metadata.rrpMin;
    }
    if (metadata.rrpMax !== undefined) {
      tr.dataset.rrpMax = metadata.rrpMax;
    }
  }

  function setRowUomMode(tr, targetMode, currentMode) {
    const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
    const qtyInput = tr.querySelector('.inp-qta');
    const priceInput = tr.querySelector('.inp-pu');
    const umInput = tr.querySelector('.inp-um');

    if (!qtyInput || !priceInput || !umInput) return;

    const haUnitaCommerciale = tr.dataset.uomCommercialId && qFactor > 1;
    if (!haUnitaCommerciale) {
      umInput.value = tr.dataset.uomStock || 'pz';
      return;
    }

    if (targetMode === currentMode) {
      umInput.value = (targetMode === 'stock') 
        ? (tr.dataset.uomStock || 'pz') 
        : (tr.dataset.uomCommercial || 'pz');
      return;
    }

    const { value: newQty, price: newPrice } = window.qkConvertUom({
      value: qtyInput.value,
      price: priceInput.value,
      qFactor,
      targetMode
    });

    if (qtyInput.value && newQty !== null) qtyInput.value = newQty;
    if (priceInput.value && newPrice !== null) priceInput.value = newPrice;

    umInput.value = (targetMode === 'stock') 
      ? (tr.dataset.uomStock || 'pz') 
      : (tr.dataset.uomCommercial || 'pz');
  }

  function buildRiga(prefill = {}) {
    const idx = righeCount++;
    const tr  = document.createElement('tr');
    tr.dataset.idx = idx;

    tr.dataset.uomStock = prefill.uom_stock || prefill.unita_misura || '';
    tr.dataset.uomStockId = prefill.uom_stock_id || '';
    tr.dataset.uomCommercial = prefill.uom_commercial || prefill.unita_misura || '';
    tr.dataset.uomCommercialId = prefill.uom_commercial_id || '';
    tr.dataset.quantitaContenuto = prefill.quantita_contenuto || '1';
    tr.dataset.costoBase = prefill.costo_unitario || prefill.costo_base || '0';
    tr.dataset.rrpMin = prefill.rrp_min !== undefined && prefill.rrp_min !== null ? prefill.rrp_min : '';
    tr.dataset.rrpMax = prefill.rrp_max !== undefined && prefill.rrp_max !== null ? prefill.rrp_max : '';

    tr.innerHTML = `
      <td class="col-search">
        <div class="qk-ac-wrap">
          <input type="text" class="qk-form-control riga-search" placeholder="Cerca…" autocomplete="off" value="${esc(prefill.descrizione || '')}">
          <div class="qk-ac-dropdown riga-ac-drop"></div>
        </div>
        <div class="riga-sku" data-sku="">${prefill.sku ? 'SKU: ' + esc(prefill.sku) : ''}</div>
        <div class="riga-compliance-warn mt-1 text-warning small fw-bold" style="display:none; line-height: 1.2;"></div>
        <input type="hidden" name="righe[${idx}][prodotto_id]" class="inp-prodotto-id" value="${prefill.prodotto_id || ''}">
        
        <input type="hidden" name="righe[${idx}][uom_stock]" class="inp-uom-stock" value="${esc(tr.dataset.uomStock)}">
        <input type="hidden" name="righe[${idx}][uom_stock_id]" class="inp-uom-stock-id" value="${esc(tr.dataset.uomStockId)}">
        <input type="hidden" name="righe[${idx}][uom_commercial]" class="inp-uom-commercial" value="${esc(tr.dataset.uomCommercial)}">
        <input type="hidden" name="righe[${idx}][uom_commercial_id]" class="inp-uom-commercial-id" value="${esc(tr.dataset.uomCommercialId)}">
        <input type="hidden" name="righe[${idx}][quantita_contenuto]" class="inp-quantita-contenuto" value="${esc(tr.dataset.quantitaContenuto)}">
        <input type="hidden" name="righe[${idx}][sku]" class="inp-sku" value="${esc(prefill.sku || '')}">
      </td>
      <td class="col-desc">
        <textarea name="righe[${idx}][descrizione]" class="qk-form-control inp-desc"
                  placeholder="Descrizione prodotto" rows="1">${esc(prefill.descrizione || '')}</textarea>
      </td>
      <td class="col-conf">
        <input type="text" name="righe[${idx}][quantita_confezione]" class="qk-form-control riga-conf inp-quantita-confezione"
               value="${esc(prefill.quantita_confezione || '')}" placeholder="—" title="Confezione">
      </td>
      <td class="col-qta">
        <input type="number" name="righe[${idx}][quantita]" class="qk-form-control text-end inp-qta"
               placeholder="0" min="0" step="0.001" value="${prefill.quantita || ''}">
      </td>
      <td class="col-um">
        <input type="text" name="righe[${idx}][unita_misura]" class="qk-form-control inp-um"
               placeholder="pz" maxlength="5" value="${esc(prefill.unita_misura || '')}">
      </td>
      <td class="col-costo text-end" style="font-weight: 500;">
        <span class="riga-costo-val">—</span>
      </td>
      <td class="col-range text-center small text-muted">
        <span class="riga-range-val">—</span>
      </td>
      <td class="col-pu">
        <div style="position:relative;width:100%">
          <input type="number" name="righe[${idx}][prezzo_unitario]" class="qk-form-control text-end inp-pu"
                 placeholder="0,00" min="0" step="0.0001" value="${prefill.prezzo_unitario || ''}" style="padding-right:20px; width:100%;">
          <span class="price-origin-badge" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); font-size:0.75rem; cursor:pointer; display:flex; align-items:center;" title="Nessun prezzo configurato">
            <i class="fa-solid fa-triangle-exclamation" style="color:var(--qk-danger)"></i>
          </span>
        </div>
      </td>
      <td class="col-sc">
        <input type="number" name="righe[${idx}][sconto_perc]" class="qk-form-control text-end inp-sc"
               placeholder="0" min="0" max="100" step="0.01" value="${prefill.sconto_perc || '0'}">
      </td>
      <td class="col-iva">
        <input type="number" name="righe[${idx}][iva_perc]" class="qk-form-control text-end inp-iva"
               placeholder="22" min="0" max="100" step="1" value="${prefill.iva_perc || '22'}">
      </td>
      <td class="col-tot">
        <div class="riga-total">€ 0,00</div>
      </td>
      <td class="col-delta text-end" style="font-weight: 600;">
        <span class="riga-delta-val">—</span>
      </td>
      <td class="col-del">
        <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger riga-del" title="Rimuovi riga"><i class="fa-solid fa-trash-can"></i></button>
      </td>
    `;

    tr.querySelector('.riga-del').addEventListener('click', () => {
      tr.remove();
      calcTotali();
    });

    const puInput = tr.querySelector('.inp-pu');
    const badge = tr.querySelector('.price-origin-badge');
    if (puInput && badge) {
      puInput.addEventListener('input', () => {
        badge.innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:var(--qk-warning)"></i>';
        badge.title = 'Modificato manualmente';
      });
    }

    ['inp-qta','inp-pu','inp-sc','inp-iva'].forEach(cls => {
      tr.querySelector('.' + cls).addEventListener('input', calcTotali);
    });

    tr.querySelector('.inp-qta').addEventListener('input', () => {
      fetchQuotePrice(tr);
    });

    const searchInput = tr.querySelector('.riga-search');
    const searchDrop  = tr.querySelector('.riga-ac-drop');

    window.qkAutocomplete({
      input:       searchInput,
      dropdown:    searchDrop,
      fetchFn:     fetchProdotti,
      onSelect:    (item) => {
        tr.querySelector('.inp-prodotto-id').value  = item.id;
        tr.querySelector('.inp-desc').value         = item.label;
        tr.querySelector('.inp-qta').value          = '1';
        tr.querySelector('.inp-pu').value           = '';
        
        syncRowMetadata(tr, {
          uomStock: item.unita_misura || 'pz',
          uomStockId: item.unita_misura_stock_id || '',
          uomCommercial: item.unita_misura_commerciale || item.unita_misura || 'pz',
          uomCommercialId: item.unita_contenuto_id || item.unita_misura_stock_id || '',
          quantitaContenuto: item.quantita_contenuto || '1',
          sku: item.sku || '',
          quantitaConfezione: item.quantita_confezione || '',
          costoBase: item.costo_base || '0',
          rrpMin: item.rrp_min !== undefined && item.rrp_min !== null ? item.rrp_min : '',
          rrpMax: item.rrp_max !== undefined && item.rrp_max !== null ? item.rrp_max : ''
        });

        setRowUomMode(tr, currentUomMode, 'stock');

        searchInput.value = item.label;
        calcTotali();
        checkRowCompliance(tr);
        fetchQuotePrice(tr);
      },
      onCreate:    () => openModal('modal-nuovo-prodotto', tr),
      createLabel: 'Crea nuovo prodotto',
    });

    let savedMode = currentUomMode;
    if (prefill.unita_misura) {
      if (prefill.unita_misura === tr.dataset.uomStock) {
        savedMode = 'stock';
      } else if (prefill.unita_misura === tr.dataset.uomCommercial) {
        savedMode = 'commercial';
      }
    }
    if (prefill.unita_misura && savedMode !== currentUomMode) {
      setRowUomMode(tr, currentUomMode, savedMode);
    }

    document.getElementById('tbody-righe').appendChild(tr);
    calcTotali();
    
    const descTextarea = tr.querySelector('.inp-desc');
    if (descTextarea) {
      const autoResizeTextarea = (el) => {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
      };
      descTextarea.addEventListener('input', () => autoResizeTextarea(descTextarea));
      setTimeout(() => autoResizeTextarea(descTextarea), 0);
    }

    if (typeof updateColumnVisibility === 'function') {
      updateColumnVisibility();
    }
    return tr;
  }

  function calcTotali() {
    let imponibile = 0;
    let totIva     = 0;
    let totaleCosto = 0;

    document.querySelectorAll('#tbody-righe tr').forEach(tr => {
      const qta  = parseFloat(tr.querySelector('.inp-qta')?.value)  || 0;
      const pu   = parseFloat(tr.querySelector('.inp-pu')?.value)   || 0;
      const sc   = parseFloat(tr.querySelector('.inp-sc')?.value)   || 0;
      const iva  = parseFloat(tr.querySelector('.inp-iva')?.value)  || 22;
      const pn   = pu * (1 - sc / 100);
      const tot  = Math.round(pn * qta * 100) / 100;
      const rig  = Math.round(tot * iva / 100 * 100) / 100;
      imponibile += tot;
      totIva     += rig;
      if (tr.querySelector('.riga-total')) {
        tr.querySelector('.riga-total').textContent = window.qkFormatEuro(tot);
      }

      const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
      const costoBase = parseFloat(tr.dataset.costoBase) || 0;
      const rrpMinRaw = tr.dataset.rrpMin ? parseFloat(tr.dataset.rrpMin) : null;
      const rrpMaxRaw = tr.dataset.rrpMax ? parseFloat(tr.dataset.rrpMax) : null;

      let costoConvertito = costoBase;
      let rrpMinConvertito = rrpMinRaw;
      let rrpMaxConvertito = rrpMaxRaw;

      if (currentUomMode === 'commercial' && qFactor > 1) {
        costoConvertito = costoBase / qFactor;
        if (rrpMinConvertito !== null) rrpMinConvertito = rrpMinRaw / qFactor;
        if (rrpMaxConvertito !== null) rrpMaxConvertito = rrpMaxRaw / qFactor;
      }

      const rowCostTotal = Math.round(costoConvertito * qta * 100) / 100;
      totaleCosto += rowCostTotal;

      const rowDelta = tot - rowCostTotal;

      const elCosto = tr.querySelector('.riga-costo-val');
      if (elCosto) {
        elCosto.textContent = costoBase > 0 ? window.qkFormatEuro(costoConvertito) : '—';
      }

      const elRange = tr.querySelector('.riga-range-val');
      if (elRange) {
        if (rrpMinConvertito !== null && rrpMaxConvertito !== null) {
          elRange.textContent = `€ ${rrpMinConvertito.toFixed(2)} → € ${rrpMaxConvertito.toFixed(2)}`;
        } else {
          elRange.textContent = '—';
        }
      }

      const elDelta = tr.querySelector('.riga-delta-val');
      if (elDelta) {
        elDelta.textContent = window.qkFormatEuro(rowDelta);
        if (rowDelta >= 0) {
          elDelta.style.color = 'var(--qk-success)';
        } else {
          elDelta.style.color = 'var(--qk-danger)';
        }
      }
    });

    const scGlob = parseFloat(document.getElementById('sconto_globale')?.value) || 0;
    const impSc  = Math.round(imponibile * (1 - scGlob / 100) * 100) / 100;
    const ivaSc  = Math.round(totIva    * (1 - scGlob / 100) * 100) / 100;
    const totale = Math.round((impSc + ivaSc) * 100) / 100;

    document.getElementById('tot-imponibile').textContent = window.qkFormatEuro(impSc);
    document.getElementById('tot-iva').textContent        = window.qkFormatEuro(ivaSc);
    document.getElementById('tot-totale').textContent     = window.qkFormatEuro(totale);

    const boxTotCliente = document.getElementById('box-tot-cliente');
    if (boxTotCliente) boxTotCliente.textContent = window.qkFormatEuro(impSc);

    const boxTotCosto = document.getElementById('box-tot-costo');
    if (boxTotCosto) boxTotCosto.textContent = window.qkFormatEuro(totaleCosto);

    const boxMargineEuro = document.getElementById('box-margine-euro');
    const boxMargineEuroCont = document.getElementById('box-margine-euro-container');
    const marginEuro = impSc - totaleCosto;
    if (boxMargineEuro) {
      boxMargineEuro.textContent = window.qkFormatEuro(marginEuro);
      if (marginEuro >= 0) {
        boxMargineEuro.className = 'fs-4 fw-bold text-success mt-1';
        if (boxMargineEuroCont) {
          boxMargineEuroCont.style.backgroundColor = 'rgba(40,167,69,0.06)';
          boxMargineEuroCont.style.borderColor = 'rgba(40,167,69,0.2)';
        }
      } else {
        boxMargineEuro.className = 'fs-4 fw-bold text-danger mt-1';
        if (boxMargineEuroCont) {
          boxMargineEuroCont.style.backgroundColor = 'rgba(220,53,69,0.06)';
          boxMargineEuroCont.style.borderColor = 'rgba(220,53,69,0.2)';
        }
      }
    }

    const boxMarginePerc = document.getElementById('box-margine-perc');
    const boxMarginePercCont = document.getElementById('box-margine-perc-container');
    const marginPerc = impSc > 0 ? (marginEuro / impSc) * 100 : 0;
    if (boxMarginePerc) {
      boxMarginePerc.textContent = marginPerc.toFixed(2) + '%';
      if (marginPerc >= 20) {
        boxMarginePerc.className = 'fs-4 fw-bold text-success mt-1';
        if (boxMarginePercCont) {
          boxMarginePercCont.style.backgroundColor = 'rgba(40,167,69,0.06)';
          boxMarginePercCont.style.borderColor = 'rgba(40,167,69,0.2)';
        }
      } else if (marginPerc >= 10) {
        boxMarginePerc.className = 'fs-4 fw-bold text-warning mt-1';
        if (boxMarginePercCont) {
          boxMarginePercCont.style.backgroundColor = 'rgba(255,193,7,0.06)';
          boxMarginePercCont.style.borderColor = 'rgba(255,193,7,0.2)';
        }
      } else {
        boxMarginePerc.className = 'fs-4 fw-bold text-danger mt-1';
        if (boxMarginePercCont) {
          boxMarginePercCont.style.backgroundColor = 'rgba(220,53,69,0.06)';
          boxMarginePercCont.style.borderColor = 'rgba(220,53,69,0.2)';
        }
      }
    }
  }

  // UoM Toggling Listener
  const elUomMode = document.getElementById('sales-uom-mode');
  if (elUomMode) {
    elUomMode.addEventListener('change', () => {
      const newMode = elUomMode.value;
      document.querySelectorAll('#tbody-righe tr').forEach(tr => {
        setRowUomMode(tr, newMode, currentUomMode);
      });
      currentUomMode = newMode;
      calcTotali();
    });
  }

  document.getElementById('btn-aggiungi-riga')?.addEventListener('click', () => buildRiga());
  document.getElementById('sconto_globale')?.addEventListener('input', calcTotali);

  // Load preloaded rows if any exist
  if (PRELOADED_RIGHE && PRELOADED_RIGHE.length > 0) {
    PRELOADED_RIGHE.forEach(r => buildRiga(r));
  } else {
    buildRiga();
  }

  // Catalogo Prodotti Logic
  const elCatSearch = document.getElementById('catalogo-search');
  const elCatGrid = document.getElementById('catalogo-grid');

  let catalogoProdottiData = [];

  async function renderCatalogo(q = '') {
    if (q.trim().length === 0) {
      elCatGrid.innerHTML = `
        <div class="col-12 text-center text-muted py-3 small">
          Digita nella barra di ricerca per visualizzare i prodotti
        </div>
      `;
      return;
    }
    
    try {
      const res = await fetchProdotti(q);
      catalogoProdottiData = res;
      if (!res || res.length === 0) {
        elCatGrid.innerHTML = `
          <div class="col-12 text-center text-muted py-3 small">
            Nessun prodotto trovato
          </div>
        `;
        return;
      }
      
      let html = '';
      res.forEach((prod, index) => {
        const label = prod.label || prod.nome || '';
        const sku = prod.sku || prod.sku_interno || '';
        const confezione = prod.quantita_confezione || prod.descrizione_confezione || '—';
        const costo = parseFloat(prod.costo_base) || 0;
        const rrpMin = prod.rrp_min !== null && prod.rrp_min !== undefined ? parseFloat(prod.rrp_min) : null;
        const rrpMax = prod.rrp_max !== null && prod.rrp_max !== undefined ? parseFloat(prod.rrp_max) : null;
        
        const badgeCostoHtml = costo > 0 ? `<span class="px-1 badge-costo rounded">Costo: ${window.qkFormatEuro(costo)}</span>` : '';
        const badgeMinHtml = rrpMin !== null ? `<span class="px-1 badge-rrp-min rounded fw-bold">Min: ${window.qkFormatEuro(rrpMin)}</span>` : '';
        const badgeMaxHtml = rrpMax !== null ? `<span class="px-1 badge-rrp-max rounded fw-bold">Max: ${window.qkFormatEuro(rrpMax)}</span>` : '';

        html += `
          <div class="col-12 col-sm-6 col-md-4 col-lg-3">
            <div class="qk-card-prod-catalogo p-2 border rounded shadow-xs h-100" style="cursor:pointer;" onclick="aggiungiProdottoDaCatalogo(${index})">
              <div class="fw-bold small text-truncate" title="${window.qkEscapeHtml(label)}">${window.qkEscapeHtml(label)}</div>
              <div class="text-muted font-monospace" style="font-size:0.75rem;">${window.qkEscapeHtml(sku)}</div>
              <div class="text-muted" style="font-size:0.75rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Conf: ${window.qkEscapeHtml(confezione)}</div>
              <div class="qk-flex-row qk-gap-xs mt-1" style="font-size:0.72rem;">
                ${badgeCostoHtml}
                ${badgeMinHtml}
                ${badgeMaxHtml}
              </div>
            </div>
          </div>
        `;
      });
      elCatGrid.innerHTML = html;
    } catch (err) {
      console.error(err);
      elCatGrid.innerHTML = `<div class="col-12 text-center text-danger py-3 small">Errore durante la ricerca</div>`;
    }
  }

  window.aggiungiProdottoDaCatalogo = function (index) {
    const prod = catalogoProdottiData[index];
    if (!prod) return;
    
    let targetTr = null;
    const rows = document.querySelectorAll('#tbody-righe tr');
    for (let tr of rows) {
      const prodId = tr.querySelector('.inp-prodotto-id').value;
      const searchVal = tr.querySelector('.riga-search').value;
      if (!prodId && !searchVal) {
        targetTr = tr;
        break;
      }
    }
    
    if (!targetTr) {
      targetTr = buildRiga();
    }
    
    targetTr.querySelector('.inp-prodotto-id').value  = prod.id;
    targetTr.querySelector('.inp-desc').value         = prod.label;
    targetTr.querySelector('.inp-qta').value          = '1';
    targetTr.querySelector('.inp-pu').value           = '';
    
    syncRowMetadata(targetTr, {
      uomStock: prod.unita_misura || 'pz',
      uomStockId: prod.unita_misura_stock_id || '',
      uomCommercial: prod.unita_misura_commerciale || prod.unita_misura || 'pz',
      uomCommercialId: prod.unita_contenuto_id || prod.unita_misura_stock_id || '',
      quantitaContenuto: prod.quantita_contenuto || '1',
      sku: prod.sku || '',
      quantitaConfezione: prod.quantita_confezione || '',
      costoBase: prod.costo_base || '0',
      rrpMin: prod.rrp_min !== null && prod.rrp_min !== undefined ? prod.rrp_min : '',
      rrpMax: prod.rrp_max !== null && prod.rrp_max !== undefined ? prod.rrp_max : ''
    });

    setRowUomMode(targetTr, currentUomMode, 'stock');

    targetTr.querySelector('.riga-search').value = prod.label;
    calcTotali();
    checkRowCompliance(targetTr);
    fetchQuotePrice(targetTr);
    window.qkToast('Prodotto aggiunto al preventivo!', 'success');
  };

  if (elCatSearch) {
    elCatSearch.addEventListener('input', window.qkDebounce(() => {
      renderCatalogo(elCatSearch.value);
    }, 250));
  }

  /* ─────────────────────────────────────────────────────────────────────────
     MODALI HELPER
     ───────────────────────────────────────────────────────────────────────── */
  let _pendingRigaTr = null;

  function openModal(id, rigaTr = null) {
    if (id === 'modal-nuovo-prodotto') _pendingRigaTr = rigaTr;
    const el = document.getElementById(id);
    if (el) new bootstrap.Modal(el).show();
  }

  function showModalError(prefix, msg) {
    const el    = document.getElementById(prefix + '-error');
    const msgEl = document.getElementById(prefix + '-error-msg');
    if (el && msgEl) { msgEl.textContent = msg; el.style.display = ''; }
  }
  function hideModalError(prefix) {
    const el = document.getElementById(prefix + '-error');
    if (el) el.style.display = 'none';
  }

  document.querySelectorAll('#form-nuovo-cliente input[name="tipo_soggetto"]').forEach(radio => {
    radio.addEventListener('change', () => {
      const isPriv = radio.value === 'privato';
      document.getElementById('mnc-rs-wrap').classList.toggle('d-none', isPriv);
      document.getElementById('mnc-nc-wrap').classList.toggle('d-none', !isPriv);
    });
  });

  /* ─────────────────────────────────────────────────────────────────────────
     SALVA NUOVO CLIENTE (AJAX)
     ───────────────────────────────────────────────────────────────────────── */
  document.getElementById('btn-nuovo-cliente').addEventListener('click', () => openModal('modal-nuovo-cliente'));

  document.getElementById('btn-salva-cliente').addEventListener('click', async () => {
    hideModalError('mnc');
    const form = document.getElementById('form-nuovo-cliente');
    const fd   = new FormData(form);
    fd.append('csrf_token', CSRF);

    const btn  = document.getElementById('btn-salva-cliente');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvataggio…';

    try {
      const res  = await fetch(BASE + '/sales/quotes/api/crea-cliente', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) {
        selectCliente(data);
        form.reset();
        bootstrap.Modal.getInstance(document.getElementById('modal-nuovo-cliente')).hide();
      } else {
        showModalError('mnc', data.error || 'Errore sconosciuto');
      }
    } catch (e) {
      showModalError('mnc', 'Errore di rete: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Crea cliente';
    }
  });

  /* ─────────────────────────────────────────────────────────────────────────
     SALVA NUOVO PRODOTTO (AJAX)
     ───────────────────────────────────────────────────────────────────────── */
  document.getElementById('btn-nuovo-prodotto')?.addEventListener('click', () => openModal('modal-nuovo-prodotto', null));

  document.getElementById('btn-salva-prodotto').addEventListener('click', async () => {
    hideModalError('mnp');
    const form = document.getElementById('form-nuovo-prodotto');
    const fd   = new FormData(form);
    fd.append('csrf_token', CSRF);

    const btn  = document.getElementById('btn-salva-prodotto');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvataggio…';

    try {
      const res  = await fetch(BASE + '/sales/quotes/api/crea-prodotto', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) {
        if (_pendingRigaTr) {
          _pendingRigaTr.querySelector('.inp-prodotto-id').value  = data.id;
          _pendingRigaTr.querySelector('.inp-desc').value         = data.label;
          _pendingRigaTr.querySelector('.inp-um').value           = data.unita_misura || '';
          _pendingRigaTr.querySelector('.riga-search').value      = data.label;
          _pendingRigaTr.querySelector('[data-sku]').textContent  = data.sku ? 'SKU: ' + data.sku : '';
          _pendingRigaTr = null;
        } else {
          buildRiga({ descrizione: data.label, unita_misura: data.unita_misura, prodotto_id: data.id });
          const rows = document.querySelectorAll('#tbody-righe tr');
          const last = rows[rows.length - 1];
          if (last) last.querySelector('.riga-search').value = data.label;
        }
        form.reset();
        calcTotali();
        bootstrap.Modal.getInstance(document.getElementById('modal-nuovo-prodotto')).hide();
      } else {
        showModalError('mnp', data.error || 'Errore sconosciuto');
      }
    } catch (e) {
      showModalError('mnp', 'Errore di rete: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Crea prodotto';
    }
  });

  /* ─────────────────────────────────────────────────────────────────────────
     VALIDAZIONE CLIENT-SIDE PRIMA DEL SUBMIT
     ───────────────────────────────────────────────────────────────────────── */
  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function checkRowCompliance(tr) {
    const clienteId = document.getElementById('cliente_id').value;
    const prodottoId = tr.querySelector('.inp-prodotto-id').value;
    const warnDiv = tr.querySelector('.riga-compliance-warn');
    
    if (!clienteId || !prodottoId || !warnDiv) {
      if (warnDiv) {
        warnDiv.style.display = 'none';
        warnDiv.innerHTML = '';
      }
      return;
    }
    
    const fd = new FormData();
    fd.set('cliente_id', clienteId);
    fd.set('prodotto_id', prodottoId);
    
    const csrfEl = document.querySelector('input[name="csrf_token"]');
    if (csrfEl) fd.set('csrf_token', csrfEl.value);

    fetch(BASE + '/sales/quotes/api/check-compliance', {
      method: 'POST',
      body: fd
    })
    .then(res => res.json())
    .then(data => {
      if (!data.ok && data.violations && data.violations.length > 0) {
        warnDiv.style.display = 'block';
        warnDiv.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> Non autorizzato: ${esc(data.violations[0].restriction_name)}`;
      } else {
        warnDiv.style.display = 'none';
        warnDiv.innerHTML = '';
      }
    })
    .catch(err => {
      console.error('Compliance check error:', err);
    });
  }

  function checkAllRowsCompliance() {
    document.querySelectorAll('#tbody-righe tr').forEach(tr => {
      checkRowCompliance(tr);
    });
  }

  setTimeout(checkAllRowsCompliance, 300);

  document.getElementById('form-offerta').addEventListener('submit', function (e) {
    const clienteId = document.getElementById('cliente_id').value;
    if (!clienteId) {
      e.preventDefault();
      document.getElementById('cliente_search').focus();
      document.getElementById('cliente_search').classList.add('is-invalid');
      setTimeout(() => document.getElementById('cliente_search').classList.remove('is-invalid'), 2500);
      alert('Seleziona un cliente prima di salvare.');
      return;
    }

    const btn = e.submitter;
    if (btn && btn.name === 'stato' && btn.value) {
      document.querySelector('input[name="stato"]') || (() => {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = 'stato'; h.value = btn.value;
        this.appendChild(h);
      })();
    }
  });

  /* ─────────────────────────────────────────────────────────────────────────
     TABS & DYNAMIC CUSTOMER DETAILS LOADING (Odoo style)
     ───────────────────────────────────────────────────────────────────────── */
  window.switchQuoteTab = function(tabId) {
    document.querySelectorAll('.qk-nav-tabs .nav-link').forEach(btn => {
      btn.classList.remove('active');
      btn.style.borderBottomColor = 'transparent';
      btn.style.color = 'var(--qk-text-muted)';
    });
    const activeBtn = document.getElementById('tab-' + tabId + '-btn');
    if (activeBtn) {
      activeBtn.classList.add('active');
      activeBtn.style.borderBottomColor = 'var(--qk-accent)';
      activeBtn.style.color = 'var(--qk-accent-text)';
    }

    document.querySelectorAll('.tab-content .tab-pane').forEach(pane => {
      pane.classList.add('d-none');
      pane.classList.remove('active');
    });
    const activePane = document.getElementById('pane-' + tabId);
    if (activePane) {
      activePane.classList.remove('d-none');
      activePane.classList.add('active');
    }
  };

  const spedSelect = document.getElementById('quote-indirizzo-spedizione-id');
  const contSelect = document.getElementById('quote-contatto-id');
  const contNomeInput = document.getElementById('quote-contatto-nome');
  const contTelInput = document.getElementById('quote-contatto-telefono');
  const contEmailInput = document.getElementById('quote-contatto-email');
  const custTelHidden = document.getElementById('customer-generale-telefono');
  const custEmailHidden = document.getElementById('customer-generale-email');
  const condPagInput = document.getElementById('quote-condizioni-pagamento');

  const elSpedFieldsBox     = document.getElementById('sped-fields-box');
  const elBtnCancelEditSped = document.getElementById('btn-cancel-edit-sped');

  const inpConsegnaVia       = document.getElementById('consegna_via');
  const inpConsegnaCivico    = document.getElementById('consegna_civico');
  const inpConsegnaCap       = document.getElementById('consegna_cap');
  const inpConsegnaCitta     = document.getElementById('consegna_citta');
  const inpConsegnaProvincia = document.getElementById('consegna_provincia');
  const inpConsegnaNazione   = document.getElementById('consegna_nazione');
  const inpConsegnaPresso    = document.getElementById('consegna_presso');
  const inpConsegnaReferente = document.getElementById('consegna_referente');
  const inpConsegnaTelefono  = document.getElementById('consegna_telefono');
  const inpConsegnaNote      = document.getElementById('consegna_note');
  const divSalvaAnagrafica   = document.getElementById('div-salva-in-anagrafica-addr');
  const chkSalvaAnagrafica   = document.getElementById('salva_in_anagrafica_indirizzo');

  let loadedContacts = [];
  let loadedAddresses = [];

  async function loadClienteDettagli() {
    if (!spedSelect || !contSelect) return;
    const clienteId = elClienteId.value;
    if (!clienteId) {
      spedSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
      contSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
      return;
    }

    spedSelect.innerHTML = '<option value="">Caricamento...</option>';
    contSelect.innerHTML = '<option value="">Caricamento...</option>';
    
    try {
      const res = await fetch(`${BASE}/sales/quotes/api/cliente-dettagli?cliente_id=${clienteId}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Errore recupero dettagli');

      loadedContacts = data.contatti || [];
      loadedAddresses = data.indirizzi || [];
      
      if (custTelHidden) custTelHidden.value = data.cliente?.telefono || '';
      if (custEmailHidden) custEmailHidden.value = data.cliente?.email || data.cliente?.cellulare || '';

      if (elHelperText && elHelperContent) {
        const parts = [data.cliente?.email || data.cliente?.cellulare, data.cliente?.telefono].filter(Boolean);
        if (parts.length > 0) {
          elHelperContent.textContent = parts.join(' · ');
          elHelperText.style.display = '';
        } else {
          elHelperText.style.display = 'none';
        }
      }

      if (condPagInput && !condPagInput.value && data.cliente?.condizioni_pagamento) {
        condPagInput.value = data.cliente.condizioni_pagamento;
      }

      const listinoSelect = document.getElementById('quote-listino-id');
      if (listinoSelect && !listinoSelect.value && data.cliente?.listino_id) {
        listinoSelect.value = data.cliente.listino_id;
        refetchAllRowPrices();
      }

      spedSelect.innerHTML = '<option value="">— Nessun indirizzo specifico (Usa sede legale) —</option>';
      const selectedSpedId = spedSelect.dataset.selectedId;
      
      loadedAddresses.forEach(ind => {
        const option = document.createElement('option');
        option.value = ind.id;
        
        const labelParts = [];
        if (ind.nome_sede) labelParts.push(`[${ind.nome_sede}]`);
        if (ind.citta) labelParts.push(ind.citta);
        option.textContent = labelParts.join(' · ');

        if (selectedSpedId && String(ind.id) === String(selectedSpedId)) {
          option.selected = true;
        }
        spedSelect.appendChild(option);
      });

      const customOption = document.createElement('option');
      customOption.value = 'custom';
      customOption.textContent = '+ Inserisci indirizzo personalizzato (una tantum)...';
      if (selectedSpedId === 'custom' || (!selectedSpedId && inpConsegnaVia && inpConsegnaVia.value)) {
        customOption.selected = true;
      }
      spedSelect.appendChild(customOption);

      syncAddressUI();

      contSelect.innerHTML = '<option value="">— Nessuno (Usa recapiti generali azienda) —</option>';
      const selectedContId = contSelect.dataset.selectedId;
      data.contatti.forEach(c => {
        const option = document.createElement('option');
        option.value = c.id;
        option.textContent = `${c.nome_completo} (${c.email || c.telefono || 'nessun recapito'})`;
        if (selectedContId && String(c.id) === String(selectedContId)) {
          option.selected = true;
        }
        contSelect.appendChild(option);
      });
      
      syncContactUI();

    } catch (err) {
      spedSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
      contSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
    }
  }

  function getOriginalAddress() {
    const val = spedSelect.value;
    if (!val) {
      return loadedAddresses.find(i => i.tipo === 'sede_legale') || loadedAddresses[0] || {};
    }
    if (val === 'custom') {
      return {};
    }
    return loadedAddresses.find(i => String(i.id) === String(val)) || {};
  }

  function clearOverrideStylesSped() {
    [inpConsegnaVia, inpConsegnaCivico, inpConsegnaCap, inpConsegnaCitta, inpConsegnaProvincia,
     inpConsegnaNazione, inpConsegnaPresso, inpConsegnaReferente, inpConsegnaTelefono, inpConsegnaNote]
    .forEach(inp => {
      if (inp) inp.classList.remove('is-overridden');
    });
  }

  function syncAddressUI() {
    const val = spedSelect.value;
    if (elSpedFieldsBox) elSpedFieldsBox.style.display = 'block';

    if (val === 'custom') {
      if (divSalvaAnagrafica) divSalvaAnagrafica.style.display = 'flex';
      document.getElementById('sped-state-badge').textContent = 'Recapiti manuali / una tantum';
      document.getElementById('sped-state-badge').className = 'badge bg-info text-dark ms-2';
      if (elBtnCancelEditSped) elBtnCancelEditSped.style.display = 'none';
      clearOverrideStylesSped();
      return;
    }

    const ind = getOriginalAddress();
    const isNewForm = !inpConsegnaVia.value;
    if (isNewForm && ind.via && quoteStato === 'bozza') {
      copyAddressToFields(ind);
    }
    
    let hasOverride = false;
    const checkField = (inputEl, origValue) => {
      if (!inputEl) return;
      const cur = inputEl.value.trim();
      const ref = (origValue ?? '').trim();
      if (cur !== ref) {
        inputEl.classList.add('is-overridden');
        hasOverride = true;
      } else {
        inputEl.classList.remove('is-overridden');
      }
    };

    checkField(inpConsegnaVia, ind.via);
    checkField(inpConsegnaCivico, ind.civico);
    checkField(inpConsegnaCap, ind.cap);
    checkField(inpConsegnaCitta, ind.citta);
    checkField(inpConsegnaProvincia, ind.provincia);
    checkField(inpConsegnaNazione, ind.nazione || 'IT');
    checkField(inpConsegnaPresso, ind.presso);
    checkField(inpConsegnaReferente, ind.referente_consegna);
    checkField(inpConsegnaTelefono, ind.telefono_consegna);
    checkField(inpConsegnaNote, ind.note_consegna);

    const badge = document.getElementById('sped-state-badge');
    if (hasOverride) {
      badge.textContent = 'Modificato localmente (override)';
      badge.className = 'badge bg-warning text-dark ms-2';
      if (elBtnCancelEditSped) elBtnCancelEditSped.style.display = 'inline-block';
      if (divSalvaAnagrafica) divSalvaAnagrafica.style.display = 'flex';
    } else {
      badge.textContent = val ? '✓ Sincronizzato con anagrafica' : '— Usa sede legale —';
      badge.className = val ? 'badge bg-success text-white ms-2' : 'badge bg-secondary text-white ms-2';
      if (elBtnCancelEditSped) elBtnCancelEditSped.style.display = 'none';
      if (divSalvaAnagrafica) divSalvaAnagrafica.style.display = 'none';
    }
  }

  function copyAddressToFields(ind) {
    const elNomeSede = document.getElementById('consegna_nome_sede');
    if (elNomeSede) elNomeSede.value = ind.nome_sede || '';
    if (inpConsegnaVia) inpConsegnaVia.value = ind.via || '';
    if (inpConsegnaCivico) inpConsegnaCivico.value = ind.civico || '';
    if (inpConsegnaCap) inpConsegnaCap.value = ind.cap || '';
    if (inpConsegnaCitta) inpConsegnaCitta.value = ind.citta || '';
    if (inpConsegnaProvincia) inpConsegnaProvincia.value = ind.provincia || '';
    if (inpConsegnaNazione) inpConsegnaNazione.value = ind.nazione || 'IT';
    if (inpConsegnaPresso) inpConsegnaPresso.value = ind.presso || '';
    if (inpConsegnaReferente) inpConsegnaReferente.value = ind.referente_consegna || '';
    if (inpConsegnaTelefono) inpConsegnaTelefono.value = ind.telefono_consegna || '';
    if (inpConsegnaNote) inpConsegnaNote.value = ind.note_consegna || '';
  }

  function clearAddressFields() {
    const elNomeSede = document.getElementById('consegna_nome_sede');
    if (elNomeSede) elNomeSede.value = '';
    [inpConsegnaVia, inpConsegnaCivico, inpConsegnaCap, inpConsegnaCitta, inpConsegnaProvincia, inpConsegnaPresso, inpConsegnaReferente, inpConsegnaTelefono, inpConsegnaNote].forEach(inp => {
      if (inp) inp.value = '';
    });
    if (inpConsegnaNazione) inpConsegnaNazione.value = 'IT';
    if (chkSalvaAnagrafica) chkSalvaAnagrafica.checked = false;
  }

  [inpConsegnaVia, inpConsegnaCivico, inpConsegnaCap, inpConsegnaCitta, inpConsegnaProvincia, inpConsegnaNazione, inpConsegnaPresso, inpConsegnaReferente, inpConsegnaTelefono, inpConsegnaNote].forEach(inp => {
    if (inp) {
      inp.addEventListener('input', syncAddressUI);
    }
  });

  if (spedSelect) {
    spedSelect.addEventListener('change', () => {
      clearAddressFields();
      const val = spedSelect.value;
      if (val && val !== 'custom') {
        const ind = loadedAddresses.find(i => String(i.id) === String(val));
        if (ind) {
          copyAddressToFields(ind);
        }
      } else if (!val) {
        const ind = getOriginalAddress();
        if (ind && ind.via) {
          copyAddressToFields(ind);
        }
      }
      syncAddressUI();
    });
  }

  if (elBtnCancelEditSped) {
    elBtnCancelEditSped.addEventListener('click', () => {
      const val = spedSelect.value;
      if (val && val !== 'custom') {
        const ind = loadedAddresses.find(i => String(i.id) === String(val));
        if (ind) copyAddressToFields(ind);
      } else {
        const ind = getOriginalAddress();
        if (ind && ind.via) {
          copyAddressToFields(ind);
        } else {
          clearAddressFields();
        }
      }
      syncAddressUI();
    });
  }

  function getOriginalContact() {
    const val = contSelect.value;
    if (!val) {
      return {};
    }
    return loadedContacts.find(c => String(c.id) === String(val)) || {};
  }

  function syncContactUI() {
    const val = contSelect.value;
    const orig = getOriginalContact();
    
    let hasOverride = false;
    const checkField = (inputEl, origValue) => {
      if (!inputEl) return;
      const cur = inputEl.value.trim();
      const ref = (origValue ?? '').trim();
      if (cur !== ref) {
        inputEl.classList.add('is-overridden');
        hasOverride = true;
      } else {
        inputEl.classList.remove('is-overridden');
      }
    };
    
    checkField(contNomeInput, orig.nome_completo || '');
    checkField(contTelInput, orig.telefono || orig.cellulare || custTelHidden?.value || '');
    checkField(contEmailInput, orig.email || custEmailHidden?.value || '');
    
    const badge = document.getElementById('cont-state-badge');
    const resetBtn = document.getElementById('btn-cancel-edit-cont');
    
    if (!val) {
      badge.textContent = 'Recapiti manuali / una tantum';
      badge.className = 'badge bg-info text-dark ms-2';
      if (resetBtn) resetBtn.style.display = 'none';
      [contNomeInput, contTelInput, contEmailInput].forEach(inp => {
        if (inp) inp.classList.remove('is-overridden');
      });
    } else if (hasOverride) {
      badge.textContent = 'Modificato localmente (override)';
      badge.className = 'badge bg-warning text-dark ms-2';
      if (resetBtn) resetBtn.style.display = 'inline-block';
    } else {
      badge.textContent = '✓ Sincronizzato con anagrafica';
      badge.className = 'badge bg-success text-white ms-2';
      if (resetBtn) resetBtn.style.display = 'none';
    }
  }

  [contNomeInput, contTelInput, contEmailInput].forEach(inp => {
    if (inp) {
      inp.addEventListener('input', syncContactUI);
    }
  });

  if (contSelect) {
    contSelect.addEventListener('change', () => {
      const selectedId = contSelect.value;
      if (!selectedId) {
        if (contTelInput) contTelInput.value = custTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = custEmailHidden?.value || '';
        if (contNomeInput) contNomeInput.value = '';
      } else {
        const contact = loadedContacts.find(c => String(c.id) === String(selectedId));
        if (contact) {
          if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || custTelHidden?.value || '';
          if (contEmailInput) contEmailInput.value = contact.email || custEmailHidden?.value || '';
          if (contNomeInput) contNomeInput.value = contact.nome_completo || '';
        }
      }
      syncContactUI();
    });
  }

  const btnCancelEditCont = document.getElementById('btn-cancel-edit-cont');
  if (btnCancelEditCont) {
    btnCancelEditCont.addEventListener('click', () => {
      const contact = getOriginalContact();
      if (contact && contact.nome_completo) {
        if (contNomeInput) contNomeInput.value = contact.nome_completo || '';
        if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || custTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = contact.email || custEmailHidden?.value || '';
      } else {
        if (contNomeInput) contNomeInput.value = '';
        if (contTelInput) contTelInput.value = custTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = custEmailHidden?.value || '';
      }
      syncContactUI();
    });
  }

  if (quoteStato !== 'bozza') {
    [spedSelect, contSelect, inpConsegnaVia, inpConsegnaCivico, inpConsegnaCap, inpConsegnaCitta, 
     inpConsegnaProvincia, inpConsegnaNazione, inpConsegnaPresso, inpConsegnaReferente, 
     inpConsegnaTelefono, inpConsegnaNote, contNomeInput, contTelInput, contEmailInput, chkSalvaAnagrafica]
    .forEach(el => {
      if (el) {
        el.disabled = true;
        el.readOnly = true;
      }
    });
    [elBtnCancelEditSped, btnCancelEditCont].forEach(el => {
      if (el) el.style.display = 'none';
    });
  }

  async function fetchQuotePrice(tr) {
    const prodottoId = tr.querySelector('.inp-prodotto-id')?.value;
    const listinoId = document.getElementById('quote-listino-id')?.value;
    const qtyInput = tr.querySelector('.inp-qta');
    const priceInput = tr.querySelector('.inp-pu');
    const clienteId = document.getElementById('cliente_id')?.value || '';

    if (!prodottoId || !qtyInput || !priceInput) return;

    const qty = parseFloat(qtyInput.value) || 1.0;
    
    try {
      const res = await fetch(`${BASE}/sales/pricelists/api/ottieni-prezzo?prodotto_id=${prodottoId}&listino_id=${listinoId || ''}&cliente_id=${clienteId}&quantita=${qty}`);
      const data = await res.json();
      
      if (data.ok) {
        let computedPrice = parseFloat(data.prezzo_unitario) || 0;
        
        const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
        const nativeUomId = data.unita_misura_id ? parseInt(data.unita_misura_id) : null;
        const isNativeCommercial = (nativeUomId && nativeUomId === parseInt(tr.dataset.uomCommercialId));
        
        if (isNativeCommercial && currentUomMode === 'stock' && qFactor > 0) {
          computedPrice = computedPrice * qFactor;
        } else if (!isNativeCommercial && currentUomMode === 'commercial' && qFactor > 0) {
          computedPrice = computedPrice / qFactor;
        }
        
        priceInput.value = parseFloat(computedPrice.toFixed(4));
        
        const discountInput = tr.querySelector('.inp-sc');
        if (discountInput && data.sconto_perc !== undefined) {
          discountInput.value = parseFloat(data.sconto_perc) || 0;
        }

        if (data.costo_unitario !== undefined) {
          tr.dataset.costoBase = data.costo_unitario;
        }
        if (data.rrp_min !== undefined) {
          tr.dataset.rrpMin = data.rrp_min !== null ? data.rrp_min : '';
        }
        if (data.rrp_max !== undefined) {
          tr.dataset.rrpMax = data.rrp_max !== null ? data.rrp_max : '';
        }
        calcTotali();
      }

      const badge = tr.querySelector('.price-origin-badge');
      if (badge && data.origine) {
        let iconHtml = '';
        if (data.origine === 'dedicato') {
          iconHtml = `<i class="fa-solid fa-tag" style="color:var(--qk-accent)" title="${data.prezzo_sorgente}"></i>`;
        } else if (data.origine === 'standard') {
          iconHtml = `<i class="fa-solid fa-globe" style="color:var(--qk-text-muted)" title="${data.prezzo_sorgente}"></i>`;
        } else if (data.origine === 'costo') {
          iconHtml = `<i class="fa-solid fa-circle-exclamation" style="color:var(--qk-warning)" title="${data.prezzo_sorgente}"></i>`;
        } else {
          iconHtml = `<i class="fa-solid fa-triangle-exclamation" style="color:var(--qk-danger)" title="${data.prezzo_sorgente}"></i>`;
        }
        badge.innerHTML = iconHtml;
        badge.title = data.prezzo_sorgente || '';
      }

      calcTotali();
    } catch (e) {
      console.error('Error fetching listino price:', e);
    }
  }

  function refetchAllRowPrices() {
    document.querySelectorAll('#tbody-righe tr').forEach(tr => {
      fetchQuotePrice(tr);
    });
  }

  document.getElementById('quote-listino-id')?.addEventListener('change', () => {
    refetchAllRowPrices();
  });

  if (elClienteId && elClienteId.value) {
    loadClienteDettagli();
  }

  refetchAllRowPrices();

  /* ── Mostra/Nascondi Colonne Odoo-Style ────────────────────────────── */
  if (btnColSettings && colSettingsDropdown) {
    btnColSettings.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = colSettingsDropdown.style.display === 'block';
      colSettingsDropdown.style.display = isVisible ? 'none' : 'block';
    });

    document.addEventListener('click', (e) => {
      if (!colSettingsDropdown.contains(e.target) && e.target !== btnColSettings) {
        colSettingsDropdown.style.display = 'none';
      }
    });
  }

  function updateColumnVisibility() {
    const showConf = chkShowConf ? chkShowConf.checked : true;
    const showSconto = chkShowSconto ? chkShowSconto.checked : false;
    const showIva = chkShowIva ? chkShowIva.checked : false;
    const showRange = chkShowRange ? chkShowRange.checked : true;

    document.querySelectorAll('.col-conf').forEach(el => {
      el.style.display = showConf ? '' : 'none';
    });
    document.querySelectorAll('.col-sc').forEach(el => {
      el.style.display = showSconto ? '' : 'none';
    });
    document.querySelectorAll('.col-iva').forEach(el => {
      el.style.display = showIva ? '' : 'none';
    });
    document.querySelectorAll('.col-range').forEach(el => {
      el.style.display = showRange ? '' : 'none';
    });
  }

  [chkShowConf, chkShowSconto, chkShowIva, chkShowRange].forEach(chk => {
    if (chk) {
      chk.addEventListener('change', updateColumnVisibility);
    }
  });

  updateColumnVisibility();
});
