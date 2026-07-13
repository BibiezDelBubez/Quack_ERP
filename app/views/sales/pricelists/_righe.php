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
#tbl-prezzi { table-layout: fixed; width: 100%; }
#tbl-prezzi .pl-td-product { width: 35%; min-width: 220px; }
#tbl-prezzi .pl-td-um      { width: 65px;  }
#tbl-prezzi .pl-td-qmin    { width: 100px; text-align: right; }
#tbl-prezzi .pl-td-prezzo  { width: 120px; text-align: right; }
#tbl-prezzi .pl-td-sconto  { width: 90px;  text-align: right; }
#tbl-prezzi .pl-td-note    { width: auto; min-width: 150px; }
#tbl-prezzi .pl-td-actions { width: 44px;  text-align: right; }

#tbl-prezzi td, #tbl-prezzi th {
  vertical-align: top !important;
  padding: .5rem .4rem !important;
}

/* ── Stile celle view ── */
.pl-sku {
  font-family: monospace;
  font-size: .72rem;
  color: var(--qk-text-muted);
  margin-top: 1px;
}
.pl-num {
  font-family: monospace;
  font-size: .82rem;
  display: block;
  padding: .2rem .35rem;
}
.pl-note-val {
  font-size: .8rem;
  color: var(--qk-text-muted);
}
.pl-um-label {
  font-size: .8rem;
  color: var(--qk-text-muted);
}

/* ── Input dentro la cella ── */
.pl-input {
  width: 100%;
  padding: .2rem .35rem;
  font-size: .82rem;
  line-height: 1.3;
  background: var(--qk-card-bg);
  border: 1px solid var(--qk-card-border);
  border-radius: var(--qk-radius-sm);
  color: var(--qk-text-primary);
  outline: none;
  transition: border-color .12s, box-shadow .12s;
  text-align: right;
  height: auto !important;
  min-height: 0 !important;
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


</style>

<!-- ── Card tabella ── -->
<div class="qk-card shadow-sm">
  <div class="qk-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="qk-card-title m-0">
      <i class="fa-solid fa-badge-percent me-2" style="color:var(--qk-accent)"></i>Prezzi prodotto
    </span>
    <div class="d-flex align-items-center gap-2">
      <div class="d-flex align-items-center gap-2 me-2">
        <label class="mb-0 fw-semibold qk-text-muted text-nowrap" style="font-size: .85rem;">Visualizza in:</label>
        <select id="uom-toggle" class="qk-form-control py-1" style="width: auto; font-size: .85rem;">
          <option value="stock" selected>Unità di Stock</option>
          <option value="comm">Unità Commerciale</option>
        </select>
      </div>
      <input type="text" id="pl-grid-search" class="pl-input" placeholder="Filtra per nome o SKU..." style="width: 220px; padding: .25rem .5rem; font-size: .8rem; margin: 0; text-align: left;">
      <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" data-bs-toggle="modal" data-bs-target="#modalImportaPrezziSales">
        <i class="fa-solid fa-file-import me-1"></i>Importa (XLSX)
      </button>
      <button type="button" id="btn-add-riga" class="btn-qk btn-qk-primary btn-qk-sm">
        <i class="fa-solid fa-plus me-1"></i>Aggiungi prodotto
      </button>
    </div>
  </div>

  <div class="qk-table-wrap" style="min-height: 320px;">
    <!-- Empty state -->
    <?php if (empty($items)): ?>
    <div id="pl-empty">
      <i class="fa-solid fa-badge-percent"></i>
      <p>Nessun prezzo configurato.</p>
      <p style="font-size:.8rem;opacity:.7">Clicca "Aggiungi prodotto" per iniziare.</p>
    </div>
    <?php endif; ?>

    <table class="qk-table" id="tbl-prezzi" <?= empty($items) ? 'style="display:none"' : '' ?>>
      <colgroup>
        <col class="pl-td-product">
        <col class="pl-td-um">
        <col class="pl-td-qmin">
        <col class="pl-td-prezzo">
        <col class="pl-td-sconto">
        <col class="pl-td-note">
        <col class="pl-td-actions">
      </colgroup>
      <thead>
        <tr>
          <th>
            <a href="?id=<?= $id ?>&sort=nome&dir=<?= ($sort === 'nome' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
              Prodotto <?= ($sort === 'nome') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
            </a>
          </th>
          <th>UM</th>
          <th class="text-end">
            <a href="?id=<?= $id ?>&sort=quantita_minima&dir=<?= ($sort === 'quantita_minima' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
              Q.tà min <?= ($sort === 'quantita_minima') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
            </a>
          </th>
          <th class="text-end">
            <a href="?id=<?= $id ?>&sort=prezzo&dir=<?= ($sort === 'prezzo' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
              Prezzo unitario <?= ($sort === 'prezzo') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
            </a>
          </th>
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
  
  <?php if (!empty($items) && isset($pages) && $pages > 1): ?>
    <div class="mt-3 mx-3 mb-3">
      <?= qk_pagination($page, $pages, '?id=' . $id . '&sort=' . $sort . '&dir=' . $dir . '&page=') ?>
    </div>
  <?php endif; ?>
</div>

<!-- ── Template per nuova riga (inizia in edit mode) ── -->
<template id="pl-tpl-new">
  <tr class="pl-riga is-editing pl-riga-new" data-riga-id="0" data-prodotto-id="">

    <td class="pl-td-product">
      <div class="pl-edit">
        <div class="qk-m2o-wrap position-relative">
          <input type="hidden" class="r-prodotto-id" value="">
          <div class="d-flex align-items-center gap-1">
            <div class="position-relative flex-grow-1">
              <input type="text" class="pl-input r-prodotto-label qk-m2o-input"
                     placeholder="Cerca prodotto…" autocomplete="off" style="text-align:left; padding-right: 24px;">
              <button type="button" class="qk-m2o-clear d-none r-prodotto-clear" style="right: 8px;">&times;</button>
            </div>
            <a href="#" class="btn-qk btn-qk-secondary btn-qk-sm pl-link-scheda" 
               target="_blank" title="Apri scheda prodotto" style="padding: 0.2rem 0.4rem; font-size: 0.75rem; display: none;">
              <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
          </div>
          <div class="qk-m2o-dropdown r-prodotto-dropdown"></div>
        </div>
      </div>
    </td>

    <td class="pl-td-um">
      <span class="pl-view pl-um-label">—</span>
      <select class="pl-edit pl-input r-unita-misura-id" style="padding: .2rem .35rem; font-size: .8rem; text-align: left; display: none;">
      </select>
    </td>

    <td class="pl-td-qmin">
      <input class="pl-edit pl-input r-qmin" type="number" min="0" step="0.001" value="0" placeholder="0">
    </td>

    <td class="pl-td-prezzo">
      <input class="pl-edit pl-input r-prezzo" type="number" min="0" step="0.0001" placeholder="0.0000">
    </td>

    <td class="pl-td-sconto">
      <input class="pl-edit pl-input r-sconto" type="number" min="0" max="100" step="0.01" value="0" placeholder="0">
    </td>

    <td class="pl-td-note">
      <input class="pl-edit pl-input r-note" type="text" placeholder="Note…" style="text-align:left">
    </td>

    <td class="pl-td-actions">
      <button type="button" class="pl-btn-icon pl-btn-cancel btn-cancel-riga" title="Annulla (Esc)">
        <i class="fa-solid fa-trash-can"></i>
      </button>
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

  // UoM Toggle Logic
  const uomToggle = document.getElementById('uom-toggle');
  if (uomToggle) {
    uomToggle.addEventListener('change', function() {
      const viewMode = this.value; // 'stock' or 'comm'
      
      tbody.querySelectorAll('.pl-riga').forEach(tr => {
        if (tr.classList.contains('is-new')) return; // skip unsaved blank rows
        
        const nativePrice = parseFloat(tr.getAttribute('data-native-price')) || 0;
        const nativeQmin = parseFloat(tr.getAttribute('data-native-qmin')) || 0;
        const factor = parseFloat(tr.getAttribute('data-factor')) || 1;
        const nativeUom = tr.getAttribute('data-um-native');
        const stockUom = tr.getAttribute('data-um-stock');
        const commUom = tr.getAttribute('data-um-comm');

        let displayPrice = nativePrice;
        let displayQmin = nativeQmin;
        let displayUom = nativeUom;

        if (viewMode === 'stock' && nativeUom === commUom && commUom !== stockUom) {
          // nativo in commerciale -> stock: qta si divide per il fattore
          // (contenuto->stock), prezzo si moltiplica (coerente con
          // qk_uom_converti_riga()/qkConvertUom() in assets/js/app.js).
          displayPrice = nativePrice * factor;
          displayQmin = nativeQmin / factor;
          displayUom = stockUom;
        } else if (viewMode === 'comm' && nativeUom === stockUom && commUom !== stockUom) {
          // nativo in stock -> commerciale: qta si moltiplica per il fattore,
          // prezzo si divide.
          displayPrice = nativePrice / factor;
          displayQmin = nativeQmin * factor;
          displayUom = commUom;
        } else if (viewMode === 'stock' && nativeUom !== commUom && nativeUom !== stockUom) {
          // generic fallback
          displayUom = stockUom;
        } else if (viewMode === 'comm' && nativeUom !== commUom && nativeUom !== stockUom) {
          // generic fallback
          displayUom = commUom;
        } else {
          // matches native
          if (viewMode === 'stock') displayUom = stockUom;
          if (viewMode === 'comm') displayUom = commUom;
        }

        // Update View Mode (text spans)
        const qminSpan = tr.querySelector('.r-qmin-view');
        if (qminSpan) qminSpan.textContent = displayQmin.toLocaleString('it-IT', {minimumFractionDigits:3, maximumFractionDigits:3});
        
        const prezzoSpan = tr.querySelector('.r-prezzo-view');
        if (prezzoSpan) prezzoSpan.textContent = displayPrice.toLocaleString('it-IT', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';

        const uomSpan = tr.querySelector('.r-um-view');
        if (uomSpan) uomSpan.textContent = displayUom;
      });
    });
    // trigger initially
    uomToggle.dispatchEvent(new Event('change'));
  }

  /* ════════════════════════════════════════════════════
     DISMISS ALL SOFT DELETED ROWS
  ════════════════════════════════════════════════════ */
  function dismissAllSoftDeleted() {
    tbody.querySelectorAll('.pl-riga-deleted-soft').forEach(tr => {
      if (tr.dataset.dismissTimeoutId) {
        clearTimeout(parseInt(tr.dataset.dismissTimeoutId));
      }
      tr.remove();
    });
    syncEmpty();
    runBusinessValidations();
  }

  /* ════════════════════════════════════════════════════
     AGGIUNGI NUOVA RIGA
  ════════════════════════════════════════════════════ */
  // Run business validations on load
  setTimeout(runBusinessValidations, 200);

  /* ════════════════════════════════════════════════════
     AGGIUNGI NUOVA RIGA
  ════════════════════════════════════════════════════ */
  document.getElementById('btn-add-riga').addEventListener('click', () => {
    // Chiudi eventuale riga già in editing
    tbody.querySelectorAll('.pl-riga.is-editing').forEach(tr => cancelEdit(tr));
    addNewRow();
  });

  function addNewRow() {
    dismissAllSoftDeleted();
    const fragment = tpl.content.cloneNode(true);
    tbody.prepend(fragment);
    const newTr = tbody.firstElementChild;
    syncEmpty();
    initRowM2O(newTr);
    newTr.querySelector('.r-prodotto-label')?.focus();
    scrollToRow(newTr);
  }

  /* ════════════════════════════════════════════════════
     CLICK-TO-EDIT & CELL FOCUS TRIGGER
  ════════════════════════════════════════════════════ */
  tbody.addEventListener('click', function (e) {
    const td = e.target.closest('td');
    if (!td) return;
    const tr = td.closest('tr.pl-riga');
    if (!tr) return;

    // Se stiamo cliccando su bottoni di azione o link
    if (tr.classList.contains('pl-riga-deleted-soft')) return;

    if (e.target.closest('.pl-btn-icon')) {
      if (e.target.closest('.btn-confirm-riga')) { confirmEdit(tr); }
      else if (e.target.closest('.btn-cancel-riga')) { cancelEdit(tr); }
      else if (e.target.closest('.btn-delete-riga')) { deleteRow(tr); }
      return;
    }
    if (e.target.closest('a')) return;

    if (!tr.classList.contains('is-editing')) {
      enterEdit(tr);
    }

    const input = td.querySelector('input, select');
    if (input && document.activeElement !== input) {
      input.focus();
      if (input.select && input.tagName !== 'SELECT') input.select();
    }
  });

  tbody.addEventListener('focusin', function (e) {
    const tr = e.target.closest('tr.pl-riga');
    if (tr && tr.classList.contains('pl-riga-deleted-soft')) return;
    if (tr && !tr.classList.contains('is-editing')) {
      enterEdit(tr);
    }
  });

  /* ════════════════════════════════════════════════════
     IMMEDIATE AUTOSAVE ON BLUR (FOCUSOUT FROM ROW)
  ════════════════════════════════════════════════════ */
  tbody.addEventListener('focusout', function (e) {
    const tr = e.target.closest('tr.pl-riga');
    if (!tr) return;

    const nextFocused = e.relatedTarget;
    // Se il focus si sposta fuori da questa riga
    if (!nextFocused || nextFocused.closest('tr.pl-riga') !== tr) {
      // Se l'elemento correlato è il pulsante Annulla/Rimuovi della stessa riga, non salvare automaticamente
      if (nextFocused && nextFocused.closest('.btn-cancel-riga, .btn-delete-riga')) {
        return;
      }
      
      const hasDirty = tr.querySelectorAll('.is-dirty').length > 0;
      const isNew = tr.dataset.rigaId === '0';

      if (hasDirty || (isNew && tr.querySelector('.r-prodotto-id')?.value)) {
        confirmEdit(tr);
      } else if (isNew && !tr.querySelector('.r-prodotto-id')?.value) {
        // NON cancelliamo la riga sul click-out (blur) per prevenire misclick.
        // La riga rimarrà aperta in modifica finché non si clicca il cestino/cancella.
      } else {
        tr.classList.remove('is-editing');
      }
    }
  });

  /* ════════════════════════════════════════════════════
     DIRTY STATE TRACKING
  ════════════════════════════════════════════════════ */
  function checkInputDirty(input) {
    const tr = input.closest('tr.pl-riga');
    if (!tr) return;

    let isDirty = false;
    const val = input.value.trim();

    if (input.classList.contains('r-qmin')) {
      isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.qmin || 0);
    } else if (input.classList.contains('r-prezzo')) {
      isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.prezzo || 0);
    } else if (input.classList.contains('r-sconto')) {
      isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.sconto || 0);
    } else if (input.classList.contains('r-note')) {
      isDirty = val !== (tr.dataset.note || '');
    } else if (input.classList.contains('r-unita-misura-id')) {
      const savedUom = tr.dataset.uomSelectedId || tr.dataset.uomStockId || '';
      isDirty = val !== savedUom;
    } else if (input.classList.contains('r-prodotto-id')) {
      isDirty = val !== (tr.dataset.prodottoId || '');
    }

    if (isDirty) {
      input.classList.add('is-dirty');
      input.style.backgroundColor = '#fef08a'; // Tailwind yellow-200
      input.style.fontStyle = 'italic';
    } else {
      input.classList.remove('is-dirty');
      input.style.backgroundColor = '';
      input.style.fontStyle = '';
    }
  }

  tbody.addEventListener('input', function (e) {
    if (e.target.matches('input, select')) {
      checkInputDirty(e.target);
      runBusinessValidations();
    }
  });

  tbody.addEventListener('change', function (e) {
    if (e.target.matches('input, select')) {
      checkInputDirty(e.target);
      runBusinessValidations();
    }
  });

  /* ════════════════════════════════════════════════════
     KEYBOARD: Navigation and commands
  ════════════════════════════════════════════════════ */
  tbody.addEventListener('keydown', function (e) {
    const input = e.target;
    const tr = input.closest('tr.pl-riga');
    if (!tr) return;

    // Arrow keys vertical navigation
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      if (!input.matches('input, select') || input.tagName === 'SELECT') return;
      if (input.classList.contains('r-prodotto-label')) {
        const m2oDropdown = input.closest('.qk-m2o-wrap')?.querySelector('.qk-m2o-dropdown');
        if (m2oDropdown && m2oDropdown.classList.contains('is-open')) return;
      }

      e.preventDefault();
      const targetTr = (e.key === 'ArrowUp') ? tr.previousElementSibling : tr.nextElementSibling;
      if (targetTr && targetTr.classList.contains('pl-riga')) {
        const classes = Array.from(input.classList).filter(c => c.startsWith('r-') || c.startsWith('pl-'));
        for (const cls of classes) {
          const targetInput = targetTr.querySelector('.' + cls);
          if (targetInput) {
            enterEdit(targetTr);
            targetInput.focus();
            if (targetInput.select) targetInput.select();
            break;
          }
        }
      }
      return;
    }

    // Tab key on Note (last field) -> Save and go to next or new row
    if (e.key === 'Tab' && input.classList.contains('r-note') && !e.shiftKey) {
      e.preventDefault();
      confirmEdit(tr).then((success) => {
        if (success) {
          const nextTr = tr.nextElementSibling;
          if (nextTr && nextTr.classList.contains('pl-riga')) {
            enterEdit(nextTr);
            nextTr.querySelector('.pl-input')?.focus();
          } else {
            addNewRow();
          }
        }
      });
      return;
    }

    // Enter key -> Save and go to next or new row
    if (e.key === 'Enter' && !e.shiftKey) {
      // Don't intercept Enter if autocomplete dropdown is open
      if (input.classList.contains('r-prodotto-label')) {
        const m2oDropdown = input.closest('.qk-m2o-wrap')?.querySelector('.qk-m2o-dropdown');
        if (m2oDropdown && m2oDropdown.classList.contains('is-open')) return;
      }
      e.preventDefault();
      confirmEdit(tr).then((success) => {
        if (success) {
          const nextTr = tr.nextElementSibling;
          if (nextTr && nextTr.classList.contains('pl-riga')) {
            enterEdit(nextTr);
            nextTr.querySelector('.pl-input')?.focus();
          } else {
            addNewRow();
          }
        }
      });
      return;
    }

    // Escape key -> Discard
    if (e.key === 'Escape') {
      e.preventDefault();
      cancelEdit(tr);
      return;
    }
  });

  /* ════════════════════════════════════════════════════
     ENTER EDIT (riga esistente)
  ════════════════════════════════════════════════════ */
  function enterEdit(tr) {
    dismissAllSoftDeleted();
    tbody.querySelectorAll('.pl-riga.is-editing').forEach(other => {
      if (other !== tr) {
        // Se l'altra riga ha modifiche pendenti, la salviamo prima di chiuderla
        const otherDirty = other.querySelectorAll('.is-dirty').length > 0;
        if (otherDirty) {
          confirmEdit(other);
        } else {
          cancelEdit(other);
        }
      }
    });
    // Pre-popola inputs dai data-*
    setInput(tr, '.r-qmin',   tr.dataset.qmin   ?? '0');
    setInput(tr, '.r-prezzo', tr.dataset.prezzo ?? '');
    setInput(tr, '.r-sconto', tr.dataset.sconto ?? '0');
    setInput(tr, '.r-note',   tr.dataset.note   ?? '');
    setInput(tr, '.r-unita-misura-id', tr.dataset.uomSelectedId || tr.dataset.uomStockId || '');
    setInput(tr, '.r-prodotto-id', tr.dataset.prodottoId || '');
    setInput(tr, '.r-prodotto-label', tr.dataset.prodottoNome || '');

    const linkScheda = tr.querySelector('.pl-link-scheda');
    if (linkScheda) {
      if (tr.dataset.prodottoId) {
        linkScheda.href = BASE + '/products/edit?id=' + tr.dataset.prodottoId;
        linkScheda.style.display = 'inline-block';
      } else {
        linkScheda.href = '#';
        linkScheda.style.display = 'none';
      }
    }
    
    // Reset inputs visual dirty markers
    tr.querySelectorAll('.pl-input').forEach(input => {
      input.classList.remove('is-dirty');
      input.style.backgroundColor = '';
      input.style.fontStyle = '';
    });

    tr.classList.add('is-editing');
    initRowM2O(tr);
    scrollToRow(tr);
  }

  /* ════════════════════════════════════════════════════
     CONFIRM EDIT
  ════════════════════════════════════════════════════ */
  async function confirmEdit(tr) {
    const prodottoId = tr.querySelector('.r-prodotto-id')?.value || tr.dataset.prodottoId;

    const prezzo = getInput(tr, '.r-prezzo');

    if (!prodottoId) { flashRow(tr, 'Seleziona un prodotto.'); return false; }
    if (!prezzo || isNaN(parseFloat(prezzo))) { flashRow(tr, 'Inserisci un prezzo valido.'); return false; }

    // Run business validations and check duplicate blockers
    runBusinessValidations();
    if (tr.dataset.hasDuplicate === 'true') {
      flashRow(tr, 'Articolo duplicato con lo stesso scaglione di quantità. Salvo bloccato.');
      return false;
    }

    const btn = tr.querySelector('.btn-confirm-riga');
    if (btn) btn.classList.add('is-saving');

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

      if (!d.ok) { flashRow(tr, d.error ?? 'Errore.'); return false; }

      applyRigaData(tr, d.riga);
      tr.classList.remove('is-editing', 'pl-riga-new');
      tr.dataset.rigaId = d.riga.id;
      
      // Reset dirty state indicators
      tr.querySelectorAll('.pl-input').forEach(input => {
        input.classList.remove('is-dirty');
        input.style.backgroundColor = '';
        input.style.fontStyle = '';
      });

      tr.classList.add('pl-riga-saved');
      setTimeout(() => tr.classList.remove('pl-riga-saved'), 800);
      syncEmpty();
      runBusinessValidations();
      return true;
    } catch (err) {
      flashRow(tr, 'Errore di connessione o salvataggio.');
      return false;
    } finally {
      if (btn) btn.classList.remove('is-saving');
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
      // Ripristina i valori precedenti
      setInput(tr, '.r-qmin', tr.dataset.qmin);
      setInput(tr, '.r-prezzo', tr.dataset.prezzo);
      setInput(tr, '.r-sconto', tr.dataset.sconto);
      setInput(tr, '.r-note', tr.dataset.note);
      setInput(tr, '.r-unita-misura-id', tr.dataset.uomSelectedId || tr.dataset.uomStockId || '');
      setInput(tr, '.r-prodotto-id', tr.dataset.prodottoId || '');
      setInput(tr, '.r-prodotto-label', tr.dataset.prodottoNome || '');

      const linkScheda = tr.querySelector('.pl-link-scheda');
      if (linkScheda) {
        if (tr.dataset.prodottoId) {
          linkScheda.href = BASE + '/products/edit?id=' + tr.dataset.prodottoId;
          linkScheda.style.display = 'inline-block';
        } else {
          linkScheda.href = '#';
          linkScheda.style.display = 'none';
        }
      }
      
      tr.querySelectorAll('.pl-input').forEach(input => {
        input.classList.remove('is-dirty');
        input.style.backgroundColor = '';
        input.style.fontStyle = '';
      });
    }
    syncEmpty();
    runBusinessValidations();
  }

  /* ════════════════════════════════════════════════════
     DELETE ROW
  ════════════════════════════════════════════════════ */
  async function deleteRow(tr) {
    try {
      const rigaId = tr.dataset.rigaId;
      const originalHtml = tr.innerHTML;
      const originalData = {
        prodottoId: tr.dataset.prodottoId,
        prodottoNome: tr.dataset.prodottoNome,
        sku: tr.dataset.sku,
        um: tr.dataset.um,
        uomStockId: tr.dataset.uomStockId,
        uomStockCode: tr.dataset.uomStockCode,
        uomCommercialId: tr.dataset.uomCommercialId,
        uomCommercialCode: tr.dataset.uomCommercialCode,
        unitaMisuraId: tr.dataset.uomSelectedId,
        qmin: tr.dataset.qmin,
        prezzo: tr.dataset.prezzo,
        sconto: tr.dataset.sconto,
        note: tr.dataset.note,
        costo: tr.dataset.costo
      };

      // Invia subito la richiesta di eliminazione al server
      const d = await apiPost('/sales/pricelists/righe/delete', { riga_id: rigaId });
      if (!d.ok) {
        window.qkToast(d.error ?? 'Errore durante l\'eliminazione.', 'danger');
        return;
      }

      window.qkSoftDeleteRow(tr, {
        colSpan: 7,
        messageHtml: `<i class="fa-solid fa-trash-can text-danger me-2"></i>Prezzo per <strong>${esc(originalData.prodottoNome)}</strong> rimosso. <a href="#" class="undo-delete-inline ms-3 fw-bold text-decoration-underline text-primary">Annulla</a>`,
        onUndo: async () => {
          try {
            const res = await apiPost('/sales/pricelists/righe/save', {
              listino_id:       LISTINO,
              riga_id:          0, // Nuova riga
              prodotto_id:      originalData.prodottoId,
              prezzo_unitario:  originalData.prezzo,
              quantita_minima:  originalData.qmin || '0',
              sconto_perc:      originalData.sconto || '0',
              unita_misura_id:  originalData.unitaMisuraId || null,
              note:             originalData.note || '',
            });

            if (res.ok) {
              applyRigaData(tr, res.riga);
              tr.classList.add('pl-riga-saved');
              setTimeout(() => tr.classList.remove('pl-riga-saved'), 800);
              syncEmpty();
              runBusinessValidations();
              return true;
            } else {
              window.qkToast('Impossibile ripristinare: ' + (res.error ?? 'Errore.'), 'danger');
              return false;
            }
          } catch (err) {
            window.qkToast('Errore durante il ripristino.', 'danger');
            return false;
          }
        },
        onDismiss: () => {
          syncEmpty();
          runBusinessValidations();
        }
      });

    } catch (err) {
      console.error("Errore durante la cancellazione:", err);
      alert("Errore durante la cancellazione: " + err.message);
    }
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
    tr.dataset.costo       = r.costo ?? '0';

    // Aggiorna elementi view
    const spanName = tr.querySelector('.pl-view span.qk-text-accent');
    const anchorLink = tr.querySelector('.pl-view a[target="_blank"]');
    const sku  = tr.querySelector('.pl-view .pl-sku');
    if (spanName) spanName.textContent = r.prodotto_nome;
    if (anchorLink) anchorLink.href = BASE + '/products/edit?id=' + r.prodotto_id;
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

    setText(tr, '.pl-td-qmin .pl-num',  fmt(r.quantita_minima, 3));
    setText(tr, '.pl-prezzo-val',  fmt(r.prezzo_unitario, 4));
    setText(tr, '.pl-td-sconto .pl-num',  fmt(r.sconto_perc, 2) + ' %');
    setText(tr, '.pl-note-val', r.note ?? '');

    // Se è una nuova riga, costruisce gli elementi view mancanti
    if (!tr.querySelector('.pl-td-product .pl-view')) {
      buildViewElements(tr, r);
    }
  }

  function buildViewElements(tr, r) {
    const fmt = (v, d) => parseFloat(v || 0).toLocaleString('it-IT', { minimumFractionDigits: d, maximumFractionDigits: d });

    const tdProduct = tr.querySelector('.pl-td-product');
    const plEdit = tdProduct.querySelector('.pl-edit');
    const viewDiv = document.createElement('div');
    viewDiv.className = 'pl-view';
    viewDiv.innerHTML = `
      <span class="fw-semibold qk-text-accent" style="cursor: pointer;">${esc(r.prodotto_nome)}</span>
      <a href="${BASE}/products/edit?id=${r.prodotto_id}" target="_blank" class="ms-1 text-muted" title="Apri scheda prodotto" style="font-size: 0.72rem;">
        <i class="fa-solid fa-arrow-up-right-from-square"></i>
      </a>
      <div class="pl-sku">${esc(r.sku_interno)}</div>
      <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
      </div>
    `;
    tdProduct.insertBefore(viewDiv, plEdit);

    setText(tr, '.pl-um-label', r.unita_misura ?? '—');

    // Numeri
    const tdQmin = tr.querySelector('.pl-td-qmin');
    if (tdQmin) tdQmin.insertAdjacentHTML('afterbegin', `<span class="pl-view pl-num">${fmt(r.quantita_minima,3)}</span>`);

    const tdPrezzo = tr.querySelector('.pl-td-prezzo');
    if (tdPrezzo) {
      tdPrezzo.insertAdjacentHTML('afterbegin', `
        <div class="pl-view">
          <span class="pl-num pl-prezzo-val">${fmt(r.prezzo_unitario,4)}</span>
        </div>`);
      tdPrezzo.insertAdjacentHTML('beforeend', `
        <div class="pl-sottocosto-warning text-warning fw-semibold mt-1" style="font-size: 0.75rem; text-align: right; display: none;">
          <i class="fa-solid fa-triangle-exclamation me-1"></i>Sottocosto (<span class="pl-costo-val">0.00</span> €)
        </div>`);
    }

    const tdSconto = tr.querySelector('.pl-td-sconto');
    if (tdSconto) tdSconto.insertAdjacentHTML('afterbegin', `<span class="pl-view pl-num">${fmt(r.sconto_perc,2)} %</span>`);

    // Note
    const tdNote = tr.querySelector('.pl-td-note');
    if (tdNote) tdNote.insertAdjacentHTML('afterbegin', `<span class="pl-view pl-note-val">${esc(r.note??'')}</span>`);

    // Actions view
    const tdActions = tr.querySelector('.pl-td-actions');
    if (tdActions) {
      tdActions.innerHTML = `
        <button type="button" class="pl-btn-icon pl-btn-danger btn-delete-riga" title="Rimuovi" style="vertical-align: middle;">
          <i class="fa-solid fa-trash-can"></i>
        </button>`;
    }
  }

  /* ════════════════════════════════════════════════════
     BUSINESS VALIDATIONS (DUPLICATES & SOTTOCOSTO)
  ════════════════════════════════════════════════════ */
  function runBusinessValidations() {
    const rows = Array.from(tbody.querySelectorAll('tr.pl-riga'));
    
    // 1. Duplicate Check
    // D4.5 (PIANO Motore di Pricing Multi-UoM §5.8/§7.4/§8 Fase 4): prima di questa modifica, la
    // chiave usava il numero grezzo di quantita_minima, senza considerare l'unità — due scaglioni
    // legittimi con lo stesso numero ma unità diverse (es. "5" in KG e "5" in FUSTO) venivano
    // segnalati come falsi duplicati, mentre i veri "doppioni mascherati" (es. 100 KG e 4 FUSTI con
    // fattore 25 = stesso valore canonico 100) restavano invisibili. Qui si stima lato client lo
    // stesso valore canonico che il server calcola con qk_uom_converti_riga() (Fase 3): se l'unità
    // selezionata sulla riga è la UoM commerciale, si divide per il fattore di conversione.
    const countMap = {};
    rows.forEach(tr => {
      const pid = tr.querySelector('.r-prodotto-id')?.value || tr.dataset.prodottoId;
      const qminInput = tr.querySelector('.r-qmin');
      const qminVal = qminInput ? qminInput.value : tr.dataset.qmin;
      let qmin = parseFloat(qminVal || 0);

      const umSelect = tr.querySelector('.r-unita-misura-id');
      const umSelectedId = umSelect ? umSelect.value : tr.dataset.uomSelectedId;
      const uomCommercialId = tr.dataset.uomCommercialId;
      const factor = parseFloat(tr.dataset.factor) || 1;

      if (qmin > 0 && uomCommercialId && String(umSelectedId) === String(uomCommercialId) && factor > 0 && factor !== 1) {
        qmin = qmin / factor; // stima del valore canonico in Stock UoM
      }
      const qminCanonico = qmin.toFixed(3);

      if (pid && pid !== '0') {
        const key = `${pid}_${qminCanonico}`;
        if (!countMap[key]) countMap[key] = [];
        countMap[key].push(tr);
      }
    });
    
    rows.forEach(tr => {
      tr.querySelectorAll('.pl-duplicate-warning').forEach(el => el.style.display = 'none');
      tr.dataset.hasDuplicate = 'false';
    });
    
    Object.keys(countMap).forEach(key => {
      const collisionRows = countMap[key];
      if (collisionRows.length > 1) {
        collisionRows.forEach(tr => {
          tr.querySelectorAll('.pl-duplicate-warning').forEach(el => el.style.display = 'block');
          tr.dataset.hasDuplicate = 'true';
        });
      }
    });
    
    // 2. Sottocosto Check
    rows.forEach(tr => {
      const prezzoInput = tr.querySelector('.r-prezzo');
      const prezzo = parseFloat((prezzoInput ? prezzoInput.value : tr.dataset.prezzo) || 0);
      
      const scontoInput = tr.querySelector('.r-sconto');
      const sconto = parseFloat((scontoInput ? scontoInput.value : tr.dataset.sconto) || 0);
      
      const costo = parseFloat(tr.dataset.costo || 0);
      
      const warningEl = tr.querySelector('.pl-sottocosto-warning');
      if (warningEl) {
        if (costo > 0) {
          const prezzoNetto = prezzo * (1 - sconto / 100);
          if (prezzoNetto < costo) {
            warningEl.querySelector('.pl-costo-val').textContent = costo.toFixed(2);
            warningEl.style.display = 'block';
          } else {
            warningEl.style.display = 'none';
          }
        } else {
          warningEl.style.display = 'none';
        }
      }
    });
  }

  /* ════════════════════════════════════════════════════
     M2O AUTOCOMPLETE per nuova riga
  ════════════════════════════════════════════════════ */
  function initRowM2O(tr) {
    const inputEl    = tr.querySelector('.r-prodotto-label');
    const hiddenEl   = tr.querySelector('.r-prodotto-id');
    const dropdownEl = tr.querySelector('.r-prodotto-dropdown');
    const clearBtn   = tr.querySelector('.r-prodotto-clear');
    const umLabel    = tr.querySelector('.pl-um-label');

    if (!inputEl || inputEl.dataset.m2oInitialized === 'true') return;
    inputEl.dataset.m2oInitialized = 'true';

    new QkM2O({
      inputEl, hiddenEl, dropdownEl, clearBtn,
      apiUrl:   BASE + '/sales/pricelists/api/prodotti',
      labelKey: 'label',
      columns: [{ key: 'label', label: 'Prodotto' }, { key: 'sku', label: 'SKU' }],
      onSelect: (id, item) => {
        const selectEl = tr.querySelector('.r-unita-misura-id');
        if (selectEl) {
          selectEl.innerHTML = '';
          if (item) {
            tr.dataset.uomStockId = item.unita_misura_stock_id || '';
            tr.dataset.uomStockCode = item.unita_misura || '';
            tr.dataset.uomCommercialId = item.unita_contenuto_id || '';
            tr.dataset.uomCommercialCode = item.unita_misura_commerciale || '';
            tr.dataset.costo = item.costo || item.costo_base || '0';
            
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
            tr.dataset.costo = '0';
          }
        }

        // Aggiorna l'icona del link esterno della scheda prodotto
        const linkScheda = tr.querySelector('.pl-link-scheda');
        if (linkScheda) {
          if (item && id) {
            linkScheda.href = BASE + '/products/edit?id=' + id;
            linkScheda.style.display = 'inline-block';
          } else {
            linkScheda.href = '#';
            linkScheda.style.display = 'none';
          }
        }

        if (!item) {
          if (umLabel) umLabel.textContent = '—';
          return;
        }
        // Pre-compila UM
        if (umLabel) umLabel.textContent = item.unita_misura || '—';
        if (hiddenEl) {
          checkInputDirty(hiddenEl);
        }
        runBusinessValidations();
        // Focus su qmin o prezzo
        setTimeout(() => tr.querySelector('.r-qmin')?.focus(), 50);
      },
      onClear: () => {
        const linkScheda = tr.querySelector('.pl-link-scheda');
        if (linkScheda) {
          linkScheda.href = '#';
          linkScheda.style.display = 'none';
        }
        if (hiddenEl) {
          checkInputDirty(hiddenEl);
        }
      }
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

  /* ── Filtro di ricerca client-side ── */
  document.getElementById('pl-grid-search')?.addEventListener('input', function (e) {
    dismissAllSoftDeleted();
    const q = e.target.value.toLowerCase().trim();
    tbody.querySelectorAll('tr.pl-riga').forEach(tr => {
      if (tr.classList.contains('is-editing')) {
        tr.style.display = '';
        return;
      }
      const nome = (tr.dataset.prodottoNome || '').toLowerCase();
      const sku = (tr.dataset.sku || '').toLowerCase();
      if (nome.includes(q) || sku.includes(q)) {
        tr.style.display = '';
      } else {
        tr.style.display = 'none';
      }
    });
  });
});</script>

<!-- Modal per Importazione Massiva Prezzi Vendita -->
<div class="modal fade" id="modalImportaPrezziSales" tabindex="-1" aria-labelledby="modalImportaPrezziSalesTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalImportaPrezziSalesTitle">
          <i class="fa-solid fa-file-import me-2"></i>Importazione Massiva Prezzi Vendita
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="qk-alert qk-alert-info mb-3">
          <i class="fa-solid fa-circle-info"></i>
          <span>Carica un file Excel (.xlsx) per importare o aggiornare i prezzi di vendita degli articoli. Se un prezzo esiste già per lo stesso articolo e scaglione quantità, verrà aggiornato.</span>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <input type="file" id="inputFilePrezziSales" accept=".xlsx" class="d-none">
            <label for="inputFilePrezziSales" class="btn-qk btn-qk-primary">
              <i class="fa-solid fa-file-excel me-1"></i>Seleziona File Excel...
            </label>
            <button type="button" class="btn-qk btn-qk-secondary" id="btnClearFilePrezziSales">
              <i class="fa-solid fa-trash me-1"></i>Rimuovi File
            </button>
          </div>
          <button type="button" class="btn-qk btn-qk-secondary" id="btnDownloadTracciatoPrezziSales">
            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Modello Tracciato
          </button>
        </div>

        <div id="importPreviewWrapPrezziSales" style="display:none" class="mb-3">
          <h6 class="fw-bold mb-2">Anteprima Righe (<span id="importPreviewCountPrezziSales">0</span> righe trovate, mostra max 10)</h6>
          <div class="qk-table-wrap" style="max-height: 250px; overflow-y: auto;">
            <table class="qk-table mb-0" style="font-size: .8rem">
              <thead id="importPreviewHeadPrezziSales"></thead>
              <tbody id="importPreviewBodyPrezziSales"></tbody>
            </table>
          </div>
        </div>

        <div id="importResultPrezziSales" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Chiudi</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btnImportaPrezziSales" disabled>
          <i class="fa-solid fa-upload me-1"></i>Avvia Importazione
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ExcelJS Library for reading/writing xlsx -->
<script src="<?= asset('js/exceljs.min.js') ?>"></script>

<script>
(function () {
  'use strict';

  var LISTINO_ID = <?= (int)($id ?? 0) ?>;
  var CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
  var IMPORT_URL = QK_BASE + '/sales/pricelists/api/import';

  var COLS_PREZZI = [
    {key:'sku_interno',     note:'Obbligatorio. SKU interno del prodotto in Quack.', esempio:'PROD-001'},
    {key:'quantita_minima', note:'Quantità minima per questa tariffa.',              esempio:'1.000'},
    {key:'prezzo_unitario', note:'Obbligatorio. Prezzo unitario di vendita.',       esempio:'15.5000'},
    {key:'sconto_perc',     note:'Sconto percentuale da applicare.',                 esempio:'5.00'},
    {key:'note',            note:'Note specifiche del prezzo.',                      esempio:'Prezzo promozionale'}
  ];

  function downloadBuffer(buffer, filename) {
    var blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click();
    setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1000);
  }

  document.getElementById('btnDownloadTracciatoPrezziSales')?.addEventListener('click', async function(){
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione...';

    var wb = new ExcelJS.Workbook();
    wb.creator = 'Quack ERP';
    var ws = wb.addWorksheet('Tariffe Vendita');
    ws.columns = COLS_PREZZI.map(function(c){ return {header:c.key, key:c.key, width:Math.max(c.key.length+4,22)}; });

    var hr = ws.getRow(1);
    COLS_PREZZI.forEach(function(c,i){
      var cell = hr.getCell(i+1);
      cell.value = c.key;
      cell.font  = {bold:true, color:{argb:'FFFFFFFF'}};
      cell.fill  = {type:'pattern', pattern:'solid', fgColor:{argb:'FF2E4057'}};
      cell.alignment = {vertical:'middle', horizontal:'center'};
      cell.note  = {texts:[{text:c.note}]};
    });
    hr.height = 22;

    var ex = ws.addRow(COLS_PREZZI.reduce(function(a,c){ a[c.key]=c.esempio; return a; }, {}));
    ex.eachCell(function(cell){
      cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFEAF2FB'}};
      cell.font = {italic:true, color:{argb:'FF555555'}};
    });
    ws.views = [{state:'frozen', ySplit:1}];

    downloadBuffer(await wb.xlsx.writeBuffer(), 'Listino_Vendite_Tracciato.xlsx');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Modello Tracciato';
  });

  var parsedRows = [];

  document.getElementById('inputFilePrezziSales')?.addEventListener('change', async function(){
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = async function(e){
      var wb = new ExcelJS.Workbook();
      await wb.xlsx.load(e.target.result);
      var ws = wb.worksheets[0];
      if (!ws) { alert('File non valido.'); return; }

      var headers = [];
      ws.getRow(1).eachCell({includeEmpty:false}, function(cell){
        headers.push(String(cell.value !== null && cell.value !== undefined ? cell.value : '').trim());
      });

      parsedRows = [];
      ws.eachRow(function(row, rn){
        if (rn === 1) return;
        var obj = {};
        headers.forEach(function(h,i){
          var v = row.getCell(i+1).value;
          obj[h] = (v !== null && v !== undefined) ? String(v).trim() : '';
        });
        if (Object.values(obj).some(function(v){ return v !== ''; })) parsedRows.push(obj);
      });
      renderPreview(headers, parsedRows);
    };
    reader.readAsArrayBuffer(file);
  });

  document.getElementById('btnClearFilePrezziSales')?.addEventListener('click', function(){
    document.getElementById('inputFilePrezziSales').value = '';
    document.getElementById('importPreviewWrapPrezziSales').style.display = 'none';
    document.getElementById('btnImportaPrezziSales').disabled = true;
    document.getElementById('importResultPrezziSales').style.display = 'none';
    parsedRows = [];
  });

  function renderPreview(headers, rows) {
    var head = document.getElementById('importPreviewHeadPrezziSales');
    var body = document.getElementById('importPreviewBodyPrezziSales');
    head.innerHTML = ''; body.innerHTML = '';

    var trH = document.createElement('tr');
    headers.forEach(function(h){
      var th = document.createElement('th'); th.textContent = h; trH.appendChild(th);
    });
    head.appendChild(trH);

    var preview = rows.slice(0, 10);
    preview.forEach(function(r){
      var tr = document.createElement('tr');
      headers.forEach(function(h){
        var td = document.createElement('td'); td.textContent = r[h] || ''; tr.appendChild(td);
      });
      body.appendChild(tr);
    });

    document.getElementById('importPreviewCountPrezziSales').textContent = rows.length;
    document.getElementById('importPreviewWrapPrezziSales').style.display = 'block';
    document.getElementById('btnImportaPrezziSales').disabled = rows.length === 0;
  }

  document.getElementById('btnImportaPrezziSales')?.addEventListener('click', async function(){
    if (parsedRows.length === 0) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Importazione in corso...';

    var fd = new FormData();
    fd.append('_token', CSRF_TOKEN);
    fd.append('listino_id', LISTINO_ID);
    fd.append('rows', JSON.stringify(parsedRows));

    try {
      var res = await fetch(IMPORT_URL, { method:'POST', body:fd });
      var d = await res.json();
      var resWrap = document.getElementById('importResultPrezziSales');
      resWrap.className = d.ok ? 'qk-alert qk-alert-success mt-3' : 'qk-alert qk-alert-danger mt-3';

      var html = '<strong>' + (d.ok ? 'Importazione completata con successo!' : 'Errore nell\'importazione.') + '</strong><br>';
      if (d.ok) {
        html += 'Caricate/Aggiornate ' + d.inserted + ' righe. ';
        if (d.skipped > 0) html += 'Saltate ' + d.skipped + ' righe con errori.<br>';
        if (d.removed > 0) html += 'Rimosse ' + d.removed + ' righe non più presenti nel file (assenti dal nuovo listino).<br>';
      } else {
        html += d.error || '';
      }

      if (d.errors && d.errors.length > 0) {
        html += '<ul class="mb-0 mt-2 text-start small" style="max-height:150px; overflow-y:auto">';
        d.errors.forEach(function(err){ html += '<li>' + err + '</li>'; });
        html += '</ul>';
      }

      resWrap.innerHTML = html;
      resWrap.style.display = 'block';

      if (d.ok) {
        setTimeout(function(){ window.location.reload(); }, 1500);
      }
    } catch(err) {
      alert('Errore di connessione.');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Avvia Importazione';
    }
  });

})();
</script>

<!-- CSS per feedback "salvato" -->
<style>
  @keyframes pl-saved-flash {
    0%   { background-color: var(--qk-success-bg); }
    100% { background-color: transparent; }
  }
  .pl-riga-saved > td { animation: pl-saved-flash .8s ease-out forwards; }
</style>

