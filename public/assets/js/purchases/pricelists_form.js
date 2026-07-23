document.addEventListener('DOMContentLoaded', function () {
  const isEdit = window.QkPurchasePricelist.isEdit;
  
  if (!isEdit) {
    const inputEl = document.getElementById('pl-fornitore-label');
    const hiddenEl = document.getElementById('pl-fornitore-id');
    const dropdownEl = document.getElementById('pl-fornitore-dropdown');
    const clearBtn = document.getElementById('pl-fornitore-clear');

    const m2o = new QkM2O({
      inputEl: inputEl,
      hiddenEl: hiddenEl,
      dropdownEl: dropdownEl,
      clearBtn: clearBtn,
      apiUrl: QK_BASE + '/purchases/rfq/api/fornitori',
      labelKey: 'nome',
      columns: [
        { key: 'nome', label: 'Fornitore' },
        { key: 'codice', label: 'Codice' }
      ]
    });

    if (hiddenEl.value) {
      clearBtn.classList.remove('d-none');
    }
  } else {
    // Gestione della griglia prezzi acquisto inline
    const BASE = QK_BASE;
    const CSRF = window.QkPurchasePricelist.csrfToken;
    const LISTINO = window.QkPurchasePricelist.listinoId;
    const tbody = document.getElementById('pl-tbody-acquisto');
    const table = document.getElementById('tbl-prezzi-acquisto');
    const empty = document.getElementById('pl-empty-acquisto');
    const tpl = document.getElementById('pl-tpl-new-acquisto');

    let isInteractingWithDropdown = false;
    document.addEventListener('mousedown', function (e) {
      if (e.target.closest('.qk-m2o-dropdown')) {
        isInteractingWithDropdown = true;
      }
    });
    document.addEventListener('mouseup', function () {
      isInteractingWithDropdown = false;
    });

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

          const uomSpan = tr.querySelector('.r-uom-view');
          if (uomSpan) uomSpan.textContent = '/ ' + displayUom;
          
          // Note: When entering "Edit" mode, we typically want them to edit in NATIVE uom to avoid rounding save errors.
          // The current design keeps the input at native values.
        });
      });
      // trigger initially
      uomToggle.dispatchEvent(new Event('change'));
    }

    // Run duplicate check on load
    setTimeout(runBusinessValidations, 200);

    document.getElementById('btn-add-riga-acquisto').addEventListener('click', () => {
      tbody.querySelectorAll('.pl-riga.is-editing').forEach(tr => cancelEdit(tr));
      addNewRow();
    });

    function addNewRow() {
      const fragment = tpl.content.cloneNode(true);
      tbody.prepend(fragment);
      const newTr = tbody.firstElementChild;
      syncEmpty();
      initNewRowM2O(newTr);
      newTr.querySelector('.r-prodotto-label')?.focus();
      newTr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /* ════════════════════════════════════════════════════
       CLICK-TO-EDIT & CELL FOCUS TRIGGER
    ════════════════════════════════════════════════════ */
    tbody.addEventListener('click', function (e) {
      const td = e.target.closest('td');
      if (!td) return;
      const tr = td.closest('tr.pl-riga');
      if (!tr) return;

      if (e.target.closest('.btn-qk')) {
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
      if (tr && !tr.classList.contains('is-editing')) {
        enterEdit(tr);
      }
    });

    /* ════════════════════════════════════════════════════
       IMMEDIATE AUTOSAVE ON BLUR (FOCUSOUT FROM ROW)
    ════════════════════════════════════════════════════ */
    tbody.addEventListener('focusout', function (e) {
      if (isInteractingWithDropdown) {
        return;
      }
      const tr = e.target.closest('tr.pl-riga');
      if (!tr) return;

      const nextFocused = e.relatedTarget;
      if (!nextFocused || nextFocused.closest('tr.pl-riga') !== tr) {
        if (nextFocused && nextFocused.closest('.btn-cancel-riga, .btn-delete-riga')) {
          return;
        }

        const hasDirty = tr.querySelectorAll('.is-dirty').length > 0;
        const isNew = tr.dataset.rigaId === '0';

        if (hasDirty || (isNew && tr.querySelector('.r-prodotto-id')?.value)) {
          confirmEdit(tr);
        } else if (isNew && !tr.querySelector('.r-prodotto-id')?.value) {
          const searchVal = tr.querySelector('.r-prodotto-label')?.value?.trim() || '';
          const prezzoVal = tr.querySelector('.r-prezzo')?.value?.trim() || '';
          const qminVal   = tr.querySelector('.r-qmin')?.value?.trim() || '0';
          const scontoVal = tr.querySelector('.r-sconto')?.value?.trim() || '0';
          const noteVal   = tr.querySelector('.r-note')?.value?.trim() || '';

          const isFullyEmpty = (searchVal === '') && 
                               (prezzoVal === '' || parseFloat(prezzoVal) === 0) &&
                               (qminVal === '' || parseFloat(qminVal) === 0) &&
                               (scontoVal === '' || parseFloat(scontoVal) === 0) &&
                               (noteVal === '');

          if (isFullyEmpty) {
            cancelEdit(tr);
          }
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

      if (input.classList.contains('r-sku-forn')) {
        isDirty = val !== (tr.dataset.skuFornitore || '');
      } else if (input.classList.contains('r-qmin')) {
        isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.qmin || 0);
      } else if (input.classList.contains('r-prezzo')) {
        isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.prezzo || 0);
      } else if (input.classList.contains('r-sconto')) {
        isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.sconto || 0);
      } else if (input.classList.contains('r-valido-dal')) {
        isDirty = val !== (tr.dataset.validoDal || '');
      } else if (input.classList.contains('r-valido-al')) {
        isDirty = val !== (tr.dataset.validoAl || '');
      } else if (input.classList.contains('r-note')) {
        isDirty = val !== (tr.dataset.note || '');
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
       KEYBOARD Navigation and events
    ════════════════════════════════════════════════════ */
    tbody.addEventListener('keydown', function (e) {
      const input = e.target;
      const tr = input.closest('tr.pl-riga');
      if (!tr) return;

      // Up/Down Arrows navigation
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        if (!input.matches('input, select') || input.tagName === 'SELECT') return;
        if (input.classList.contains('r-prodotto-label')) {
          const m2oDropdown = input.closest('.qk-m2o-wrap')?.querySelector('.qk-m2o-dropdown');
          if (m2oDropdown && m2oDropdown.classList.contains('open')) return;
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

      // Tab on note -> save and go to next or new row
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
        if (input.classList.contains('r-prodotto-label')) {
          const m2oDropdown = input.closest('.qk-m2o-wrap')?.querySelector('.qk-m2o-dropdown');
          if (m2oDropdown && m2oDropdown.classList.contains('open')) return;
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

      // Escape -> Discard
      if (e.key === 'Escape') {
        e.preventDefault();
        cancelEdit(tr);
        return;
      }
    });

    function enterEdit(tr) {
      tbody.querySelectorAll('.pl-riga.is-editing').forEach(other => {
        if (other !== tr) {
          const otherDirty = other.querySelectorAll('.is-dirty').length > 0;
          if (otherDirty) {
            confirmEdit(other);
          } else {
            cancelEdit(other);
          }
        }
      });
      // Popola gli input con i dataset
      tr.querySelector('.r-sku-forn').value = tr.dataset.skuFornitore || '';
      tr.querySelector('.r-qmin').value = tr.dataset.qmin || '0';
      tr.querySelector('.r-prezzo').value = tr.dataset.prezzo || '';
      tr.querySelector('.r-sconto').value = tr.dataset.sconto || '0';
      tr.querySelector('.r-valido-dal').value = tr.dataset.validoDal || '';
      tr.querySelector('.r-valido-al').value = tr.dataset.validoAl || '';
      tr.querySelector('.r-note').value = tr.dataset.note || '';

      tr.querySelectorAll('.qk-form-control').forEach(input => {
        input.classList.remove('is-dirty');
        input.style.backgroundColor = '';
        input.style.fontStyle = '';
      });

      tr.classList.add('is-editing');
    }

    async function confirmEdit(tr) {
      const isNew = tr.dataset.rigaId === '0';
      const prodottoId = isNew ? tr.querySelector('.r-prodotto-id').value : tr.dataset.prodottoId;
      const prezzo = tr.querySelector('.r-prezzo').value.trim();

      if (!prodottoId) { flashRow(tr, 'Seleziona un prodotto.'); return false; }
      if (!prezzo || isNaN(parseFloat(prezzo))) { flashRow(tr, 'Inserisci un prezzo valido.'); return false; }

      const scontoInput = tr.querySelector('.r-sconto').value.trim() || '0';
      const scontoVal = parseFloat(scontoInput);
      if (scontoVal > 50) {
        if (!confirm('Attenzione: stai applicando uno sconto eccezionale superiore al 50%. Sei sicuro di voler procedere?')) {
          return false;
        }
      }

      // Check duplicates before saving
      runBusinessValidations();
      if (tr.dataset.hasDuplicate === 'true') {
        flashRow(tr, 'Articolo duplicato con lo stesso scaglione di quantità. Salvo bloccato.');
        return false;
      }

      const btn = tr.querySelector('.btn-confirm-riga');
      if (btn) btn.classList.add('is-saving');

      try {
        const res = await fetch(BASE + '/purchases/pricelists/righe/save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            csrf_token: CSRF,
            listino_acquisto_id: LISTINO,
            riga_id: tr.dataset.rigaId,
            prodotto_id: prodottoId,
            sku_fornitore: tr.querySelector('.r-sku-forn').value.trim(),
            quantita_minima: tr.querySelector('.r-qmin').value.trim() || '0',
            prezzo: prezzo,
            sconto_perc: tr.querySelector('.r-sconto').value.trim() || '0',
            valido_dal: tr.querySelector('.r-valido-dal').value,
            valido_al: tr.querySelector('.r-valido-al').value,
            note: tr.querySelector('.r-note').value.trim()
          })
        });

        const d = await res.json();
        if (!d.ok) { flashRow(tr, d.error || 'Errore durante il salvataggio.'); return false; }

        applyRigaData(tr, d.riga);
        tr.classList.remove('is-editing');
        tr.classList.add('pl-riga-saved');
        setTimeout(() => tr.classList.remove('pl-riga-saved'), 800);
        syncEmpty();
        runBusinessValidations();
        return true;
      } catch (err) {
        flashRow(tr, 'Errore di rete.');
        return false;
      } finally {
        if (btn) btn.classList.remove('is-saving');
      }
    }

    function cancelEdit(tr) {
      if (tr.dataset.rigaId === '0') {
        tr.remove();
      } else {
        tr.classList.remove('is-editing');
        tr.querySelector('.r-sku-forn').value = tr.dataset.skuFornitore || '';
        tr.querySelector('.r-qmin').value = tr.dataset.qmin || '0';
        tr.querySelector('.r-prezzo').value = tr.dataset.prezzo || '';
        tr.querySelector('.r-sconto').value = tr.dataset.sconto || '0';
        tr.querySelector('.r-valido-dal').value = tr.dataset.validoDal || '';
        tr.querySelector('.r-valido-al').value = tr.dataset.validoAl || '';
        tr.querySelector('.r-note').value = tr.dataset.note || '';

        tr.querySelectorAll('.qk-form-control').forEach(input => {
          input.classList.remove('is-dirty');
          input.style.backgroundColor = '';
          input.style.fontStyle = '';
        });
      }
      syncEmpty();
      runBusinessValidations();
    }

    async function deleteRow(tr) {
      try {
        const rigaId = tr.dataset.rigaId;
        const originalHtml = tr.innerHTML;
        const originalData = {
          prodottoId: tr.dataset.prodottoId,
          prodottoNome: tr.dataset.prodottoNome,
          sku: tr.dataset.sku,
          skuFornitore: tr.dataset.skuFornitore,
          qmin: tr.dataset.qmin,
          prezzo: tr.dataset.prezzo,
          sconto: tr.dataset.sconto,
          validoDal: tr.dataset.validoDal,
          validoAl: tr.dataset.validoAl,
          note: tr.dataset.note
        };

        const res = await fetch(BASE + '/purchases/pricelists/righe/delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf_token: CSRF, riga_id: rigaId })
        });
        const d = await res.json();
        if (!d.ok) {
          window.qkToast(d.error || "Errore durante l'eliminazione.", "danger");
          return;
        }

        window.qkSoftDeleteRow(tr, {
          colSpan: 9,
          messageHtml: `<i class="fa-solid fa-trash-can text-danger me-2"></i>Prezzo per <strong>${escapeHtml(originalData.prodottoNome)}</strong> rimosso. <a href="#" class="undo-delete-inline ms-3 fw-bold text-decoration-underline text-primary">Annulla</a>`,
          onUndo: async () => {
            try {
              const res = await fetch(BASE + '/purchases/pricelists/righe/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                  csrf_token: CSRF,
                  listino_acquisto_id: LISTINO,
                  riga_id: '0',
                  prodotto_id: originalData.prodottoId,
                  sku_fornitore: originalData.skuFornitore,
                  quantita_minima: originalData.qmin || '0',
                  prezzo: originalData.prezzo,
                  sconto_perc: originalData.sconto || '0',
                  valido_dal: originalData.validoDal,
                  valido_al: originalData.validoAl,
                  note: originalData.note
                })
              });
              const rdata = await res.json();
              if (rdata.ok) {
                applyRigaData(tr, rdata.riga);
                tr.classList.add('pl-riga-saved');
                setTimeout(() => tr.classList.remove('pl-riga-saved'), 800);
                syncEmpty();
                runBusinessValidations();
                return true;
              } else {
                window.qkToast('Impossibile ripristinare: ' + (rdata.error || 'Errore.'), 'danger');
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
        window.qkToast('Errore durante la cancellazione.', 'danger');
      }
    }

    function applyRigaData(tr, r) {
      tr.dataset.rigaId = r.id;
      tr.dataset.prodottoId = r.prodotto_id;
      tr.dataset.prodottoNome = r.prodotto_nome || '';
      tr.dataset.sku = r.sku_interno || '';
      tr.dataset.skuFornitore = r.sku_fornitore || '';
      tr.dataset.qmin = r.quantita_minima;
      tr.dataset.prezzo = r.prezzo;
      tr.dataset.sconto = r.sconto_perc;
      tr.dataset.validoDal = r.valido_dal || '';
      tr.dataset.validoAl = r.valido_al || '';
      tr.dataset.note = r.note || '';

      const fmt = (v, d) => parseFloat(v || 0).toLocaleString('it-IT', { minimumFractionDigits: d, maximumFractionDigits: d });

      // Se riga nuova, costruiamo le celle view
      tr.innerHTML = `
        <td>
          <div class="pl-view">
            <a href="${BASE}/products/edit?id=${r.prodotto_id}" class="fw-semibold qk-text-accent" tabindex="-1">${escapeHtml(r.prodotto_nome)}</a>
            <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
              <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
            </div>
          </div>
        </td>
        <td class="col-sku-interno"><code>${escapeHtml(r.sku_interno)}</code></td>
        <td class="col-sku-fornitore">
          <span class="pl-view text-monospace">${escapeHtml(r.sku_fornitore || '—')}</span>
          <input class="pl-edit qk-form-control py-1 px-2 text-monospace r-sku-forn" type="text" value="${escapeHtml(r.sku_fornitore || '')}">
        </td>
        <td class="text-end">
          <span class="pl-view text-monospace">${fmt(r.quantita_minima, 3)}</span>
          <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-qmin" type="number" min="0" step="0.001" value="${r.quantita_minima}">
        </td>
        <td class="text-end">
          <span class="pl-view text-monospace fw-bold">${fmt(r.prezzo, 4)} €</span>
          <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-prezzo" type="number" min="0" step="0.0001" value="${r.prezzo}">
        </td>
        <td class="text-end col-sconto-perc">
          <span class="pl-view text-monospace">${fmt(r.sconto_perc, 2)} %</span>
          <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-sconto" type="number" min="0" max="100" step="0.01" value="${r.sconto_perc}">
        </td>
        <td class="col-validita">
          <div class="pl-view text-monospace small">
            ${r.valido_dal || r.valido_al ? 
              (r.valido_dal ? formatDate(r.valido_dal) : '...') + ' &rarr; ' + (r.valido_al ? formatDate(r.valido_al) : '...') : 
              'Sempre valido'}
          </div>
          <div class="pl-edit d-flex gap-1">
            <input class="qk-form-control py-1 px-1 r-valido-dal" type="date" value="${r.valido_dal || ''}" style="font-size:.75rem; min-width: 0; flex: 1;">
            <input class="qk-form-control py-1 px-1 r-valido-al" type="date" value="${r.valido_al || ''}" style="font-size:.75rem; min-width: 0; flex: 1;">
          </div>
        </td>
        <td class="col-note-prezzo">
          <span class="pl-view qk-text-muted small">${escapeHtml(r.note || '')}</span>
          <input class="pl-edit qk-form-control py-1 px-2 r-note" type="text" value="${escapeHtml(r.note || '')}">
        </td>
        <td>
          <button type="button" class="btn-qk btn-qk-danger btn-qk-sm px-2 btn-delete-riga" title="Rimuovi" style="vertical-align: middle;">
            <i class="fa-solid fa-trash-can"></i>
          </button>
        </td>
      `;
    }

    /* ════════════════════════════════════════════════════
       BUSINESS VALIDATIONS (DUPLICATES)
    ════════════════════════════════════════════════════ */
    function runBusinessValidations() {
      const rows = Array.from(tbody.querySelectorAll('tr.pl-riga'));
      
      // D4.5 (PIANO Motore di Pricing Multi-UoM §5.8/§7.4/§8 Fase 4): stessa correzione di
      // app/views/sales/pricelists/_righe.php — la chiave usava il numero grezzo, senza
      // considerare l'unità nativa della riga. Qui non c'è un select per-riga (questa griglia
      // ha un solo "toggle" globale di visualizzazione), quindi si stima il valore canonico
      // usando i dataset già presenti (data-um-native/-um-stock/-um-comm/-factor) impostati
      // dal PHP lato server.
      const countMap = {};
      rows.forEach(tr => {
        const pid = tr.querySelector('.r-prodotto-id')?.value || tr.dataset.prodottoId;
        const qminInput = tr.querySelector('.r-qmin');
        const qminVal = qminInput ? qminInput.value : tr.dataset.qmin;
        let qmin = parseFloat(qminVal || 0);

        const umNative = tr.dataset.umNative;
        const umStock = tr.dataset.umStock;
        const umComm = tr.dataset.umComm;
        const factor = parseFloat(tr.dataset.factor) || 1;

        if (qmin > 0 && umNative && umComm && umNative === umComm && umComm !== umStock && factor > 0 && factor !== 1) {
          // Contenuto/commerciale -> stock: la quantità si DIVIDE per il fattore
          // (qk_uom_converti_riga(): quantita_stock = qta / quantita_contenuto).
          // Era invertito (qmin * factor), vanificando il rilevamento duplicati.
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
    }

    function initNewRowM2O(tr) {
      const inputEl = tr.querySelector('.r-prodotto-label');
      const hiddenEl = tr.querySelector('.r-prodotto-id');
      const dropdownEl = tr.querySelector('.qk-m2o-dropdown');
      const clearBtn = tr.querySelector('.r-prodotto-clear');

      new QkM2O({
        inputEl, hiddenEl, dropdownEl, clearBtn,
        apiUrl: BASE + '/purchases/pricelists/api/prodotti',
        labelKey: 'label',
        columns: [{ key: 'label', label: 'Prodotto' }, { key: 'sku', label: 'SKU' }],
        onSelect: (id, item) => {
          if (item) {
            tr.querySelector('.r-sku-label').textContent = item.sku || '—';
            runBusinessValidations();
            setTimeout(() => tr.querySelector('.r-sku-forn')?.focus(), 50);
          } else {
            tr.querySelector('.r-sku-label').textContent = '—';
          }
        }
      });
    }

    function syncEmpty() {
      const hasRows = tbody.children.length > 0;
      empty.style.display = hasRows ? 'none' : 'block';
      table.style.display = hasRows ? 'table' : 'none';
      updateColumnVisibility();
    }

    function flashRow(tr, msg) {
      tr.style.outline = '2px solid var(--qk-danger)';
      tr.style.outlineOffset = '-2px';
      tr.title = msg;
      setTimeout(() => { tr.style.outline = ''; tr.title = ''; }, 2000);
    }

    function formatDate(ds) {
      if (!ds) return '';
      const p = ds.split('-');
      return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : ds;
    }

    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    /* ── Mostra/Nascondi Colonne ───────────────────────────────────────── */
    const btnColSettings = document.getElementById('btn-col-settings');
    const colSettingsDropdown = document.getElementById('col-settings-dropdown');
    const chkSkuInterno = document.getElementById('chk-show-sku-interno');
    const chkSkuFornitore = document.getElementById('chk-show-sku-fornitore');
    const chkSconto = document.getElementById('chk-show-sconto-perc');
    const chkValidita = document.getElementById('chk-show-validita');
    const chkNote = document.getElementById('chk-show-note');

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

      [chkSkuInterno, chkSkuFornitore, chkSconto, chkValidita, chkNote].forEach(chk => {
        chk?.addEventListener('change', updateColumnVisibility);
      });
    }

    function updateColumnVisibility() {
      const showSkuInterno = chkSkuInterno ? chkSkuInterno.checked : true;
      const showSkuFornitore = chkSkuFornitore ? chkSkuFornitore.checked : true;
      const showSconto = chkSconto ? chkSconto.checked : true;
      const showValidita = chkValidita ? chkValidita.checked : false;
      const showNote = chkNote ? chkNote.checked : true;

      document.querySelectorAll('.col-sku-interno').forEach(el => el.style.display = showSkuInterno ? '' : 'none');
      document.querySelectorAll('.col-sku-fornitore').forEach(el => el.style.display = showSkuFornitore ? '' : 'none');
      document.querySelectorAll('.col-sconto-perc').forEach(el => el.style.display = showSconto ? '' : 'none');
      document.querySelectorAll('.col-validita').forEach(el => el.style.display = showValidita ? '' : 'none');
      document.querySelectorAll('.col-note-prezzo').forEach(el => el.style.display = showNote ? '' : 'none');
    }

    // Applica stato iniziale
    updateColumnVisibility();

    /* ── Filtro di ricerca client-side ── */
    document.getElementById('pl-grid-search-acquisto')?.addEventListener('input', function (e) {
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
  }
});



(function () {
  'use strict';

  var LISTINO_ID = window.QkPurchasePricelist.listinoId;
  var CSRF_TOKEN = window.QkPurchasePricelist.csrfToken;
  var IMPORT_URL = QK_BASE + '/purchases/pricelists/api/import';

  var COLS_PREZZI = [
    {key:'sku_interno',     note:'Obbligatorio. SKU interno del prodotto in Quack.', esempio:'PROD-001'},
    {key:'sku_fornitore',   note:'Codice articolo assegnato dal fornitore.',         esempio:'SUPP-PROD-99'},
    {key:'quantita_minima', note:'Quantità minima per questa tariffa.',              esempio:'1.000'},
    {key:'prezzo',          note:'Obbligatorio. Prezzo unitario di acquisto.',       esempio:'12.5000'},
    {key:'sconto_perc',     note:'Sconto percentuale da applicare.',                 esempio:'5.00'},
    {key:'valido_dal',      note:'Data inizio validità riga (AAAA-MM-GG).',          esempio:'2026-01-01'},
    {key:'valido_al',       note:'Data fine validità riga (AAAA-MM-GG).',            esempio:'2026-12-31'},
    {key:'note',            note:'Note specifiche del prezzo.',                      esempio:'Prezzo bloccato da contratto'}
  ];

  function downloadBuffer(buffer, filename) {
    var blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click();
    setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1000);
  }

  document.getElementById('btnDownloadTracciatoPrezzi').addEventListener('click', async function(){
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione...';

    var wb = new ExcelJS.Workbook();
    wb.creator = 'Quack ERP';
    var ws = wb.addWorksheet('Tariffe');
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

    downloadBuffer(await wb.xlsx.writeBuffer(), 'Listino_Acquisti_Tracciato.xlsx');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Modello Tracciato';
  });

  var parsedRows = [];

  document.getElementById('inputFilePrezziAcquisto').addEventListener('change', async function(){
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

  function renderPreview(headers, rows){
    var th = '<tr>';
    headers.forEach(function(h){ th += '<th style="white-space:nowrap">'+esc(h)+'</th>'; });
    th += '</tr>';
    document.getElementById('importPreviewHeadPrezzi').innerHTML = th;

    var tb = '';
    rows.slice(0,10).forEach(function(row){
      tb += '<tr>';
      headers.forEach(function(h){
        var v = row[h] !== undefined ? row[h] : '';
        var bad = (h==='sku_interno'||h==='prezzo') && v==='';
        tb += '<td style="white-space:nowrap'+(bad?';color:var(--qk-danger);font-weight:600':'')+'">' + esc(v||'--') + '</td>';
      });
      tb += '</tr>';
    });
    if (rows.length > 10)
      tb += '<tr><td colspan="'+headers.length+'" class="text-center qk-text-muted" style="font-size:.8rem">... e altre '+(rows.length-10)+' righe</td></tr>';

    document.getElementById('importPreviewBodyPrezzi').innerHTML = tb;
    document.getElementById('importPreviewCountPrezzi').textContent = rows.length;
    document.getElementById('importPreviewWrapPrezzi').style.display = 'block';
    document.getElementById('btnImportaPrezziAcquisto').disabled = rows.length === 0;
    document.getElementById('importResultPrezzi').style.display  = 'none';
  }

  document.getElementById('btnClearFilePrezzi').addEventListener('click', function(){
    parsedRows = [];
    document.getElementById('inputFilePrezziAcquisto').value = '';
    document.getElementById('importPreviewWrapPrezzi').style.display = 'none';
    document.getElementById('btnImportaPrezziAcquisto').disabled = true;
    document.getElementById('importResultPrezzi').style.display  = 'none';
  });

  document.getElementById('modalImportaPrezziAcquisto').addEventListener('hidden.bs.modal', function(){
    document.getElementById('btnClearFilePrezzi').click();
  });

  function normalizePrezzoHeader(h) {
    var clean = String(h || '').trim().toLowerCase()
      .replace(/[\s\-\_]+/g, '_')
      .replace(/[\(\)]/g, '');
    
    var map = {
      'sku': 'sku_interno',
      'sku_interno': 'sku_interno',
      'codice': 'sku_interno',
      'codice_articolo': 'sku_interno',
      'prodotto_sku': 'sku_interno',
      
      'sku_fornitore': 'sku_fornitore',
      'codice_fornitore': 'sku_fornitore',
      'codice_articolo_fornitore': 'sku_fornitore',
      'part_number': 'sku_fornitore',
      
      'quantita_minima': 'quantita_minima',
      'quantità_minima': 'quantita_minima',
      'quantita': 'quantita_minima',
      'qta': 'quantita_minima',
      
      'prezzo': 'prezzo',
      'prezzo_unitario': 'prezzo',
      'prezzo_acquisto': 'prezzo',
      'costo': 'prezzo',
      'costo_unitario': 'prezzo',
      
      'sconto': 'sconto_perc',
      'sconto_perc': 'sconto_perc',
      'sconto_%': 'sconto_perc',
      
      'valido_dal': 'valido_dal',
      'inizio_validita': 'valido_dal',
      'data_inizio': 'valido_dal',
      
      'valido_al': 'valido_al',
      'fine_validita': 'valido_al',
      'data_fine': 'valido_al',
      
      'note': 'note',
      'note_prezzo': 'note',
      'descrizione': 'note'
    };
    return map[clean] || clean;
  }

  document.getElementById('btnImportaPrezziAcquisto').addEventListener('click', async function(){
    if (!parsedRows.length) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Importazione...';

    var normalizedRows = parsedRows.map(function(row) {
      var normObj = {};
      Object.keys(row).forEach(function(key) {
        var normKey = normalizePrezzoHeader(key);
        normObj[normKey] = row[key];
      });
      return normObj;
    });

    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('listino_acquisto_id', LISTINO_ID);
    fd.append('rows', JSON.stringify(normalizedRows));

    try {
      var resp   = await fetch(IMPORT_URL, {method:'POST', body:fd});
      var result = await resp.json();
      var box    = document.getElementById('importResultPrezzi');
      box.style.display = 'block';

      if (!result.ok) {
        box.innerHTML = '<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>' + esc(result.error) + '</div>';
      } else {
        var html = '<div class="alert alert-success py-2 mb-2">'
                 + '<i class="fa-solid fa-circle-check me-1"></i>'
                 + '<strong>'+result.inserted+'</strong> tariffe importate/aggiornate'
                 + (result.skipped>0 ? ', <strong>'+result.skipped+'</strong> saltate' : '')
                 + (result.expired>0 ? ', <strong>'+result.expired+'</strong> chiuse perché non più presenti nel file (assenti dal nuovo listino fornitore)' : '') + '.'
                 + '</div>';
        if (result.errors && result.errors.length) {
          html += '<details style="font-size:.8rem"><summary style="cursor:pointer" class="qk-text-muted">Dettaglio righe saltate ('+result.errors.length+')</summary>'
               + '<ul class="mt-1 mb-0">';
          result.errors.forEach(function(e){ html += '<li>'+esc(e)+'</li>'; });
          html += '</ul></details>';
        }
        box.innerHTML = html;
        if (result.inserted > 0) {
          parsedRows = [];
          document.getElementById('importPreviewWrapPrezzi').style.display = 'none';
          setTimeout(function(){ window.location.reload(); }, 1500);
        }
      }
    } catch(err) {
      document.getElementById('importResultPrezzi').innerHTML =
        '<div class="alert alert-danger py-2">Errore di rete: '+esc(err.message)+'</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Avvia Importazione';
  });

  function esc(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();