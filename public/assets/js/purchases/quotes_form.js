(function () {
  'use strict';

  const API_FORNITORI = <?= json_encode(url('purchases/rfq/api/fornitori')) ?>;
  const API_PRODOTTI  = <?= json_encode(url('purchases/rfq/api/prodotti')) ?>;
  const CAN_EDIT      = <?= $canEdit ? 'true' : 'false' ?>;
  const IS_SPONTANEOUS = <?= empty($values['rfq_id']) ? 'true' : 'false' ?>;

  let fornitoreSel = { id: '<?= e($values['fornitore_id']) ?>', nome: '' };
  let rigaIdx      = 0;

  function autocomplete({ inputEl, dropdownEl, clearEl, hiddenEl, fetchFn, onSelect }) {
    let timer = null;

    inputEl.addEventListener('input', () => {
      clearTimeout(timer);
      const q = inputEl.value.trim();
      if (q.length < 1) { dropdownEl.innerHTML = ''; dropdownEl.style.display = 'none'; return; }
      timer = setTimeout(async () => {
        const items = await fetchFn(q);
        if (!items.length) {
          dropdownEl.innerHTML = '<div class="qk-m2o-item qk-text-muted" style="font-size:.83rem">Nessun risultato</div>';
        } else {
          dropdownEl.innerHTML = items.map(it =>
            `<div class="qk-m2o-item" data-id="${it.id}" data-label="${it.label?.replace(/"/g,'&quot;') ?? ''}" data-extra='${JSON.stringify(it)}'>${it.label ?? ''}</div>`
          ).join('');
        }
        dropdownEl.style.display = 'block';

        dropdownEl.querySelectorAll('.qk-m2o-item[data-id]').forEach(el => {
          el.addEventListener('mousedown', e => {
            e.preventDefault();
            const data = JSON.parse(el.dataset.extra);
            hiddenEl.value  = el.dataset.id;
            inputEl.value   = el.dataset.label;
            dropdownEl.style.display = 'none';
            if (clearEl) { clearEl.classList.remove('d-none'); }
            onSelect && onSelect(data);
          });
        });
      }, 220);
    });

    inputEl.addEventListener('blur', () => {
      setTimeout(() => { dropdownEl.style.display = 'none'; }, 200);
    });

    if (clearEl) {
      clearEl.addEventListener('click', () => {
        hiddenEl.value = '';
        inputEl.value  = '';
        clearEl.classList.add('d-none');
        dropdownEl.style.display = 'none';
        onSelect && onSelect(null);
      });
    }
  }

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
    }));
  }

  if (CAN_EDIT && IS_SPONTANEOUS) {
    // Autocomplete fornitore
    const fInput    = document.getElementById('off-fornitore-label');
    const fHidden   = document.getElementById('off-fornitore-id');
    const fDropdown = document.getElementById('off-fornitore-dropdown');
    const fClear    = document.getElementById('off-fornitore-clear');

    if (fInput) {
      autocomplete({
        inputEl:    fInput,
        dropdownEl: fDropdown,
        clearEl:    fClear,
        hiddenEl:   fHidden,
        fetchFn:    fetchFornitori,
        onSelect:   (sel) => {
          fornitoreSel = sel ? { id: String(sel.id), nome: sel.label } : { id: '', nome: '' };
          loadFornitoreDettagli();
        },
      });
      if (fHidden.value) fClear?.classList.remove('d-none');
    }

    // Righe dinamiche
    const tbody   = document.querySelector('#form-offerta table.qk-table tbody');
    const tplRiga = document.getElementById('tpl-riga');
    const btnAdd  = document.getElementById('btn-add-riga');

    function addRiga() {
      const clone = tplRiga.content.cloneNode(true);
      const tr    = clone.querySelector('tr');
      const idx   = rigaIdx++;
      tr.dataset.idx = idx;

      tbody.appendChild(clone);
      const newTr = tbody.querySelector(`tr[data-idx="${idx}"]`);
      initRiga(newTr);
      return newTr;
    }

    function initRiga(tr) {
      const pInput    = tr.querySelector('.r-prodotto-label');
      const pHidden   = tr.querySelector('.r-prodotto-id');
      const pDropdown = tr.querySelector('.r-prodotto-dropdown');
      const pClear    = tr.querySelector('.r-prodotto-clear');

      autocomplete({
        inputEl:    pInput,
        dropdownEl: pDropdown,
        clearEl:    pClear,
        hiddenEl:   pHidden,
        fetchFn:    fetchProdotti,
        onSelect:   (p) => {
          if (p) {
            tr.querySelector('.r-sku').value = p.sku_fornitore ?? '';
            tr.querySelector('.r-uom-id').value = p.uom_id ?? '';
            tr.querySelector('.r-uom-label').textContent = p.uom ?? '—';
            tr.querySelector('.r-prezzo').value = p.prezzo ?? '';
          } else {
            tr.querySelector('.r-sku').value = '';
            tr.querySelector('.r-uom-id').value = '';
            tr.querySelector('.r-uom-label').textContent = '—';
            tr.querySelector('.r-prezzo').value = '';
          }
        }
      });

      tr.querySelector('.r-rimuovi').addEventListener('click', () => {
        tr.remove();
      });

      const defaultDateInput = document.getElementById('off-data-consegna-default');
      if (defaultDateInput && defaultDateInput.value) {
        const rowDate = tr.querySelector('.r-consegna');
        if (rowDate && !rowDate.value) {
          rowDate.value = defaultDateInput.value;
        }
      }
    }

    if (btnAdd) {
      btnAdd.addEventListener('click', addRiga);
    }

    // Inizializza le righe esistenti
    tbody.querySelectorAll('tr.offerta-riga').forEach(tr => {
      initRiga(tr);
    });

    // Se non ci sono righe, aggiungine una di default
    if (tbody.querySelectorAll('tr.offerta-riga').length === 0) {
      addRiga();
    }
  }

  // Funzione per aggiornare il badge dello scostamento quantità
  function updateQtyDeviation(input) {
    const requested = parseFloat(input.dataset.requested);
    const badge = input.nextElementSibling;
    if (isNaN(requested) || !badge || !badge.classList.contains('qty-delta-badge')) {
      return;
    }
    const current = parseFloat(input.value) || 0;
    const diff = current - requested;
    if (diff === 0) {
      input.style.borderColor = '';
      badge.style.display = 'none';
      badge.innerHTML = '';
    } else {
      input.style.borderColor = 'var(--qk-warning)'; // warning orange
      badge.style.display = 'block';
      const sign = diff > 0 ? '+' : '';
      const pct = ((diff / requested) * 100).toFixed(0);
      badge.innerHTML = `<span style="color:var(--qk-warning);font-weight:600"><i class="fa-solid fa-triangle-exclamation"></i> Scostamento: ${sign}${diff} (${sign}${pct}%)</span>`;
    }
  }

  // Monitora tutti gli input quantità per lo scostamento
  document.querySelectorAll('.r-qty').forEach(input => {
    input.addEventListener('input', () => updateQtyDeviation(input));
    updateQtyDeviation(input);
  });

  // Sostituto handlers per RFQ-linked
  window.toggleSostitutoRow = function(checkbox, idx) {
    const origContainer = document.getElementById('prod-originale-' + idx);
    const sostContainer = document.getElementById('prod-sostituto-' + idx);
    const labelSost = sostContainer.querySelector('.r-prodotto-label');
    const valueSost = sostContainer.querySelector('.r-prodotto-id');
    const hiddenFlag = checkbox.parentNode.parentNode.querySelector('.flag-sostituto-value');
    
    if (checkbox.checked) {
      origContainer.style.display = 'none';
      sostContainer.style.display = 'block';
      labelSost.setAttribute('required', 'required');
      hiddenFlag.value = '1';
    } else {
      origContainer.style.display = 'block';
      sostContainer.style.display = 'none';
      labelSost.removeAttribute('required');
      hiddenFlag.value = '0';
      // Ripristina l'articolo originale
      valueSost.value = checkbox.dataset.origId;
      labelSost.value = checkbox.dataset.origLabel;
      const clearBtn = sostContainer.querySelector('.r-prodotto-clear');
      if (CAN_EDIT && clearBtn) clearBtn.classList.remove('d-none');
    }
  };

  // Inizializza gli autocomplete per i sostituti se modificabile
  if (CAN_EDIT && !IS_SPONTANEOUS) {
    // Corretto 15/07/2026 (Revisione Ciclo Acquisti §3bis, punto B.2): il campo
    // reale renderizzato da csrf_field() si chiama "csrf_token", non "_csrf" —
    // il selettore precedente non trovava mai nulla, CSRF_TOKEN era sempre
    // una stringa vuota. Allineato alla convenzione già usata nel resto dell'app.
    const CSRF_TOKEN = document.querySelector('input[name=csrf_token]')?.value ?? '';
    document.querySelectorAll('.prod-sostituto-container').forEach(container => {
      const pInput    = container.querySelector('.r-prodotto-label');
      const pHidden   = container.querySelector('.r-prodotto-id');
      const pDropdown = container.querySelector('.r-prodotto-dropdown');
      const pClear    = container.querySelector('.r-prodotto-clear');
      const tr        = container.closest('tr');

      autocomplete({
        inputEl:    pInput,
        dropdownEl: pDropdown,
        clearEl:    pClear,
        hiddenEl:   pHidden,
        fetchFn:    fetchProdotti,
        onSelect:   (p) => {
          if (p) {
            tr.querySelector('.r-sku').value = p.sku_fornitore ?? '';
            tr.querySelector('.r-uom-id').value = p.uom_id ?? '';
            tr.querySelector('.r-uom-label').textContent = p.uom ?? '—';
            tr.querySelector('.r-prezzo').value = p.prezzo ?? '';
          }
        }
      });

      // Tasto crea prodotto al volo per sostituto
      const btnCrea = container.querySelector('.btn-quick-crea-prod');
      if (btnCrea) {
        btnCrea.addEventListener('click', async () => {
          const nome = prompt("Inserisci il nome del nuovo prodotto:");
          if (!nome || nome.trim() === '') return;

          try {
            const formData = new FormData();
            formData.append('nome', nome);
            formData.append('csrf_token', CSRF_TOKEN);

            const res = await fetch(<?= json_encode(url('purchases/rfq/api/crea-prodotto')) ?>, {
              method: 'POST',
              body: formData
            });
            const data = await res.json();
            if (data.ok) {
              pHidden.value = data.id;
              pInput.value = `${data.nome} (${data.sku_interno})`;
              pClear.classList.remove('d-none');
              tr.querySelector('.r-sku').value = data.sku_interno;
            } else {
              alert("Errore: " + data.error);
            }
          } catch (err) {
            alert("Errore di connessione durante la creazione del prodotto.");
          }
        });
      }
    });
  }

  // Sdoppiamento riga
  window.splitRiga = function(button, idx) {
    const tr = button.closest('tr');
    const clone = tr.cloneNode(true);
    
    clone.style.background = 'rgba(0,0,0,0.02)';
    clone.classList.add('riga-split-figlia');
    
    const tempParentId = tr.getAttribute('data-temp-id') || ('row_' + idx);
    clone.querySelector('.r-padre-id').value = tempParentId;
    
    const childTempId = 'split_' + Math.random().toString(36).substr(2, 9);
    clone.setAttribute('data-temp-id', childTempId);
    
    clone.querySelector('.r-temp-id').value = childTempId;
    clone.querySelector('.r-qty').value = '0';
    clone.querySelector('.r-qty').dataset.requested = '';
    const warningBadge = clone.querySelector('.qty-delta-badge');
    if (warningBadge) {
      warningBadge.style.display = 'none';
      warningBadge.innerHTML = '';
    }
    
    const actionsTd = clone.querySelector('td:last-child');
    actionsTd.innerHTML = `
      <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" title="Rimuovi split" onclick="rimuoviSplit(this)">
        <i class="fa-solid fa-xmark"></i>
      </button>
    `;
    
    tr.after(clone);
    
    const newQty = clone.querySelector('.r-qty');
    newQty.addEventListener('input', () => updateQtyDeviation(newQty));
  };

  window.rimuoviSplit = function(button) {
    button.closest('tr').remove();
  };

  /* ── Caricamento dinamico dettagli fornitore (contatti, listini) ────── */
  const contSelect = document.getElementById('off-contatto-id');
  const contNomeInput = document.getElementById('off-contatto-nome');
  const contTelInput = document.getElementById('off-contatto-telefono');
  const contEmailInput = document.getElementById('off-contatto-email');
  const fornTelHidden = document.getElementById('off-fornitore-telefono');
  const fornEmailHidden = document.getElementById('off-fornitore-email');
  const listiniSelect = document.getElementById('off-listino-acquisto-id');
  const API_FORNITORE_DETTAGLI = <?= json_encode(url('purchases/rfq/api/fornitore-dettagli')) ?>;

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
    const fornitoreId = document.getElementById('off-fornitore-id')?.value;
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

      loadedContacts = data.contatti || [];

      if (fornTelHidden) fornTelHidden.value = data.fornitore?.telefono || '';
      if (fornEmailHidden) fornEmailHidden.value = data.fornitore?.email || data.fornitore?.cellulare || '';

      const condPag = document.getElementById('off-condizioni-pagamento');
      if (condPag && !condPag.value && data.fornitore?.condizioni_pagamento) {
        condPag.value = data.fornitore.condizioni_pagamento;
      }

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

  if (contSelect) {
    contSelect.addEventListener('change', () => {
      const selectedId = contSelect.value;
      if (!selectedId) {
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

  /* ── Gestione interattiva Indirizzo Consegna ───────────────────────── */
  const indSelect = document.getElementById('off-indirizzo-consegna-id');
  let loadedAddress = null; // l'indirizzo master ereditato

  async function loadOriginalAddress() {
    const masterAddrId = document.getElementById('off-indirizzo-consegna-master-id')?.value;
    
    if (!masterAddrId) return;

    try {
      const res = await fetch(<?= json_encode(url('purchases/rfq/api/azienda-indirizzi')) ?> + '?indirizzo_id=' + masterAddrId);
      const data = await res.json();
      if (data.ok && data.indirizzo) {
        loadedAddress = data.indirizzo;
        syncAddressUI();
      }
    } catch (e) {
      console.error(e);
    }
  }

  function syncAddressUI() {
    if (!loadedAddress) return;
    const inpVia = document.getElementById('off-consegna-via');
    if (!inpVia) return;

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

    checkField(document.getElementById('off-consegna-via'), loadedAddress.via);
    checkField(document.getElementById('off-consegna-civico'), loadedAddress.civico);
    checkField(document.getElementById('off-consegna-cap'), loadedAddress.cap);
    checkField(document.getElementById('off-consegna-citta'), loadedAddress.citta);
    checkField(document.getElementById('off-consegna-provincia'), loadedAddress.provincia);
    checkField(document.getElementById('off-consegna-nazione'), loadedAddress.nazione || 'IT');
    checkField(document.getElementById('off-consegna-presso'), loadedAddress.presso);
    checkField(document.getElementById('off-consegna-referente'), loadedAddress.referente_consegna);
    checkField(document.getElementById('off-consegna-telefono'), loadedAddress.telefono_consegna);
    checkField(document.getElementById('off-consegna-note'), loadedAddress.note_consegna);

    const badge = document.getElementById('addr-state-badge');
    const resetBtn = document.getElementById('btn-cancel-edit-addr');

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

  [
    'off-consegna-presso', 'off-consegna-via', 'off-consegna-civico', 'off-consegna-cap',
    'off-consegna-citta', 'off-consegna-provincia', 'off-consegna-nazione',
    'off-consegna-referente', 'off-consegna-telefono', 'off-consegna-note'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', syncAddressUI);
  });

  const btnCancelEditAddr = document.getElementById('btn-cancel-edit-addr');
  if (btnCancelEditAddr) {
    btnCancelEditAddr.addEventListener('click', () => {
      if (!loadedAddress) return;
      
      const elVia = document.getElementById('off-consegna-via');
      const elCiv = document.getElementById('off-consegna-civico');
      const elCap = document.getElementById('off-consegna-cap');
      const elCit = document.getElementById('off-consegna-citta');
      const elPrv = document.getElementById('off-consegna-provincia');
      const elNaz = document.getElementById('off-consegna-nazione');
      const elPre = document.getElementById('off-consegna-presso');
      const elRef = document.getElementById('off-consegna-referente');
      const elTel = document.getElementById('off-consegna-telefono');
      const elNot = document.getElementById('off-consegna-note');

      if (elVia) elVia.value = loadedAddress.via || '';
      if (elCiv) elCiv.value = loadedAddress.civico || '';
      if (elCap) elCap.value = loadedAddress.cap || '';
      if (elCit) elCit.value = loadedAddress.citta || '';
      if (elPrv) elPrv.value = loadedAddress.provincia || '';
      if (elNaz) elNaz.value = loadedAddress.nazione || 'IT';
      if (elPre) elPre.value = loadedAddress.presso || '';
      if (elRef) elRef.value = loadedAddress.referente_consegna || '';
      if (elTel) elTel.value = loadedAddress.telefono_consegna || '';
      if (elNot) elNot.value = loadedAddress.note_consegna || '';

      syncAddressUI();
    });
  }

  loadOriginalAddress();

  // Implement Read-only Lock for status not 'in_valutazione'
  const quoteStato = <?= json_encode($values['stato'] ?? 'in_valutazione') ?>;
  if (quoteStato !== 'in_valutazione') {
    [
      contSelect, contNomeInput, contTelInput, contEmailInput,
      document.getElementById('off-consegna-presso'),
      document.getElementById('off-consegna-via'),
      document.getElementById('off-consegna-civico'),
      document.getElementById('off-consegna-cap'),
      document.getElementById('off-consegna-citta'),
      document.getElementById('off-consegna-provincia'),
      document.getElementById('off-consegna-nazione'),
      document.getElementById('off-consegna-referente'),
      document.getElementById('off-consegna-telefono'),
      document.getElementById('off-consegna-note')
    ].forEach(el => {
      if (el) {
        el.disabled = true;
        el.readOnly = true;
      }
    });
    if (btnCancelEditCont) btnCancelEditCont.style.display = 'none';
    if (btnCancelEditAddr) btnCancelEditAddr.style.display = 'none';
  }

  loadFornitoreDettagli();

  /* ── Mostra/Nascondi Colonne ───────────────────────────────────────── */
  const btnColSettings = document.getElementById('btn-col-settings');
  const colSettingsDropdown = document.getElementById('col-settings-dropdown');
  const chkSku = document.getElementById('chk-show-sku');
  const chkDesc = document.getElementById('chk-show-desc');
  const chkSconto = document.getElementById('chk-show-sconto');
  const chkIva = document.getElementById('chk-show-iva');
  const chkConsegna = document.getElementById('chk-show-consegna');
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
  }

  function updateColumnVisibility() {
    const showSku = chkSku ? chkSku.checked : true;
    const showDesc = chkDesc ? chkDesc.checked : true;
    const showSconto = chkSconto ? chkSconto.checked : true;
    const showIva = chkIva ? chkIva.checked : true;
    const showConsegna = chkConsegna ? chkConsegna.checked : true;
    const showNote = chkNote ? chkNote.checked : true;

    document.querySelectorAll('.col-sku').forEach(el => el.style.display = showSku ? '' : 'none');
    document.querySelectorAll('.col-desc').forEach(el => el.style.display = showDesc ? '' : 'none');
    document.querySelectorAll('.col-sconto').forEach(el => el.style.display = showSconto ? '' : 'none');
    document.querySelectorAll('.col-iva').forEach(el => el.style.display = showIva ? '' : 'none');
    document.querySelectorAll('.col-consegna').forEach(el => el.style.display = showConsegna ? '' : 'none');
    document.querySelectorAll('.col-note').forEach(el => el.style.display = showNote ? '' : 'none');

    localStorage.setItem('off_show_sku', showSku ? '1' : '0');
    localStorage.setItem('off_show_desc', showDesc ? '1' : '0');
    localStorage.setItem('off_show_sconto', showSconto ? '1' : '0');
    localStorage.setItem('off_show_iva', showIva ? '1' : '0');
    localStorage.setItem('off_show_consegna', showConsegna ? '1' : '0');
    localStorage.setItem('off_show_note', showNote ? '1' : '0');
  }

  [chkSku, chkDesc, chkSconto, chkIva, chkConsegna, chkNote].forEach(chk => {
    if (chk) chk.addEventListener('change', updateColumnVisibility);
  });

  if (chkSku) {
    const pref = localStorage.getItem('off_show_sku');
    chkSku.checked = (pref === null || pref === '1');
  }
  if (chkDesc) {
    const pref = localStorage.getItem('off_show_desc');
    chkDesc.checked = (pref === null || pref === '1');
  }
  if (chkSconto) {
    const pref = localStorage.getItem('off_show_sconto');
    chkSconto.checked = (pref === '1');
  }
  if (chkIva) {
    const pref = localStorage.getItem('off_show_iva');
    chkIva.checked = (pref === null || pref === '1');
  }
  if (chkConsegna) {
    const pref = localStorage.getItem('off_show_consegna');
    chkConsegna.checked = (pref === '1');
  }
  if (chkNote) {
    const pref = localStorage.getItem('off_show_note');
    chkNote.checked = (pref === null || pref === '1');
  }
  updateColumnVisibility();

  // Override template and split trigger to run visibility update
  const origAddRiga = window.addRiga;
  if (typeof origAddRiga === 'function') {
    window.addRiga = function() {
      const res = origAddRiga();
      updateColumnVisibility();
      return res;
    };
  }
  const origSplitRiga = window.splitRiga;
  if (typeof origSplitRiga === 'function') {
    window.splitRiga = function(button, idx) {
      origSplitRiga(button, idx);
      updateColumnVisibility();
    };
  }

  // Gestione propagazione Data Consegna di Testata (silente)
  const defaultDateInput = document.getElementById('off-data-consegna-default');
  if (defaultDateInput) {
    let prevDefaultDate = defaultDateInput.value;
    defaultDateInput.addEventListener('change', () => {
      const newDefaultDate = defaultDateInput.value;
      document.querySelectorAll('tr.offerta-riga').forEach(tr => {
        const rowDateInput = tr.querySelector('.r-consegna');
        if (rowDateInput) {
          const currentVal = rowDateInput.value;
          // Propaga se il campo è vuoto, oppure se corrispondeva al valore di default precedente
          if (!currentVal || currentVal === prevDefaultDate) {
            rowDateInput.value = newDefaultDate;
          }
        }
      });
      prevDefaultDate = newDefaultDate;
    });
  }

  /* ── Switcher Tab Odoo-Style ───────────────────────────────────────── */
  window.switchOffertaTab = function(tabId) {
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
})();



function toggleAltroMotivo(val) {
  const group = document.getElementById('altro-motivo-group');
  const input = document.getElementById('motivo_rifiuto_altro');
  if (val === 'Altro') {
    group.style.display = 'block';
    input.setAttribute('required', 'required');
  } else {
    group.style.display = 'none';
    input.removeAttribute('required');
    input.value = '';
  }
}