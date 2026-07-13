/* products-form.js
 * All PHP-derived config is read from window.QkPF (injected inline in _form.php)
 */
/* global QkPF, CSRF, bootstrap, QkM2O, QkPicker */
(function () {

  const CSRF = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

  /* ── Utilities HTML escape ───────────────────────────────────────────── */
  function esc(s)     { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function escA(s)    { return String(s ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;'); }
  function fmtNum(v, d) {
    if (v == null || v === '') return null;
    return parseFloat(v).toFixed(d ?? 2).replace('.', ',');
  }

  /* Bootstrap tooltips */
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

    /* ── Sidebar "Foto Principale" — nascondi quando il tab attivo non è "Dati prodotto" ──
     *
     * Il pannello .qk-status-panel (foto + stato) occupa spazio fisso nella sidebar.
     * Ha senso solo nel tab principale (Dati prodotto); nei tab Fornitori, ADR, Kit
     * è rumore visivo e ruba larghezza utile. Lo nascondiamo tramite evento shown.bs.tab.
     */
    const statusPanel = document.querySelector('.qk-status-panel');
    const formGrid    = document.querySelector('.qk-form-grid');
    if (statusPanel) {
      /* Tab che NON devono mostrare la sidebar foto/stato */
      const TABS_SENZA_SIDEBAR = ['ptab-btn-fornitori', 'ptab-btn-adr', 'ptab-btn-kit', 'ptab-btn-listini'];

      /**
       * Nasconde/mostra il pannello laterale E collassa la colonna CSS grid
       * in modo che il contenuto del tab occupi tutta la larghezza disponibile.
       */
      function syncSidebar(activeTabId) {
        const nascondi = TABS_SENZA_SIDEBAR.includes(activeTabId);
        statusPanel.style.display = nascondi ? 'none' : '';
        /* Collassa/espande la colonna destra del grid */
        formGrid?.classList.toggle('no-sidebar', nascondi);
      }

      /* Ascolta i cambi tab di Bootstrap */
      document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tabBtn => {
        tabBtn.addEventListener('shown.bs.tab', function () {
          syncSidebar(this.id);
        });
      });

      /* Sincronizza subito con l'eventuale tab già attivo al caricamento */
      const activeTab = document.querySelector('[data-bs-toggle="tab"].active');
      if (activeTab) syncSidebar(activeTab.id);
    }
    /* ── Fine sidebar toggle ── */
  });

  /* ── Radio card + visibilità tab Kit ────────────────────────────────── */
  const kitTabLi = document.getElementById('ptab-li-kit');
  const kitTabBtn = document.getElementById('ptab-btn-kit');

  document.querySelectorAll('.qk-radio-card').forEach(card => {
    const inp = card.querySelector('input[type="radio"]');
    if (!inp) return;
    inp.addEventListener('change', () => {
      document.querySelectorAll('.qk-radio-card').forEach(c => {
        c.classList.toggle('is-selected', !!(c.querySelector('input[type="radio"]')?.checked));
      });
      // Mostra/nascondi tab Kit
      if (kitTabLi) {
        const isKit = inp.value === 'kit' && inp.checked;
        kitTabLi.style.display = isKit ? '' : 'none';
        // Se stiamo nascondendo il tab kit e il tab è attivo, torna a "Dati prodotto"
        if (!isKit && kitTabBtn?.classList.contains('active')) {
          document.getElementById('ptab-btn-dati')?.click();
        }
      }
    });
  });

  /* ══════════════════════════════════════════════════════════════════════
     TAG — M2M chip field stile Odoo
  ══════════════════════════════════════════════════════════════════════ */
  (function () {
    const API_TAG    = QkPF.apiTag;
    const field      = document.getElementById('tag-m2m-field');
    const searchInp  = document.getElementById('tag-search-input');
    const dropdown   = document.getElementById('tag-m2m-dd');

    let allTags = [];
    try {
      allTags = JSON.parse(document.getElementById('all-tags-data')?.textContent ?? '[]');
    } catch {}

    const selectedIds = () => new Set(
      [...field.querySelectorAll('.qk-m2m-chip')].map(c => Number(c.dataset.id))
    );

    function addChip(id, nome) {
      id = Number(id);
      if (selectedIds().has(id)) return;
      const chip = document.createElement('span');
      chip.className   = 'qk-m2m-chip';
      chip.dataset.id  = id;
      chip.innerHTML   = `<span class="qk-m2m-chip-label">${nome}</span>`
                       + `<button type="button" class="qk-m2m-chip-remove" aria-label="Rimuovi">&#215;</button>`
                       + `<input type="hidden" name="tag_ids[]" value="${id}">`;
      chip.querySelector('.qk-m2m-chip-remove').addEventListener('click', () => chip.remove());
      field.insertBefore(chip, searchInp);
    }

    field.querySelectorAll('.qk-m2m-chip-remove').forEach(btn =>
      btn.addEventListener('click', () => btn.closest('.qk-m2m-chip').remove())
    );

    field.addEventListener('click', () => searchInp?.focus());

    function renderDropdown(q) {
      const sel    = selectedIds();
      const lq     = q.toLowerCase();
      const avail  = allTags.filter(t => !sel.has(Number(t.id)) && (!q || t.nome.toLowerCase().includes(lq)));
      let html     = '';

      avail.forEach(t => {
        html += `<div class="qk-m2m-option" data-id="${t.id}" data-nome="${t.nome}">${t.nome}</div>`;
      });

      if (q) {
        const exact = allTags.some(t => t.nome.toLowerCase() === lq);
        if (!exact) html += `<div class="qk-m2m-option is-create" data-action="create">+ Crea &ldquo;${q}&rdquo;</div>`;
      } else {
        html += `<div class="qk-m2m-option is-create" data-action="create-prompt">+ Crea nuovo tag (digita il nome…)</div>`;
      }

      if (!avail.length && !q) html = '<div class="qk-m2m-option is-empty">Nessun tag ancora. Digita un nome e premi Invio per crearne uno.</div>'
                                     + `<div class="qk-m2m-option is-create" data-action="create-prompt">+ Crea nuovo tag (digita il nome…)</div>`;

      dropdown.innerHTML = html;

      dropdown.querySelectorAll('.qk-m2m-option[data-id]').forEach(opt => {
        opt.addEventListener('click', () => {
          addChip(opt.dataset.id, opt.dataset.nome);
          searchInp.value = '';
          closeDropdown();
          searchInp.focus();
        });
      });

      dropdown.querySelector('[data-action="create-prompt"]')?.addEventListener('click', () => {
        searchInp.focus();
      });

      dropdown.querySelector('[data-action="create"]')?.addEventListener('click', async () => {
        const nome = searchInp.value.trim();
        if (!nome) return;
        closeDropdown();
        try {
          const fd = new FormData();
          fd.append('csrf_token', CSRF);
          fd.append('nome', nome);
          const res = await fetch(API_TAG, { method: 'POST', body: fd });
          const out = await res.json();
          if (out.ok) {
            allTags.push({ id: out.id, nome: out.nome });
            addChip(out.id, out.nome);
            searchInp.value = '';
          } else {
            alert(out.error || 'Errore nella creazione del tag.');
          }
        } catch { alert('Errore di rete.'); }
        searchInp.focus();
      });
    }

    function openDropdown(q) {
      renderDropdown(q);
      dropdown.classList.add('is-open');
    }
    function closeDropdown() { dropdown.classList.remove('is-open'); }

    searchInp?.addEventListener('focus', () => openDropdown(searchInp.value.trim()));
    searchInp?.addEventListener('input', () => openDropdown(searchInp.value.trim()));
    searchInp?.addEventListener('keydown', e => {
      if (e.key === 'Escape') { closeDropdown(); }
      if (e.key === 'Backspace' && !searchInp.value) {
        const chips = field.querySelectorAll('.qk-m2m-chip');
        chips[chips.length - 1]?.remove();
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        const q = searchInp.value.trim();
        if (!q) return;
        const createOpt = dropdown.querySelector('[data-action="create"]');
        if (createOpt) createOpt.click();
      }
    });

    document.addEventListener('click', e => {
      if (!document.getElementById('tag-m2m-wrap')?.contains(e.target)) closeDropdown();
    }, true);
  })();

  /* ── ADR toggle ──────────────────────────────────────────────────────── */
  const adrSel = document.getElementById('adr-sostanza-sel');
  const pgWrap = document.getElementById('adr-pg-wrap');
  function toggleAdr() {
    const on = !!(adrSel && adrSel.value);
    if (pgWrap) { pgWrap.style.opacity = on ? '1' : '0.4'; pgWrap.style.pointerEvents = on ? 'auto' : 'none'; }
    const lbl  = pgWrap?.querySelector('label');
    const star = lbl?.querySelector('.required');
    if (on && !star && lbl) { const s = document.createElement('span'); s.className = 'required'; s.textContent = ' *'; lbl.appendChild(s); }
    else if (!on && star) star.remove();
  }
  if (adrSel) adrSel.addEventListener('change', toggleAdr);

  /* ── Foto drop zone ──────────────────────────────────────────────────── */
  const fotoInput   = document.getElementById('foto-input');
  const dropZone    = document.getElementById('foto-drop-zone');
  const prevWrap    = document.getElementById('foto-preview');
  const prevImg     = document.getElementById('foto-preview-img');
  const prevName    = document.getElementById('foto-preview-name');
  const btnChoose   = document.getElementById('btn-choose-foto');
  const btnRemove   = document.getElementById('btn-remove-foto');
  const fotoCurrent = document.getElementById('foto-current');

  function applyPreview(file) {
    const r = new FileReader();
    r.onload = e => {
      if (prevImg)  prevImg.src = e.target.result;
      if (prevName) prevName.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
      prevWrap?.classList.remove('d-none');
      dropZone?.classList.add('d-none');
      if (fotoCurrent) fotoCurrent.style.display = 'none';
    };
    r.readAsDataURL(file);
  }
  fotoInput?.addEventListener('change', function () { if (this.files[0]) applyPreview(this.files[0]); });
  btnChoose?.addEventListener('click', () => fotoInput?.click());
  btnRemove?.addEventListener('click', () => {
    if (fotoInput) fotoInput.value = '';
    prevWrap?.classList.add('d-none');
    dropZone?.classList.remove('d-none');
    if (fotoCurrent) fotoCurrent.style.display = '';
  });
  if (dropZone) {
    dropZone.addEventListener('click', () => fotoInput?.click());
    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.style.borderColor = 'var(--qk-primary)'; });
    dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
    dropZone.addEventListener('drop', e => {
      e.preventDefault(); dropZone.style.borderColor = '';
      const f = e.dataTransfer?.files[0];
      if (f && fotoInput) { const dt = new DataTransfer(); dt.items.add(f); fotoInput.files = dt.files; applyPreview(f); }
    });
  }

  /* ── Crea categoria AJAX ─────────────────────────────────────────────── */
  const btnCrea  = document.getElementById('btn-crea-cat');
  const catNome  = document.getElementById('new-cat-nome');
  const catErr   = document.getElementById('new-cat-error');
  const modalCat = document.getElementById('modal-new-cat');
  const API_CAT  = QkPF.apiCat;

  modalCat?.addEventListener('shown.bs.modal', () => catNome?.focus());
  modalCat?.addEventListener('hidden.bs.modal', () => {
    if (catNome) catNome.value = '';
    if (catErr) { catErr.textContent = ''; catErr.classList.add('d-none'); }
  });
  catNome?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); btnCrea?.click(); } });
  btnCrea?.addEventListener('click', async function () {
    const nome = catNome?.value.trim();
    if (!nome) {
      if (catErr) { catErr.textContent = 'Il nome è obbligatorio.'; catErr.classList.remove('d-none'); }
      catNome?.focus(); return;
    }
    this.disabled = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>';
    try {
      const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('nome', nome);
      const res = await fetch(API_CAT, { method: 'POST', body: fd });
      const out = await res.json();
      if (out.ok) {
        // FIX #2: aggiorna il picker M2O e aggiungi la categoria alla cache interna
        // così è immediatamente trovabile senza round-trip al server
        if (catM2O) {
          catM2O.setValue(out.id, out.nome ?? out.label);
          // Inserisce nella cache interna del QkM2O per la ricerca inline
          if (Array.isArray(catM2O._rows)) {
            catM2O._rows.push({ id: out.id, nome: out.nome ?? out.label });
          }
        } else {
          document.getElementById('cat-id-input').value    = out.id;
          document.getElementById('cat-label-input').value = out.nome ?? out.label;
          document.getElementById('cat-clear-btn')?.classList.remove('d-none');
        }
        bootstrap.Modal.getInstance(modalCat)?.hide();
      } else {
        if (catErr) { catErr.textContent = out.error || 'Errore.'; catErr.classList.remove('d-none'); }
      }
    } catch { if (catErr) { catErr.textContent = 'Errore di rete.'; catErr.classList.remove('d-none'); } }
    finally { this.disabled = false; this.innerHTML = '<i class="fa-solid fa-plus me-1"></i>Crea'; }
  });

  /* ── Categoria — QkM2O (init rimandato a DOMContentLoaded) ─────────── */
  let catM2O = null;

if (QkPF.isEdit) {
  /* ══════════════════════════════════════════════════════════════════════
     FORNITORI — gestione metadata fornitore (modale leggero)
     I prezzi/scaglioni sono gestiti da products-suppliers.js nel tab.
     Qui gestiamo solo: apertura modale, salvataggio metadata, chiusura.
     Dopo ogni save/delete viene emesso un evento custom (qk:forn-saved /
     qk:forn-deleted) che products-suppliers.js ascolta per aggiornare
     la tabella senza ricaricare la pagina.
  ══════════════════════════════════════════════════════════════════════ */
  const API_FORN_SAVE = QkPF.apiFornSave;
  const PRODOTTO_ID   = QkPF.prodottoId;

  const modalForn    = document.getElementById('modal-fornitore');
  const mfId         = document.getElementById('mf-id');
  const mfFornId     = document.getElementById('mf-fornitore-id');
  const mfSku        = document.getElementById('mf-sku');
  const mfNomeProd   = document.getElementById('mf-nome-prod-forn');
  const mfQtaMin     = document.getElementById('mf-qta-min');
  const mfOrdinePref = document.getElementById('mf-ordine-pref');
  const mfAttivo     = document.getElementById('mf-attivo');
  const mfRrpMin     = document.getElementById('mf-rrp-min');
  const mfRrpMax     = document.getElementById('mf-rrp-max');
  const mfLeadTime   = document.getElementById('mf-lead-time');
  const mfNote       = document.getElementById('mf-note');
  const mfError      = document.getElementById('mf-error');
  const mfErrorMsg   = document.getElementById('mf-error-msg');
  const btnSalvaForn = document.getElementById('btn-salva-forn');

  const mfShowErr = msg => { if (mfErrorMsg) mfErrorMsg.textContent = msg; mfError?.classList.remove('d-none'); };
  const mfHideErr = ()  => mfError?.classList.add('d-none');

  let mfM2O = null;

  /* ── Popola il modale fornitore con i dati del bottone trigger ───── */
  modalForn?.addEventListener('show.bs.modal', function (e) {
    const btn  = e.relatedTarget;
    const mode = btn?.dataset.mode ?? 'create';
    const title = modalForn.querySelector('.modal-title');

    mfHideErr();

    if (mode === 'edit') {
      title.textContent = 'Modifica fornitore';
      mfId.value        = btn.dataset.id;
      mfM2O?.setValue(btn.dataset.fornitoreId, btn.dataset.fornitoreLabel);
      mfSku.value       = btn.dataset.skuFornitore      ?? '';
      mfNomeProd.value  = btn.dataset.nomeProdottoFornitore ?? '';
      mfQtaMin.value    = btn.dataset.qtaMin            ?? '';
      if (mfOrdinePref) mfOrdinePref.value = btn.dataset.ordinePref || '1';
      mfAttivo.checked  = btn.dataset.attivo === '1';
      if (mfRrpMin)   mfRrpMin.value   = btn.dataset.rrpMin   ?? '';
      if (mfRrpMax)   mfRrpMax.value   = btn.dataset.rrpMax   ?? '';
      if (mfLeadTime) mfLeadTime.value = btn.dataset.leadTime ?? '';
      if (mfNote)     mfNote.value     = btn.dataset.note     ?? '';
    } else {
      title.textContent = 'Aggiungi fornitore';
      [mfId, mfFornId, mfSku, mfNomeProd,
       mfQtaMin, mfRrpMin, mfRrpMax, mfLeadTime, mfNote]
        .forEach(el => { if (el) el.value = ''; });
      mfM2O?.clear();
      if (mfOrdinePref) mfOrdinePref.value = '1';
      mfAttivo.checked = true;
    }
  });

  /* ── Salva fornitore (metadata): invia AJAX, emette qk:forn-saved ── */
  btnSalvaForn?.addEventListener('click', async function () {
    mfHideErr();
    if (!mfFornId?.value) { mfShowErr('Seleziona un fornitore.'); return; }

    this.disabled  = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Salvataggio…';

    try {
      const fd = new FormData();
      fd.append('csrf_token',                   CSRF);
      fd.append('prodotto_id',                  PRODOTTO_ID);
      fd.append('id',                           mfId.value);
      fd.append('fornitore_id',                 mfFornId.value);
      fd.append('sku_fornitore',                mfSku.value);
      fd.append('nome_prodotto_fornitore',      mfNomeProd.value);
      fd.append('quantita_minima',              mfQtaMin.value);
      fd.append('ordine_preferenza_fornitore',  mfOrdinePref ? mfOrdinePref.value : '1');
      fd.append('attivo',                       mfAttivo.checked ? '1' : '0');
      fd.append('rrp_min',                      mfRrpMin ? mfRrpMin.value : '');
      fd.append('rrp_max',                      mfRrpMax ? mfRrpMax.value : '');
      fd.append('lead_time_giorni',             mfLeadTime ? mfLeadTime.value : '');
      fd.append('note',                         mfNote ? mfNote.value : '');
      /* I prezzi sono gestiti da products-suppliers.js — non toccarli qui */
      fd.append('no_prezzi', '1');

      const res = await fetch(API_FORN_SAVE, { method: 'POST', body: fd, credentials: 'same-origin' });
      const out = await res.json();

      if (out.ok && out.row) {
        /* Notifica products-suppliers.js (ascolta 'qk:forn-saved') */
        document.dispatchEvent(new CustomEvent('qk:forn-saved', {
          detail: { row: out.row },
        }));
        bootstrap.Modal.getInstance(modalForn)?.hide();
      } else {
        mfShowErr(out.error || 'Errore nel salvataggio.');
      }
    } catch {
      mfShowErr('Errore di rete.');
    } finally {
      this.disabled  = false;
      this.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Salva';
    }
  });

  /* ══════════════════════════════════════════════════════════════════════
     KIT — gestione componenti
  ══════════════════════════════════════════════════════════════════════ */
  const API_KIT_SAVE   = QkPF.apiKitSave;
  const API_KIT_DELETE = QkPF.apiKitDelete;
  const KIT_ID         = QkPF.prodottoId;

  const modalComp      = document.getElementById('modal-componente');
  const mcProdId       = document.getElementById('mc-prodotto-id');
  const mcQta          = document.getElementById('mc-quantita');
  const mcNote         = document.getElementById('mc-note');
  const mcError        = document.getElementById('mc-error');
  const mcErrorMsg     = document.getElementById('mc-error-msg');
  const mcEditInfo     = document.getElementById('mc-edit-info');
  const btnSalvaComp   = document.getElementById('btn-salva-componente');
  let   mcM2O          = null;
  let   mcEditMode     = false; // in edit mode il prodotto è bloccato

  const mcShowErr = msg => { if (mcErrorMsg) mcErrorMsg.textContent = msg; mcError?.classList.remove('d-none'); };
  const mcHideErr = ()  => mcError?.classList.add('d-none');

  /* ── Costruisce HTML riga componente ─────────────────────────────────── */
  function buildKitRow(row) {
    const dash = '<span class="qk-text-muted">—</span>';
    return `<tr id="kit-row-${row.componente_id}">
      <td><span class="fw-semibold">${esc(row.nome)}</span></td>
      <td class="font-monospace">${esc(row.sku_interno)}</td>
      <td class="text-end font-monospace">${fmtNum(row.quantita, 3)}</td>
      <td>${row.note ? esc(row.note) : dash}</td>
      <td class="col-actions">
        <div class="d-flex gap-1 justify-content-end">
          <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm btn-edit-componente"
                  data-componente-id="${row.componente_id}"
                  data-nome="${escA(row.nome)}"
                  data-sku="${escA(row.sku_interno)}"
                  data-quantita="${escA(row.quantita)}"
                  data-note="${escA(row.note ?? '')}">
            <i class="fa-solid fa-pen"></i>
          </button>
          <button type="button" class="btn-qk btn-qk-danger btn-qk-sm btn-delete-componente"
                  data-componente-id="${row.componente_id}"
                  data-nome="${escA(row.nome)}">
            <i class="fa-solid fa-trash"></i>
          </button>
        </div>
      </td>
    </tr>`;
  }

  /* ── Aggiorna badge tab kit ────────────────────────────────────────── */
  function updateKitBadge(delta) {
    const badge = document.getElementById('badge-kit');
    if (!badge) return;
    const n = Math.max(0, parseInt(badge.textContent || '0') + delta);
    badge.textContent = n;
    n > 0 ? badge.classList.remove('d-none') : badge.classList.add('d-none');
  }

  /* ── Assicura che la tabella kit esista nel DOM ──────────────────────── */
  function ensureKitTable() {
    let tbody = document.getElementById('kit-tbody');
    if (tbody) return tbody;
    document.getElementById('kit-empty')?.remove();
    document.getElementById('kit-table-wrap').innerHTML = `<table class="qk-table" id="kit-table">
      <thead><tr>
        <th>Prodotto</th><th>SKU</th>
        <th style="width:130px" class="text-end">Quantità</th>
        <th>Note</th><th class="col-actions"></th>
      </tr></thead>
      <tbody id="kit-tbody"></tbody>
    </table>`;
    return document.getElementById('kit-tbody');
  }

  /* ── Apri modal "Aggiungi componente" ─────────────────────────────── */
  document.getElementById('btn-add-componente')?.addEventListener('click', () => {
    if (!modalComp) return;
    mcEditMode = false;
    mcHideErr();
    if (mcProdId)  mcProdId.value  = '';
    if (mcQta)     mcQta.value     = '';
    if (mcNote)    mcNote.value    = '';
    mcM2O?.clear();
    // Riabilita il picker prodotto in modalità creazione
    document.getElementById('mc-prodotto-label')?.removeAttribute('readonly');
    document.getElementById('mc-m2o-wrap')?.removeAttribute('style');
    mcEditInfo?.classList.add('d-none');
    document.getElementById('modal-componente').querySelector('.modal-title').textContent = 'Aggiungi componente';
    bootstrap.Modal.getOrCreateInstance(modalComp).show();
  });

  /* ── Event delegation: edit componente ──────────────────────────────── */
  document.getElementById('kit-table-wrap')?.addEventListener('click', function(e) {
    const btnEdit = e.target.closest('.btn-edit-componente');
    const btnDel  = e.target.closest('.btn-delete-componente');

    if (btnEdit) {
      mcEditMode = true;
      mcHideErr();
      if (mcProdId) mcProdId.value = btnEdit.dataset.componenteId;
      if (mcQta)    mcQta.value    = btnEdit.dataset.quantita;
      if (mcNote)   mcNote.value   = btnEdit.dataset.note;
      // Blocca il picker prodotto in edit — solo qty e note modificabili
      mcM2O?.setValue(btnEdit.dataset.componenteId, btnEdit.dataset.nome + ' [' + btnEdit.dataset.sku + ']');
      document.getElementById('mc-prodotto-label')?.setAttribute('readonly', 'readonly');
      document.getElementById('mc-m2o-wrap')?.setAttribute('style', 'pointer-events:none;opacity:.6');
      mcEditInfo?.classList.remove('d-none');
      document.getElementById('modal-componente').querySelector('.modal-title').textContent = 'Modifica quantità';
      bootstrap.Modal.getOrCreateInstance(modalComp).show();
    }

    if (btnDel) {
      const nome = btnDel.dataset.nome;
      const compId = btnDel.dataset.componenteId;
      if (!confirm(`Rimuovere «${nome}» dal kit?`)) return;
      (async () => {
        try {
          const fd = new FormData();
          fd.append('csrf_token',    CSRF);
          fd.append('kit_id',        KIT_ID);
          fd.append('componente_id', compId);
          const res = await fetch(API_KIT_DELETE, { method: 'POST', body: fd });
          const out = await res.json();
          if (out.ok) {
            document.getElementById('kit-row-' + compId)?.remove();
            updateKitBadge(-1);
            const tbody = document.getElementById('kit-tbody');
            if (tbody && !tbody.querySelector('tr')) {
              const wrap = document.getElementById('kit-table-wrap');
              document.getElementById('kit-table')?.remove();
              if (wrap) wrap.innerHTML = '<div class="qk-empty" id="kit-empty">'
                + '<i class="fa-solid fa-boxes-stacked"></i>'
                + '<p>Nessun componente ancora.</p></div>';
            }
          } else { alert(out.error || 'Errore nella rimozione.'); }
        } catch { alert('Errore di rete.'); }
      })();
    }
  });

  /* ── Salva componente ────────────────────────────────────────────────── */
  btnSalvaComp?.addEventListener('click', async function () {
    mcHideErr();
    if (!mcProdId?.value) { mcShowErr('Seleziona un prodotto.'); return; }
    if (!mcQta?.value || parseFloat(mcQta.value) <= 0) { mcShowErr('Inserisci una quantità valida (> 0).'); return; }
    this.disabled = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Salvataggio…';
    try {
      const fd = new FormData();
      fd.append('csrf_token',    CSRF);
      fd.append('kit_id',        KIT_ID);
      fd.append('componente_id', mcProdId.value);
      fd.append('quantita',      mcQta.value);
      fd.append('note',          mcNote?.value ?? '');

      const res = await fetch(API_KIT_SAVE, { method: 'POST', body: fd });
      const out = await res.json();

      if (out.ok && out.row) {
        const tbody = ensureKitTable();
        const existing = document.getElementById('kit-row-' + out.row.componente_id);
        const rowHtml  = buildKitRow(out.row);
        if (existing) {
          existing.outerHTML = rowHtml;
        } else {
          tbody.insertAdjacentHTML('beforeend', rowHtml);
          updateKitBadge(+1);
        }
        bootstrap.Modal.getInstance(modalComp)?.hide();
      } else {
        mcShowErr(out.error || 'Errore nel salvataggio.');
      }
    } catch { mcShowErr('Errore di rete.'); }
    finally { this.disabled = false; this.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Salva'; }
  });

}

  /* ══════════════════════════════════════════════════════════════════════
     QkM2O init — rimandato a DOMContentLoaded
  ══════════════════════════════════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof QkM2O === 'undefined') return;

    /* ── Categoria (sempre, sia create che edit) ────────────────────── */
    const catFullPicker = new QkPicker({
      title:       'Cerca: Categoria',
      apiUrl:      QkPF.apiSearchCat,
      columns:     [{ key: 'nome', label: 'Nome categoria' }],
      onSelect:    (id, row) => catM2O?.setValue(id, row.nome ?? ''),
      allowCreate: true,
      onCreate:    () => bootstrap.Modal.getOrCreateInstance(
                     document.getElementById('modal-new-cat')
                   ).show(),
    });

    catM2O = new QkM2O({
      inputEl:    document.getElementById('cat-label-input'),
      hiddenEl:   document.getElementById('cat-id-input'),
      clearBtn:   document.getElementById('cat-clear-btn'),
      dropdownEl: document.getElementById('cat-m2o-dd'),
      apiUrl:     QkPF.apiSearchCat,
      labelKey:   'nome',
      columns:    [{ key: 'nome', label: 'Nome' }],
      picker:     catFullPicker,
      onCreate:   () => bootstrap.Modal.getOrCreateInstance(
                    document.getElementById('modal-new-cat')
                  ).show(),
    });

    if (QkPF.isEdit) {

      /* ── Fornitore (picker nel modale fornitore) ──────────────────── */
      const mfFullPicker = new QkPicker({
        title:       'Cerca: Fornitore',
        apiUrl:      QkPF.apiSearchForn,
        columns:     [
          { key: 'ragione_sociale', label: 'Ragione sociale' },
          { key: 'email',           label: 'E-mail' },
        ],
        allowCreate: true,
        createUrl:   QkPF.suppliersCreateUrl,
        onSelect:    (id, row) => mfM2O?.setValue(id, row.ragione_sociale ?? ''),
      });

      /* Il modale fornitore non gestisce più prezzi/listini (rinviati al tab
         products-suppliers.js), quindi la M2O non ha hook onSelect/onClear. */
      mfM2O = new QkM2O({
        inputEl:    document.getElementById('mf-fornitore-label'),
        hiddenEl:   document.getElementById('mf-fornitore-id'),
        clearBtn:   document.getElementById('mf-fornitore-clear'),
        dropdownEl: document.getElementById('mf-m2o-dd'),
        apiUrl:     QkPF.apiSearchForn,
        labelKey:   'ragione_sociale',
        columns:    [{ key: 'ragione_sociale', label: 'Ragione sociale' }, { key: 'email', label: 'E-mail' }],
        picker:     mfFullPicker,
      });

      /* ── Prodotto picker (modale componente kit) ──────────────────── */
      const mcFullPicker = new QkPicker({
        title:    'Cerca: Prodotto',
        apiUrl:   QkPF.apiSearchProdExclude,
        columns:  [
          { key: 'nome',        label: 'Nome' },
          { key: 'sku_interno', label: 'SKU' },
        ],
        onSelect: (id, row) => mcM2O?.setValue(id, row.nome ?? ''),
      });

      mcM2O = new QkM2O({
        inputEl:    document.getElementById('mc-prodotto-label'),
        hiddenEl:   document.getElementById('mc-prodotto-id'),
        clearBtn:   document.getElementById('mc-prodotto-clear'),
        dropdownEl: document.getElementById('mc-m2o-dd'),
        apiUrl:     QkPF.apiSearchProdExclude,
        labelKey:   'nome',
        columns:    [{ key: 'nome', label: 'Nome' }, { key: 'sku_interno', label: 'SKU' }],
        picker:     mcFullPicker,
      });

    }

    /* ── UoM: azzera "quantità per confezione" al cambio di Stock UoM ─────
     * (REVISIONE §6.3 Problema 2, punto 3): se il prodotto ha già una UoM
     * commerciale configurata, il vecchio fattore di conversione non è valido
     * per la nuova Stock UoM. Costringiamo l'utente a re-inserirlo
     * consapevolmente invece di lasciare per sbaglio il vecchio numero — che
     * il server rifiuterebbe comunque (vedi guardia in qk_update_prodotto()).
     */
    (function () {
      const selUm = document.getElementById('sel-um');
      const qtaContenutoInput = document.querySelector('input[name="quantita_contenuto"]');
      if (!selUm || !qtaContenutoInput) return;

      const stockUomOriginale = QkPF.uomStock;
      let avvisoUomEl = null;

      selUm.addEventListener('change', function () {
        const cambiata = selUm.value !== stockUomOriginale;
        const haUnitaCommerciale = !!QkPF.uomCommercialId;

        if (cambiata && haUnitaCommerciale) {
          qtaContenutoInput.value = '';
          if (!avvisoUomEl) {
            avvisoUomEl = document.createElement('div');
            avvisoUomEl.className = 'qk-form-hint text-danger';
            avvisoUomEl.style.marginTop = '4px';
            avvisoUomEl.textContent = "Hai cambiato l'unità di stock: inserisci di nuovo quanta unità commerciale corrisponde a 1 unità della nuova unità di stock.";
            qtaContenutoInput.insertAdjacentElement('afterend', avvisoUomEl);
          }
          avvisoUomEl.style.display = '';
        } else if (avvisoUomEl) {
          avvisoUomEl.style.display = 'none';
        }
      });
    })();
  });

})();
