/**
 * assets/js/rfq-form.js — Gestione interattiva Richieste di Preventivo (RFQ) acquisto
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  /* ── Costanti ────────────────────────────────────────────────────────── */
  const API_FORNITORI = window.QK_RFQ_CONFIG.apiFornitori;
  const API_PRODOTTI  = window.QK_RFQ_CONFIG.apiProdotti;
  const API_PREZZO    = window.QK_RFQ_CONFIG.apiPrezzo;
  const API_FORNITORE_CREA = window.QK_RFQ_CONFIG.apiFornitoreCrea;
  const CAN_EDIT      = window.QK_RFQ_CONFIG.canEdit;
  const CSRF_TOKEN    = document.querySelector('input[name=_csrf]')?.value ?? '';
  /* ── Stato globale ───────────────────────────────────────────────────── */
  let fornitoreSel = { id: String(window.QK_RFQ_CONFIG.fornitoreId || ''), nome: '' };
  let rigaIdx      = 0;  // contatore righe per data-idx univoci
  let draggedRow   = null;
  let currentUomMode = document.getElementById('rfq-uom-mode')?.value || 'commercial';

  function setRowUomMode(tr, targetMode, currentMode) {
    const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
    const qtyInput = tr.querySelector('.r-qty');
    const priceInput = tr.querySelector('.r-prezzo');
    const uomLabel = tr.querySelector('.r-uom-label');
    const uomIdInput = tr.querySelector('.r-uom-id');

    if (!qtyInput || !uomLabel || !uomIdInput) return;

    if (targetMode === currentMode) {
      if (uomLabel) {
        uomLabel.textContent = (targetMode === 'stock') 
          ? (tr.dataset.uomStock || '—') 
          : (tr.dataset.uomCommercial || '—');
      }
      if (uomIdInput) {
        uomIdInput.value = (targetMode === 'stock')
          ? (tr.dataset.uomStockId || '')
          : (tr.dataset.uomCommercialId || '');
      }
      return;
    }

    const { value: newQty, price: newPrice } = window.qkConvertUom({
      value: qtyInput.value,
      price: priceInput ? priceInput.value : null,
      qFactor,
      targetMode
    });

    if (qtyInput.value && newQty !== null) qtyInput.value = newQty;
    if (priceInput && priceInput.value && newPrice !== null) priceInput.value = newPrice;

    if (uomLabel) {
      uomLabel.textContent = (targetMode === 'stock') 
        ? (tr.dataset.uomStock || '—') 
        : (tr.dataset.uomCommercial || '—');
    }
    if (uomIdInput) {
      uomIdInput.value = (targetMode === 'stock')
        ? (tr.dataset.uomStockId || '')
        : (tr.dataset.uomCommercialId || '');
    }
  }
  /* ── Fetch fornitori ─────────────────────────────────────────────────── */
  async function fetchFornitori(q) {
    const res = await fetch(`${API_FORNITORI}?q=${encodeURIComponent(q)}`);
    const data = await res.json();
    return (data.fornitori ?? []).map(f => ({
      id: f.id,
      label: f.nome,
      codice: f.codice,
      ...f
    }));
  }

  /* ── Fetch prodotti (con prezzo fornitore se selezionato) ─────────────── */
  async function fetchProdotti(q) {
    const url = `${API_PRODOTTI}?q=${encodeURIComponent(q)}&fornitore_id=${fornitoreSel.id || 0}`;
    const res  = await fetch(url);
    const data = await res.json();
    return (data.prodotti ?? []).map(p => ({
      id:            p.id,
      label:         `${p.nome} (${p.sku_interno})`,
      nome:          p.nome,
      sku_interno:   p.sku_interno,
      sku_fornitore: p.sku_fornitore ?? '',
      uom_id:        p.unita_misura_id ?? '',
      uom:           p.uom ?? '',
      prezzo:        p.prezzo_unitario ?? '',
      iva_perc:      p.iva_perc ?? '22',
      uom_comm:      p.uom_comm || p.uom_comm_prod || p.uom || '—',
      uom_comm_id:   p.uom_comm_id || p.uom_comm_prod_id || p.unita_misura_id || '',
      uom_comm_prod: p.uom_comm_prod ?? '',
      uom_comm_prod_id: p.uom_comm_prod_id ?? '',
      formato:       p.formato ?? '',
    }));
  }

  /* ══════════════════════════════════════════════════════════════════════
     CAMPO FORNITORE (solo se editabile)
  ═══════════════════════════════════════════════════════════════════════ */
  if (CAN_EDIT) {
    const fInput    = document.getElementById('rfq-fornitore-label');
    const fHidden   = document.getElementById('rfq-fornitore-id');
    const fDropdown = document.getElementById('rfq-fornitore-dropdown');
    const fClear    = document.getElementById('rfq-fornitore-clear');

    if (fInput) {
      window.qkAutocomplete({
        input:       fInput,
        dropdown:    fDropdown,
        fetchFn:     fetchFornitori,
        onSelect:    (sel) => {
          if (sel) {
            fHidden.value = sel.id;
            fInput.value = sel.label || sel.nome || '';
            fClear?.classList.remove('d-none');
            fornitoreSel = { id: String(sel.id), nome: sel.label || sel.nome || '' };
          } else {
            fHidden.value = '';
            fInput.value = '';
            fClear?.classList.add('d-none');
            fornitoreSel = { id: '', nome: '' };
          }
          loadFornitoreDettagli();
        },
      });

      if (fClear) {
        fClear.addEventListener('click', () => {
          fHidden.value = '';
          fInput.value = '';
          fClear.classList.add('d-none');
          fDropdown.style.display = 'none';
          fornitoreSel = { id: '', nome: '' };
          loadFornitoreDettagli();
        });
      }

      // Se c'è già un valore, mostra il tasto X
      if (fHidden.value) fClear?.classList.remove('d-none');
    }
  }

  /* ══════════════════════════════════════════════════════════════════════
     RIGHE — aggiunta dinamica
  ═══════════════════════════════════════════════════════════════════════ */
  const tbody   = document.getElementById('righe-body');
  const tplRiga = document.getElementById('tpl-riga');

  function addRiga(prefill = {}) {
    // Rimuovi riga "vuoto" se presente
    const empty = document.getElementById('riga-empty');
    if (empty) empty.remove();

    const clone = tplRiga.content.cloneNode(true);
    const tr    = clone.querySelector('tr');
    const idx   = rigaIdx++;
    tr.dataset.idx = idx;

    // Popola dati se forniti (es. da pre-fill PHP → JS)
    if (prefill.prodotto_id) {
      tr.querySelector('.r-prodotto-id').value     = prefill.prodotto_id;
      tr.querySelector('.r-prodotto-label').value  = prefill.label ?? '';
      tr.querySelector('.r-sku').value             = prefill.sku_fornitore ?? '';
      tr.querySelector('.r-qty').value             = prefill.quantita_richiesta ?? '1';
      tr.querySelector('.r-prezzo').value          = prefill.prezzo_indicativo ?? '';
      tr.querySelector('.r-desc').value            = prefill.descrizione ?? '';
      tr.querySelector('.r-uom-id').value          = prefill.uom_id ?? '';
      tr.querySelector('.r-uom-label').textContent = prefill.uom ?? '—';
    }

    tbody.appendChild(clone);
    const newTr = tbody.querySelector(`tr[data-idx="${idx}"]`);
    initRiga(newTr);
    return newTr;
  }

  function initRiga(tr) {
    const prodInput    = tr.querySelector('.r-prodotto-label');
    const prodHidden   = tr.querySelector('.r-prodotto-id');
    const prodDropdown = tr.querySelector('.r-prodotto-dropdown');
    const prodClear    = tr.querySelector('.r-prodotto-clear');
    const uomId        = tr.querySelector('.r-uom-id');
    const uomLabel     = tr.querySelector('.r-uom-label');
    const skuInput     = tr.querySelector('.r-sku');
    const prezzoInput  = tr.querySelector('.r-prezzo');
    const ivaInput     = tr.querySelector('.r-iva');
    const rimuoviBtn   = tr.querySelector('.r-rimuovi');
    const qtyInput     = tr.querySelector('.r-qty');

    window.qkAutocomplete({
      input:       prodInput,
      dropdown:    prodDropdown,
      fetchFn:     fetchProdotti,
      onSelect:    (sel) => {
        if (!sel) {
          prodHidden.value    = '';
          prodInput.value     = '';
          prodClear?.classList.add('d-none');
          uomId.value        = '';
          uomLabel.textContent = '—';
          tr.dataset.uomStock = '—';
          tr.dataset.uomStockId = '';
          tr.dataset.uomCommercial = '—';
          tr.dataset.uomCommercialId = '';
          tr.dataset.quantitaContenuto = '1';
          const formatoSpan = tr.querySelector('.r-formato');
          if (formatoSpan) formatoSpan.textContent = '—';
          if (ivaInput) ivaInput.value = '22';
          recalcRowTotal(tr);
          return;
        }
        prodHidden.value = sel.id;
        prodInput.value = sel.label || sel.nome || '';
        prodClear?.classList.remove('d-none');
        tr.dataset.uomStock = sel.uom ?? '—';
        tr.dataset.uomStockId = sel.uom_id ?? '';
        tr.dataset.uomCommercial = sel.uom_comm || sel.uom_comm_prod || sel.uom || '—';
        tr.dataset.uomCommercialId = sel.uom_comm_id || sel.uom_comm_prod_id || sel.uom_id || '';
        tr.dataset.quantitaContenuto = sel.quantita_contenuto || '1';
        
        const formatoSpan = tr.querySelector('.r-formato');
        if (formatoSpan) formatoSpan.textContent = sel.formato || '—';
        
        if (sel.sku_fornitore) skuInput.value  = sel.sku_fornitore;
        if (sel.prezzo)        prezzoInput.value = sel.prezzo;
        if (sel.iva_perc && ivaInput) ivaInput.value = sel.iva_perc;

        // Converte e allinea le UoM in base al modo corrente
        const mode = document.getElementById('rfq-uom-mode')?.value || 'commercial';
        setRowUomMode(tr, mode, 'commercial');

        recalcRowTotal(tr);

        // Auto-crea una nuova riga vuota se si compila l'ultima
        const allRows = Array.from(tbody.querySelectorAll('tr.rfq-riga'));
        if (allRows[allRows.length - 1] === tr) {
          addRiga();
        }
      },
    });

    if (prodClear) {
      prodClear.addEventListener('click', () => {
        prodHidden.value = '';
        prodInput.value  = '';
        prodClear.classList.add('d-none');
        prodDropdown.style.display = 'none';
        uomId.value        = '';
        uomLabel.textContent = '—';
        tr.dataset.uomStock = '—';
        tr.dataset.uomStockId = '';
        tr.dataset.uomCommercial = '—';
        tr.dataset.uomCommercialId = '';
        tr.dataset.quantitaContenuto = '1';
        const formatoSpan = tr.querySelector('.r-formato');
        if (formatoSpan) formatoSpan.textContent = '—';
        if (ivaInput) ivaInput.value = '22';
        recalcRowTotal(tr);
      });
    }

    // Navigazione tra le righe tramite Invio
    tr.querySelectorAll('input').forEach(input => {
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          const nextTr = tr.nextElementSibling;
          if (nextTr && nextTr.classList.contains('rfq-riga')) {
            nextTr.querySelector('.r-prodotto-label')?.focus();
          } else {
            const newTr = addRiga();
            newTr.querySelector('.r-prodotto-label')?.focus();
          }
        }
      });
    });

    // Sync rejection checkbox to hidden field
    const rifiutatoCheck = tr.querySelector('.r-e-rifiutato-check');
    const rifiutatoHidden = tr.querySelector('.r-e-rifiutato');
    if (rifiutatoCheck && rifiutatoHidden) {
      rifiutatoCheck.addEventListener('change', () => {
        rifiutatoHidden.value = rifiutatoCheck.checked ? '1' : '0';
      });
    }

    // Aggiorna il pulsante X se già valorizzato
    if (CAN_EDIT && prodHidden.value) prodClear?.classList.remove('d-none');

    // Listener per i calcoli in tempo reale
    if (qtyInput)    qtyInput.addEventListener('input', () => recalcRowTotal(tr));
    if (prezzoInput) prezzoInput.addEventListener('input', () => recalcRowTotal(tr));
    if (ivaInput)    ivaInput.addEventListener('input', () => recalcRowTotal(tr));

    if (rimuoviBtn) {
      rimuoviBtn.addEventListener('click', () => {
        tr.remove();
        recalcTotals();
        if (!tbody.querySelector('tr.rfq-riga')) {
           const emptyRow = document.createElement('tr');
           emptyRow.id    = 'riga-empty';
           emptyRow.style.cursor = 'pointer';
           emptyRow.onclick = () => document.getElementById('btn-aggiungi-riga').click();
           emptyRow.innerHTML = `<td colspan="12" class="qk-table-empty">
             <i class="fa-solid fa-inbox"></i> Nessuna riga. Clicca qui o su "Aggiungi riga" per iniziare.
           </td>`;
          tbody.appendChild(emptyRow);
        }
      });
    }

    // Drag and Drop listeners
    if (CAN_EDIT) {
      tr.addEventListener('dragstart', (e) => {
        draggedRow = tr;
        tr.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
      });

      tr.addEventListener('dragend', () => {
        tr.classList.remove('is-dragging');
        draggedRow = null;
        document.querySelectorAll('.rfq-riga').forEach(row => {
          row.classList.remove('drag-over-top', 'drag-over-bottom');
        });
      });

      tr.addEventListener('dragover', (e) => {
        if (!draggedRow || draggedRow === tr) return;
        e.preventDefault();
        const rect = tr.getBoundingClientRect();
        const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
        tr.classList.toggle('drag-over-bottom', next);
        tr.classList.toggle('drag-over-top', !next);
      });

      tr.addEventListener('dragleave', () => {
        tr.classList.remove('drag-over-bottom', 'drag-over-top');
      });

      tr.addEventListener('drop', (e) => {
        if (!draggedRow || draggedRow === tr) return;
        e.preventDefault();
        tr.classList.remove('drag-over-bottom', 'drag-over-top');
        
        const rect = tr.getBoundingClientRect();
        const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
        if (next) {
          tr.parentNode.insertBefore(draggedRow, tr.nextSibling);
        } else {
          tr.parentNode.insertBefore(draggedRow, tr);
        }
        recalcTotals();
      });
    }

    recalcRowTotal(tr);
  }

  // Ricalcola il totale di una riga
  function recalcRowTotal(tr) {
    const qty = parseFloat(tr.querySelector('.r-qty')?.value) || 0;
    const prezzo = parseFloat(tr.querySelector('.r-prezzo')?.value) || 0;
    const total = qty * prezzo;
    const totalSpan = tr.querySelector('.r-totale-riga');
    if (totalSpan) {
      totalSpan.textContent = total.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    recalcTotals();
  }

  // Ricalcola i totali generali
  function recalcTotals() {
    let imponibile = 0;
    let iva = 0;

    document.querySelectorAll('tr.rfq-riga').forEach(tr => {
      const qty = parseFloat(tr.querySelector('.r-qty')?.value) || 0;
      const prezzo = parseFloat(tr.querySelector('.r-prezzo')?.value) || 0;
      const ivaPerc = parseFloat(tr.querySelector('.r-iva')?.value) || 0;

      const rowImponibile = qty * prezzo;
      const rowIva = rowImponibile * (ivaPerc / 100);

      imponibile += rowImponibile;
      iva += rowIva;
    });

    const valuta = document.getElementById('rfq-valuta')?.value || 'EUR';
    const symbol = { EUR: '€', USD: '$', GBP: '£' }[valuta] || '€';

    document.querySelectorAll('.currency-symbol').forEach(el => {
      el.textContent = symbol;
    });

    const spanImponibile = document.getElementById('totale-imponibile');
    const spanIva = document.getElementById('totale-iva');
    const spanIvato = document.getElementById('totale-ivato');

    if (spanImponibile) spanImponibile.textContent = imponibile.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (spanIva) spanIva.textContent = iva.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (spanIvato) spanIvato.textContent = (imponibile + iva).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // Gestione cambio UoM
  const uomModeSelect = document.getElementById('rfq-uom-mode');
  if (uomModeSelect) {
    uomModeSelect.addEventListener('change', () => {
      const targetMode = uomModeSelect.value;
      document.querySelectorAll('tr.rfq-riga').forEach(tr => {
        setRowUomMode(tr, targetMode, currentUomMode);
        recalcRowTotal(tr);
      });
      currentUomMode = targetMode;
    });
    // Allinea subito i testi e gli ID delle UoM sul caricamento pagina
    uomModeSelect.dispatchEvent(new Event('change'));
  }

  // Gestione valuta
  const valutaSelect = document.getElementById('rfq-valuta');
  if (valutaSelect) {
    valutaSelect.addEventListener('change', recalcTotals);
  }

  // Bottone "Aggiungi riga"
  const btnAdd = document.getElementById('btn-aggiungi-riga');
  if (btnAdd) {
    btnAdd.addEventListener('click', () => {
      const tr = addRiga();
      tr.querySelector('.r-prodotto-label')?.focus();
    });
  }

  // Inizializza righe PHP pre-esistenti (non usano il template ma lo stesso init)
  document.querySelectorAll('tr.rfq-riga').forEach(tr => {
    rigaIdx = Math.max(rigaIdx, (parseInt(tr.dataset.idx) || 0) + 1);
    initRiga(tr);
  });

  // Ricalcola subito al caricamento
  recalcTotals();


  /* ══════════════════════════════════════════════════════════════════════
     MODALE: Stampa & Segna come Inviata
  ═══════════════════════════════════════════════════════════════════════ */
  const btnInvia   = document.getElementById('btn-invia-rfq');
  const modalInvia = document.getElementById('modal-invia-rfq');
  if (btnInvia && modalInvia) {
    btnInvia.addEventListener('click', () => {
      modalInvia.style.display = 'flex';
    });
  }

  const btnCopiaEmail = document.getElementById('btn-copia-email');
  if (btnCopiaEmail) {
    btnCopiaEmail.addEventListener('click', () => {
      const rfqNumero = window.QK_RFQ_CONFIG.rfqNumero || '';
      const fornitoreNome = window.QK_RFQ_CONFIG.fornitoreNome || '';
      const emailText = `Spett.le ${fornitoreNome || 'Fornitore'},\n\nin allegato vi inviamo la nostra richiesta di offerta ${rfqNumero} per la quotazione degli articoli indicati nel documento.\n\nRestiamo in attesa di un vostro riscontro in merito a prezzi e tempi di consegna.\n\nCordiali saluti,\nUfficio Acquisti`;
      
      navigator.clipboard.writeText(emailText).then(() => {
        const originalText = btnCopiaEmail.innerHTML;
        btnCopiaEmail.innerHTML = '<i class="fa-solid fa-check"></i> Copiato!';
        btnCopiaEmail.style.backgroundColor = 'var(--qk-success-bg, #d1e7dd)';
        btnCopiaEmail.style.color = 'var(--qk-success-color, #0f5132)';
        setTimeout(() => {
          btnCopiaEmail.innerHTML = originalText;
          btnCopiaEmail.style.backgroundColor = '';
          btnCopiaEmail.style.color = '';
        }, 2000);
      }).catch(err => {
        alert("Errore nella copia del testo: " + err);
      });
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     MODALE: Creazione rapida fornitore
  ═══════════════════════════════════════════════════════════════════════ */
  const btnSalvaFornitore = document.getElementById('btn-qf-salva');
  if (btnSalvaFornitore) {
    btnSalvaFornitore.addEventListener('click', async () => {
      const ragione = document.getElementById('qf-ragione-sociale')?.value.trim();
      const piva    = document.getElementById('qf-partita-iva')?.value.trim();
      const errDiv  = document.getElementById('qf-error');
      const errMsg  = document.getElementById('qf-error-msg');

      if (!ragione) {
        errMsg.textContent = 'La ragione sociale è obbligatoria.';
        errDiv.style.display = 'flex';
        return;
      }
      errDiv.style.display = 'none';

      try {
        const res  = await fetch(API_FORNITORE_CREA, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ _csrf: CSRF_TOKEN, ragione_sociale: ragione, partita_iva: piva }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error ?? 'Errore sconosciuto');

        // Popola il campo fornitore
        const fHidden = document.getElementById('rfq-fornitore-id');
        const fInput  = document.getElementById('rfq-fornitore-label');
        const fClear  = document.getElementById('rfq-fornitore-clear');
        if (fHidden) fHidden.value  = String(data.id);
        if (fInput)  fInput.value   = data.nome;
        if (fClear)  fClear.classList.remove('d-none');
        fornitoreSel = { id: String(data.id), nome: data.nome };

        // Chiudi modal
        document.getElementById('modal-quick-fornitore').style.display = 'none';
        document.getElementById('qf-ragione-sociale').value = '';
        document.getElementById('qf-partita-iva').value     = '';
      } catch (err) {
        errMsg.textContent = err.message;
        errDiv.style.display = 'flex';
      }
    });
  }

  /* ── Caricamento dinamico indirizzi di consegna ─────────────────────── */
  const azSelect = document.getElementById('rfq-azienda-interna-id');
  const indSelect = document.getElementById('rfq-indirizzo-consegna-id');
  const API_AZIENDA_INDIRIZZI = window.QK_RFQ_CONFIG.apiAziendaIndirizzi;

  let loadedAddresses = [];

  function getOriginalAddress() {
    const val = indSelect.value;
    if (!val) {
      return loadedAddresses[0] || {};
    }
    return loadedAddresses.find(i => String(i.id) === String(val)) || {};
  }

  function clearOverrideStylesSped() {
    [
      document.getElementById('consegna_via'),
      document.getElementById('consegna_civico'),
      document.getElementById('consegna_cap'),
      document.getElementById('consegna_citta'),
      document.getElementById('consegna_provincia'),
      document.getElementById('consegna_nazione'),
      document.getElementById('consegna_presso'),
      document.getElementById('consegna_referente'),
      document.getElementById('consegna_telefono'),
      document.getElementById('consegna_note')
    ].forEach(inp => {
      if (inp) inp.classList.remove('is-overridden');
    });
  }

  function syncAddressUI() {
    const val = indSelect.value;
    const ind = getOriginalAddress();
    const inpVia = document.getElementById('consegna_via');
    if (!inpVia) return;

    const isNewForm = !inpVia.value;
    if (isNewForm && ind.via && CAN_EDIT) {
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

    checkField(document.getElementById('consegna_via'), ind.via);
    checkField(document.getElementById('consegna_civico'), ind.civico);
    checkField(document.getElementById('consegna_cap'), ind.cap);
    checkField(document.getElementById('consegna_citta'), ind.citta);
    checkField(document.getElementById('consegna_provincia'), ind.provincia);
    checkField(document.getElementById('consegna_nazione'), ind.nazione || 'IT');
    checkField(document.getElementById('consegna_presso'), ind.presso);
    checkField(document.getElementById('consegna_referente'), ind.referente_consegna);
    checkField(document.getElementById('consegna_telefono'), ind.telefono_consegna);
    checkField(document.getElementById('consegna_note'), ind.note_consegna);

    const badge = document.getElementById('sped-state-badge');
    const resetBtn = document.getElementById('btn-cancel-edit-sped');
    if (hasOverride) {
      badge.textContent = 'Modificato localmente (override)';
      badge.className = 'badge bg-warning text-dark ms-2';
      if (resetBtn) resetBtn.style.display = 'inline-block';
    } else {
      badge.textContent = '✓ Sincronizzato con anagrafica';
      badge.className = 'badge bg-success text-white ms-2';
      if (resetBtn) resetBtn.style.display = 'none';
    }
  }

  function copyAddressToFields(ind) {
    const elVia = document.getElementById('consegna_via');
    const elCiv = document.getElementById('consegna_civico');
    const elCap = document.getElementById('consegna_cap');
    const elCit = document.getElementById('consegna_citta');
    const elPrv = document.getElementById('consegna_provincia');
    const elNaz = document.getElementById('consegna_nazione');
    const elPre = document.getElementById('consegna_presso');
    const elRef = document.getElementById('consegna_referente');
    const elTel = document.getElementById('consegna_telefono');
    const elNot = document.getElementById('consegna_note');

    if (elVia) elVia.value = ind.via || '';
    if (elCiv) elCiv.value = ind.civico || '';
    if (elCap) elCap.value = ind.cap || '';
    if (elCit) elCit.value = ind.citta || '';
    if (elPrv) elPrv.value = ind.provincia || '';
    if (elNaz) elNaz.value = ind.nazione || 'IT';
    if (elPre) elPre.value = ind.presso || '';
    if (elRef) elRef.value = ind.referente_consegna || '';
    if (elTel) elTel.value = ind.telefono_consegna || '';
    if (elNot) elNot.value = ind.note_consegna || '';
  }

  function clearAddressFields() {
    [
      'consegna_via', 'consegna_civico', 'consegna_cap', 'consegna_citta',
      'consegna_provincia', 'consegna_presso', 'consegna_referente',
      'consegna_telefono', 'consegna_note'
    ].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    const elNaz = document.getElementById('consegna_nazione');
    if (elNaz) elNaz.value = 'IT';
  }

  // Bind input changes to sync override indicator
  [
    'consegna_via', 'consegna_civico', 'consegna_cap', 'consegna_citta',
    'consegna_provincia', 'consegna_nazione', 'consegna_presso',
    'consegna_referente', 'consegna_telefono', 'consegna_note'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', syncAddressUI);
  });

  const btnCancelEditSped = document.getElementById('btn-cancel-edit-sped');
  if (btnCancelEditSped) {
    btnCancelEditSped.addEventListener('click', () => {
      const ind = getOriginalAddress();
      if (ind) copyAddressToFields(ind);
      syncAddressUI();
    });
  }

  async function loadAziendaAddresses() {
    if (!azSelect || !indSelect) return;
    const aziendaId = azSelect.value;
    if (!aziendaId) {
      indSelect.innerHTML = '<option value="">Seleziona azienda prima...</option>';
      return;
    }

    indSelect.innerHTML = '<option value="">Caricamento...</option>';
    try {
      const res = await fetch(`${API_AZIENDA_INDIRIZZI}?azienda_id=${aziendaId}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Errore recupero indirizzi');

      loadedAddresses = data.indirizzi || [];
      indSelect.innerHTML = '';
      if (loadedAddresses.length === 0) {
        indSelect.innerHTML = '<option value="">Nessun indirizzo spedizione/magazzino trovato</option>';
        return;
      }

      const selectedId = indSelect.dataset.selectedId;
      let matched = false;

      // Aggiungi opzione vuota di default
      const defaultOpt = document.createElement('option');
      defaultOpt.value = '';
      defaultOpt.textContent = '— Nessun indirizzo selezionato —';
      if (!selectedId) {
        defaultOpt.selected = true;
        matched = true;
      }
      indSelect.appendChild(defaultOpt);

      loadedAddresses.forEach(ind => {
        const option = document.createElement('option');
        option.value = ind.id;
        
        // Costruisci una label leggibile compatta: Nome sede · Città
        const labelParts = [];
        if (ind.nome_sede) labelParts.push(`[${ind.nome_sede}]`);
        if (ind.citta) labelParts.push(ind.citta);
        option.textContent = labelParts.join(' · ') || (ind.via + ' ' + (ind.civico || ''));
        
        if (selectedId && String(ind.id) === String(selectedId)) {
          option.selected = true;
          matched = true;
        }
        
        indSelect.appendChild(option);
      });

      if (!matched && loadedAddresses.length > 0 && CAN_EDIT) {
        // Se non trova corrispondenza ma siamo in modifica, seleziona il primo indirizzo reale
        indSelect.options[1].selected = true; 
      }
      syncAddressUI();
    } catch (err) {
      indSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
    }
  }

  if (azSelect) {
    azSelect.addEventListener('change', () => {
      if (indSelect) indSelect.removeAttribute('data-selected-id');
      loadAziendaAddresses();
    });
  }

  if (indSelect) {
    indSelect.addEventListener('change', () => {
      clearAddressFields();
      const val = indSelect.value;
      if (val) {
        const ind = loadedAddresses.find(i => String(i.id) === String(val));
        if (ind) copyAddressToFields(ind);
      }
      syncAddressUI();
    });
  }

  // Carica gli indirizzi subito all'avvio
  loadAziendaAddresses();

  /* ── Caricamento dinamico dettagli fornitore (contatti, listini) ────── */
  const contSelect = document.getElementById('rfq-contatto-id');
  const contNomeInput = document.getElementById('rfq-contatto-nome');
  const contTelInput = document.getElementById('rfq-contatto-telefono');
  const contEmailInput = document.getElementById('rfq-contatto-email');
  const fornTelHidden = document.getElementById('rfq-fornitore-telefono');
  const fornEmailHidden = document.getElementById('rfq-fornitore-email');
  const listiniSelect = document.getElementById('rfq-listino-acquisto-id');
  const API_FORNITORE_DETTAGLI = window.QK_RFQ_CONFIG.apiFornitoreDettagli;

  let loadedContacts = [];

  function getOriginalContact() {
    const val = contSelect.value;
    if (!val) return {};
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
    checkField(contTelInput, orig.telefono || orig.cellulare || fornTelHidden?.value || '');
    checkField(contEmailInput, orig.email || fornEmailHidden?.value || '');

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
    if (inp) inp.addEventListener('input', syncContactUI);
  });

  const btnCancelEditCont = document.getElementById('btn-cancel-edit-cont');
  if (btnCancelEditCont) {
    btnCancelEditCont.addEventListener('click', () => {
      const contact = getOriginalContact();
      if (contact && contact.nome_completo) {
        if (contNomeInput) contNomeInput.value = contact.nome_completo || '';
        if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || fornTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = contact.email || fornEmailHidden?.value || '';
      } else {
        if (contNomeInput) contNomeInput.value = '';
        if (contTelInput) contTelInput.value = fornTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = fornEmailHidden?.value || '';
      }
      syncContactUI();
    });
  }

  async function loadFornitoreDettagli() {
    if (!contSelect || !listiniSelect) return;
    const fornitoreId = document.getElementById('rfq-fornitore-id')?.value;
    if (!fornitoreId) {
      contSelect.innerHTML = '<option value="">Seleziona fornitore prima...</option>';
      listiniSelect.innerHTML = '<option value="">Nessun listino specifico...</option>';
      return;
    }

    contSelect.innerHTML = '<option value="">Caricamento...</option>';
    listiniSelect.innerHTML = '<option value="">Caricamento...</option>';
    try {
      const res = await fetch(`${API_FORNITORE_DETTAGLI}?fornitore_id=${fornitoreId}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Errore recupero dettagli');

      // Memorizza i contatti scaricati per l'evento change
      loadedContacts = data.contatti || [];

      // Salva recapiti aziendali generali come fallback
      if (fornTelHidden) fornTelHidden.value = data.fornitore?.telefono || '';
      if (fornEmailHidden) fornEmailHidden.value = data.fornitore?.email || data.fornitore?.cellulare || '';

      // Se stiamo creando e il fornitore ha condizioni di pagamento predefinite, proponile
      const condPag = document.getElementById('rfq-condizioni-pagamento');
      if (condPag && !condPag.value && data.fornitore?.condizioni_pagamento) {
        condPag.value = data.fornitore.condizioni_pagamento;
      }

      // Popola dropdown Contatti / Referente
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

      // Popola dropdown Listini
      listiniSelect.innerHTML = '<option value="">— Nessun listino specifico —</option>';
      const selectedListinoId = listiniSelect.dataset.selectedId;
      data.listini.forEach(l => {
        const option = document.createElement('option');
        option.value = l.id;
        option.textContent = l.nome_listino;
        if (selectedListinoId && String(l.id) === String(selectedListinoId)) {
          option.selected = true;
        }
        listiniSelect.appendChild(option);
      });

      syncContactUI();
    } catch (err) {
      contSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
      listiniSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
    }
  }

  // Listener cambio contatto referente
  if (contSelect) {
    contSelect.addEventListener('change', () => {
      const selectedId = contSelect.value;
      if (!selectedId) {
        // Fallback su recapiti generali dell'azienda
        if (contTelInput) contTelInput.value = fornTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = fornEmailHidden?.value || '';
        if (contNomeInput) contNomeInput.value = '';
      } else {
        const contact = loadedContacts.find(c => String(c.id) === String(selectedId));
        if (contact) {
          if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || fornTelHidden?.value || '';
          if (contEmailInput) contEmailInput.value = contact.email || fornEmailHidden?.value || '';
          if (contNomeInput) contNomeInput.value = contact.nome_completo || '';
        }
      }
      syncContactUI();
    });
  }

  // Auto-suggerimento Resa Porto basato sull'Incoterm
  const incotermSelect = document.getElementById('rfq-incoterm-codice');
  const resaPortoSelect = document.getElementById('rfq-resa-porto');
  if (incotermSelect && resaPortoSelect) {
    incotermSelect.addEventListener('change', () => {
      const val = incotermSelect.value;
      if (['EXW', 'FCA', 'FOB', 'FAS'].includes(val)) {
        resaPortoSelect.value = 'porto_assegnato';
      } else if (['CPT', 'CIP', 'DAP', 'DDP', 'DPU', 'CFR', 'CIF'].includes(val)) {
        resaPortoSelect.value = 'porto_franco';
      }
    });
  }

  // Implement Read-only Lock for status not 'bozza'
  const rfqStato = window.QK_RFQ_CONFIG.rfqStato || 'bozza';
  if (rfqStato !== 'bozza') {
    [
      azSelect, indSelect, contSelect,
      document.getElementById('consegna_via'),
      document.getElementById('consegna_civico'),
      document.getElementById('consegna_cap'),
      document.getElementById('consegna_citta'),
      document.getElementById('consegna_provincia'),
      document.getElementById('consegna_nazione'),
      document.getElementById('consegna_presso'),
      document.getElementById('consegna_referente'),
      document.getElementById('consegna_telefono'),
      document.getElementById('consegna_note'),
      document.getElementById('rfq-contatto-nome'),
      document.getElementById('rfq-contatto-telefono'),
      document.getElementById('rfq-contatto-email')
    ].forEach(el => {
      if (el) {
        el.disabled = true;
        el.readOnly = true;
      }
    });
    if (btnCancelEditSped) btnCancelEditSped.style.display = 'none';
    if (btnCancelEditCont) btnCancelEditCont.style.display = 'none';
  }

  // Carica i dettagli del fornitore subito all'avvio (se già selezionato in modifica)
  loadFornitoreDettagli();



  /* ── Mostra/Nascondi Colonne Odoo-Style ────────────────────────────── */
  const btnColSettings = document.getElementById('btn-col-settings');
  const colSettingsDropdown = document.getElementById('col-settings-dropdown');
  const chkFormato = document.getElementById('chk-show-formato');
  const chkPrezzo = document.getElementById('chk-show-prezzo');
  const chkTotale = document.getElementById('chk-show-totale');
  const chkDataConsegna = document.getElementById('chk-show-data-consegna');
  const chkRifiutato = document.getElementById('chk-show-rifiutato');

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
    const showFormato = chkFormato ? chkFormato.checked : true;
    const showPrezzo = chkPrezzo ? chkPrezzo.checked : true;
    const showTotale = chkTotale ? chkTotale.checked : true;
    const showDataConsegna = chkDataConsegna ? chkDataConsegna.checked : false;
    const showRifiutato = chkRifiutato ? chkRifiutato.checked : false;

    document.querySelectorAll('.col-formato').forEach(el => {
      el.style.display = showFormato ? '' : 'none';
    });

    document.querySelectorAll('.col-prezzo').forEach(el => {
      el.style.display = showPrezzo ? '' : 'none';
    });

    document.querySelectorAll('.col-totale').forEach(el => {
      el.style.display = showTotale ? '' : 'none';
    });

    document.querySelectorAll('.col-data-consegna').forEach(el => {
      if (showDataConsegna) {
        el.classList.remove('col-hidden');
      } else {
        el.classList.add('col-hidden');
      }
    });

    document.querySelectorAll('.col-rifiutato').forEach(el => {
      if (showRifiutato) {
        el.classList.remove('col-hidden');
      } else {
        el.classList.add('col-hidden');
      }
    });
    
    localStorage.setItem('rfq_show_formato', showFormato ? '1' : '0');
    localStorage.setItem('rfq_show_prezzo', showPrezzo ? '1' : '0');
    localStorage.setItem('rfq_show_totale', showTotale ? '1' : '0');
    localStorage.setItem('rfq_show_data_consegna', showDataConsegna ? '1' : '0');
    localStorage.setItem('rfq_show_rifiutato', showRifiutato ? '1' : '0');
  }

  if (chkFormato) chkFormato.addEventListener('change', updateColumnVisibility);
  if (chkPrezzo) chkPrezzo.addEventListener('change', updateColumnVisibility);
  if (chkTotale) chkTotale.addEventListener('change', updateColumnVisibility);
  if (chkDataConsegna) chkDataConsegna.addEventListener('change', updateColumnVisibility);
  if (chkRifiutato) chkRifiutato.addEventListener('change', updateColumnVisibility);

  // Inizializzazione della preferenza da localStorage o dai dati esistenti
  if (chkFormato) {
    const pref = localStorage.getItem('rfq_show_formato');
    chkFormato.checked = (pref === null || pref === '1');
  }
  if (chkPrezzo) {
    const pref = localStorage.getItem('rfq_show_prezzo');
    chkPrezzo.checked = (pref === null || pref === '1');
  }
  if (chkTotale) {
    const pref = localStorage.getItem('rfq_show_totale');
    chkTotale.checked = (pref === null || pref === '1');
  }
  if (chkDataConsegna) {
    const hasDataConsegnaValue = Array.from(document.querySelectorAll('.r-data-consegna')).some(el => el.value !== '');
    const pref = localStorage.getItem('rfq_show_data_consegna');
    chkDataConsegna.checked = (pref === '1') || (pref === null && hasDataConsegnaValue);
  }
  if (chkRifiutato) {
    const hasRifiutatoValue = Array.from(document.querySelectorAll('.r-e-rifiutato')).some(el => el.value === '1');
    const pref = localStorage.getItem('rfq_show_rifiutato');
    chkRifiutato.checked = (pref === '1') || (pref === null && hasRifiutatoValue);
  }
  updateColumnVisibility();

  /* ── Switcher Tab Odoo-Style ───────────────────────────────────────── */
  window.switchRfqTab = function(tabId) {
    document.querySelectorAll('.qk-nav-tabs .nav-link').forEach(btn => {
      btn.classList.remove('active');
    });
    const activeBtn = document.getElementById('tab-' + tabId + '-btn');
    if (activeBtn) activeBtn.classList.add('active');

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

});
