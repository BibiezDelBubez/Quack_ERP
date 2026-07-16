document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const BASE    = QK_BASE;
  const CSRF    = window.QkPricelistConfig.csrfToken;
  const LISTINO = window.QkPricelistConfig.listinoId;
  const VALUTA  = window.QkPricelistConfig.valuta;

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

    if (e.target.closest('.btn-cancel-riga, .btn-confirm-riga, .btn-delete-riga')) {
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
            nextTr.querySelector('.qk-form-control')?.focus();
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
            nextTr.querySelector('.qk-form-control')?.focus();
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
    tr.querySelectorAll('.qk-form-control').forEach(input => {
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
      tr.querySelectorAll('.qk-form-control').forEach(input => {
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
      
      tr.querySelectorAll('.qk-form-control').forEach(input => {
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
        <button type="button" class="btn-qk btn-qk-danger btn-qk-sm btn-delete-riga" title="Rimuovi" style="vertical-align: middle;">
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
});