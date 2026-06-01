/**
 * products-suppliers.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Gestione inline dei fornitori e degli scaglioni di prezzo nel tab "Fornitori"
 * della scheda prodotto (/products/edit?id=X).
 *
 * Architettura (v2 — card per fornitore)
 * ──────────────────────────────────────
 * Invece di un'unica <table> con righe miste (fornitore + prezzi),
 * ogni fornitore è una card autonoma (.qks-supplier-block):
 *
 *   ┌─ [★] Astra Meccanica  SKU: AM-001  ● Attivo   [✎ Modifica][+Prezzo][🗑]
 *   │  ┌─────────────┬──────────┬─────────┬────────┬──────┬──────┬──────┬─┐
 *   │  │ LISTINO     │ Q.TÀ MIN.│ PREZZO  │ SC. %  │ DAL  │  AL  │ NOTE │ │
 *   │  ├─────────────┼──────────┼─────────┼────────┼──────┼──────┼──────┼─┤
 *   │  │ Base        │    —     │  19,00  │   —    │  —   │  —   │      │✎🗑│
 *   │  └─────────────┴──────────┴─────────┴────────┴──────┴──────┴──────┴─┘
 *   └─ [★] Faber  ● Attivo   [✎ Modifica][+Prezzo][🗑]
 *      Nessun prezzo — usa +Prezzo per aggiungere uno scaglione.
 *
 * Vantaggi rispetto alla tabella piatta:
 * • Nessuna perdita di contesto durante lo scroll (header sempre visibile)
 * • Semantica corretta: ogni tabella descrive solo i propri dati
 * • Aggiunta/modifica prezzi avviene in un contesto visivamente delimitato
 * • Temi CSS supportati tramite variabili CSS (products-suppliers.css)
 *
 * Dipendenze
 * ──────────
 *   window.QkPF  — iniettato da products/_form.php
 *   products-suppliers.css — incluso da ProductsController via $extra_css
 *
 * Campi usati da window.QkPF:
 *   prodottoId        (int)     — id prodotto corrente
 *   fornitori         (array)   — record da qk_load_prodotto_fornitori
 *   tuttePrezzi       (array)   — record da qk_load_prodotto_tutti_prezzi
 *   apiPrezzoSave     (string)  — URL POST save prezzo
 *   apiPrezzoDelete   (string)  — URL POST delete prezzo
 *   apiFornListini    (string)  — URL GET listini per fornitore_id
 *   apiFornDelete     (string)  — URL POST elimina fornitore
 */

/* global QkPF */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    /* ──────────────────────────────────────────────────────────────────────
       COSTANTI E RIFERIMENTI DOM
    ────────────────────────────────────────────────────────────────────── */

    const cfg       = window.QkPF;
    const prodId    = cfg.prodottoId;

    /* Token CSRF — letto dal campo hidden nel form principale, mai da globale */
    const CSRF = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    const container = document.getElementById('fornitori-container');
    const emptyEl   = document.getElementById('fornitori-empty');

    /* Se il contenitore non è nel DOM (prodotto non salvato), esci */
    if (!container) return;


    /* ──────────────────────────────────────────────────────────────────────
       STATO LOCALE
       I dati PHP vengono clonati per mutarli localmente senza ricaricare.
       I prezzi sono indicizzati per prodotto_fornitore_id.
    ────────────────────────────────────────────────────────────────────── */

    /** @type {Array<Object>} — array di record fornitore */
    let fornitori = [...(cfg.fornitori || [])];

    /**
     * Map: prodotto_fornitore_id → Array<prezzoRow>
     * @type {Map<number, Array<Object>>}
     */
    const prezziMap = buildPrezziMap(cfg.tuttePrezzi || []);

    /**
     * Cache listini per fornitore_id: Map<number, Array<{id, nome_listino}>>
     * Evita richieste AJAX duplicate per lo stesso fornitore.
     * @type {Map<number, Array<Object>>}
     */
    const listiniCache = new Map();


    /* ──────────────────────────────────────────────────────────────────────
       UTILITIES
    ────────────────────────────────────────────────────────────────────── */

    /**
     * Costruisce Map prodotto_fornitore_id → prezzi dall'array flat.
     * @param {Array<Object>} rows
     * @returns {Map<number, Array<Object>>}
     */
    function buildPrezziMap(rows) {
      const m = new Map();
      rows.forEach(r => {
        const pfId = Number(r.prodotto_fornitore_id);
        if (!m.has(pfId)) m.set(pfId, []);
        m.get(pfId).push({ ...r });
      });
      return m;
    }

    /** Escape per contenuto HTML (anti-XSS). */
    function esc(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    }

    /** Escape per attributi HTML. */
    function escA(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;');
    }

    /**
     * Formatta un numero con N decimali in stile italiano.
     * @param {*} n
     * @param {number} [dec=2]
     */
    function fmtNum(n, dec = 2) {
      const v = parseFloat(n);
      if (isNaN(v)) return '—';
      return v.toLocaleString('it-IT', {
        minimumFractionDigits: dec,
        maximumFractionDigits: dec,
      });
    }

    /**
     * Formatta un prezzo con decimali smart: min 2, max 4, no trailing zeros.
     * Esempi: 19 → "19,00" | 12.3456 → "12,3456" | 0.0025 → "0,0025"
     * @param {*} n
     */
    function fmtPrezzo(n) {
      const v = parseFloat(n);
      if (isNaN(v)) return '—';
      return v.toLocaleString('it-IT', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
      });
    }

    /**
     * Carica i listini di un fornitore via AJAX (con cache).
     * @param {number} fornitoreId
     * @returns {Promise<Array<{id:number, nome_listino:string}>>}
     */
    async function fetchListini(fornitoreId) {
      if (listiniCache.has(fornitoreId)) return listiniCache.get(fornitoreId);
      try {
        const res  = await fetch(`${cfg.apiFornListini}?fornitore_id=${encodeURIComponent(fornitoreId)}`);
        const data = await res.json();
        const list = data.ok ? (data.listini || []) : [];
        listiniCache.set(fornitoreId, list);
        return list;
      } catch {
        return [];
      }
    }

    /**
     * Invia un POST AJAX con FormData + CSRF.
     * @param {string} url
     * @param {Object} fields
     * @returns {Promise<Object>}
     */
    async function postAjax(url, fields) {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      Object.entries(fields).forEach(([k, v]) => fd.append(k, v ?? ''));
      const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
      return res.json();
    }


    /* ──────────────────────────────────────────────────────────────────────
       RENDERING — lista completa
    ────────────────────────────────────────────────────────────────────── */

    /**
     * Ri-renderizza tutto il contenitore dai dati locali.
     * Chiamata all'init e dopo operazioni che cambiano il numero di fornitori.
     */
    function renderAll() {
      container.innerHTML = '';

      const sorted = [...fornitori].sort(
        (a, b) => (Number(a.ordine_preferenza_fornitore) || 99)
                - (Number(b.ordine_preferenza_fornitore) || 99)
      );

      if (sorted.length === 0) {
        if (emptyEl)   emptyEl.classList.remove('d-none');
        container.classList.add('d-none');
        return;
      }

      if (emptyEl)   emptyEl.classList.add('d-none');
      container.classList.remove('d-none');

      sorted.forEach(forn => container.appendChild(buildSupplierBlock(forn)));
    }

    /**
     * Costruisce il DOM di una card fornitore completa.
     * Ritorna un HTMLElement (non lo appende direttamente — permette
     * l'inserimento ordinato in rerenderFornitoreGroup).
     *
     * Struttura:
     *   div.qks-supplier-block
     *     div.qks-supplier-hd          ← nome, SKU, lead time, stato, azioni
     *     div.qks-price-empty          ← se nessun prezzo (no tabella)
     *     div.qks-price-wrap           ← wrapper overflow per responsivo
     *       table.qks-price-table
     *         thead                    ← colonne (solo se ci sono prezzi)
     *         tbody#qks-ptbody-{pfId}
     *
     * @param {Object} forn — record da qk_load_prodotto_fornitori
     * @returns {HTMLElement}
     */
    function buildSupplierBlock(forn) {
      const pfId     = Number(forn.id);
      const prezzi   = prezziMap.get(pfId) || [];
      const isActive = forn.attivo == 1;
      const isPref   = Number(forn.ordine_preferenza_fornitore) === 1;

      const block         = document.createElement('div');
      block.id            = `qks-block-${pfId}`;
      block.className     = `qks-supplier-block${isActive ? '' : ' is-inactive'}`;
      block.dataset.pfId  = pfId;

      /* ── Header fornitore ─────────────────────────────────────────── */
      const hd = document.createElement('div');
      hd.className = 'qks-supplier-hd';

      /* Icona preferenza */
      const prefIcon = isPref
        ? `<i class="fa-solid fa-star" style="color:#f59e0b;font-size:.85rem" title="Fornitore preferenziale"></i>`
        : `<span style="width:1rem;display:inline-flex;align-items:center;justify-content:center;
                        font-size:.7rem;font-weight:700;color:var(--qk-text-muted)"
               title="Ordine preferenza ${esc(forn.ordine_preferenza_fornitore)}">${esc(forn.ordine_preferenza_fornitore)}</span>`;

      /* Badge SKU */
      const skuHtml = forn.sku_fornitore
        ? `<span class="qks-sku-badge">
             <span class="qks-sku-label">SKU</span>
             ${esc(forn.sku_fornitore)}
           </span>`
        : '';

      /* Nome commerciale del prodotto presso il fornitore */
      const nomeCommHtml = forn.nome_prodotto_fornitore
        ? `<span style="font-size:.8rem;color:var(--qk-text-muted)">${esc(forn.nome_prodotto_fornitore)}</span>`
        : '';

      /* Lead time */
      const leadHtml = forn.lead_time_giorni
        ? `<span class="qks-lead-time">
             <i class="fa-regular fa-clock me-1"></i>${esc(forn.lead_time_giorni)}&thinsp;gg
           </span>`
        : '';

      /* Badge stato attivo/non attivo */
      const statusHtml = isActive
        ? `<span class="qks-status-badge is-active">
             <span class="qks-dot"></span>Attivo
           </span>`
        : `<span class="qks-status-badge is-inactive">
             <span class="qks-dot"></span>Non attivo
           </span>`;

      /* Pulsanti azioni */
      const actionsHtml = `
        <div class="qks-supplier-actions">
          <button type="button"
                  class="btn-qk btn-qk-secondary btn-qk-xs qks-btn-edit-forn"
                  data-bs-toggle="modal"
                  data-bs-target="#modal-fornitore"
                  data-mode="edit"
                  data-id="${pfId}"
                  data-fornitore-id="${escA(forn.fornitore_id)}"
                  data-fornitore-label="${escA(forn.nome_fornitore_rs)}"
                  data-sku-fornitore="${escA(forn.sku_fornitore ?? '')}"
                  data-nome-prodotto-fornitore="${escA(forn.nome_prodotto_fornitore ?? '')}"
                  data-qta-min="${escA(forn.quantita_minima ?? '')}"
                  data-ordine-pref="${esc(forn.ordine_preferenza_fornitore)}"
                  data-attivo="${isActive ? '1' : '0'}"
                  data-rrp-min="${escA(forn.rrp_min ?? '')}"
                  data-rrp-max="${escA(forn.rrp_max ?? '')}"
                  data-lead-time="${escA(forn.lead_time_giorni ?? '')}"
                  data-note="${escA(forn.note ?? '')}"
                  title="Modifica metadati fornitore">
            <i class="fa-solid fa-pen me-1"></i>Modifica
          </button>
          <button type="button"
                  class="btn-qk btn-qk-primary btn-qk-xs qks-btn-add-prezzo"
                  data-pf-id="${pfId}"
                  data-fornitore-id="${esc(forn.fornitore_id)}"
                  title="Aggiungi scaglione di prezzo">
            <i class="fa-solid fa-plus me-1"></i>Prezzo
          </button>
          <button type="button"
                  class="btn-qk btn-qk-danger btn-qk-xs qks-btn-delete-forn"
                  data-id="${pfId}"
                  data-nome="${escA(forn.nome_fornitore_rs)}"
                  title="Rimuovi fornitore dal prodotto">
            <i class="fa-solid fa-trash"></i>
          </button>
        </div>
      `;

      hd.innerHTML = `
        ${prefIcon}
        <div class="qks-supplier-meta">
          <span class="qks-supplier-name">${esc(forn.nome_fornitore_rs)}</span>
          ${nomeCommHtml}
          ${skuHtml}
          ${leadHtml}
        </div>
        ${statusHtml}
        ${actionsHtml}
      `;
      block.appendChild(hd);

      /* ── Corpo prezzi ─────────────────────────────────────────────── */
      if (prezzi.length === 0) {
        /* Stato vuoto inline — nessuna tabella da mostrare */
        const empty = document.createElement('div');
        empty.className       = 'qks-price-empty';
        empty.dataset.pfId    = pfId;
        empty.dataset.type    = 'empty';
        empty.innerHTML =
          `<i class="fa-regular fa-circle-dot me-1 opacity-40"></i>` +
          `Nessun prezzo — usa il pulsante <strong>+ Prezzo</strong> per aggiungere uno scaglione.`;
        block.appendChild(empty);
      } else {
        /* Mini-tabella prezzi con thead autonomo */
        const wrap = document.createElement('div');
        wrap.className = 'qks-price-wrap';

        const table   = document.createElement('table');
        table.className = 'qks-price-table';

        table.innerHTML = `
          <thead>
            <tr>
              <th>Listino</th>
              <th class="text-end qks-col-qtamin" style="width:90px">Q.tà min.</th>
              <th class="text-end" style="width:105px">Prezzo</th>
              <th class="text-end" style="width:80px">Sconto %</th>
              <th class="qks-col-dal" style="width:105px">Valido dal</th>
              <th class="qks-col-al"  style="width:105px">Valido al</th>
              <th class="qks-col-note">Note</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
        `;

        const tbody       = document.createElement('tbody');
        tbody.id          = `qks-ptbody-${pfId}`;
        tbody.dataset.pfId = pfId;

        prezzi.forEach(p => tbody.appendChild(buildPrezzoReadRow(pfId, p)));

        table.appendChild(tbody);
        wrap.appendChild(table);
        block.appendChild(wrap);
      }

      return block;
    }

    /**
     * Costruisce una singola riga prezzo in modalità lettura.
     * Restituisce un <tr> da appendere al tbody della mini-tabella.
     *
     * @param {number} pfId
     * @param {Object} p — record prezzo
     * @returns {HTMLTableRowElement}
     */
    function buildPrezzoReadRow(pfId, p) {
      const tr            = document.createElement('tr');
      tr.id               = `qks-prow-${p.id}`;
      tr.className        = 'qks-price-row';
      tr.dataset.pfId     = pfId;
      tr.dataset.prezzoId = p.id;

      /* Listino label */
      const listinoLabel = p.nome_listino
        ? `<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 fw-normal">
             ${esc(p.nome_listino)}
           </span>`
        : `<em style="color:var(--qk-text-muted);font-size:.85em">Base</em>`;

      /* Q.tà minima: mostra "—" per 0 (riga Base non ha senso con "0,000") */
      const qtaLabel = parseFloat(p.quantita_minima || 0) > 0
        ? `<span class="font-monospace">${fmtNum(p.quantita_minima, 3)}</span>`
        : `<span style="color:var(--qk-text-muted)">—</span>`;

      /* Sconto */
      const scontoLabel = parseFloat(p.sconto_perc || 0) > 0
        ? `${fmtNum(p.sconto_perc, 2)} %`
        : `<span style="color:var(--qk-text-muted)">—</span>`;

      tr.innerHTML = `
        <td>${listinoLabel}</td>
        <td class="text-end qks-col-qtamin">${qtaLabel}</td>
        <td class="text-end font-monospace fw-semibold">${fmtPrezzo(p.prezzo)}</td>
        <td class="text-end">${scontoLabel}</td>
        <td class="qks-col-dal" style="color:var(--qk-text-muted)">${p.valido_dal ? esc(p.valido_dal) : '—'}</td>
        <td class="qks-col-al"  style="color:var(--qk-text-muted)">${p.valido_al  ? esc(p.valido_al)  : '—'}</td>
        <td class="qks-col-note" style="color:var(--qk-text-muted)">${p.note ? esc(p.note) : ''}</td>
        <td class="col-actions">
          <div class="d-flex gap-1 justify-content-end">
            <button type="button"
                    class="btn-qk btn-qk-secondary btn-qk-xs qks-btn-edit-prezzo"
                    data-prezzo-id="${p.id}"
                    data-pf-id="${pfId}"
                    title="Modifica">
              <i class="fa-solid fa-pen"></i>
            </button>
            <button type="button"
                    class="btn-qk btn-qk-danger btn-qk-xs qks-btn-delete-prezzo"
                    data-prezzo-id="${p.id}"
                    data-pf-id="${pfId}"
                    title="Elimina">
              <i class="fa-solid fa-trash"></i>
            </button>
          </div>
        </td>
      `;
      return tr;
    }

    /**
     * Inserisce (o sostituisce) una riga prezzo con un form di modifica inline.
     * Se la tabella prezzi non esiste ancora per il fornitore (stato vuoto),
     * la crea prima di inserire il form.
     *
     * @param {number}  pfId         — prodotto_fornitore_id
     * @param {number}  fornitoreId
     * @param {Object}  [p={}]       — prezzo da pre-compilare (vuoto = insert)
     * @param {HTMLElement} [refTr]  — riga dopo cui inserire (null = in fondo)
     */
    async function insertEditRow(pfId, fornitoreId, p = {}, refTr = null) {
      const listini = await fetchListini(fornitoreId);

      const isNew  = !p.id;
      const rowId  = isNew ? `qks-pnew-${pfId}` : `qks-pedit-${p.id}`;

      /* Rimuove un eventuale form già aperto per questa stessa riga */
      document.getElementById(rowId)?.remove();

      /* Se non esiste ancora la tabella nel blocco (stato vuoto → primo prezzo),
         crea il wrapper + table + thead + tbody e rimuove il messaggio vuoto */
      let tbody = document.getElementById(`qks-ptbody-${pfId}`);
      if (!tbody) {
        const block = document.getElementById(`qks-block-${pfId}`);
        if (!block) return;

        /* Rimuove il messaggio "nessun prezzo" */
        block.querySelector('[data-type="empty"]')?.remove();

        /* Crea la struttura tabella */
        const wrap  = document.createElement('div');
        wrap.className = 'qks-price-wrap';

        const table = document.createElement('table');
        table.className = 'qks-price-table';
        table.innerHTML = `
          <thead>
            <tr>
              <th>Listino</th>
              <th class="text-end qks-col-qtamin" style="width:90px">Q.tà min.</th>
              <th class="text-end" style="width:105px">Prezzo</th>
              <th class="text-end" style="width:80px">Sconto %</th>
              <th class="qks-col-dal" style="width:105px">Valido dal</th>
              <th class="qks-col-al"  style="width:105px">Valido al</th>
              <th class="qks-col-note">Note</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
        `;

        tbody = document.createElement('tbody');
        tbody.id           = `qks-ptbody-${pfId}`;
        tbody.dataset.pfId = pfId;

        table.appendChild(tbody);
        wrap.appendChild(table);
        block.appendChild(wrap);
      }

      /* Costruisce le opzioni del <select> listino */
      const optsHtml = `
        <option value="">Base (nessun listino)</option>
        ${listini.map(l =>
          `<option value="${l.id}" ${String(p.listino_acquisto_id) === String(l.id) ? 'selected' : ''}>${esc(l.nome_listino)}</option>`
        ).join('')}
      `;

      const tr          = document.createElement('tr');
      tr.id             = rowId;
      tr.className      = 'qks-price-edit-row';
      tr.dataset.pfId   = pfId;
      if (!isNew) tr.dataset.prezzoId = p.id;

      tr.innerHTML = `
        <!-- Listino -->
        <td>
          <select class="qk-form-control qk-form-control-sm" id="${rowId}-listino">
            ${optsHtml}
          </select>
        </td>
        <!-- Q.tà minima -->
        <td class="qks-col-qtamin">
          <input type="number" class="qk-form-control qk-form-control-sm text-end"
                 id="${rowId}-qta" value="${escA(p.quantita_minima ?? 0)}"
                 min="0" step="0.001" placeholder="0">
        </td>
        <!-- Prezzo -->
        <td>
          <input type="number" class="qk-form-control qk-form-control-sm text-end"
                 id="${rowId}-prezzo" value="${escA(p.prezzo ?? '')}"
                 min="0" step="0.0001" placeholder="0,00" required>
        </td>
        <!-- Sconto % -->
        <td>
          <input type="number" class="qk-form-control qk-form-control-sm text-end"
                 id="${rowId}-sconto" value="${escA(p.sconto_perc ?? 0)}"
                 min="0" max="100" step="0.01" placeholder="0">
        </td>
        <!-- Valido dal -->
        <td class="qks-col-dal">
          <input type="date" class="qk-form-control qk-form-control-sm"
                 id="${rowId}-dal" value="${escA(p.valido_dal ?? '')}">
        </td>
        <!-- Valido al -->
        <td class="qks-col-al">
          <input type="date" class="qk-form-control qk-form-control-sm"
                 id="${rowId}-al" value="${escA(p.valido_al ?? '')}">
        </td>
        <!-- Note -->
        <td class="qks-col-note">
          <input type="text" class="qk-form-control qk-form-control-sm"
                 id="${rowId}-note" value="${escA(p.note ?? '')}" placeholder="…" maxlength="255">
        </td>
        <!-- Azioni inline -->
        <td class="col-actions">
          <div class="d-flex gap-1 justify-content-end">
            <button type="button"
                    class="btn-qk btn-qk-primary btn-qk-xs qks-btn-save-prezzo-inline"
                    data-row-id="${rowId}"
                    data-pf-id="${pfId}"
                    data-prezzo-id="${p.id ?? 0}"
                    title="Salva">
              <i class="fa-solid fa-check"></i>
            </button>
            <button type="button"
                    class="btn-qk btn-qk-secondary btn-qk-xs qks-btn-cancel-prezzo"
                    data-row-id="${rowId}"
                    data-prezzo-id="${p.id ?? 0}"
                    data-pf-id="${pfId}"
                    title="Annulla">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>
        </td>
      `;

      /* Inserisce la riga nel tbody della mini-tabella del fornitore */
      if (refTr && refTr.nextSibling) {
        tbody.insertBefore(tr, refTr.nextSibling);
      } else {
        tbody.appendChild(tr);
      }

      /* Focus + scroll */
      document.getElementById(`${rowId}-prezzo`)?.focus();
      tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }


    /* ──────────────────────────────────────────────────────────────────────
       INIZIALIZZAZIONE
    ────────────────────────────────────────────────────────────────────── */

    renderAll();


    /* ──────────────────────────────────────────────────────────────────────
       GESTIONE EVENTI — delegazione su #fornitori-container
       (sostituisce la delegazione su tbody della vecchia tabella piatta)
    ────────────────────────────────────────────────────────────────────── */

    container.addEventListener('click', async function (e) {
      const btn = e.target.closest('button[class]');
      if (!btn) return;

      /* ── + Prezzo ─────────────────────────────────────────────────── */
      if (btn.classList.contains('qks-btn-add-prezzo')) {
        e.preventDefault();
        const pfId        = Number(btn.dataset.pfId);
        const fornitoreId = Number(btn.dataset.fornitoreId);

        /* Trova l'ultima riga prezzo del tbody di questo fornitore */
        const tbody  = document.getElementById(`qks-ptbody-${pfId}`);
        const lastTr = tbody ? tbody.lastElementChild : null;
        await insertEditRow(pfId, fornitoreId, {}, lastTr);
        return;
      }

      /* ── Modifica prezzo ──────────────────────────────────────────── */
      if (btn.classList.contains('qks-btn-edit-prezzo')) {
        e.preventDefault();
        const prezzoId = Number(btn.dataset.prezzoId);
        const pfId     = Number(btn.dataset.pfId);

        const p    = (prezziMap.get(pfId) || []).find(x => Number(x.id) === prezzoId);
        const forn = fornitori.find(f => Number(f.id) === pfId);
        if (!p || !forn) return;

        const readRow = document.getElementById(`qks-prow-${prezzoId}`);
        await insertEditRow(pfId, Number(forn.fornitore_id), p, readRow);
        if (readRow) readRow.classList.add('d-none');
        return;
      }

      /* ── Salva prezzo inline ──────────────────────────────────────── */
      if (btn.classList.contains('qks-btn-save-prezzo-inline')) {
        e.preventDefault();
        await handleSavePrezzo(btn);
        return;
      }

      /* ── Annulla modifica prezzo ──────────────────────────────────── */
      if (btn.classList.contains('qks-btn-cancel-prezzo')) {
        e.preventDefault();
        const rowId    = btn.dataset.rowId;
        const prezzoId = Number(btn.dataset.prezzoId);
        const pfId     = Number(btn.dataset.pfId);

        document.getElementById(rowId)?.remove();

        /* Se era un edit (prezzoId > 0), ripristina la riga read-only.
           Se era un insert nuovo e non ci sono altri prezzi, mostra stato vuoto. */
        if (prezzoId > 0) {
          document.getElementById(`qks-prow-${prezzoId}`)?.classList.remove('d-none');
        } else {
          /* Controlla se la tabella è rimasta vuota */
          const tbody = document.getElementById(`qks-ptbody-${pfId}`);
          if (tbody && tbody.querySelectorAll('tr:not(.d-none)').length === 0) {
            rerenderFornitoreGroup(pfId);
          }
        }
        return;
      }

      /* ── Elimina prezzo ───────────────────────────────────────────── */
      if (btn.classList.contains('qks-btn-delete-prezzo')) {
        e.preventDefault();
        const prezzoId = Number(btn.dataset.prezzoId);
        const pfId     = Number(btn.dataset.pfId);
        await handleDeletePrezzo(prezzoId, pfId);
        return;
      }

      /* ── Elimina fornitore ────────────────────────────────────────── */
      if (btn.classList.contains('qks-btn-delete-forn')) {
        e.preventDefault();
        const id   = Number(btn.dataset.id);
        const nome = btn.dataset.nome;
        if (!confirm(`Rimuovere il fornitore "${nome}" da questo prodotto?`)) return;

        try {
          const data = await postAjax(cfg.apiFornDelete, {
            id:          id,
            prodotto_id: prodId,
          });
          if (data.ok) {
            fornitori = fornitori.filter(f => Number(f.id) !== id);
            prezziMap.delete(id);
            renderAll();
          } else {
            alert('Errore durante la rimozione: ' + (data.error || 'Errore sconosciuto'));
          }
        } catch (err) {
          alert('Errore di rete: ' + err.message);
        }
        return;
      }
    });


    /* ──────────────────────────────────────────────────────────────────────
       SALVATAGGIO PREZZO (inline)
    ────────────────────────────────────────────────────────────────────── */

    /**
     * Legge il form inline, invia via AJAX, aggiorna stato locale,
     * ri-renderizza solo il gruppo interessato.
     * @param {HTMLButtonElement} btn — il pulsante "Salva" ✓
     */
    async function handleSavePrezzo(btn) {
      const rowId = btn.dataset.rowId;
      const pfId  = Number(btn.dataset.pfId);
      const id    = Number(btn.dataset.prezzoId); // 0 = insert

      const get = suffix => document.getElementById(`${rowId}-${suffix}`);

      const fields = {
        id:                    id || 0,
        prodotto_fornitore_id: pfId,
        prodotto_id:           prodId,
        listino_acquisto_id:   get('listino')?.value  || '',
        quantita_minima:       get('qta')?.value      || 0,
        prezzo:                get('prezzo')?.value   || 0,
        sconto_perc:           get('sconto')?.value   || 0,
        valido_dal:            get('dal')?.value      || '',
        valido_al:             get('al')?.value       || '',
        note:                  get('note')?.value     || '',
      };

      const prezzo = parseFloat(fields.prezzo);
      if (isNaN(prezzo) || prezzo < 0) {
        alert('Inserisci un prezzo valido (≥ 0).');
        get('prezzo')?.focus();
        return;
      }

      btn.disabled  = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

      try {
        const data = await postAjax(cfg.apiPrezzoSave, fields);

        if (!data.ok) {
          alert('Errore nel salvataggio: ' + (data.error || 'Errore sconosciuto'));
          return;
        }

        /* Aggiorna la mappa locale */
        const savedRow = data.row;
        const pArr = prezziMap.get(pfId) || [];
        if (id > 0) {
          const idx = pArr.findIndex(x => Number(x.id) === id);
          if (idx >= 0) pArr[idx] = savedRow; else pArr.push(savedRow);
        } else {
          pArr.push(savedRow);
        }
        prezziMap.set(pfId, pArr);

        /* Re-render solo il blocco di questo fornitore */
        rerenderFornitoreGroup(pfId);

      } catch (err) {
        alert('Errore di rete: ' + err.message);
      } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
      }
    }


    /* ──────────────────────────────────────────────────────────────────────
       ELIMINAZIONE PREZZO
    ────────────────────────────────────────────────────────────────────── */

    /**
     * Elimina un prezzo via AJAX, aggiorna stato e DOM.
     */
    async function handleDeletePrezzo(prezzoId, pfId) {
      if (!confirm('Eliminare questo scaglione di prezzo?')) return;

      try {
        const data = await postAjax(cfg.apiPrezzoDelete, {
          id:          prezzoId,
          prodotto_id: prodId,
        });

        if (!data.ok) {
          alert('Errore durante l\'eliminazione: ' + (data.error || 'Errore sconosciuto'));
          return;
        }

        const pArr = prezziMap.get(pfId) || [];
        prezziMap.set(pfId, pArr.filter(x => Number(x.id) !== prezzoId));
        rerenderFornitoreGroup(pfId);

      } catch (err) {
        alert('Errore di rete: ' + err.message);
      }
    }


    /* ──────────────────────────────────────────────────────────────────────
       HELPERS DOM
    ────────────────────────────────────────────────────────────────────── */

    /**
     * Rimuove e ri-renderizza solo il blocco del fornitore pfId,
     * preservando l'ordine relativo agli altri blocchi.
     * @param {number} pfId
     */
    function rerenderFornitoreGroup(pfId) {
      const oldBlock = document.getElementById(`qks-block-${pfId}`);
      const forn     = fornitori.find(f => Number(f.id) === pfId);

      if (!forn) {
        /* Il fornitore non esiste più: rimuovi il blocco */
        oldBlock?.remove();
        return;
      }

      const newBlock = buildSupplierBlock(forn);

      if (oldBlock) {
        /* Sostituisce in-place mantenendo la posizione */
        container.replaceChild(newBlock, oldBlock);
      } else {
        /* Non trovato (caso raro): appende in fondo e riordina */
        container.appendChild(newBlock);
      }
    }


    /* ──────────────────────────────────────────────────────────────────────
       EVENTI CUSTOM — da products-form.js (salva / elimina fornitore)
    ────────────────────────────────────────────────────────────────────── */

    /**
     * Ascolta qk:forn-saved: aggiunge o aggiorna il fornitore.
     * Il detail.row è il record restituito da saveForn().
     */
    document.addEventListener('qk:forn-saved', function (e) {
      const row = e.detail?.row;
      if (!row) return;

      const pfId = Number(row.id);
      const idx  = fornitori.findIndex(f => Number(f.id) === pfId);

      if (idx >= 0) {
        fornitori[idx] = row;
        rerenderFornitoreGroup(pfId);
      } else {
        fornitori.push(row);
        prezziMap.set(pfId, []);
        /* Aggiunge il nuovo blocco in fondo, poi riordina tutto */
        renderAll();
      }
    });

    /**
     * Ascolta qk:forn-deleted: rimuove il fornitore.
     */
    document.addEventListener('qk:forn-deleted', function (e) {
      const id = Number(e.detail?.id);
      if (!id) return;
      fornitori = fornitori.filter(f => Number(f.id) !== id);
      prezziMap.delete(id);
      renderAll();
    });

  }); // fine DOMContentLoaded

})(); // fine IIFE
