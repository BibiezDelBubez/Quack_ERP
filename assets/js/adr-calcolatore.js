/**
 * adr-calcolatore.js — Motore di calcolo esenzione ADR (regola 1000 punti, ADR 1.1.3.6)
 *
 * Utilizzo:
 *
 *   const calc = new QkAdrCalcolatore({
 *     tbody:         document.getElementById('mio-tbody'),
 *     categorie:     [...],          // array da adr_categorie_trasporto
 *     sostanze:      [...],          // array sostanze_chimiche con join codici_onu
 *     apiProdottiAdr: '/adr/api/prodotti-adr',
 *     onUpdate:      ({ totale, stato, rows }) => { ... },  // callback opzionale
 *   });
 *
 *   calc.addRowSostanza();   // aggiunge riga con select sostanza
 *   calc.addRowProdotto();   // aggiunge riga con typeahead prodotto
 *   calc.reset();            // svuota tutto
 *   calc.getTotale();        // → number | null
 *   calc.getStato();         // → 'vuoto' | 'esente' | 'non-esente' | 'bloccato'
 *   calc.getRows();          // → array righe correnti (read-only)
 *
 * La view deve solo:
 *   1. Creare il <tbody> e passarlo come `tbody`
 *   2. Istanziare QkAdrCalcolatore
 *   3. Usare onUpdate per aggiornare il pannello risultato
 *   4. Collegare i propri pulsanti a addRowSostanza/addRowProdotto/reset
 */

class QkAdrCalcolatore {

  /* ── Costruttore ────────────────────────────────────────────────────── */

  constructor({ tbody, categorie = [], sostanze = [], apiProdottiAdr = '', onUpdate = null }) {
    this._tbody          = tbody;
    this._categorie      = categorie;
    this._sostanze       = sostanze;
    this._apiProdottiAdr = apiProdottiAdr;
    this._onUpdate       = onUpdate;

    this._rows   = [];
    this._nextId = 1;
    this._debounce = null;

    // Mappa id → sostanza per lookup rapido
    this._sostanzeMap = {};
    sostanze.forEach(s => { this._sostanzeMap[s.id] = s; });

    this._renderRows();
  }

  /* ── API pubblica ───────────────────────────────────────────────────── */

  addRowSostanza() {
    const id = this._nextId++;
    this._rows.push({
      id, type: 'sostanza',
      sostanzaId: '', label: '', unCode: '',
      categoria: '', moltiplicatore: null, quantita: 0,
    });
    this._renderRows();
    const sel = this._tbody.querySelector(`[data-row-id="${id}"] .qkac-sel-sostanza`);
    if (sel) sel.focus();
  }

  addRowProdotto() {
    const id = this._nextId++;
    this._rows.push({
      id, type: 'prodotto',
      prodottoId: '', label: '', sostanzaNome: '', unCode: '',
      categoria: '', moltiplicatore: null, quantita: 0,
    });
    this._renderRows();
    const inp = this._tbody.querySelector(`[data-row-id="${id}"] .qkac-inp-search`);
    if (inp) inp.focus();
  }

  reset() {
    this._rows   = [];
    this._nextId = 1;
    this._renderRows();
  }

  getTotale() {
    const stato = this.getStato();
    if (stato === 'vuoto' || stato === 'bloccato') return null;
    return this._rows.reduce((sum, r) => {
      const p = this._puntiRiga(r);
      return sum + (p ?? 0);
    }, 0);
  }

  getStato() {
    if (this._rows.length === 0) return 'vuoto';
    if (this._rows.some(r => r.categoria === '0')) return 'bloccato';
    const tot = this._rows.reduce((s, r) => s + (this._puntiRiga(r) ?? 0), 0);
    return tot < 1000 ? 'esente' : 'non-esente';
  }

  getRows() {
    return [...this._rows];
  }

  /* ── Calcolo ────────────────────────────────────────────────────────── */

  _puntiRiga(row) {
    if (row.moltiplicatore === null || row.moltiplicatore === undefined) return null;
    return row.quantita * row.moltiplicatore;
  }

  _formatPunti(row) {
    const p = this._puntiRiga(row);
    if (p === null) return '—';
    return p % 1 === 0 ? String(p) : p.toFixed(1);
  }

  _notifyUpdate() {
    if (typeof this._onUpdate === 'function') {
      this._onUpdate({
        totale: this.getTotale(),
        stato:  this.getStato(),
        rows:   this.getRows(),
      });
    }
  }

  /* ── HTML helpers ───────────────────────────────────────────────────── */

  _esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  _buildSelectSostanze(currentId) {
    const withCat    = this._sostanze.filter(s => s.categoria_trasporto != null);
    const withoutCat = this._sostanze.filter(s => s.categoria_trasporto == null);
    let html = '<option value="">— Seleziona sostanza —</option>';
    if (withCat.length) {
      html += '<optgroup label="Sostanze con categoria nota">';
      withCat.forEach(s => {
        const lbl = s.nome + (s.codice_onu ? ' — UN' + s.codice_onu : '') + ' (Cat.' + s.categoria_trasporto + ')';
        html += `<option value="${s.id}" ${String(s.id) === String(currentId) ? 'selected' : ''}>${this._esc(lbl)}</option>`;
      });
      html += '</optgroup>';
    }
    if (withoutCat.length) {
      html += '<optgroup label="Categoria mancante — inserisci manualmente">';
      withoutCat.forEach(s => {
        const lbl = s.nome + (s.codice_onu ? ' — UN' + s.codice_onu : '') + ' [cat. ?]';
        html += `<option value="${s.id}" ${String(s.id) === String(currentId) ? 'selected' : ''}>${this._esc(lbl)}</option>`;
      });
      html += '</optgroup>';
    }
    return html;
  }

  _buildSelectCategorie(currentCat) {
    let html = '<option value="">—</option>';
    this._categorie.forEach(c => {
      const lbl = 'Cat. ' + c.categoria + ' (×' + c.moltiplicatore_punti + ')';
      html += `<option value="${c.categoria}" ${String(c.categoria) === String(currentCat) ? 'selected' : ''}>${lbl}</option>`;
    });
    return html;
  }

  /* ── Render tabella ─────────────────────────────────────────────────── */

  _renderRows() {
    if (this._rows.length === 0) {
      this._tbody.innerHTML = `
        <tr><td colspan="7" class="text-center py-4" style="color:var(--qk-text-muted)">
          <i class="fa-regular fa-circle-plus me-1"></i>
          Aggiungi sostanze ADR o prodotti del catalogo per avviare il calcolo.
        </td></tr>`;
      this._notifyUpdate();
      return;
    }

    this._tbody.innerHTML = this._rows.map(row => {
      const catEditable =
        row.categoria === '' ||
        (row.type === 'sostanza' && row.sostanzaId && this._sostanzeMap[row.sostanzaId]?.categoria_trasporto == null) ||
        (row.type === 'prodotto' && !row.prodottoId);

      const colSostanza = row.type === 'sostanza'
        ? `<select class="qk-form-control qk-form-control-sm qkac-sel-sostanza">${this._buildSelectSostanze(row.sostanzaId)}</select>`
        : `<div class="qk-m2o-wrap">
             <input type="text" class="qk-form-control qk-form-control-sm qk-m2o-input qkac-inp-search"
                    value="${this._esc(row.label)}" placeholder="Cerca prodotto…"
                    ${row.prodottoId ? 'readonly' : ''}>
             ${row.prodottoId
               ? `<button type="button" class="btn-qk btn-qk-secondary btn-qk-sm qkac-btn-clear-prod"
                         style="position:absolute;right:4px;top:50%;transform:translateY(-50%);padding:2px 6px"
                         title="Cambia prodotto"><i class="fa-solid fa-xmark fa-xs"></i></button>`
               : ''}
           </div>`;

      const colCat = catEditable
        ? `<select class="qk-form-control qk-form-control-sm qkac-sel-categoria">${this._buildSelectCategorie(row.categoria)}</select>`
        : `<span class="fw-semibold">${this._esc(String(row.categoria))}</span>`;

      return `
      <tr data-row-id="${row.id}" data-type="${row.type}">
        <td>${colSostanza}</td>
        <td class="qkac-cell-un">
          ${row.unCode
            ? `<span class="qk-badge adr">UN${this._esc(String(row.unCode))}</span>`
            : '<span style="color:var(--qk-text-muted)">—</span>'}
        </td>
        <td class="qkac-cell-cat">${colCat}</td>
        <td class="text-center qkac-cell-molt" style="color:var(--qk-text-muted)">
          ${row.moltiplicatore != null ? '×' + row.moltiplicatore : '—'}
        </td>
        <td>
          <input type="number" class="qk-form-control qk-form-control-sm qkac-inp-qty text-end"
                 value="${row.quantita || ''}" min="0" step="0.1" placeholder="0">
        </td>
        <td class="text-end fw-semibold qkac-cell-punti"
            style="color:${this._puntiRiga(row) === null ? 'var(--qk-text-muted)' : 'inherit'}">
          ${this._formatPunti(row)}
        </td>
        <td>
          <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm qkac-btn-remove" title="Rimuovi">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </td>
      </tr>`;
    }).join('');

    // Attacca listeners
    this._tbody.querySelectorAll('[data-row-id]').forEach(tr => {
      const rowId = parseInt(tr.dataset.rowId);
      const type  = tr.dataset.type;

      if (type === 'sostanza') {
        const sel = tr.querySelector('.qkac-sel-sostanza');
        if (sel) sel.addEventListener('change', () => this._onSostanzaChange(rowId, sel.value));
      } else {
        const inp = tr.querySelector('.qkac-inp-search');
        if (inp) this._setupTypeahead(rowId, inp, tr);
        const btnClear = tr.querySelector('.qkac-btn-clear-prod');
        if (btnClear) btnClear.addEventListener('click', () => this._clearProdotto(rowId));
      }

      const selCat = tr.querySelector('.qkac-sel-categoria');
      if (selCat) selCat.addEventListener('change', () => this._onCatChange(rowId, selCat.value));

      const inpQty = tr.querySelector('.qkac-inp-qty');
      if (inpQty) inpQty.addEventListener('input', () => this._onQtyChange(rowId, inpQty.value));

      const btnRem = tr.querySelector('.qkac-btn-remove');
      if (btnRem) btnRem.addEventListener('click', () => this._removeRow(rowId));
    });

    this._notifyUpdate();
  }

  /* ── Event handlers interni ─────────────────────────────────────────── */

  _removeRow(id) {
    this._rows = this._rows.filter(r => r.id !== id);
    this._renderRows();
  }

  _onSostanzaChange(rowId, sostanzaId) {
    const row = this._rows.find(r => r.id === rowId);
    if (!row) return;
    row.sostanzaId = sostanzaId;
    if (sostanzaId && this._sostanzeMap[sostanzaId]) {
      const s = this._sostanzeMap[sostanzaId];
      row.unCode         = s.codice_onu || '';
      row.categoria      = s.categoria_trasporto != null ? String(s.categoria_trasporto) : '';
      row.moltiplicatore = s.moltiplicatore_punti != null ? parseInt(s.moltiplicatore_punti) : null;
    } else {
      row.unCode = ''; row.categoria = ''; row.moltiplicatore = null;
    }
    this._renderRows();
  }

  _onCatChange(rowId, catVal) {
    const row = this._rows.find(r => r.id === rowId);
    if (!row) return;
    row.categoria = catVal;
    const catObj = this._categorie.find(c => String(c.categoria) === String(catVal));
    row.moltiplicatore = catObj ? parseInt(catObj.moltiplicatore_punti) : null;
    const tr = this._tbody.querySelector(`[data-row-id="${rowId}"]`);
    if (tr) {
      tr.querySelector('.qkac-cell-molt').textContent  = row.moltiplicatore != null ? '×' + row.moltiplicatore : '—';
      tr.querySelector('.qkac-cell-punti').textContent = this._formatPunti(row);
    }
    this._notifyUpdate();
  }

  _onQtyChange(rowId, val) {
    const row = this._rows.find(r => r.id === rowId);
    if (!row) return;
    row.quantita = parseFloat(String(val).replace(',', '.')) || 0;
    const tr = this._tbody.querySelector(`[data-row-id="${rowId}"]`);
    if (tr) tr.querySelector('.qkac-cell-punti').textContent = this._formatPunti(row);
    this._notifyUpdate();
  }

  _clearProdotto(rowId) {
    const row = this._rows.find(r => r.id === rowId);
    if (row) {
      row.prodottoId = ''; row.label = ''; row.unCode = '';
      row.categoria  = ''; row.moltiplicatore = null;
    }
    this._renderRows();
  }

  /* ── Typeahead prodotto ─────────────────────────────────────────────── */

  /* Sostituisce fetch+dropdown scritti a mano (classi .qkac-ta-dd/.qkac-ta-
     item mai riusate altrove) con QkM2O, il componente condiviso già usato
     nel resto dell'app — vedi audit DRY/UI 27/07/2026. Qui non serve "crea
     al volo" (è un calcolatore, non un form di salvataggio), quindi nessun
     onCreate/picker.
     Nota sul trade-off: il vecchio suggerimento combinava UN + sostanza +
     categoria in un'unica riga sotto il nome; il rendering standard di
     QkM2O supporta una sola colonna secondaria — ho scelto il codice UN
     (il dato più rilevante in un calcolatore ADR). Sostanza e categoria
     restano comunque visibili nella riga della tabella subito dopo la
     selezione (celle .qkac-cell-un/.qkac-cell-cat, vedi _selectProdotto). */
  _setupTypeahead(rowId, inputEl, tr) {
    let dd = inputEl.parentElement.querySelector('.qk-m2o-dropdown');
    if (!dd) {
      dd = document.createElement('div');
      dd.className = 'qk-m2o-dropdown';
      inputEl.parentElement.appendChild(dd);
    }

    new QkM2O({
      inputEl:    inputEl,
      dropdownEl: dd,
      apiUrl:     this._apiProdottiAdr,
      labelKey:   'nome',
      columns:    [{ key: 'nome', label: 'Prodotto' }, { key: 'codice_onu', label: 'UN' }],
      onSelect:   (id, row) => this._selectProdotto(rowId, row, inputEl),
    });
  }

  _selectProdotto(rowId, p, inputEl) {
    const row = this._rows.find(r => r.id === rowId);
    if (!row) return;
    row.prodottoId     = p.id;
    row.label          = p.nome + ' (' + p.sku_interno + ')';
    row.sostanzaNome   = p.sostanza_nome || '';
    row.unCode         = p.codice_onu || '';
    row.categoria      = p.categoria_trasporto != null ? String(p.categoria_trasporto) : '';
    row.moltiplicatore = p.moltiplicatore_punti != null ? parseInt(p.moltiplicatore_punti) : null;
    inputEl.value    = row.label;
    inputEl.readOnly = true;
    // Nessun dd.style.display='none' qui: QkM2O chiude da sé il proprio dropdown alla selezione.

    // Aggiorna le celle statiche senza re-render completo
    const tr = this._tbody.querySelector(`[data-row-id="${rowId}"]`);
    if (tr) {
      tr.querySelector('.qkac-cell-un').innerHTML =
        row.unCode ? `<span class="qk-badge adr">UN${this._esc(String(row.unCode))}</span>` : '—';
      const cellCat = tr.querySelector('.qkac-cell-cat');
      if (row.categoria !== '') {
        cellCat.innerHTML = `<span class="fw-semibold">${this._esc(row.categoria)}</span>`;
      } else {
        cellCat.innerHTML = `<select class="qk-form-control qk-form-control-sm qkac-sel-categoria">${this._buildSelectCategorie('')}</select>`;
        const selCat = cellCat.querySelector('.qkac-sel-categoria');
        if (selCat) selCat.addEventListener('change', () => this._onCatChange(rowId, selCat.value));
      }
      tr.querySelector('.qkac-cell-molt').textContent = row.moltiplicatore != null ? '×' + row.moltiplicatore : '—';
      tr.querySelector('.qkac-cell-punti').textContent = this._formatPunti(row);
      // Aggiunge il tasto "cambia prodotto"
      const inpWrap = inputEl.parentElement;
      if (!inpWrap.querySelector('.qkac-btn-clear-prod')) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-qk btn-qk-secondary btn-qk-sm qkac-btn-clear-prod';
        btn.title = 'Cambia prodotto';
        btn.style.cssText = 'position:absolute;right:4px;top:50%;transform:translateY(-50%);padding:2px 6px';
        btn.innerHTML = '<i class="fa-solid fa-xmark fa-xs"></i>';
        btn.addEventListener('click', () => this._clearProdotto(rowId));
        inpWrap.appendChild(btn);
      }
    }
    this._notifyUpdate();
  }
}
