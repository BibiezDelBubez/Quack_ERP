document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.h-check, .p-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
      const lbl = document.getElementById(this.dataset.label);
      if (lbl) lbl.classList.toggle('h-chip-active', this.checked);
    });
  });
  document.querySelectorAll('.pitt-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
      const lbl = document.getElementById(this.dataset.label);
      if (lbl) lbl.style.borderColor = this.checked ? 'var(--qk-accent)' : 'var(--qk-border)';
    });
  });
  const idInput = document.getElementById('codice-onu-id');
  const labelInput = document.getElementById('codice-onu-label');
  const clearBtn = document.getElementById('codice-onu-clear');
  const dropdownEl = document.getElementById('codice-onu-dropdown');
  const selGI  = document.querySelector('[name="gruppo_imballaggio"]');
  const banner = document.getElementById('onu-dedup-banner');

  if (labelInput && idInput) {
    window.qkAutocomplete({
      input: labelInput,
      dropdown: dropdownEl,
      fetchFn: function(term, callback) {
        fetch('"+window.QkAdrSostanze.lookupUrl+"?type=codici_onu&q=' + encodeURIComponent(term))
          .then(res => res.json())
          .then(data => {
            const results = data.map(item => {
              let label = 'UN' + item.label;
              if (item.sub) {
                label += ' — ' + item.sub;
              }
              return { id: item.id, label: label };
            });
            callback(results);
          })
          .catch(err => {
            console.error('Error lookup ONU:', err);
            callback([]);
          });
      },
      onSelect: function(selItem) {
        if (selItem) {
          idInput.value = selItem.id;
          labelInput.value = selItem.label;
          if (clearBtn) clearBtn.classList.remove('d-none');
          checkOnu();
        } else {
          idInput.value = '';
          labelInput.value = '';
          if (clearBtn) clearBtn.classList.add('d-none');
          checkOnu();
        }
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        idInput.value = '';
        labelInput.value = '';
        clearBtn.classList.add('d-none');
        dropdownEl.style.display = 'none';
        checkOnu();
      });
    }
  }

  const baseUrl   = idInput ? idInput.dataset.checkUrl : '';
  const excludeId = idInput ? idInput.dataset.excludeId || '0' : '0';

  function checkOnu() {
    const v = idInput ? idInput.value : '';
    if (!v || !banner) { if (banner) banner.style.display='none'; return; }
    const gi = selGI ? selGI.value : '';
    const qs = '?codice_onu_id=' + encodeURIComponent(v)
             + '&exclude_id='    + encodeURIComponent(excludeId)
             + (gi ? '&gruppo_imballaggio=' + encodeURIComponent(gi) : '');
    fetch(baseUrl + qs).then(r=>r.json()).then(data => {
      if (!data.sostanze || !data.sostanze.length) { banner.style.display='none'; return; }
      const lista = data.sostanze.map(s => {
        const href = '"+window.QkAdrSostanze.editUrl+"' + s.id;
        return '<a href="'+href+'" target="_blank" class="fw-semibold text-decoration-none" style="color:inherit">'+s.nome+(s.gruppo_imballaggio?' · GI '+s.gruppo_imballaggio:'')+(s.codice_cas?' · '+s.codice_cas:'')+'</a>';
      }).join('<br>');
      banner.style.cssText='display:block;background:#fffbeb;border:1px solid #f59e0b;border-radius:var(--qk-radius,4px);padding:.5rem .75rem';
      banner.innerHTML='<i class="fa-solid fa-triangle-exclamation me-1" style="color:#f59e0b"></i><strong>Attenzione:</strong> questo ONU è già usato da:<br>'+lista+'<br><span class="qk-text-muted">Verifica che non sia la stessa sostanza.</span>';
    }).catch(()=>{if (banner) banner.style.display='none';});
  }

  if (idInput) idInput.addEventListener('change', checkOnu);
  if (selGI) selGI.addEventListener('change', checkOnu);
  if (idInput && idInput.value) checkOnu();
});
/* ══════════════════════════════════════════════════════════════════════════
 * Tab Restrizioni Compliance — AJAX
 * ══════════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const SOSTANZA_ID  = window.QkAdrSostanze.sostanzaId;
  const API_LIST     = window.QkAdrSostanze.apiRestrizioni;
  const API_ADD      = window.QkAdrSostanze.apiRestrizioniAdd;
  const API_REMOVE   = window.QkAdrSostanze.apiRestrizioniRemove;
  const CSRF         = window.QkAdrSostanze.csrfToken;

  const container    = document.getElementById('restrizioni-container');
  const badge        = document.getElementById('badge-restrizioni');
  const tabBtn       = document.getElementById('btn-tab-restrizioni');

  if (!container) return;

  // Carica al click sul tab (lazy load)
  if (tabBtn) {
    tabBtn.addEventListener('shown.bs.tab', load);
    tabBtn.addEventListener('click', function() {
      // Se il container ha ancora lo spinner (non caricato o fallito), forza caricamento
      if (container.querySelector('.fa-spinner')) {
        load();
      }
    });
  }

  // ── helpers ───────────────────────────────────────────────────────────

  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function pill(icon, text) {
    return `<span class="qkr-pill"><i class="fa-solid ${icon} fa-xs"></i> ${esc(text)}</span>`;
  }

  // ── render principale ─────────────────────────────────────────────────

  async function load() {
    container.innerHTML = `<div class="qk-empty py-4"><i class="fa-solid fa-spinner fa-spin fa-lg mb-2"></i><br>Caricamento…</div>`;
    try {
      const res  = await fetch(`${API_LIST}?sostanza_id=${SOSTANZA_ID}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error ?? 'Errore sconosciuto');
      render(data);
    } catch(e) {
      container.innerHTML = `<div class="alert alert-danger m-3">${esc(e.message)}</div>`;
    }
  }

  function render(data) {
    const dirette   = data.dirette   ?? [];
    const ereditate = data.ereditate ?? [];
    const tutti     = data.tutti     ?? [];

    // Badge tab
    const nDirette = dirette.length;
    if (badge) {
      badge.textContent = nDirette;
      badge.classList.toggle('d-none', nDirette === 0);
    }

    let html = '';

    // ── Toolbar: titolo + pulsante aggiungi ──────────────────────────────
    html += `
      <div class="qk-table-toolbar mb-3">
        <div class="qk-table-toolbar-left">
          <span class="fw-semibold">Restrizioni di vendita</span>
          <span class="qk-text-muted small ms-2">
            Documenti o autorizzazioni richiesti per poter vendere questa sostanza.
          </span>
        </div>
        <div class="qk-table-toolbar-right">
          <button class="btn-qk btn-qk-primary btn-qk-sm" id="btn-aggiungi-restrizione">
            <i class="fa-solid fa-plus me-1"></i> Aggiungi restrizione
          </button>
        </div>
      </div>`;

    // Pannello aggiungi (nascosto di default)
    html += buildAddPanel(tutti);

    // ── Lista restrizioni dirette ─────────────────────────────────────────
    if (dirette.length === 0) {
      // Testo diverso se ci sono restrizioni ereditate dal codice ONU o no.
      // (internamente: se non ci sono restrizioni dirette, il sistema usa quelle collegate
      //  al codice ONU come fallback — ma all'utente basta sapere cosa fare)
      const emptyMsg = ereditate.length > 0
        ? `Nessuna restrizione configurata manualmente.<br>
           <span class="qk-text-muted">Il sistema rileva già una restrizione in automatico (vedi sotto) — se è corretta non devi fare nulla.</span>`
        : `Nessuna restrizione di vendita.<br>
           <span class="qk-text-muted">Se questa sostanza è soggetta a normative particolari (es. precursori di esplosivi, stupefacenti, biocidi), aggiungila con il pulsante qui sopra.</span>`;

      html += `<div class="qk-empty"><i class="fa-solid fa-shield-halved"></i><p>${emptyMsg}</p></div>`;
    } else {
      dirette.forEach(r => { html += buildCard(r, 'direct'); });
    }

    // ── Sezione rilevata automaticamente dal codice ONU (read-only) ───────
    // Internamente è il "fallback via ONU" — mostrato come info, non come azione
    if (ereditate.length > 0) {
      const infoMsg = dirette.length > 0
        ? 'Rilevata dal codice ONU, ma <strong>non attiva</strong> perché è già presente una restrizione configurata manualmente sopra.'
        : 'Rilevata in automatico dal codice ONU. Non devi fare nulla.';

      html += `
        <div class="qk-card mt-3" style="border-style:dashed">
          <div class="qk-card-header">
            <span class="qk-card-title" style="font-size:.85rem">
              <i class="fa-solid fa-wand-magic-sparkles me-1" style="color:var(--qk-accent)"></i>
              Rilevata automaticamente
            </span>
            <span class="qk-card-subtitle">${infoMsg}</span>
          </div>
          <div class="qk-card-body pt-2 pb-2">`;
      ereditate.forEach(r => { html += buildCard(r, 'inherited'); });
      html += `</div></div>`;
    }

    container.innerHTML = html;
    attachEvents(tutti);
  }

  // ── Card singola restrizione ─────────────────────────────────────────

  function buildCard(r, source) {
    const isDirect = source === 'direct';
    const iconClass = isDirect ? 'qkr-icon--direct' : 'qkr-icon--inherited';
    const sourceBadge = isDirect
      ? `<span class="qkr-source-badge qkr-source-badge--direct">Diretta</span>`
      : `<span class="qkr-source-badge qkr-source-badge--inherited">Da ONU ${esc(r.codice_onu ?? '')}</span>`;

    const pills = [];
    if (parseInt(r.requires_vat)) pills.push(pill('fa-address-card', 'P.IVA richiesta'));
    if (r.validity_days)          pills.push(pill('fa-calendar-days', `Validità ${r.validity_days} gg`));

    const concMin = r.concentrazione_min ?? r.min_concentration;
    const concMax = r.concentrazione_max ?? r.max_concentration;
    let concHtml = '';
    if (concMin !== null && concMin !== undefined) {
      concHtml = `<div class="qkr-conc"><i class="fa-solid fa-flask fa-xs me-1"></i>Attiva da conc. ≥ ${concMin}%${concMax ? ` e ≤ ${concMax}%` : ''}</div>`;
    }

    const noteHtml = r.note ? `<div class="qkr-note">${esc(r.note)}</div>` : '';

    const removeBtn = isDirect
      ? `<button class="btn-qk btn-qk-secondary btn-qk-sm text-danger btn-remove-restrizione"
                 data-type-id="${r.restriction_type_id}"
                 data-name="${esc(r.name)}"
                 title="Rimuovi restrizione">
           <i class="fa-solid fa-xmark"></i>
         </button>`
      : '';

    return `
      <div class="qkr-card" data-type-id="${r.restriction_type_id ?? ''}">
        <div class="qkr-icon ${iconClass}">
          <i class="fa-solid fa-shield-halved"></i>
        </div>
        <div class="qkr-body">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="qkr-name">${esc(r.name)}</span>
            ${sourceBadge}
          </div>
          <div class="qkr-ref">${esc(r.regulatory_ref ?? '')}</div>
          ${pills.length ? `<div class="qkr-meta">${pills.join('')}</div>` : ''}
          ${concHtml}
          ${noteHtml}
        </div>
        <div class="flex-shrink-0">${removeBtn}</div>
      </div>`;
  }

  // ── Pannello aggiungi inline ─────────────────────────────────────────

  function buildAddPanel(tutti) {
    const options = tutti.map(t =>
      `<option value="${t.id}">${esc(t.name)} — ${esc(t.regulatory_ref ?? '')}</option>`
    ).join('');

    return `
      <div class="qkr-add-panel d-none" id="qkr-add-panel">
        <div class="fw-semibold mb-2 small"><i class="fa-solid fa-plus-circle me-1" style="color:var(--qk-accent)"></i>Aggiungi restrizione compliance</div>
        <div class="qk-form-row">
          <div class="qk-form-group" style="flex:3">
            <label class="qk-form-label required">Tipo di restrizione</label>
            <select id="qkr-select-tipo" class="qk-form-control">
              <option value="">— Seleziona —</option>
              ${options}
            </select>
          </div>
          <div class="qk-form-group" style="flex:1">
            <label class="qk-form-label">Conc. min (%) <i class="fa-solid fa-circle-info ms-1 text-muted" title="Soglia legale minima di concentrazione oltre la quale la restrizione si attiva." style="cursor:help;"></i></label>
            <input type="text" id="qkr-conc-min" class="qk-form-control" placeholder="Es. 10">
          </div>
          <div class="qk-form-group" style="flex:1">
            <label class="qk-form-label">Conc. max (%) <i class="fa-solid fa-circle-info ms-1 text-muted" title="Soglia legale massima di concentrazione entro cui si applica la restrizione." style="cursor:help;"></i></label>
            <input type="text" id="qkr-conc-max" class="qk-form-control" placeholder="Es. 50">
          </div>
        </div>
        <div class="qk-form-row">
          <div class="qk-form-group" style="flex:1">
            <label class="qk-form-label">Note</label>
            <input type="text" id="qkr-note" class="qk-form-control" placeholder="Riferimento interno, motivazione…">
          </div>
        </div>
        <div class="d-flex gap-2 mt-1">
          <button class="btn-qk btn-qk-primary btn-qk-sm" id="qkr-btn-salva">
            <i class="fa-solid fa-floppy-disk me-1"></i> Salva
          </button>
          <button class="btn-qk btn-qk-secondary btn-qk-sm" id="qkr-btn-annulla">Annulla</button>
          <span id="qkr-save-error" class="small text-danger align-self-center d-none"></span>
        </div>
      </div>`;
  }

  // ── eventi ────────────────────────────────────────────────────────────

  function attachEvents(tutti) {
    // Apri pannello aggiungi
    const btnAdd = document.getElementById('btn-aggiungi-restrizione');
    const panel  = document.getElementById('qkr-add-panel');
    if (btnAdd && panel) {
      btnAdd.addEventListener('click', () => {
        panel.classList.remove('d-none');
        btnAdd.classList.add('d-none');
        document.getElementById('qkr-select-tipo')?.focus();
      });
    }

    // Annulla
    document.getElementById('qkr-btn-annulla')?.addEventListener('click', () => {
      panel?.classList.add('d-none');
      btnAdd?.classList.remove('d-none');
    });

    // Salva
    document.getElementById('qkr-btn-salva')?.addEventListener('click', async () => {
      const typeId  = document.getElementById('qkr-select-tipo')?.value;
      const concMin = document.getElementById('qkr-conc-min')?.value.trim();
      const concMax = document.getElementById('qkr-conc-max')?.value.trim();
      const note    = document.getElementById('qkr-note')?.value.trim();
      const errEl   = document.getElementById('qkr-save-error');

      if (!typeId) {
        if (errEl) { errEl.textContent = 'Seleziona un tipo di restrizione.'; errEl.classList.remove('d-none'); }
        return;
      }
      if (errEl) errEl.classList.add('d-none');

      const body = new FormData();
      body.append('sostanza_id',        SOSTANZA_ID);
      body.append('restriction_type_id', typeId);
      body.append('concentrazione_min',  concMin);
      body.append('concentrazione_max',  concMax);
      body.append('note',                note);
      body.append('csrf_token',          CSRF);

      try {
        const res  = await fetch(API_ADD, { method: 'POST', body });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error ?? 'Errore');
        await load(); // ricarica tutto
      } catch(e) {
        if (errEl) { errEl.textContent = e.message; errEl.classList.remove('d-none'); }
      }
    });

    // Rimuovi
    document.querySelectorAll('.btn-remove-restrizione').forEach(btn => {
      btn.addEventListener('click', async () => {
        const typeId = btn.dataset.typeId;
        const name   = btn.dataset.name;
        if (!confirm(`Rimuovere la restrizione "${name}" da questa sostanza?`)) return;

        const body = new FormData();
        body.append('sostanza_id',         SOSTANZA_ID);
        body.append('restriction_type_id', typeId);
        body.append('csrf_token',          CSRF);

        try {
          const res  = await fetch(API_REMOVE, { method: 'POST', body });
          const data = await res.json();
          if (!data.ok) throw new Error(data.error ?? 'Errore');
          await load();
        } catch(e) {
          alert('Errore: ' + e.message);
        }
      });
    });
  }

  // ==========================================
  // DUAL-LIST SHUTTLE PICKER (PRODOTTI ASSOCIATI)
  // ==========================================
  
  // Iniettiamo gli stili CSS personalizzati per lo shuttle picker in modo da rimanere conformi e non inquinare i file globali
  (function injectShuttleStyles() {
    const styleEl = document.createElement('style');
    styleEl.textContent = `
      .shuttle-item {
        padding: 8px 12px;
        margin-bottom: 6px;
        border: 1px solid var(--qk-border);
        border-radius: 6px;
        cursor: pointer;
        transition: background-color 0.15s, border-color 0.15s, transform 0.15s;
        background-color: var(--qk-card-bg);
        user-select: none;
        display: flex;
        align-items: center;
        gap: 10px;
      }
      .shuttle-item:hover {
        background-color: var(--qk-bg-subtle);
        border-color: var(--qk-accent);
      }
      .shuttle-item.selected {
        background-color: var(--qk-accent-light, rgba(0, 102, 204, 0.04));
        border-color: var(--qk-accent);
      }
      .shuttle-item input[type="checkbox"] {
        cursor: pointer;
        width: 16px;
        height: 16px;
        accent-color: var(--qk-accent);
      }
    `;
    document.head.appendChild(styleEl);
  })();

  // Stato dell'interfaccia
  let associatedList = <?= json_encode(array_map(function($p) {
      return [
          'id' => (int)$p['id'],
          'nome' => $p['nome'],
          'sku' => $p['sku_interno'],
          'attivo' => (bool)$p['attivo'],
          'categoria_id' => $p['categoria_id'] ? (int)$p['categoria_id'] : null
      ];
  }, $prodotti_associati)) ?>;

  let availableList = []; // Prodotti trovati dalla ricerca

  // Elementi DOM dello Shuttle Picker
  const elSearchAvailable = document.getElementById('shuttle-search');
  const elCategoryFilter = document.getElementById('shuttle-category');
  const elContainerAvailable = document.getElementById('shuttle-available-container');
  const elCountAvailableSelected = document.getElementById('shuttle-available-count');
  const elBtnAssociate = document.getElementById('btn-shuttle-associate');
  const elBadgeAvailableTotal = document.getElementById('shuttle-available-badge-total');

  const elSearchAssociated = document.getElementById('shuttle-associated-search');
  const elContainerAssociated = document.getElementById('shuttle-associated-container');
  const elCountAssociatedSelected = document.getElementById('shuttle-associated-count');
  const elBtnDisassociate = document.getElementById('btn-shuttle-disassociate');
  const elBadgeAssociatedTotal = document.getElementById('shuttle-associated-badge-total');
  const elBadgeTabCount = document.getElementById('badge-prodotti-count');

  // Funzione helper per l'escaping HTML
  function escapeHTML(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Rende la lista dei prodotti disponibili
  function renderAvailable() {
    if (availableList.length === 0) {
      elBadgeAvailableTotal.textContent = '0';
      const term = elSearchAvailable.value.trim();
      if (term.length < 2) {
        elContainerAvailable.innerHTML = `
          <div class="text-muted text-center py-5 shuttle-empty-state">
            <i class="fa-solid fa-magnifying-glass fa-2xl mb-3 text-muted" style="opacity: 0.5;"></i>
            <div class="small fw-semibold">Digita almeno 2 caratteri per cercare i prodotti nel catalogo.</div>
          </div>
        `;
      } else {
        elContainerAvailable.innerHTML = `
          <div class="text-muted text-center py-5 shuttle-empty-state">
            <i class="fa-solid fa-boxes-stacked fa-2xl mb-3 text-muted" style="opacity: 0.5;"></i>
            <div class="small fw-semibold">Nessun prodotto disponibile trovato con questi filtri.</div>
          </div>
        `;
      }
      updateAvailableCount();
      return;
    }

    // Escludiamo quelli già associati
    const associatedIds = new Set(associatedList.map(p => p.id));
    const filtered = availableList.filter(p => !associatedIds.has(p.id));

    elBadgeAvailableTotal.textContent = filtered.length;

    if (filtered.length === 0) {
      elContainerAvailable.innerHTML = `
        <div class="text-muted text-center py-5 shuttle-empty-state">
          <i class="fa-solid fa-check-double fa-2xl mb-3 text-success" style="opacity: 0.7;"></i>
          <div class="small fw-semibold text-success">Tutti i prodotti cercati sono già associati!</div>
        </div>
      `;
      updateAvailableCount();
      return;
    }

    let html = '';
    filtered.forEach(p => {
      const isSelected = p._selected ? 'selected' : '';
      const isChecked = p._selected ? 'checked' : '';
      html += `
        <div class="shuttle-item ${isSelected}" data-id="${p.id}">
          <input type="checkbox" class="shuttle-cb" data-id="${p.id}" ${isChecked}>
          <div class="flex-grow-1">
            <div class="fw-semibold text-primary" style="font-size: 0.85rem;">${escapeHTML(p.nome)}</div>
            <div class="text-muted font-monospace small d-flex align-items-center gap-2" style="font-size: 0.72rem;">
              <span>SKU: ${escapeHTML(p.sku)}</span>
              ${p.attivo ? '<span class="text-success">● Attivo</span>' : '<span class="text-danger">● Inattivo</span>'}
            </div>
          </div>
        </div>
      `;
    });

    elContainerAvailable.innerHTML = html;

    // Aggiungi event listeners per la selezione al click sull'intero elemento
    elContainerAvailable.querySelectorAll('.shuttle-item').forEach(item => {
      item.addEventListener('click', function(e) {
        // Se il click non è direttamente sul checkbox, cambialo manualmente
        if (e.target.type !== 'checkbox') {
          const cb = this.querySelector('.shuttle-cb');
          cb.checked = !cb.checked;
          cb.dispatchEvent(new Event('change'));
        }
      });
    });

    elContainerAvailable.querySelectorAll('.shuttle-cb').forEach(cb => {
      cb.addEventListener('change', function() {
        const id = parseInt(this.dataset.id);
        const item = availableList.find(p => p.id === id);
        if (item) {
          item._selected = this.checked;
          const parent = this.closest('.shuttle-item');
          if (parent) parent.classList.toggle('selected', this.checked);
        }
        updateAvailableCount();
      });
    });

    updateAvailableCount();
  }

  // Rende la lista dei prodotti associati
  function renderAssociated() {
    let list = [...associatedList];

    // Filtro locale su associati per nome o SKU
    const localQ = elSearchAssociated.value.trim().toLowerCase();
    if (localQ.length > 0) {
      list = list.filter(p => 
        p.nome.toLowerCase().includes(localQ) || 
        p.sku.toLowerCase().includes(localQ)
      );
    }

    elBadgeAssociatedTotal.textContent = associatedList.length;
    if (elBadgeTabCount) {
      elBadgeTabCount.textContent = associatedList.length;
      elBadgeTabCount.classList.toggle('d-none', associatedList.length === 0);
    }

    if (list.length === 0) {
      elContainerAssociated.innerHTML = `
        <div class="text-muted text-center py-5 shuttle-empty-state">
          <i class="fa-solid fa-link-slash fa-2xl mb-3 text-muted" style="opacity: 0.5;"></i>
          <div class="small fw-semibold">Nessun prodotto associato trovato.</div>
        </div>
      `;
      updateAssociatedCount();
      return;
    }

    let html = '';
    list.forEach(p => {
      const isSelected = p._selected ? 'selected' : '';
      const isChecked = p._selected ? 'checked' : '';
      html += `
        <div class="shuttle-item ${isSelected}" data-id="${p.id}">
          <input type="checkbox" class="shuttle-cb" data-id="${p.id}" ${isChecked}>
          <div class="flex-grow-1">
            <div class="fw-semibold text-success" style="font-size: 0.85rem;">${escapeHTML(p.nome)}</div>
            <div class="text-muted font-monospace small d-flex align-items-center justify-content-between" style="font-size: 0.72rem;">
              <span>SKU: ${escapeHTML(p.sku)}</span>
              <a href=""+window.QkAdrSostanze.productsEditUrl+"${p.id}" class="text-decoration-none text-muted" target="_blank" title="Apri scheda prodotto" style="cursor: pointer;">
                Apri scheda <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size:0.65rem;"></i>
              </a>
            </div>
          </div>
        </div>
      `;
    });

    elContainerAssociated.innerHTML = html;

    // Aggiungi event listeners per la selezione al click sull'intero elemento
    elContainerAssociated.querySelectorAll('.shuttle-item').forEach(item => {
      item.addEventListener('click', function(e) {
        // Se il click è su "Apri scheda", non fare il toggle
        if (e.target.closest('a')) return;
        if (e.target.type !== 'checkbox') {
          const cb = this.querySelector('.shuttle-cb');
          cb.checked = !cb.checked;
          cb.dispatchEvent(new Event('change'));
        }
      });
    });

    elContainerAssociated.querySelectorAll('.shuttle-cb').forEach(cb => {
      cb.addEventListener('change', function() {
        const id = parseInt(this.dataset.id);
        const item = associatedList.find(p => p.id === id);
        if (item) {
          item._selected = this.checked;
          const parent = this.closest('.shuttle-item');
          if (parent) parent.classList.toggle('selected', this.checked);
        }
        updateAssociatedCount();
      });
    });

    updateAssociatedCount();
  }

  // Aggiorna contatore e pulsante dei prodotti disponibili
  function updateAvailableCount() {
    const selected = availableList.filter(p => p._selected && !associatedList.some(assoc => assoc.id === p.id));
    const count = selected.length;
    elCountAvailableSelected.textContent = `${count} selezionati`;
    elBtnAssociate.disabled = count === 0;
  }

  // Aggiorna contatore e pulsante dei prodotti associati
  function updateAssociatedCount() {
    const selected = associatedList.filter(p => p._selected);
    const count = selected.length;
    elCountAssociatedSelected.textContent = `${count} selezionati`;
    elBtnDisassociate.disabled = count === 0;
  }

  // Cerca prodotti sul server tramite API
  let searchTimer = null;
  async function searchProducts() {
    const query = elSearchAvailable.value.trim();
    const catId = elCategoryFilter.value;

    if (query.length < 2) {
      availableList = [];
      renderAvailable();
      return;
    }

    // Mostriamo uno spinner di caricamento nella lista
    elContainerAvailable.innerHTML = `
      <div class="text-center py-5 text-muted">
        <i class="fa-solid fa-spinner fa-spin fa-2xl mb-3 text-accent"></i>
        <div class="small fw-semibold">Ricerca in corso...</div>
      </div>
    `;

    try {
      const response = await fetch('"+window.QkAdrSostanze.salesQuotesApiProdottiUrl+"?q=' + encodeURIComponent(query));
      const data = await response.json();

      let results = data.map(p => ({
        id: parseInt(p.id),
        nome: p.nome || p.label || '',
        sku: p.sku || p.sku_interno || '',
        attivo: true,
        categoria_id: p.categoria_id ? parseInt(p.categoria_id) : null,
        _selected: false
      }));

      // Se c'è un filtro categoria, filtriamo i risultati
      if (catId) {
        results = results.filter(p => p.categoria_id === parseInt(catId));
      }

      availableList = results;
      renderAvailable();
    } catch (err) {
      console.error(err);
      window.qkToast('Errore nel caricamento del catalogo.', 'danger');
      elContainerAvailable.innerHTML = `
        <div class="text-danger text-center py-5">
          <i class="fa-solid fa-triangle-exclamation fa-2xl mb-3"></i>
          <div class="small fw-semibold">Errore nel recupero dei prodotti.</div>
        </div>
      `;
    }
  }

  // Event listener per la ricerca
  if (elSearchAvailable) {
    elSearchAvailable.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(searchProducts, 250);
    });
  }

  if (elCategoryFilter) {
    elCategoryFilter.addEventListener('change', searchProducts);
  }

  if (elSearchAssociated) {
    elSearchAssociated.addEventListener('input', renderAssociated);
  }

  // Associazione massiva di prodotti selezionati
  if (elBtnAssociate) {
    elBtnAssociate.addEventListener('click', async function() {
      const selected = availableList.filter(p => p._selected && !associatedList.some(assoc => assoc.id === p.id));
      if (selected.length === 0) return;

      elBtnAssociate.disabled = true;
      const originalHTML = elBtnAssociate.innerHTML;
      elBtnAssociate.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Associazione...';

      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('sostanza_id', SOSTANZA_ID);
      selected.forEach(p => {
        fd.append('prodotto_ids[]', p.id);
      });

      try {
        const response = await fetch('"+window.QkAdrSostanze.apiAssociaProdotti+"', {
          method: 'POST',
          body: fd
        });
        const resData = await response.json();

        if (resData.ok) {
          window.qkToast(`Associati con successo ${selected.length} prodotti!`, 'success');
          
          // Spostiamo i prodotti dallo stato disponibili ad associati
          selected.forEach(p => {
            p._selected = false;
            associatedList.push({
              id: p.id,
              nome: p.nome,
              sku: p.sku,
              attivo: p.attivo,
              categoria_id: p.categoria_id
            });
          });

          // Pulisci selezione e renderizza
          renderAvailable();
          renderAssociated();
        } else {
          window.qkToast(resData.error || 'Errore durante l\'associazione.', 'danger');
        }
      } catch (err) {
        console.error(err);
        window.qkToast('Errore di rete.', 'danger');
      } finally {
        elBtnAssociate.disabled = false;
        elBtnAssociate.innerHTML = originalHTML;
      }
    });
  }

  // Disassociazione massiva di prodotti selezionati
  if (elBtnDisassociate) {
    elBtnDisassociate.addEventListener('click', async function() {
      const selected = associatedList.filter(p => p._selected);
      if (selected.length === 0) return;

      if (!confirm(`Vuoi davvero scollegare ${selected.length} prodotti selezionati da questa sostanza?`)) {
        return;
      }

      elBtnDisassociate.disabled = true;
      const originalHTML = elBtnDisassociate.innerHTML;
      elBtnDisassociate.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Rimozione...';

      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      selected.forEach(p => {
        fd.append('prodotto_ids[]', p.id);
      });

      try {
        const response = await fetch('"+window.QkAdrSostanze.apiDisassociaProdotto+"', {
          method: 'POST',
          body: fd
        });
        const resData = await response.json();

        if (resData.ok) {
          window.qkToast(`Scollegati con successo ${selected.length} prodotti!`, 'success');

          // Rimuoviamo dalla lista locale dei prodotti associati
          const selectedIds = new Set(selected.map(p => p.id));
          associatedList = associatedList.filter(p => !selectedIds.has(p.id));

          // Aggiorna renderizzazione
          renderAvailable();
          renderAssociated();
        } else {
          window.qkToast(resData.error || 'Errore durante lo scollegamento.', 'danger');
        }
      } catch (err) {
        console.error(err);
        window.qkToast('Errore di rete.', 'danger');
      } finally {
        elBtnDisassociate.disabled = false;
        elBtnDisassociate.innerHTML = originalHTML;
      }
    });
  }

  // Primo caricamento
  renderAssociated();
  renderAvailable();

});
