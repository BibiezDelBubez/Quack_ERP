<?php
/**
 * sales/pricelists/_righe.php
 * Tabella prezzi prodotto con inline row editing.
 * Variabili: $id (int), $items (array), $values (array per valuta)
 */
?>

<style>
/* ── Inline editing: toggle view/edit via .is-editing su <tr> ── */
.pl-riga:not(.is-editing) .pl-edit { display: none !important; }
.pl-riga.is-editing .pl-view       { display: none !important; }

/* ── Layout tabella ── */
#tbl-prezzi { table-layout: fixed; }
#tbl-prezzi .pl-td-product { width: auto; min-width: 200px; }
#tbl-prezzi .pl-td-um      { width: 60px;  }
#tbl-prezzi .pl-td-num     { width: 130px; text-align: right; }
#tbl-prezzi .pl-td-note    { width: 180px; }
#tbl-prezzi .pl-td-actions { width: 72px;  text-align: right; }

/* ── Stile celle view ── */
.pl-sku {
  font-family: monospace;
  font-size: .75rem;
  color: var(--qk-text-muted);
  margin-top: 1px;
}
.pl-num {
  font-family: monospace;
  font-size: .875rem;
  display: block;
  padding: .35rem .5rem;
}
.pl-note-val {
  font-size: .82rem;
  color: var(--qk-text-muted);
}
.pl-um-label {
  font-size: .8rem;
  color: var(--qk-text-muted);
}

/* ── Input dentro la cella ── */
.pl-input {
  width: 100%;
  padding: .28rem .45rem;
  font-size: .875rem;
  line-height: 1.4;
  background: var(--qk-card-bg);
  border: 1px solid var(--qk-card-border);
  border-radius: var(--qk-radius-sm);
  color: var(--qk-text-primary);
  outline: none;
  transition: border-color .12s, box-shadow .12s;
  text-align: right;
}
.pl-input:focus {
  border-color: var(--qk-accent);
  box-shadow: 0 0 0 2px var(--qk-focus-ring, rgba(37,99,235,.12));
}
.pl-td-product .pl-input { text-align: left; }
.pl-td-note    .pl-input { text-align: left; }

/* ── Riga in modifica ── */
.pl-riga.is-editing > td {
  background-color: var(--qk-accent-light) !important;
}
.pl-riga.is-editing > td:first-child {
  box-shadow: inset 3px 0 0 var(--qk-accent);
}

/* ── Riga nuova: animazione entrata ── */
@keyframes pl-row-in {
  from { opacity: 0; transform: translateY(-5px); }
  to   { opacity: 1; transform: translateY(0); }
}
.pl-riga-new { animation: pl-row-in .14s ease-out forwards; }

/* ── Bottoni azione ── */
.pl-actions-view,
.pl-actions-edit {
  display: flex;
  justify-content: flex-end;
  gap: 4px;
}
.pl-btn-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px; height: 28px;
  border: 1px solid var(--qk-card-border);
  border-radius: var(--qk-radius-sm);
  background: transparent;
  color: var(--qk-text-muted);
  font-size: .8rem;
  cursor: pointer;
  transition: background .12s, color .12s, border-color .12s;
}
.pl-btn-icon:hover            { background: var(--qk-table-head-bg); color: var(--qk-text-primary); }
.pl-btn-danger:hover          { background: var(--qk-danger-bg); color: var(--qk-danger); border-color: var(--qk-danger); }
.pl-btn-confirm               { border-color: var(--qk-success); color: var(--qk-success); }
.pl-btn-confirm:hover         { background: var(--qk-success-bg); }
.pl-btn-confirm.is-saving     { opacity: .6; pointer-events: none; }
.pl-btn-cancel:hover          { background: var(--qk-table-head-bg); }

/* ── Stato saving: spinner ── */
.pl-btn-confirm.is-saving::before {
  content: '';
  display: inline-block;
  width: 10px; height: 10px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: pl-spin .6s linear infinite;
  margin-right: 0;
}
.pl-btn-confirm.is-saving i { display: none; }
@keyframes pl-spin { to { transform: rotate(360deg); } }

/* ── Empty state ── */
#pl-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .5rem;
  padding: 2.5rem 1rem;
  color: var(--qk-text-muted);
}
#pl-empty i { font-size: 2rem; opacity: .4; }
#pl-empty p { margin: 0; font-size: .875rem; }

/* ── M2O dentro cella nuova riga ── */
.pl-riga .qk-m2o-wrap  { position: relative; }
.pl-riga .qk-m2o-clear { position: absolute; right: .5rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--qk-text-muted); font-size: 1rem; padding: 0; line-height: 1; }
.pl-riga .qk-m2o-dropdown { position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 600; background: var(--qk-card-bg); border: 1px solid var(--qk-card-border); border-radius: var(--qk-radius); box-shadow: 0 8px 24px rgba(0,0,0,.15); max-height: 240px; overflow-y: auto; display: none; }
.pl-riga .qk-m2o-dropdown.open { display: block; }
</style>

<!-- ── Card tabella ── -->
<div class="qk-card shadow-sm">
  <div class="qk-card-header">
    <span class="qk-card-title">
      <i class="fa-solid fa-badge-percent me-2" style="color:var(--qk-accent)"></i>Prezzi prodotto
    </span>
    <button type="button" id="btn-add-riga" class="btn-qk btn-qk-primary btn-qk-sm">
      <i class="fa-solid fa-plus me-1"></i>Aggiungi prodotto
    </button>
  </div>

  <div class="qk-table-wrap" style="min-height:0">
    <!-- Empty state -->
    <?php if (empty($items)): ?>
    <div id="pl-empty">
      <i class="fa-solid fa-badge-percent"></i>
      <p>Nessun prezzo configurato.</p>
      <p style="font-size:.8rem;opacity:.7">Clicca "Aggiungi prodotto" per iniziare.</p>
    </div>
    <?php endif; ?>

    <table class="qk-table" id="tbl-prezzi" <?= empty($items) ? 'style="display:none"' : '' ?>>
      <thead>
        <tr>
          <th>Prodotto</th>
          <th>UM</th>
          <th class="text-end">Q.tà min</th>
          <th class="text-end">Prezzo unitario</th>
          <th class="text-end">Sconto %</th>
          <th>Note</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="pl-tbody">
        <?php foreach ($items as $item): ?>
          <?php include __DIR__ . '/_riga.php'; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Template per nuova riga (inizia in edit mode) ── -->
<template id="pl-tpl-new">
  <tr class="pl-riga is-editing pl-riga-new" data-riga-id="0" data-prodotto-id="">

    <td class="pl-td-product">
      <div class="pl-edit">
        <div class="qk-m2o-wrap">
          <input type="hidden" class="r-prodotto-id" value="">
          <input type="text" class="pl-input r-prodotto-label qk-m2o-input"
                 placeholder="Cerca prodotto…" autocomplete="off" style="text-align:left">
          <button type="button" class="qk-m2o-clear d-none r-prodotto-clear">&times;</button>
          <div class="qk-m2o-dropdown r-prodotto-dropdown"></div>
        </div>
      </div>
    </td>

    <td class="pl-td-um">
      <span class="pl-view pl-um-label">—</span>
      <select class="pl-edit pl-input r-unita-misura-id" style="padding: .2rem .35rem; font-size: .8rem; text-align: left; display: none;">
      </select>
    </td>

    <td class="pl-td-num">
      <input class="pl-edit pl-input r-qmin" type="number" min="0" step="0.001" value="0" placeholder="0">
    </td>

    <td class="pl-td-num">
      <input class="pl-edit pl-input r-prezzo" type="number" min="0" step="0.0001" placeholder="0.0000">
    </td>

    <td class="pl-td-num">
      <input class="pl-edit pl-input r-sconto" type="number" min="0" max="100" step="0.01" value="0" placeholder="0">
    </td>

    <td class="pl-td-note">
      <input class="pl-edit pl-input r-note" type="text" placeholder="Note…" style="text-align:left">
    </td>

    <td class="pl-td-actions">
      <div class="pl-edit pl-actions-edit">
        <button type="button" class="pl-btn-icon pl-btn-confirm btn-confirm-riga" title="Salva (Invio)">
          <i class="fa-solid fa-check"></i>
        </button>
        <button type="button" class="pl-btn-icon pl-btn-cancel btn-cancel-riga" title="Annulla (Esc)">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    </td>

  </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const BASE    = QK_BASE;
  const CSRF    = <?= json_encode(csrf_token()) ?>;
  const LISTINO = <?= (int)$id ?>;
  const VALUTA  = <?= json_encode($values['valuta'] ?? 'EUR') ?>;

  const tbody   = document.getElementById('pl-tbody');
  const table   = document.getElementById('tbl-prezzi');
  const empty   = document.getElementById('pl-empty');
  const tpl     = document.getElementById('pl-tpl-new');

  /* ════════════════════════════════════════════════════
     AGGIUNGI NUOVA RIGA
  ════════════════════════════════════════════════════ */
  document.getElementById('btn-add-riga').addEventListener('click', () => {
    // Chiudi eventuale riga già in editing
    tbody.querySelectorAll('.pl-riga.is-editing').forEach(tr => cancelEdit(tr));
    addNewRow();
  });

  function addNewRow() {
    const fragment = tpl.content.cloneNode(true);
    const tr = fragment.querySelector('tr');
    tbody.appendChild(fragment);
    syncEmpty();
    initNewRowM2O(tbody.lastElementChild);
    tbody.lastElementChild.querySelector('.r-prodotto-label')?.focus();
    scrollToRow(tbody.lastElementChild);
  }

  /* ════════════════════════════════════════════════════
     DELEGAZIONE CLICK sul tbody
  ════════════════════════════════════════════════════ */
  tbody.addEventListener('click', function (e) {
    const tr = e.target.closest('tr.pl-riga');
    if (!tr) return;

    if (e.target.closest('.btn-edit-riga'))    { enterEdit(tr); return; }
    if (e.target.closest('.btn-confirm-riga')) { confirmEdit(tr); return; }
    if (e.target.closest('.btn-cancel-riga'))  { cancelEdit(tr); return; }
    if (e.target.closest('.btn-delete-riga'))  { deleteRow(tr); return; }
  });

  /* ════════════════════════════════════════════════════
     KEYBOARD: Invio salva, Esc annulla, Tab naviga
  ════════════════════════════════════════════════════ */
  tbody.addEventListener('keydown', function (e) {
    const tr = e.target.closest('tr.pl-riga.is-editing');
    if (!tr) return;

    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      confirmEdit(tr);
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      cancelEdit(tr);
    }
  });

  /* ════════════════════════════════════════════════════
     ENTER EDIT (riga esistente)
  ════════════════════════════════════════════════════ */
  function enterEdit(tr) {
    tbody.querySelectorAll('.pl-riga.is-editing').forEach(other => {
      if (other !== tr) cancelEdit(other);
    });
    // Pre-popola inputs dai data-*
    setInput(tr, '.r-qmin',   tr.dataset.qmin   ?? '0');
    setInput(tr, '.r-prezzo', tr.dataset.prezzo ?? '');
    setInput(tr, '.r-sconto', tr.dataset.sconto ?? '0');
    setInput(tr, '.r-note',   tr.dataset.note   ?? '');
    setInput(tr, '.r-unita-misura-id', tr.dataset.uomSelectedId || tr.dataset.uomStockId || '');
    tr.classList.add('is-editing');
    tr.querySelector('.r-qmin')?.focus();
    scrollToRow(tr);
  }

  /* ════════════════════════════════════════════════════
     CONFIRM EDIT
  ════════════════════════════════════════════════════ */
  async function confirmEdit(tr) {
    const isNew     = tr.dataset.rigaId === '0';
    const prodottoId = isNew
      ? (tr.querySelector('.r-prodotto-id')?.value ?? '')
      : tr.dataset.prodottoId;

    const prezzo = getInput(tr, '.r-prezzo');

    if (!prodottoId) return flashRow(tr, 'Seleziona un prodotto.');
    if (!prezzo || isNaN(parseFloat(prezzo))) return flashRow(tr, 'Inserisci un prezzo valido.');

    const btn = tr.querySelector('.btn-confirm-riga');
    btn.classList.add('is-saving');

    try {
      const d = await apiPost('/sales/pricelists/righe/save', {
        listino_id:       LISTINO,
        riga_id:          tr.dataset.rigaId,
        prodotto_id:      prodottoId,
        prezzo_unitario:  prezzo,
        quantita_minima:  getInput(tr, '.r-qmin')   || '0',
        sconto_perc:      getInput(tr, '.r-sconto')  || '0',
        unita_misura_id:  getInput(tr, '.r-unita-misura-id') || null,
        note:             getInput(tr, '.r-note')    || '',
      });

      if (!d.ok) { flashRow(tr, d.error ?? 'Errore.'); return; }

      applyRigaData(tr, d.riga);
      tr.classList.remove('is-editing', 'pl-riga-new');
      tr.dataset.rigaId = d.riga.id;
      tr.classList.add('pl-riga-saved');
      setTimeout(() => tr.classList.remove('pl-riga-saved'), 800);
      syncEmpty();

    } finally {
      btn.classList.remove('is-saving');
    }
  }

  /* ════════════════════════════════════════════════════
     CANCEL EDIT
  ════════════════════════════════════════════════════ */
  function cancelEdit(tr) {
    if (tr.dataset.rigaId === '0') {
      tr.remove();
    } else {
      tr.classList.remove('is-editing');
    }
    syncEmpty();
  }

  /* ════════════════════════════════════════════════════
     DELETE ROW
  ════════════════════════════════════════════════════ */
  async function deleteRow(tr) {
    if (!confirm('Rimuovere questo prezzo dal listino?')) return;
    const d = await apiPost('/sales/pricelists/righe/delete', { riga_id: tr.dataset.rigaId });
    if (d.ok) { tr.remove(); syncEmpty(); }
    else alert(d.error ?? 'Errore.');
  }

  /* ════════════════════════════════════════════════════
     APPLICA DATI API → riga view state
  ════════════════════════════════════════════════════ */
  function applyRigaData(tr, r) {
    const fmt = (v, d) => parseFloat(v || 0).toLocaleString('it-IT', { minimumFractionDigits: d, maximumFractionDigits: d });

    // Aggiorna data-*
    tr.dataset.prodottoId  = r.prodotto_id;
    tr.dataset.prodottoNome = r.prodotto_nome;
    tr.dataset.sku         = r.sku_interno;
    tr.dataset.um          = r.unita_misura ?? '';
    tr.dataset.uomStockId  = r.uom_stock_id ?? '';
    tr.dataset.uomStockCode = r.uom_stock_code ?? '';
    tr.dataset.uomCommercialId = r.uom_commercial_id ?? '';
    tr.dataset.uomCommercialCode = r.uom_commercial_code ?? '';
    tr.dataset.uomSelectedId = r.unita_misura_id ?? '';
    tr.dataset.qmin        = r.quantita_minima;
    tr.dataset.prezzo      = r.prezzo_unitario;
    tr.dataset.sconto      = r.sconto_perc;
    tr.dataset.note        = r.note ?? '';

    // Aggiorna elementi view
    const link = tr.querySelector('.pl-view a.qk-text-accent');
    const sku  = tr.querySelector('.pl-view .pl-sku');
    if (link) { link.textContent = r.prodotto_nome; link.href = BASE + '/products/edit?id=' + r.prodotto_id; }
    if (sku)  sku.textContent = r.sku_interno;

    setText(tr, '.pl-um-label', r.unita_misura ?? '—');
    
    // Aggiorna UoM select
    const selectEl = tr.querySelector('.r-unita-misura-id');
    if (selectEl) {
      selectEl.innerHTML = '';
      const optStock = document.createElement('option');
      optStock.value = r.uom_stock_id;
      optStock.textContent = r.uom_stock_code;
      if (parseInt(r.unita_misura_id || r.uom_stock_id) === parseInt(r.uom_stock_id)) {
        optStock.selected = true;
      }
      selectEl.appendChild(optStock);
      
      if (r.uom_commercial_id) {
        const optComm = document.createElement('option');
        optComm.value = r.uom_commercial_id;
        optComm.textContent = r.uom_commercial_code;
        if (parseInt(r.unita_misura_id) === parseInt(r.uom_commercial_id)) {
          optComm.selected = true;
        }
        selectEl.appendChild(optComm);
      }
    }

    setText(tr, '.pl-td-num:nth-child(3) .pl-num',  fmt(r.quantita_minima, 3));
    setText(tr, '.pl-td-num:nth-child(4) .pl-num',  fmt(r.prezzo_unitario, 4));
    setText(tr, '.pl-td-num:nth-child(5) .pl-num',  fmt(r.sconto_perc, 2) + ' %');
    setText(tr, '.pl-note-val', r.note ?? '');

    // Se è una nuova riga, costruisce gli elementi view mancanti
    if (!tr.querySelector('.pl-view')) {
      buildViewElements(tr, r);
    }
  }

  function buildViewElements(tr, r) {
    const fmt = (v, d) => parseFloat(v || 0).toLocaleString('it-IT', { minimumFractionDigits: d, maximumFractionDigits: d });

    tr.querySelector('.pl-td-product').innerHTML = `
      <div class="pl-view">
        <a href="${BASE}/products/edit?id=${r.prodotto_id}" class="fw-semibold qk-text-accent" tabindex="-1">${esc(r.prodotto_nome)}</a>
        <div class="pl-sku">${esc(r.sku_interno)}</div>
      </div>
      ${tr.querySelector('.pl-td-product').innerHTML}`;

    setText(tr, '.pl-um-label', r.unita_misura ?? '—');

    // Numeri
    ['3','4','5'].forEach((n, i) => {
      const td = tr.querySelector(`.pl-td-num:nth-child(${n})`);
      if (!td) return;
      const vals = [fmt(r.quantita_minima,3), fmt(r.prezzo_unitario,4), fmt(r.sconto_perc,2)+' %'];
      td.insertAdjacentHTML('afterbegin', `<span class="pl-view pl-num">${vals[i]}</span>`);
    });

    // Note
    const tdNote = tr.querySelector('.pl-td-note');
    if (tdNote) tdNote.insertAdjacentHTML('afterbegin', `<span class="pl-view pl-note-val">${esc(r.note??'')}</span>`);

    // Actions view
    const tdActions = tr.querySelector('.pl-td-actions');
    if (tdActions) tdActions.insertAdjacentHTML('afterbegin', `
      <div class="pl-view pl-actions-view">
        <button type="button" class="pl-btn-icon btn-edit-riga" title="Modifica">
          <i class="fa-solid fa-pencil"></i>
        </button>
        <button type="button" class="pl-btn-icon pl-btn-danger btn-delete-riga" title="Rimuovi">
          <i class="fa-solid fa-trash-can"></i>
        </button>
      </div>`);
  }

  /* ════════════════════════════════════════════════════
     M2O AUTOCOMPLETE per nuova riga
  ════════════════════════════════════════════════════ */
  function initNewRowM2O(tr) {
    const inputEl    = tr.querySelector('.r-prodotto-label');
    const hiddenEl   = tr.querySelector('.r-prodotto-id');
    const dropdownEl = tr.querySelector('.r-prodotto-dropdown');
    const clearBtn   = tr.querySelector('.r-prodotto-clear');
    const umLabel    = tr.querySelector('.pl-um-label');

    if (!inputEl) return;

    new QkM2O({
      inputEl, hiddenEl, dropdownEl, clearBtn,
      apiUrl:   BASE + '/sales/pricelists/api/prodotti',
      labelKey: 'label',
      columns: [{ key: 'label', label: 'Prodotto' }, { key: 'sku', label: 'SKU' }],
      onSelect: (item) => {
        const selectEl = tr.querySelector('.r-unita-misura-id');
        if (selectEl) {
          selectEl.innerHTML = '';
          if (item) {
            tr.dataset.uomStockId = item.unita_misura_stock_id || '';
            tr.dataset.uomStockCode = item.unita_misura || '';
            tr.dataset.uomCommercialId = item.unita_contenuto_id || '';
            tr.dataset.uomCommercialCode = item.unita_misura_commerciale || '';
            
            // Add stock option
            const optStock = document.createElement('option');
            optStock.value = item.unita_misura_stock_id;
            optStock.textContent = item.unita_misura;
            selectEl.appendChild(optStock);
            
            // Add commercial option if defined
            if (item.unita_contenuto_id) {
              const optComm = document.createElement('option');
              optComm.value = item.unita_contenuto_id;
              optComm.textContent = item.unita_misura_commerciale;
              selectEl.appendChild(optComm);
            }
            selectEl.style.display = 'block';
          } else {
            selectEl.style.display = 'none';
          }
        }
        if (!item) {
          if (umLabel) umLabel.textContent = '—';
          return;
        }
        // Pre-compila UM
        if (umLabel) umLabel.textContent = item.unita_misura || '—';
        // Focus su prezzo
        setTimeout(() => tr.querySelector('.r-qmin')?.focus(), 50);
      },
    });
  }

  /* ════════════════════════════════════════════════════
     FEEDBACK VISIVO
  ════════════════════════════════════════════════════ */
  function flashRow(tr, msg) {
    tr.style.outline = '2px solid var(--qk-danger)';
    tr.style.outlineOffset = '-2px';
    tr.title = msg;
    setTimeout(() => { tr.style.outline = ''; tr.title = ''; }, 2000);
  }

  /* ════════════════════════════════════════════════════
     SYNC EMPTY STATE / TABLE VISIBILITY
  ════════════════════════════════════════════════════ */
  function syncEmpty() {
    const hasRows = tbody.children.length > 0;
    if (empty) empty.style.display = hasRows ? 'none' : '';
    if (table) table.style.display = hasRows ? '' : 'none';
  }

  /* ════════════════════════════════════════════════════
     HELPERS
  ════════════════════════════════════════════════════ */
  function getInput(tr, sel) { return tr.querySelector(sel)?.value?.trim() ?? ''; }
  function setInput(tr, sel, val) { const el = tr.querySelector(sel); if (el) el.value = val; }
  function setText(tr, sel, val) { const el = tr.querySelector(sel); if (el) el.textContent = val; }
  function esc(s) { return (s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function scrollToRow(tr) { tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }

  function apiPost(path, data) {
    const body = Object.entries({ _token: CSRF, ...data })
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v ?? '')}`).join('&');
    return fetch(BASE + path, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    }).then(r => r.json());
  }

  /* ── Attiva toggle stato del listino ── */
  document.getElementById('pl-attivo')?.addEventListener('change', function () {
    document.getElementById('pl-attivo-label').textContent =
      this.checked ? 'Listino attivo' : 'Listino disattivato';
  });
});
</script>

<!-- CSS per feedback "salvato" -->
<style>
  @keyframes pl-saved-flash {
    0%   { background-color: var(--qk-success-bg); }
    100% { background-color: transparent; }
  }
  .pl-riga-saved > td { animation: pl-saved-flash .8s ease-out forwards; }
</style>
