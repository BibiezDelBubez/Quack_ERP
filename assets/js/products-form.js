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

  /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     TAG — M2M chip field stile Odoo
  â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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
  const fotoInput        = document.getElementById('foto-input');
  const dropZone         = document.getElementById('foto-drop-zone');
  const prevWrap         = document.getElementById('foto-preview');
  const prevImg          = document.getElementById('foto-preview-img');
  const prevName         = document.getElementById('foto-preview-name');
  const btnChoose        = document.getElementById('btn-choose-foto');
  const btnChooseLabel   = document.getElementById('btn-choose-foto-label');
  const btnRemove        = document.getElementById('btn-remove-foto');
  const fotoCurrent      = document.getElementById('foto-current');
  const rimuoviFotoInput = document.getElementById('rimuovi-foto-input');

  function applyPreview(file) {
    const r = new FileReader();
    r.onload = e => {
      if (prevImg)  prevImg.src = e.target.result;
      if (prevName) prevName.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
      prevWrap?.classList.remove('d-none');
      dropZone?.classList.add('d-none');
      if (fotoCurrent) fotoCurrent.style.display = 'none';
      if (btnRemove) btnRemove.classList.remove('d-none');
      if (btnChooseLabel) btnChooseLabel.textContent = 'Cambia foto';
      if (rimuoviFotoInput) rimuoviFotoInput.value = '0';
    };
    r.readAsDataURL(file);
  }
  fotoInput?.addEventListener('change', function () { if (this.files[0]) applyPreview(this.files[0]); });
  btnChoose?.addEventListener('click', () => fotoInput?.click());
  btnRemove?.addEventListener('click', () => {
    if (fotoInput) fotoInput.value = '';
    prevWrap?.classList.add('d-none');
    if (fotoCurrent) fotoCurrent.style.display = 'none';
    dropZone?.classList.remove('d-none');
    btnRemove?.classList.add('d-none');
    if (btnChooseLabel) btnChooseLabel.textContent = 'Scegli foto';
    if (rimuoviFotoInput) rimuoviFotoInput.value = '1';
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
  /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     FORNITORI — gestione metadata fornitore (modale leggero)
     I prezzi/scaglioni sono gestiti da products-suppliers.js nel tab.
     Qui gestiamo solo: apertura modale, salvataggio metadata, chiusura.
     Dopo ogni save/delete viene emesso un evento custom (qk:forn-saved /
     qk:forn-deleted) che products-suppliers.js ascolta per aggiornare
     la tabella senza ricaricare la pagina.
  â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

  /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     KIT — gestione componenti
  â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

  /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     QkM2O init — rimandato a DOMContentLoaded
  â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

/* ----------------------------------------------------------------------
   Estratto da _form.php (Matrix Pricing)
---------------------------------------------------------------------- */
(function() {
  const table = document.getElementById('pricing-matrix-table');
  if (!table) return;
  const prodottoId = window.QkPF.prodottoId;
  const csrfToken = window.QkPF.csrfToken;
  const baseUrl = window.QkPF.baseUrl;
  
  const tab1BaseInput = document.querySelector('input[name="prezzo_vendita_base"]');
  let currentSalesUomMode = 'stock';
  const salesUomModeSelect = document.getElementById('sales-uom-mode');

  function updatePlaceholders() {
    const qFactor = parseFloat(window.QkPF.quantitaContenuto) || 1;
    const hasComm = !!window.QkPF.uomCommercialId && qFactor > 1;

    let standardBaseVal = 0;
    if (tab1BaseInput) {
      standardBaseVal = parseFloat(tab1BaseInput.value) || 0;
    } else {
      const standardRow = table.querySelector('.listino-row[data-listino-id="1"]');
      if (standardRow) {
        const standardBaseCell = standardRow.querySelector('.matrix-cell-wrapper[data-qty="0"]');
        standardBaseVal = parseFloat(standardBaseCell.dataset.stockPrice) || 0;
      }
    }

    const rows = table.querySelectorAll('.listino-row');
    rows.forEach(row => {
      const lid = row.dataset.listinoId;
      const isStandard = (lid === "1");
      
      const baseCell = row.querySelector('.matrix-cell-wrapper[data-qty="0"]');
      if (!baseCell) return;
      const baseValStr = baseCell.dataset.stockPrice || '';
      const listinoBaseVal = baseValStr !== '' ? parseFloat(baseValStr) : (isStandard ? standardBaseVal : null);

      if (!isStandard) {
        const baseInput = baseCell.querySelector('.cell-price-input');
        if (baseValStr === '') {
          let dispVal = standardBaseVal;
          if (currentSalesUomMode === 'commercial' && hasComm) {
            dispVal = standardBaseVal / qFactor;
          }
          baseInput.placeholder = dispVal.toFixed(4) + ' (Base)';
        } else {
          baseInput.placeholder = "—";
        }
      }

      const cells = Array.from(row.querySelectorAll('.matrix-cell-wrapper[data-qty]:not([data-qty="0"])'))
        .sort((a, b) => parseFloat(a.dataset.qty) - parseFloat(b.dataset.qty));

      let lastVal = listinoBaseVal !== null ? listinoBaseVal : standardBaseVal;

      cells.forEach(cell => {
        const priceInput = cell.querySelector('.cell-price-input');
        const cellValStr = cell.dataset.stockPrice || '';

        let dispVal = lastVal;
        if (currentSalesUomMode === 'commercial' && hasComm) {
          dispVal = lastVal / qFactor;
        }
        priceInput.placeholder = dispVal.toFixed(4) + ' (Ered.)';

        if (cellValStr !== '') {
          lastVal = parseFloat(cellValStr);
        }
      });
    });
  }

  function updateSalesMatrixUom() {
    const qFactor = parseFloat(window.QkPF.quantitaContenuto) || 1;
    const hasComm = !!window.QkPF.uomCommercialId && qFactor > 1;
    
    table.querySelectorAll('.qty-column-header').forEach(th => {
      const baseQty = parseFloat(th.dataset.qty);
      const span = th.querySelector('span');
      if (!span) return;
      
      if (currentSalesUomMode === 'commercial' && hasComm) {
        const commQty = baseQty * qFactor;
        span.textContent = `Qtà ${commQty.toLocaleString('it-IT')} ${window.QkPF.uomCommercial}+`;
      } else {
        span.textContent = `Qtà ${baseQty.toLocaleString('it-IT')} ${window.QkPF.uomStock}+`;
      }
    });

    table.querySelectorAll('.matrix-cell-wrapper').forEach(cell => {
      const priceInput = cell.querySelector('.cell-price-input');
      if (!priceInput) return;

      const rawValStr = cell.dataset.stockPrice || '';
      if (rawValStr !== '') {
        const rawVal = parseFloat(rawValStr);
        if (currentSalesUomMode === 'commercial' && hasComm) {
          priceInput.value = (rawVal / qFactor).toFixed(4);
        } else {
          priceInput.value = rawVal.toFixed(4);
        }
      } else {
        priceInput.value = '';
      }
    });

    updatePlaceholders();
  }

  function cacheCellValues(cell) {
    const priceInput = cell.querySelector('.cell-price-input');
    const price = priceInput ? priceInput.value.trim() : '';
    cell.dataset.stockPrice = price; 
    cell.dataset.lastPrice = price;
  }

  table.querySelectorAll('.matrix-cell-wrapper').forEach(cell => {
    cacheCellValues(cell);
  });

  if (tab1BaseInput) {
    tab1BaseInput.addEventListener('input', function() {
      const standardRow = table.querySelector('.listino-row[data-listino-id="1"]');
      if (standardRow) {
        const standardBaseCell = standardRow.querySelector('.matrix-cell-wrapper[data-qty="0"]');
        const standardBaseInput = standardBaseCell.querySelector('.cell-price-input');
        
        const qFactor = parseFloat(window.QkPF.quantitaContenuto) || 1;
        const hasComm = !!window.QkPF.uomCommercialId && qFactor > 1;

        let val = parseFloat(tab1BaseInput.value) || 0;
        standardBaseCell.dataset.stockPrice = val.toFixed(4);
        
        if (currentSalesUomMode === 'commercial' && hasComm) {
          standardBaseInput.value = (val / qFactor).toFixed(4);
        } else {
          standardBaseInput.value = val.toFixed(4);
        }
        
        updatePlaceholders();
      }
    });

    tab1BaseInput.addEventListener('change', function() {
      const standardRow = table.querySelector('.listino-row[data-listino-id="1"]');
      if (standardRow) {
        const cell = standardRow.querySelector('.matrix-cell-wrapper[data-qty="0"]');
        handleCellAutosave(cell);
      }
    });
  }

  table.addEventListener('focusout', function(e) {
    const input = e.target.closest('.cell-price-input');
    if (input) {
      const cell = input.closest('.matrix-cell-wrapper');
      const row = cell.closest('.listino-row');
      if (row.dataset.listinoId === "1" && cell.dataset.qty === "0" && tab1BaseInput) {
        const qFactor = parseFloat(window.QkPF.quantitaContenuto) || 1;
        const hasComm = !!window.QkPF.uomCommercialId && qFactor > 1;
        let displayVal = parseFloat(input.value) || 0;
        if (currentSalesUomMode === 'commercial' && hasComm) {
          tab1BaseInput.value = (displayVal * qFactor).toFixed(4);
        } else {
          tab1BaseInput.value = displayVal.toFixed(4);
        }
      }
      handleCellAutosave(cell);
    }
  });

  table.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      const input = e.target.closest('.cell-price-input');
      if (input) {
        input.blur();
        e.preventDefault();
      }
    }
  });

  async function handleCellAutosave(cell) {
    const row = cell.closest('.listino-row');
    const listinoId = row.dataset.listinoId;
    const qty = cell.dataset.qty;
    const rigaId = cell.dataset.rigaId;

    const priceInput = cell.querySelector('.cell-price-input');
    const displayVal = priceInput.value.trim();
    
    const qFactor = parseFloat(window.QkPF.quantitaContenuto) || 1;
    const hasComm = !!window.QkPF.uomCommercialId && qFactor > 1;

    let priceVal = displayVal;
    if (displayVal !== '') {
      const parsed = parseFloat(displayVal);
      if (currentSalesUomMode === 'commercial' && hasComm) {
        priceVal = (parsed * qFactor).toFixed(4);
      } else {
        priceVal = parsed.toFixed(4);
      }
    }
    
    cell.dataset.stockPrice = priceVal;

    const prevPrice = cell.dataset.lastPrice || '';
    if (priceVal === prevPrice) {
      return; 
    }

    if (priceVal === '') {
      if (rigaId && rigaId !== "0" && rigaId !== "-1") {
        cell.classList.add('saving');
        try {
          const fd = new FormData();
          fd.append('riga_id', rigaId);
          fd.append('csrf_token', csrfToken);

          const res = await fetch(`${baseUrl}/sales/pricelists/righe/delete`, {
            method: 'POST',
            body: fd
          });
          const data = await res.json();
          if (data.ok) {
            cell.classList.remove('saving');
            cell.classList.add('saved');
            cell.dataset.rigaId = "0";
            cacheCellValues(cell);
            setTimeout(() => cell.classList.remove('saved'), 1500);
            updatePlaceholders();
          } else {
            cell.classList.remove('saving');
            cell.classList.add('error');
            alert(data.error || 'Errore durante la cancellazione.');
          }
        } catch (err) {
          cell.classList.remove('saving');
          cell.classList.add('error');
        }
      }
      return;
    }

    if (parseFloat(priceVal) < 0) {
      cell.classList.add('error');
      alert('Il prezzo deve essere maggiore o uguale a 0.');
      return;
    }

    cell.classList.remove('error');
    cell.classList.add('saving');

    try {
      const fd = new FormData();
      fd.append('listino_id', listinoId);
      fd.append('riga_id', (rigaId === "-1" || rigaId === "0") ? '0' : rigaId);
      fd.append('prodotto_id', prodottoId);
      fd.append('quantita_minima', qty);
      fd.append('prezzo_unitario', priceVal);
      fd.append('sconto_perc', '0');
      fd.append('note', '');
      fd.append('csrf_token', csrfToken);

      const res = await fetch(`${baseUrl}/sales/pricelists/righe/save`, {
        method: 'POST',
        body: fd
      });
      const data = await res.json();

      if (data.ok) {
        cell.classList.remove('saving');
        cell.classList.add('saved');
        
        if (rigaId !== "-1") {
          cell.dataset.rigaId = data.riga.id;
        }
        cacheCellValues(cell);

        setTimeout(() => cell.classList.remove('saved'), 1500);
        updatePlaceholders();
      } else {
        cell.classList.remove('saving');
        cell.classList.add('error');
        alert(data.error || 'Errore durante il salvataggio.');
      }
    } catch (err) {
      cell.classList.remove('saving');
      cell.classList.add('error');
    }
  }

  document.getElementById('btn-add-qty-column')?.addEventListener('click', function() {
    const qFactor = parseFloat(window.QkPF.quantitaContenuto) || 1;
    const hasComm = !!window.QkPF.uomCommercialId && qFactor > 1;
    const unitText = (currentSalesUomMode === 'commercial' && hasComm) ? window.QkPF.uomCommercial : window.QkPF.uomStock;
    
    if (table.querySelector('thead th input.qty-inline-input')) return;

    const theadRow = table.querySelector('thead tr');
    const th = document.createElement('th');
    th.classList.add('qty-column-header', 'temp-qty-column');
    th.style.width = "200px";
    
    th.innerHTML = `
      <div class="d-flex align-items-center justify-content-between">
        <div class="input-group input-group-sm">
          <input type="number" step="any" min="0" class="form-control qty-inline-input" placeholder="Qtà (${escapeHtml(unitText)})" style="min-width: 80px;">
          <button class="btn btn-outline-success btn-confirm-qty" type="button"><i class="fa-solid fa-check"></i></button>
          <button class="btn btn-outline-danger btn-cancel-qty" type="button"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>
    `;

    theadRow.appendChild(th);
    const input = th.querySelector('.qty-inline-input');
    input.focus();

    const rows = table.querySelectorAll('.listino-row');
    rows.forEach(row => {
      const td = document.createElement('td');
      td.classList.add('temp-qty-cell');
      row.appendChild(td);
    });

    const confirmFn = () => {
      const qtyStr = input.value;
      if (!qtyStr) return cancelFn();
      let qty = parseFloat(qtyStr.replace(',', '.'));
      if (isNaN(qty) || qty <= 0) {
        alert("Quantità non valida. Inserire un numero maggiore di 0.");
        return;
      }
      if (currentSalesUomMode === 'commercial' && hasComm) {
        qty = qty / qFactor;
      }
      if (table.querySelector(`thead th[data-qty="${qty}"]`)) {
        alert("Questo scaglione di quantità esiste già.");
        return;
      }
      
      cancelFn();
      insertQtyColumn(qty);
    };

    const cancelFn = () => {
      th.remove();
      rows.forEach(row => {
        const td = row.querySelector('.temp-qty-cell');
        if (td) td.remove();
      });
    };

    th.querySelector('.btn-confirm-qty').addEventListener('click', confirmFn);
    th.querySelector('.btn-cancel-qty').addEventListener('click', cancelFn);
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); confirmFn(); }
      if (e.key === 'Escape') cancelFn();
    });
  });

  function insertQtyColumn(qty) {
    const theadRow = table.querySelector('thead tr');
    const headers = Array.from(theadRow.querySelectorAll('th.qty-column-header'));
    
    let insertIndex = headers.length;
    for (let i = 0; i < headers.length; i++) {
      if (parseFloat(headers[i].dataset.qty) > qty) {
        insertIndex = i;
        break;
      }
    }

    const qFactor = parseFloat(window.QkPF.quantitaContenuto) || 1;
    const hasComm = !!window.QkPF.uomCommercialId && qFactor > 1;
    let headerText = `Qtà ${qty.toLocaleString('it-IT')} ${window.QkPF.uomStock}+`;
    if (currentSalesUomMode === 'commercial' && hasComm) {
      headerText = `Qtà ${(qty * qFactor).toLocaleString('it-IT')} ${window.QkPF.uomCommercial}+`;
    }

    const th = document.createElement('th');
    th.classList.add('qty-column-header');
    th.dataset.qty = qty;
    th.style.width = "200px";
    th.innerHTML = `
      <div class="d-flex align-items-center justify-content-between">
        <span>${headerText}</span>
        <button type="button" class="btn-delete-qty-column" data-qty="${qty}" title="Elimina questo scaglione per tutti i listini">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    `;

    const refHeader = headers[insertIndex] || theadRow.querySelector('th:last-child');
    theadRow.insertBefore(th, refHeader);

    const rows = table.querySelectorAll('.listino-row');
    rows.forEach(row => {
      const valuta = row.dataset.valuta;
      const td = document.createElement('td');
      td.innerHTML = `
        <div class="matrix-cell-wrapper" data-qty="${qty}" data-riga-id="0">
          <div class="matrix-price-input-group">
            <span class="matrix-price-addon">${escapeHtml(valuta)}</span>
            <input type="number" class="matrix-input-price cell-price-input"
                   step="0.0001" min="0" placeholder="Ereditato..." value="">
          </div>
        </div>
      `;

      const refCell = row.cells[insertIndex + 2] || row.querySelector('td:last-child');
      row.insertBefore(td, refCell);

      cacheCellValues(td.querySelector('.matrix-cell-wrapper'));
    });

    updatePlaceholders();
    if (typeof window.qkMakeColumnsResizable === 'function') {
      window.qkMakeColumnsResizable(table);
    } else {
      document.addEventListener('DOMContentLoaded', () => {
        window.qkMakeColumnsResizable?.(table);
      });
    }
  }

  table.addEventListener('click', async function(e) {
    const delBtn = e.target.closest('.btn-delete-qty-column');
    if (!delBtn) return;
    
    const qty = delBtn.dataset.qty;
    if (!confirm(`Sei sicuro di voler eliminare lo scaglione quantità ${qty}+ e tutte le sue regole per tutti i listini?`)) {
      return;
    }

    const cellsToDelete = Array.from(table.querySelectorAll(`.matrix-cell-wrapper[data-qty="${qty}"]`))
      .filter(cell => {
        const rid = cell.dataset.rigaId;
        return rid && rid !== "0" && rid !== "-1";
      });

    for (const cell of cellsToDelete) {
      const rigaId = cell.dataset.rigaId;
      const fd = new FormData();
      fd.append('riga_id', rigaId);
      fd.append('csrf_token', csrfToken);
      await fetch(`${baseUrl}/sales/pricelists/righe/delete`, { method: 'POST', body: fd });
    }

    const th = table.querySelector(`thead th[data-qty="${qty}"]`);
    if (th) {
      const headers = Array.from(table.querySelectorAll('thead th'));
      const colIndex = headers.indexOf(th);
      th.remove();

      const rows = table.querySelectorAll('tr');
      rows.forEach(row => {
        if (row.cells[colIndex]) {
          row.cells[colIndex].remove();
        }
      });
    }

    updatePlaceholders();
  });

  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&#039;");
  }

  updatePlaceholders();
  if (typeof window.qkMakeColumnsResizable === 'function') {
    window.qkMakeColumnsResizable(table);
  } else {
    document.addEventListener('DOMContentLoaded', () => {
      window.qkMakeColumnsResizable?.(table);
    });
  }

  salesUomModeSelect?.addEventListener('change', function() {
    currentSalesUomMode = this.value;
    updateSalesMatrixUom();
  });

})();

/* ----------------------------------------------------------------------
   Estratto da _form.php (Compliance)
---------------------------------------------------------------------- */
(function () {
  document.addEventListener('DOMContentLoaded', () => {
    const PRODOTTO_ID  = window.QkPF.prodottoId;
    const CSRF         = window.QkPF.csrfToken;
    const BASE         = window.QkPF.baseUrl;
    const API_LIST     = BASE + '/products/compliance/list';
    const API_ADD      = BASE + '/products/compliance/add';
    const API_REMOVE   = BASE + '/products/compliance/remove';

    const tbody        = document.getElementById('compliance-tbody');
    if (!tbody) return;
    const emptyEl      = document.getElementById('compliance-empty');
    const listWrap     = document.getElementById('compliance-list-wrap');
    const badge        = document.getElementById('badge-compliance');
    const suggestBanner = document.getElementById('compliance-suggest-banner');
    const suggestText  = document.getElementById('compliance-suggest-text');
    const typeSelect   = document.getElementById('compliance-add-type');
    const modalEl      = document.getElementById('modal-compliance-add');
    const modal        = modalEl ? new bootstrap.Modal(modalEl) : null;

    let allTypes       = [];
    let assegnateIds   = new Set();
    let suggerimenti   = [];

    async function load() {
      try {
        const r = await fetch(API_LIST + '?prodotto_id=' + PRODOTTO_ID);
        const d = await r.json();
        if (!d.ok) return;

        allTypes      = d.tutti;
        assegnateIds  = new Set(d.assegnate.map(a => a.restriction_type_id));
        suggerimenti  = d.suggerimenti.filter(s => !assegnateIds.has(s.restriction_type_id));

        renderTable(d.assegnate);
        renderSuggestions();
        populateSelect();
      } catch (e) { console.error('compliance load', e); }
    }

    function renderTable(rows) {
      tbody.innerHTML = '';
      if (rows.length === 0) {
        emptyEl.classList.remove('d-none');
        listWrap.classList.add('d-none');
        badge.classList.add('d-none');
      } else {
        emptyEl.classList.add('d-none');
        listWrap.classList.remove('d-none');
        badge.classList.remove('d-none');
        badge.textContent = rows.length;
        rows.forEach(r => tbody.appendChild(buildRow(r)));
      }
    }

    function buildRow(r) {
      const tr = document.createElement('tr');
      tr.dataset.rtid = r.restriction_type_id;
      tr.innerHTML = `
        <td><strong>${esc(r.name)}</strong></td>
        <td><span class="qk-form-hint">${esc(r.regulatory_ref || '—')}</span></td>
        <td><span class="small" style="color:var(--qk-text-muted)">${esc(r.note || '—')}</span></td>
        <td>
          <button type="button" class="btn-qk btn-qk-danger btn-qk-sm btn-remove-restrizione" title="Rimuovi">
            <i class="fa-solid fa-trash-can"></i>
          </button>
        </td>`;
      return tr;
    }

    function renderSuggestions() {
      if (suggerimenti.length === 0) {
        suggestBanner.classList.add('d-none');
        return;
      }
      const nomi = suggerimenti.map(s => `<strong>${esc(s.name)}</strong>`).join(', ');
      suggestText.innerHTML = `Dal codice ONU di questo prodotto risulta: ${nomi}. Vuoi aggiungerle?`;
      suggestBanner.classList.remove('d-none');
    }

    function populateSelect() {
      typeSelect.innerHTML = '<option value="">— Seleziona —</option>';
      allTypes.filter(t => !assegnateIds.has(t.id)).forEach(t => {
        const o = document.createElement('option');
        o.value = t.id;
        o.textContent = t.name + (t.regulatory_ref ? ' (' + t.regulatory_ref + ')' : '');
        typeSelect.appendChild(o);
      });
    }

    document.getElementById('btn-add-restrizione')?.addEventListener('click', () => {
      document.getElementById('compliance-add-note').value = '';
      modal?.show();
    });

    document.getElementById('btn-compliance-add-confirm')?.addEventListener('click', async () => {
      const rtid = typeSelect.value;
      const note = document.getElementById('compliance-add-note').value.trim();
      if (!rtid) { typeSelect.focus(); return; }

      await addRestrizione(parseInt(rtid), note);
      modal?.hide();
    });

    document.getElementById('btn-apply-suggestions')?.addEventListener('click', async () => {
      for (const s of suggerimenti) {
        await addRestrizione(s.restriction_type_id, '');
      }
    });

    async function addRestrizione(rtid, note) {
      const fd = new FormData();
      fd.append('prodotto_id', PRODOTTO_ID);
      fd.append('restriction_type_id', rtid);
      fd.append('note', note);
      fd.append('csrf_token', CSRF);

      const r = await fetch(API_ADD, { method: 'POST', body: fd });
      const d = await r.json();
      if (d.ok) {
        assegnateIds.add(rtid);
        suggerimenti = suggerimenti.filter(s => s.restriction_type_id !== rtid);
        tbody.appendChild(buildRow(d.restrizione));
        emptyEl.classList.add('d-none');
        listWrap.classList.remove('d-none');
        badge.classList.remove('d-none');
        badge.textContent = assegnateIds.size;
        populateSelect();
        renderSuggestions();
      } else {
        alert(d.error || 'Errore.');
      }
    }

    tbody.addEventListener('click', async function (e) {
      const btn = e.target.closest('.btn-remove-restrizione');
      if (!btn) return;
      const tr   = btn.closest('tr');
      const rtid = parseInt(tr.dataset.rtid);

      if (!confirm('Rimuovere questa restrizione dal prodotto?')) return;

      const fd = new FormData();
      fd.append('prodotto_id', PRODOTTO_ID);
      fd.append('restriction_type_id', rtid);
      fd.append('csrf_token', CSRF);

      const r = await fetch(API_REMOVE, { method: 'POST', body: fd });
      const d = await r.json();
      if (d.ok) {
        tr.remove();
        assegnateIds.delete(rtid);
        badge.textContent = assegnateIds.size;
        if (assegnateIds.size === 0) {
          emptyEl.classList.remove('d-none');
          listWrap.classList.add('d-none');
          badge.classList.add('d-none');
        }
        populateSelect();
        suggerimenti = allTypes.filter(t =>
          !assegnateIds.has(t.id) &&
          suggerimenti.some(s => s.restriction_type_id === t.id)
        );
        renderSuggestions();
      } else {
        alert(d.error || 'Errore nella rimozione.');
      }
    });

    function esc(str) {
      if (!str) return '';
      return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.getElementById('ptab-btn-adr')?.addEventListener('shown.bs.tab', function () {
      if (!tbody.dataset.loaded) {
        load();
        tbody.dataset.loaded = '1';
      }
    });
  });
})();

/* ----------------------------------------------------------------------
   Estratto da _form.php (Delete Prodotto)
---------------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('btn-delete-prodotto')?.addEventListener('click', function() {
    document.getElementById('form-delete-prodotto')?.requestSubmit();
  });
});
