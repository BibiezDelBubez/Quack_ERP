<?php
/**
 * entity_picker_modal.php
 * Modale di ricerca riutilizzabile (stile Odoo) — incluso una sola volta in footer.php.
 * Usato da QkPicker (JS) in tutta l'app.
 */
?>
<!-- ═══════════════════════ QK ENTITY PICKER ═══════════════════════════════ -->
<div class="modal fade" id="qk-picker-modal" tabindex="-1"
     aria-labelledby="qk-picker-title" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header pb-2">
        <h5 class="modal-title" id="qk-picker-title">Cerca</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body pt-2">
        <!-- Search bar -->
        <div class="qk-search-wrap mb-3">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="search" id="qk-picker-search"
                 class="qk-form-control qk-search-input"
                 placeholder="Ricerca…" autocomplete="off">
        </div>

        <!-- Results -->
        <div id="qk-picker-results" style="min-height:200px;max-height:420px;overflow-y:auto">
          <div class="d-flex justify-content-center align-items-center" style="height:200px">
            <span class="text-muted small">Digita per cercare o scorri la lista</span>
          </div>
        </div>

        <!-- Pagination info -->
        <div id="qk-picker-pager" class="d-flex justify-content-between align-items-center mt-2 d-none"
             style="font-size:.82rem;color:var(--qk-text-muted)">
          <span id="qk-picker-count"></span>
          <div class="d-flex gap-1">
            <button class="btn-qk btn-qk-secondary btn-qk-sm" id="qk-picker-prev">&lsaquo; Prec.</button>
            <button class="btn-qk btn-qk-secondary btn-qk-sm" id="qk-picker-next">Succ. &rsaquo;</button>
          </div>
        </div>
      </div>

      <div class="modal-footer pt-2">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary d-none" id="qk-picker-btn-nuovo">
          <i class="fa-solid fa-plus me-1"></i>Nuovo
        </button>
      </div>

    </div>
  </div>
</div>

<script>
/* =============================================================================
   QkPicker — componente picker riutilizzabile stile Odoo
   Utilizzo:
     const picker = new QkPicker({
       triggerEl:   element,          // bottone/link che apre il picker
       targetInput: 'mf-fornitore-id', // id dell'input hidden che riceve il valore
       labelInput:  'mf-fornitore-label', // id dell'input testo che mostra il nome
       title:       'Cerca: Fornitore',
       apiUrl:      QK_BASE + '/products/api/fornitori',
       columns:     [{ key:'ragione_sociale', label:'Nome' }, { key:'email', label:'E-mail' }],
       onSelect:    (id, row) => { ... },
       allowCreate: false,            // mostra bottone "Nuovo"
       createUrl:   '/suppliers/create',
     });
============================================================================= */
(function (global) {
  'use strict';

  const PAGE_SIZE = 20;

  class QkPicker {
    constructor(cfg) {
      this.cfg      = cfg;
      this.modal    = document.getElementById('qk-picker-modal');
      this.title    = document.getElementById('qk-picker-title');
      this.search   = document.getElementById('qk-picker-search');
      this.results  = document.getElementById('qk-picker-results');
      this.pager    = document.getElementById('qk-picker-pager');
      this.countEl  = document.getElementById('qk-picker-count');
      this.prevBtn  = document.getElementById('qk-picker-prev');
      this.nextBtn  = document.getElementById('qk-picker-next');
      this.nuovoBtn = document.getElementById('qk-picker-btn-nuovo');

      if (!this.modal) return;

      this._allRows  = [];
      this._filtered = [];
      this._page     = 0;
      this._bsModal  = null;
      this._debounce = null;

      // Bind trigger
      if (cfg.triggerEl) {
        cfg.triggerEl.addEventListener('click', () => this.open());
      }
    }

    open() {
      if (!this.modal) return;

      // Title
      if (this.title) this.title.textContent = this.cfg.title || 'Cerca';

      // Nuovo button
      if (this.nuovoBtn) {
        if (this.cfg.allowCreate) {
          this.nuovoBtn.classList.remove('d-none');
          this.nuovoBtn.onclick = () => {
            this._bsModal?.hide();
            if (this.cfg.onCreate) {
              this.cfg.onCreate();
            } else if (this.cfg.createUrl) {
              window.open(this.cfg.createUrl, '_blank');
            }
          };
        } else {
          this.nuovoBtn.classList.add('d-none');
        }
      }

      // Reset
      if (this.search) this.search.value = '';
      this._page = 0;

      // Show
      this._bsModal = bootstrap.Modal.getOrCreateInstance(this.modal);
      this._bsModal.show();

      // Load data
      this._load('');

      // Search listener (debounced)
      const handler = () => {
        clearTimeout(this._debounce);
        this._debounce = setTimeout(() => {
          this._page = 0;
          this._filter(this.search.value.trim());
          this._render();
        }, 200);
      };
      if (this.search) {
        this.search.removeEventListener('input', this._searchHandler);
        this._searchHandler = handler;
        this.search.addEventListener('input', handler);
        setTimeout(() => this.search && this.search.focus(), 300);
      }

      // Pager
      if (this.prevBtn) this.prevBtn.onclick = () => { if (this._page > 0) { this._page--; this._render(); } };
      if (this.nextBtn) this.nextBtn.onclick = () => {
        const pages = Math.ceil(this._filtered.length / PAGE_SIZE);
        if (this._page < pages - 1) { this._page++; this._render(); }
      };
    }

    async _load(q) {
      if (this.results) {
        this.results.innerHTML = '<div class="d-flex justify-content-center py-4">'
          + '<div class="spinner-border spinner-border-sm text-secondary" role="status"></div></div>';
      }
      try {
        const url = this.cfg.apiUrl + (q ? '?q=' + encodeURIComponent(q) : '');
        const res = await fetch(url, { credentials: 'same-origin' });
        this._allRows = await res.json();
      } catch (e) {
        this._allRows = [];
      }
      this._filter(this.search ? this.search.value.trim() : '');
      this._render();
    }

    _filter(q) {
      if (!q) {
        this._filtered = this._allRows;
        return;
      }
      const lq = q.toLowerCase();
      this._filtered = this._allRows.filter(row =>
        Object.values(row).some(v => v && String(v).toLowerCase().includes(lq))
      );
    }

    _render() {
      if (!this.results) return;
      const cols    = this.cfg.columns || [{ key: 'id', label: 'ID' }];
      const start   = this._page * PAGE_SIZE;
      const slice   = this._filtered.slice(start, start + PAGE_SIZE);
      const total   = this._filtered.length;
      const pages   = Math.ceil(total / PAGE_SIZE);

      if (slice.length === 0) {
        this.results.innerHTML = '<div class="text-center text-muted py-4 small">Nessun risultato trovato.</div>';
        if (this.pager) this.pager.classList.add('d-none');
        return;
      }

      let html = '<table class="qk-table" style="font-size:.875rem">'
               + '<thead><tr>';
      cols.forEach(c => { html += `<th>${c.label}</th>`; });
      html += '</tr></thead><tbody>';

      slice.forEach(row => {
        const id = row.id ?? row[cols[0].key];
        html += `<tr class="qk-picker-row" style="cursor:pointer" data-id="${id}" data-row='${JSON.stringify(row).replace(/'/g, '&#39;')}'>`;
        cols.forEach(c => {
          html += `<td>${row[c.key] ?? '<span class="text-muted">—</span>'}</td>`;
        });
        html += '</tr>';
      });
      html += '</tbody></table>';
      this.results.innerHTML = html;

      // Click handler
      this.results.querySelectorAll('.qk-picker-row').forEach(tr => {
        tr.addEventListener('click', () => {
          const id  = tr.dataset.id;
          let   row = {};
          try { row = JSON.parse(tr.dataset.row); } catch (e) {}
          this._select(id, row);
        });
      });

      // Pager
      if (pages > 1) {
        if (this.pager) this.pager.classList.remove('d-none');
        if (this.countEl) this.countEl.textContent =
          `${start + 1}–${Math.min(start + PAGE_SIZE, total)} di ${total}`;
        if (this.prevBtn) this.prevBtn.disabled = this._page === 0;
        if (this.nextBtn) this.nextBtn.disabled = this._page >= pages - 1;
      } else {
        if (this.pager) this.pager.classList.add('d-none');
      }
    }

    _select(id, row) {
      if (this.cfg.onSelect) this.cfg.onSelect(id, row);
      if (this._bsModal) this._bsModal.hide();
    }
  }

  global.QkPicker = QkPicker;

/* =============================================================================
   QkM2O — campo Many2one stile Odoo
   (autocomplete inline + dropdown + "Cerca di più…" che apre QkPicker)
   Utilizzo:
     const m2o = new QkM2O({
       inputEl:    el,              // <input type="text"> visibile
       hiddenEl:   el,              // <input type="hidden"> con l'id
       clearBtn:   el,              // pulsante × (opzionale)
       dropdownEl: el,              // <div> dropdown (vuoto)
       apiUrl:     '/api/...',      // endpoint JSON [{id,...},...]
       labelKey:   'nome',          // chiave label primaria
       columns:    [{key,label}],   // colonne dropdown (max 2 visibili)
       pageSize:   8,
       picker:     QkPickerInstance,// aperto da "Cerca di più…"
       onCreate:   (q) => {},       // mostra opzione "+ Crea …"
       onSelect:   (id, row) => {},
       onClear:    () => {},
     });
============================================================================= */
  class QkM2O {
    constructor(cfg) {
      this.cfg        = Object.assign({ labelKey: 'id', pageSize: 8 }, cfg);
      this.inputEl    = cfg.inputEl;
      this.hiddenEl   = cfg.hiddenEl;
      this.dropdownEl = cfg.dropdownEl;
      this.clearBtn   = cfg.clearBtn;
      this._rows      = [];
      this._debounce  = null;
      this._bind();
    }

    _bind() {
      const { inputEl, dropdownEl, clearBtn } = this;
      if (!inputEl || !dropdownEl) return;

      inputEl.addEventListener('focus', () => {
        // UX: Seleziona tutto il testo in modo che digitando si sovrascriva
        inputEl.select();
        // Cerca a vuoto per mostrare subito tutte le opzioni disponibili
        this._search('');
      });

      inputEl.addEventListener('input', () => {
        clearTimeout(this._debounce);
        this._debounce = setTimeout(() => {
          const val = inputEl.value.trim();
          this._search(val);
        }, 220);
      });

      inputEl.addEventListener('keydown', e => {
        if (e.key === 'Escape') { 
          this._close(); 
          inputEl.blur(); 
        } else if (e.key === 'Enter') {
          // Ricerca esplicita premendo Invio: bypassa il limite di 1 carattere
          const val = inputEl.value.trim();
          if (val.length === 1) {
            e.preventDefault();
            clearTimeout(this._debounce);
            this._search(val);
          }
        }
      });

      document.addEventListener('click', e => {
        const wrap = inputEl.closest('.qk-m2o-wrap');
        if (wrap && !wrap.contains(e.target)) {
          this._close();
          if (!this.hiddenEl?.value) inputEl.value = '';
        }
      }, true);

      clearBtn?.addEventListener('click', e => { e.stopPropagation(); this.clear(); });
    }

    async _search(q) {
      this.dropdownEl.innerHTML = '<div class="qk-m2o-option is-loading">Ricerca…</div>';
      this._open();
      try {
        const separator = this.cfg.apiUrl.includes('?') ? '&' : '?';
        const url  = this.cfg.apiUrl + (q ? separator + 'q=' + encodeURIComponent(q) : '');
        const res  = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        
        // Se la risposta è un array, la usiamo direttamente
        if (Array.isArray(data)) {
          this._rows = data;
        } else if (data && typeof data === 'object') {
          // Se la risposta è un oggetto, cerchiamo al suo interno una chiave che contenga un array
          const arrKey = Object.keys(data).find(k => Array.isArray(data[k]));
          this._rows = arrKey ? data[arrKey] : [];
        } else {
          this._rows = [];
        }
      } catch { this._rows = []; }
      this._render(q);
    }

    _render(q) {
      const { labelKey, columns, pageSize, picker, onCreate } = this.cfg;
      const col1  = columns?.[0]?.key ?? labelKey;
      const col2  = columns?.[1]?.key ?? null;
      const slice = Array.isArray(this._rows) ? this._rows.slice(0, pageSize) : [];
      let html    = '';

      if (slice.length === 0 && !onCreate) {
        html = '<div class="qk-m2o-option is-empty">Nessun risultato trovato.</div>';
      }

      slice.forEach(row => {
        // Fallback per la colonna principale (se undefined, prova a usare chiavi comuni)
        let lbl = row[col1];
        if (lbl === undefined || lbl === null) {
          lbl = row['nome'] ?? row['label'] ?? row['ragione_sociale'] ?? row['codice'] ?? '';
        }
        
        // Fallback per la colonna secondaria
        let sub = '';
        if (col2) {
          sub = row[col2];
          if (sub === undefined || sub === null) {
            sub = row['sub'] ?? row['codice'] ?? row['partita_iva'] ?? row['descrizione'] ?? '';
          }
        }

        html += `<div class="qk-m2o-option" data-id="${row.id}" data-label="${String(lbl).replace(/"/g,'&quot;')}">`;
        html += `<span>${lbl}</span>`;
        if (sub) html += ` <small class="ms-2" style="color:var(--qk-text-muted);font-size:.8em">${sub}</small>`;
        html += '</div>';
      });

      if (picker) {
        const extra = this._rows.length > pageSize ? ` (${this._rows.length})` : '';
        html += `<div class="qk-m2o-option is-more" data-action="more">Cerca di più…${extra}</div>`;
      }

      if (onCreate && q) {
        const exact = this._rows.some(r => (r[col1] ?? '').toLowerCase() === q.toLowerCase());
        if (!exact)
          html += `<div class="qk-m2o-option is-create" data-action="create">+ Crea &ldquo;${q}&rdquo;</div>`;
      }

      this.dropdownEl.innerHTML = html;

      this.dropdownEl.querySelectorAll('.qk-m2o-option[data-id]').forEach(opt => {
        opt.addEventListener('click', () => {
          const row = this._rows.find(r => String(r.id) === opt.dataset.id) ?? {};
          this.setValue(opt.dataset.id, opt.dataset.label);
          this._close();
          this.cfg.onSelect?.(opt.dataset.id, row);
        });
      });

      this.dropdownEl.querySelector('[data-action="more"]')?.addEventListener('click', () => {
        this._close();
        picker?.open();
      });

      this.dropdownEl.querySelector('[data-action="create"]')?.addEventListener('click', () => {
        this._close();
        onCreate?.(q);
      });

      this._open();
    }

    _open()  { this.dropdownEl.classList.add('is-open'); }
    _close() { this.dropdownEl.classList.remove('is-open'); }

    setValue(id, label) {
      if (this.hiddenEl) this.hiddenEl.value = id;
      if (this.inputEl)  this.inputEl.value  = label;
      if (this.clearBtn) this.clearBtn.classList.remove('d-none');
    }

    clear() {
      if (this.hiddenEl) this.hiddenEl.value = '';
      if (this.inputEl)  { this.inputEl.value = ''; this.inputEl.focus(); }
      if (this.clearBtn) this.clearBtn.classList.add('d-none');
      this.cfg.onClear?.();
    }
  }

  global.QkM2O = QkM2O;

})(window);
</script>
