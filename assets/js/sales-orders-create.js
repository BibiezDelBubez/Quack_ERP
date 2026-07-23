/**
 * assets/js/sales-orders-create.js — Gestione creazione ordini cliente diretti
 */
document.addEventListener('DOMContentLoaded', function () {
'use strict';

const CSRF = window.QK_ORDERS_CREATE_CONFIG.csrf;
const BASE = (typeof QK_BASE !== 'undefined') ? QK_BASE : '';

// ─── Utilità ──────────────────────────────────────────────────────────────
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function apiFetch(url, opts) {
  try {
    const r = await fetch(BASE + url, opts || { credentials: 'same-origin' });
    return await r.json();
  } catch (e) { return []; }
}

function openModal(id) {
  const el = document.getElementById(id);
  if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
}

// ─── Tab switch ───────────────────────────────────────────────────────────
window.switchOrdTab = function(name) {
  document.querySelectorAll('#form-ordine-diretto .qk-nav-tabs .nav-link').forEach((btn,i) => {
    const pane = ['prodotti','spedizione','note'][i];
    const active = pane === name;
    btn.classList.toggle('active', active);
    document.getElementById('ord-pane-' + pane)?.classList.toggle('d-none', !active);
    document.getElementById('ord-pane-' + pane)?.classList.toggle('active', active);
  });
};

// ─── Gestione cliente ──────────────────────────────────────────────────────
const elClienteId    = document.getElementById('ord-cliente-id');
const elClienteLabel = document.getElementById('ord-cliente-label');
const elClienteSearch= document.getElementById('ord-cliente-search');
const elClienteDrop  = document.getElementById('ord-ac-clienti-drop');
const elBtnClear     = document.getElementById('ord-btn-clear-cliente');
const elHelper       = document.getElementById('ord-cliente-helper');
const elHelperContent= document.getElementById('ord-cliente-helper-content');

let _spedOriginal = {}; // dati originali dell'indirizzo selezionato
let _contOriginal = {};

function selectCliente(item) {
  elClienteId.value    = item.id;
  elClienteLabel.value = item.label;
  elClienteSearch.value = item.label;
  if (elBtnClear) elBtnClear.classList.remove('d-none');
  const parts = [item.email, item.telefono].filter(Boolean);
  if (parts.length > 0 && elHelper) {
    elHelperContent.textContent = parts.join(' · ');
    elHelper.style.display = '';
  }
  loadClienteDettagli(item.id);
}

function clearCliente() {
  elClienteId.value = '';
  elClienteLabel.value = '';
  elClienteSearch.value = '';
  if (elBtnClear) elBtnClear.classList.add('d-none');
  if (elHelper) elHelper.style.display = 'none';
  const spedSel = document.getElementById('ord-indirizzo-spedizione-id');
  const contSel = document.getElementById('ord-contatto-id');
  if (spedSel) spedSel.innerHTML = '<option value="">Seleziona cliente prima...</option>';
  if (contSel) contSel.innerHTML = '<option value="">Seleziona cliente prima...</option>';
  document.getElementById('ord-sped-fields-box').style.display = 'none';
}

elBtnClear?.addEventListener('click', clearCliente);
elClienteSearch?.addEventListener('input', () => { if (!elClienteSearch.value.trim()) clearCliente(); });

window.qkAutocomplete({
  input:    elClienteSearch,
  dropdown: elClienteDrop,
  fetchFn:  (q) => apiFetch('/sales/orders/api/clienti?q=' + encodeURIComponent(q)),
  onSelect: selectCliente,
  onCreate: () => openModal('ord-modal-nuovo-cliente'),
  createLabel: 'Crea nuovo cliente',
});

async function loadClienteDettagli(clienteId) {
  const data = await apiFetch('/sales/orders/api/cliente-dettagli?cliente_id=' + clienteId);
  if (!data || !data.ok) return;

  // Precompila condizioni pagamento
  if (data.cliente?.condizioni_pagamento) {
    const elCond = document.getElementById('ord-condizioni-pagamento');
    if (elCond && !elCond.value) elCond.value = data.cliente.condizioni_pagamento;
  }

  // Precompila listino di vendita
  if (data.cliente?.listino_id) {
    const elListino = document.getElementById('ord-listino-id');
    if (elListino) elListino.value = data.cliente.listino_id;
  }

  // Popola select indirizzi
  const spedSel = document.getElementById('ord-indirizzo-spedizione-id');
  if (spedSel) {
    spedSel.innerHTML = '<option value="">— Nessun indirizzo —</option>';
    if (data.indirizzi && data.indirizzi.length) {
      data.indirizzi.forEach(addr => {
        const star = addr.predefinito == 1 ? ' ★' : '';
        const opt = document.createElement('option');
        opt.value = addr.id;
        opt.textContent = (addr.nome_sede || addr.tipo) + star + '  ·  ' + addr.citta;
        opt.dataset.addr = JSON.stringify(addr);
        spedSel.appendChild(opt);
      });
      spedSel.appendChild((() => { const o = document.createElement('option'); o.value = 'custom'; o.textContent = '+ Inserisci indirizzo una tantum...'; return o; })());
      // Auto-selezione predefinito
      const defIdx = Array.from(spedSel.options).findIndex(o => {
        try { return o.dataset.addr && JSON.parse(o.dataset.addr).predefinito == 1; } catch(e){ return false; }
      });
      if (defIdx > 0) { spedSel.selectedIndex = defIdx; onSpedSelChange(); }
    } else {
      spedSel.innerHTML = '<option value="custom">+ Inserisci indirizzo una tantum...</option>';
      onSpedSelChange();
    }
  }

  // Popola select contatti
  const contSel = document.getElementById('ord-contatto-id');
  if (contSel) {
    contSel.innerHTML = '<option value="">— Nessun referente specifico —</option>';
    if (data.contatti && data.contatti.length) {
      data.contatti.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.nome_completo + (c.email ? ' (' + c.email + ')' : '');
        opt.dataset.nome = c.nome_completo || '';
        opt.dataset.email = c.email || '';
        opt.dataset.telefono = c.telefono || c.cellulare || '';
        contSel.appendChild(opt);
      });
    }
    contSel.addEventListener('change', onContSelChange);
    if (data.contatti?.length) {
      // Auto-selezione primo contatto
      contSel.selectedIndex = 1;
      onContSelChange();
    }
  }
}

function onSpedSelChange() {
  const spedSel = document.getElementById('ord-indirizzo-spedizione-id');
  const box     = document.getElementById('ord-sped-fields-box');
  const divSalva= document.getElementById('ord-div-salva-anagrafica');
  if (!spedSel || !box) return;

  const selected = spedSel.options[spedSel.selectedIndex];
  if (!selected || !selected.value) { box.style.display = 'none'; return; }

  box.style.display = '';

  if (selected.value === 'custom') {
    // Form vuoto per inserimento manuale
    ['via','civico','cap','citta','provincia','nazione','presso','referente','telefono','note'].forEach(f => {
      const el = document.getElementById('ord-consegna-' + f);
      if (el) el.value = f === 'nazione' ? 'IT' : '';
    });
    _spedOriginal = {};
    if (divSalva) divSalva.style.display = 'flex';
    document.getElementById('ord-sped-state-badge').innerHTML = ' <span class="qk-badge draft" style="font-size:.72rem;">Una tantum</span>';
    return;
  }

  if (divSalva) divSalva.style.display = 'none';
  let addr = {};
  try { addr = JSON.parse(selected.dataset.addr || '{}'); } catch (e) { console.error('Error:', e); }
  _spedOriginal = { ...addr };
  fillSpedFields(addr);
  document.getElementById('ord-sped-state-badge').innerHTML = ' <span class="qk-badge confirmed" style="font-size:.72rem;">Da anagrafica</span>';
}

function fillSpedFields(addr) {
  const map = { via:'via', civico:'civico', cap:'cap', citta:'citta', provincia:'provincia',
                nazione:'nazione', presso:'presso', referente_consegna:'referente',
                telefono_consegna:'telefono', note_consegna:'note' };
  Object.entries(map).forEach(([src, dest]) => {
    const el = document.getElementById('ord-consegna-' + dest);
    if (el) el.value = addr[src] || (dest === 'nazione' ? 'IT' : '');
  });
  const nomeSede = document.getElementById('ord-consegna-nome-sede');
  if (nomeSede) nomeSede.value = addr['nome_sede'] || '';
  markSpedOverrides();
}

function markSpedOverrides() {
  const fields = ['via','civico','cap','citta','provincia','nazione','presso','referente','telefono','note'];
  const addrMap = { via:'via', civico:'civico', cap:'cap', citta:'citta', provincia:'provincia',
                    nazione:'nazione', presso:'presso', referente:'referente_consegna',
                    telefono:'telefono_consegna', note:'note_consegna' };
  fields.forEach(f => {
    const el = document.getElementById('ord-consegna-' + f);
    if (!el || !Object.keys(_spedOriginal).length) return;
    const origVal = _spedOriginal[addrMap[f]] || (f === 'nazione' ? 'IT' : '');
    const changed = el.value !== origVal;
    el.style.borderColor = changed ? 'var(--qk-accent)' : '';
  });
}

document.getElementById('ord-indirizzo-spedizione-id')?.addEventListener('change', onSpedSelChange);

// Override detection
['via','civico','cap','citta','provincia','nazione','presso','referente','telefono','note'].forEach(f => {
  document.getElementById('ord-consegna-' + f)?.addEventListener('input', markSpedOverrides);
});

document.getElementById('ord-btn-ripristina-sped')?.addEventListener('click', () => {
  if (Object.keys(_spedOriginal).length) fillSpedFields(_spedOriginal);
});

function onContSelChange() {
  const sel = document.getElementById('ord-contatto-id');
  if (!sel) return;
  const opt = sel.options[sel.selectedIndex];
  const nome = document.getElementById('ord-contatto-nome');
  const tel  = document.getElementById('ord-contatto-telefono');
  const email= document.getElementById('ord-contatto-email');
  if (nome)  nome.value  = opt?.dataset.nome  || '';
  if (tel)   tel.value   = opt?.dataset.telefono || '';
  if (email) email.value = opt?.dataset.email || '';
}

// ─── Righe prodotto ───────────────────────────────────────────────────────
let righeCount = 0;
let currentUomMode = document.getElementById('ord-uom-mode')?.value || 'commercial';

document.getElementById('ord-uom-mode')?.addEventListener('change', (e) => {
  const newMode = e.target.value;
  document.querySelectorAll('#ord-tbody-righe tr').forEach(tr => {
    setRowUomMode(tr, newMode, currentUomMode);
  });
  currentUomMode = newMode;
  calcTotali();
});

function setRowUomMode(tr, targetMode, currentMode) {
  const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
  const qtyInput   = tr.querySelector('.ord-inp-qta');
  const priceInput = tr.querySelector('.ord-inp-pu');
  const umInput    = tr.querySelector('.ord-inp-um');
  if (!qtyInput || !priceInput || !umInput) return;
  const hasComm = tr.dataset.uomCommercialId && qFactor > 1;
  if (!hasComm) { umInput.value = tr.dataset.uomStock || 'pz'; return; }
  if (targetMode === currentMode) {
    umInput.value = (targetMode === 'stock') ? (tr.dataset.uomStock||'pz') : (tr.dataset.uomCommercial||'pz');
    return;
  }
  const { value: newQty, price: newPrice } = window.qkConvertUom({ value: qtyInput.value, price: priceInput.value, qFactor, targetMode });
  if (qtyInput.value && newQty !== null) qtyInput.value = newQty;
  if (priceInput.value && newPrice !== null) priceInput.value = newPrice;
  umInput.value = (targetMode === 'stock') ? (tr.dataset.uomStock||'pz') : (tr.dataset.uomCommercial||'pz');
}

function buildRiga(prefill = {}) {
  const idx = righeCount++;
  const tr  = document.createElement('tr');
  tr.dataset.idx = idx;
  tr.dataset.uomStock         = prefill.uom_stock || prefill.unita_misura || '';
  tr.dataset.uomStockId       = prefill.uom_stock_id || '';
  tr.dataset.uomCommercial    = prefill.uom_commercial || prefill.unita_misura || '';
  tr.dataset.uomCommercialId  = prefill.uom_commercial_id || '';
  tr.dataset.quantitaContenuto= prefill.quantita_contenuto || '1';
  tr.dataset.costoBase        = prefill.costo_unitario || prefill.costo_base || '0';

  tr.innerHTML = `
    <td class="col-search">
      <div class="qk-ac-wrap">
        <input type="text" class="qk-form-control ord-riga-search" placeholder="Cerca…" autocomplete="off" value="${esc(prefill.descrizione||'')}">
        <div class="qk-ac-dropdown ord-riga-ac-drop"></div>
      </div>
      <div class="riga-sku">${prefill.sku ? 'SKU: '+esc(prefill.sku) : ''}</div>
      <input type="hidden" name="righe[${idx}][prodotto_id]" class="ord-inp-prodotto-id" value="${prefill.prodotto_id||''}">
      <input type="hidden" name="righe[${idx}][uom_stock]"          class="ord-inp-uom-stock"         value="${esc(tr.dataset.uomStock)}">
      <input type="hidden" name="righe[${idx}][uom_stock_id]"       class="ord-inp-uom-stock-id"      value="${esc(tr.dataset.uomStockId)}">
      <input type="hidden" name="righe[${idx}][uom_commercial]"     class="ord-inp-uom-commercial"    value="${esc(tr.dataset.uomCommercial)}">
      <input type="hidden" name="righe[${idx}][uom_commercial_id]"  class="ord-inp-uom-commercial-id" value="${esc(tr.dataset.uomCommercialId)}">
      <input type="hidden" name="righe[${idx}][quantita_contenuto]" class="ord-inp-qcont"             value="${esc(tr.dataset.quantitaContenuto)}">
      <input type="hidden" name="righe[${idx}][sku]"               class="ord-inp-sku"               value="${esc(prefill.sku||'')}">
      <input type="hidden" name="righe[${idx}][costo_unitario]"     class="ord-inp-costo-hidden"      value="${esc(tr.dataset.costoBase)}">
    </td>
    <td class="col-desc">
      <textarea name="righe[${idx}][descrizione]" class="qk-form-control ord-inp-desc" placeholder="Descrizione prodotto" rows="1">${esc(prefill.descrizione||'')}</textarea>
    </td>
    <td class="col-conf">
      <input type="text" name="righe[${idx}][quantita_confezione]" class="qk-form-control" value="${esc(prefill.quantita_confezione||'')}" placeholder="—">
    </td>
    <td class="col-qta">
      <input type="number" name="righe[${idx}][quantita]" class="qk-form-control text-end ord-inp-qta"
             placeholder="0" min="0" step="0.001" value="${prefill.quantita||''}">
    </td>
    <td class="col-um">
      <input type="text" name="righe[${idx}][unita_misura]" class="qk-form-control ord-inp-um"
             placeholder="pz" maxlength="5" value="${esc(prefill.unita_misura||'')}">
    </td>
    <td class="col-costo text-end" style="font-weight:500;">
      <span class="ord-riga-costo-val">—</span>
    </td>
    <td class="col-pu">
      <div style="position:relative;width:100%">
        <input type="number" name="righe[${idx}][prezzo_unitario]" class="qk-form-control text-end ord-inp-pu"
               placeholder="0,00" min="0" step="0.0001" value="${prefill.prezzo_unitario||''}" style="padding-right:20px; width:100%;">
        <span class="ord-price-badge" style="position:absolute;right:4px;font-size:.75rem;cursor:pointer;" title="Nessun prezzo configurato">
          <i class="fa-solid fa-triangle-exclamation" style="color:var(--qk-danger)"></i>
        </span>
      </div>
    </td>
    <td class="col-sc">
      <input type="number" name="righe[${idx}][sconto_perc]" class="qk-form-control text-end ord-inp-sc"
             placeholder="0" min="0" max="100" step="0.01" value="${prefill.sconto_perc||'0'}">
    </td>
    <td class="col-iva">
      <input type="number" name="righe[${idx}][iva_perc]" class="qk-form-control text-end ord-inp-iva"
             placeholder="22" min="0" max="100" step="1" value="${prefill.iva_perc||'22'}">
    </td>
    <td class="col-tot">
      <div class="ord-riga-total">€ 0,00</div>
    </td>
    <td class="col-del">
      <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger ord-riga-del" title="Rimuovi"><i class="fa-solid fa-trash-can"></i></button>
    </td>
  `;

  tr.querySelector('.ord-riga-del').addEventListener('click', () => { tr.remove(); calcTotali(); });

  const puInput = tr.querySelector('.ord-inp-pu');
  const badge   = tr.querySelector('.ord-price-badge');
  puInput?.addEventListener('input', () => {
    if (badge) { badge.innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:var(--qk-warning)"></i>'; badge.title = 'Modificato manualmente'; }
  });
  ['ord-inp-qta','ord-inp-pu','ord-inp-sc','ord-inp-iva'].forEach(cls => {
    tr.querySelector('.' + cls)?.addEventListener('input', calcTotali);
  });

  // Autocomplete prodotto
  const srch = tr.querySelector('.ord-riga-search');
  const drop = tr.querySelector('.ord-riga-ac-drop');
  window.qkAutocomplete({
    input:    srch,
    dropdown: drop,
    fetchFn:  (q) => apiFetch('/sales/orders/api/prodotti?q=' + encodeURIComponent(q)),
    onSelect: (item) => {
      tr.querySelector('.ord-inp-prodotto-id').value  = item.id;
      tr.querySelector('.ord-inp-desc').value         = item.label;
      tr.querySelector('.ord-inp-qta').value          = '1';
      tr.querySelector('.ord-inp-pu').value           = '';
      tr.dataset.uomStock         = item.unita_misura || 'pz';
      tr.dataset.uomStockId       = item.unita_misura_stock_id || '';
      tr.dataset.uomCommercial    = item.unita_misura_commerciale || item.unita_misura || 'pz';
      tr.dataset.uomCommercialId  = item.unita_contenuto_id || item.unita_misura_stock_id || '';
      tr.dataset.quantitaContenuto= item.quantita_contenuto || '1';
      tr.dataset.costoBase        = item.costo_base || '0';
      tr.querySelector('.ord-inp-uom-stock').value         = tr.dataset.uomStock;
      tr.querySelector('.ord-inp-uom-stock-id').value      = tr.dataset.uomStockId;
      tr.querySelector('.ord-inp-uom-commercial').value    = tr.dataset.uomCommercial;
      tr.querySelector('.ord-inp-uom-commercial-id').value = tr.dataset.uomCommercialId;
      tr.querySelector('.ord-inp-qcont').value             = tr.dataset.quantitaContenuto;
      tr.querySelector('.ord-inp-sku').value               = item.sku || '';
      tr.querySelector('.ord-inp-costo-hidden').value      = tr.dataset.costoBase;
      const skuDiv = tr.querySelector('.riga-sku');
      if (skuDiv) skuDiv.textContent = item.sku ? 'SKU: '+item.sku : '';
      setRowUomMode(tr, currentUomMode, 'stock');
      srch.value = item.label;
      fetchOrdinePrice(tr, item);
      calcTotali();
    },
    onCreate: () => openModal('ord-modal-nuovo-prodotto'),
    createLabel: 'Crea nuovo prodotto',
  });

  // Auto-resize textarea descrizione
  const desc = tr.querySelector('.ord-inp-desc');
  if (desc) {
    const resize = () => { desc.style.height='auto'; desc.style.height=desc.scrollHeight+'px'; };
    desc.addEventListener('input', resize);
    setTimeout(resize, 0);
  }

  document.getElementById('ord-tbody-righe').appendChild(tr);
  calcTotali();
  return tr;
}

async function fetchOrdinePrice(tr, item) {
  const clienteId = document.getElementById('ord-cliente-id')?.value;
  if (!clienteId || !item.id) return;
  const listinoId = document.getElementById('ord-listino-id')?.value || '';
  try {
    // AUDIT 3 §1.2: prima mandava i parametri via POST body a un endpoint che
    // legge solo GET (quindi non arrivava mai nulla), con anche un nome
    // parametro sbagliato (qta invece di quantita) e una chiave di risposta
    // sbagliata (prezzo invece di prezzo_unitario) — tre bug indipendenti
    // sullo stesso percorso. Ora usa la stessa funzione condivisa e corretta
    // già usata dal form preventivi (pricing-widget.js).
    const data = await qkFetchPrezzoSuggerito({ prodottoId: item.id, listinoId, clienteId, quantita: 1 });
    if (data && data.ok && data.prezzo_unitario !== undefined && data.prezzo_unitario !== null) {
      const puInput = tr.querySelector('.ord-inp-pu');
      const badge   = tr.querySelector('.ord-price-badge');
      if (puInput && !puInput.value) {
        puInput.value = data.prezzo_unitario;
        if (badge) { badge.innerHTML = '<i class="fa-solid fa-tag" style="color:var(--qk-success)"></i>'; badge.title = data.prezzo_sorgente || 'Da listino'; }
        calcTotali();
      }
    }
  } catch (e) { console.error('Error:', e); }
}

function calcTotali() {
  let imponibile = 0, totIva = 0, totaleCosto = 0;
  document.querySelectorAll('#ord-tbody-righe tr').forEach(tr => {
    const qta  = parseFloat(tr.querySelector('.ord-inp-qta')?.value)  || 0;
    const pu   = parseFloat(tr.querySelector('.ord-inp-pu')?.value)   || 0;
    const sc   = parseFloat(tr.querySelector('.ord-inp-sc')?.value)   || 0;
    const iva  = parseFloat(tr.querySelector('.ord-inp-iva')?.value)  || 22;
    const pn   = pu * (1 - sc / 100);
    const tot  = Math.round(pn * qta * 100) / 100;
    const rig  = Math.round(tot * iva / 100 * 100) / 100;
    imponibile += tot;
    totIva     += rig;
    const rigTotal = tr.querySelector('.ord-riga-total');
    if (rigTotal) rigTotal.textContent = window.qkFormatEuro ? window.qkFormatEuro(tot) : ('€ ' + tot.toFixed(2));

    const qFactor   = parseFloat(tr.dataset.quantitaContenuto) || 1;
    const costoBase = parseFloat(tr.dataset.costoBase) || 0;
    let costoConv   = (currentUomMode === 'commercial' && qFactor > 1) ? costoBase / qFactor : costoBase;
    const rigaCosto = Math.round(costoConv * qta * 100) / 100;
    totaleCosto += rigaCosto;

    const elCosto = tr.querySelector('.ord-riga-costo-val');
    if (elCosto) elCosto.textContent = costoBase > 0 ? (window.qkFormatEuro ? window.qkFormatEuro(costoConv) : ('€ '+costoConv.toFixed(2))) : '—';

    // Aggiorna hidden costo
    const hiddenCosto = tr.querySelector('.ord-inp-costo-hidden');
    if (hiddenCosto) hiddenCosto.value = costoConv;
  });

  const fmt = window.qkFormatEuro ? window.qkFormatEuro : (v => '€ ' + v.toFixed(2).replace('.',','));
  document.getElementById('ord-tot-imponibile').textContent = fmt(imponibile);
  document.getElementById('ord-tot-iva').textContent        = fmt(totIva);
  document.getElementById('ord-tot-totale').textContent     = fmt(imponibile + totIva);
  document.getElementById('ord-box-tot-cliente').textContent= fmt(imponibile);
  document.getElementById('ord-box-tot-costo').textContent  = fmt(totaleCosto);
  const margineEur = imponibile - totaleCosto;
  const marginePct = imponibile > 0 ? (margineEur / imponibile) * 100 : 0;
  document.getElementById('ord-box-margine-eur').textContent = fmt(margineEur);
  document.getElementById('ord-box-margine-pct').textContent = marginePct.toFixed(2).replace('.',',') + '%';
  ['ord-box-margine-eur-wrap','ord-box-margine-pct-wrap'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.background  = margineEur >= 0 ? 'var(--qk-success-bg)' : 'var(--qk-danger-bg,#fff5f5)';
    el.style.borderColor = margineEur >= 0 ? 'var(--qk-success)' : 'var(--qk-danger)';
    el.querySelector('div:last-child').className = 'fs-4 fw-bold mt-1 ' + (margineEur >= 0 ? 'text-success' : 'text-danger');
  });
}

document.getElementById('ord-btn-aggiungi-riga')?.addEventListener('click', () => buildRiga());

// ─── Catalogo rapido ──────────────────────────────────────────────────────
let catTimer = null;
document.getElementById('ord-catalogo-search')?.addEventListener('input', (e) => {
  clearTimeout(catTimer);
  const q = e.target.value.trim();
  if (q.length < 2) {
    document.getElementById('ord-catalogo-grid').innerHTML = '<div class="col-12 text-center text-muted py-3 small">Digita almeno 2 caratteri…</div>';
    return;
  }
  catTimer = setTimeout(async () => {
    const items = await apiFetch('/sales/orders/api/prodotti?q=' + encodeURIComponent(q));
    const grid  = document.getElementById('ord-catalogo-grid');
    if (!items || !items.length) {
      grid.innerHTML = '<div class="col-12 text-center text-muted py-3 small">Nessun prodotto trovato</div>';
      return;
    }
    grid.innerHTML = items.slice(0, 24).map(it => `
      <div class="col-sm-6 col-md-4 col-lg-3">
        <div class="qk-card-prod-catalogo rounded p-2 h-100" style="cursor:pointer;" onclick="addCatalogoRow(${JSON.stringify(it).replace(/"/g,'&quot;')})">
          <div style="font-size:.78rem; font-family:monospace; color:var(--qk-text-muted);">${esc(it.sku||'')}</div>
          <div class="fw-semibold" style="font-size:.88rem; line-height:1.2;">${esc(it.label)}</div>
          <div style="font-size:.78rem; color:var(--qk-text-muted);">${esc(it.unita_misura||'pz')}</div>
        </div>
      </div>
    `).join('');
  }, 250);
});

window.addCatalogoRow = function(item) {
  buildRiga({
    prodotto_id: item.id,
    descrizione: item.label,
    sku: item.sku || '',
    unita_misura: item.unita_misura || 'pz',
    uom_stock: item.unita_misura || 'pz',
    uom_stock_id: item.unita_misura_stock_id || '',
    uom_commercial: item.unita_misura_commerciale || item.unita_misura || 'pz',
    uom_commercial_id: item.unita_contenuto_id || '',
    quantita_contenuto: item.quantita_contenuto || '1',
    costo_base: item.costo_base || '0',
    quantita_confezione: item.quantita_confezione || '',
  });
};

// ─── Modali nuovo cliente / prodotto ─────────────────────────────────────
document.querySelectorAll('#ord-rc-azienda, #ord-rc-privato').forEach(el => {
  el.addEventListener('change', () => {
    const isPriv = el.id === 'ord-rc-privato' && el.querySelector('input').checked;
    document.getElementById('ord-mnc-rs-wrap')?.classList.toggle('d-none', isPriv);
    document.getElementById('ord-mnc-nc-wrap')?.classList.toggle('d-none', !isPriv);
  });
});

document.getElementById('ord-btn-nuovo-cliente')?.addEventListener('click', () => openModal('ord-modal-nuovo-cliente'));
document.getElementById('ord-btn-salva-cliente')?.addEventListener('click', async () => {
  const form = document.getElementById('ord-form-nuovo-cliente');
  const fd   = new FormData(form);
  fd.append('csrf_token', CSRF);
  const errDiv = document.getElementById('ord-mnc-error');
  const errMsg = document.getElementById('ord-mnc-error-msg');
  try {
    const r = await fetch(BASE + '/sales/orders/api/crea-cliente', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await r.json();
    if (data.success) {
      selectCliente({ id: data.id, label: data.label, email: data.email, telefono: data.telefono });
      bootstrap.Modal.getInstance(document.getElementById('ord-modal-nuovo-cliente'))?.hide();
    } else {
      errMsg.textContent = data.error || 'Errore sconosciuto'; errDiv.style.display = '';
    }
  } catch(e) { errMsg.textContent = 'Errore di rete'; errDiv.style.display = ''; }
});

document.getElementById('ord-btn-nuovo-prodotto')?.addEventListener('click', () => openModal('ord-modal-nuovo-prodotto'));
document.getElementById('ord-btn-salva-prodotto')?.addEventListener('click', async () => {
  const form = document.getElementById('ord-form-nuovo-prodotto');
  const fd   = new FormData(form);
  fd.append('csrf_token', CSRF);
  const errDiv = document.getElementById('ord-mnp-error');
  const errMsg = document.getElementById('ord-mnp-error-msg');
  try {
    const r = await fetch(BASE + '/sales/orders/api/crea-prodotto', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await r.json();
    if (data.success) {
      buildRiga({ prodotto_id: data.id, descrizione: data.label, sku: data.sku || '', unita_misura: data.unita_misura || 'pz' });
      bootstrap.Modal.getInstance(document.getElementById('ord-modal-nuovo-prodotto'))?.hide();
    } else {
      errMsg.textContent = data.error || 'Errore'; errDiv.style.display = '';
    }
  } catch(e) { errMsg.textContent = 'Errore di rete'; errDiv.style.display = ''; }
});

// Prima riga vuota all'apertura
buildRiga();

});
