/**
 * customers-contacts.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Gestione AJAX della tab "Contatti" nella scheda cliente.
 *
 * Dipendenze
 * ──────────
 *   window.QkCustomer  — iniettato da customers/_form.php (solo in edit mode)
 *     .customerId      (int)    — id del cliente corrente
 *     .contatti        (array)  — contatti pre-caricati da PHP
 *     .apiList         (string) — URL GET lista contatti
 *     .apiSave         (string) — URL POST salva contatto
 *     .apiDelete       (string) — URL POST elimina contatto
 *     .csrfToken       (string) — token CSRF corrente
 *
 * suppliers-contacts.css — incluso da CustomersController via $extra_css (stesso design e classi)
 */

/* global QkCustomer, bootstrap */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    const cfg = window.QkCustomer;
    if (!cfg) return; // sicurezza: non siamo in edit mode

    /* ── Riferimenti DOM ─────────────────────────────────────────────────── */
    const grid        = document.getElementById('qksc-grid');
    const badge       = document.getElementById('badge-contatti');
    const modalEl     = document.getElementById('modalContatto');
    const form        = document.getElementById('formContatto');
    const modalTitle  = document.getElementById('modalContattoTitle');
    const errBox      = document.getElementById('modalContattoErr');
    const saveBtn     = document.getElementById('btnSalvaContatto');

    if (!grid || !modalEl || !form) return;

    const bsModal = new bootstrap.Modal(modalEl);

    /* ── Stato locale ────────────────────────────────────────────────────── */
    /** @type {Map<number, Object>} */
    let contattiMap = new Map();

    // Carica i dati pre-iniettati da PHP
    (cfg.contatti || []).forEach(c => contattiMap.set(c.id, c));

    /* ── Utilities ───────────────────────────────────────────────────────── */

    function esc(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    /**
     * Calcola un colore (0–7) deterministico dal nome del contatto,
     * usato per le varianti di colore dell'avatar.
     */
    function avatarColor(nome, cognome) {
      const s = (nome + cognome).toUpperCase();
      let h = 0;
      for (let i = 0; i < s.length; i++) {
        h = (h * 31 + s.charCodeAt(i)) & 0xffff;
      }
      return h % 8;
    }

    function initials(nome, cognome) {
      const n = (nome || '').trim();
      const c = (cognome || '').trim();
      if (n && c) return (n[0] + c[0]).toUpperCase();
      if (c) return c.slice(0, 2).toUpperCase();
      if (n) return n.slice(0, 2).toUpperCase();
      return '?';
    }

    /* ── Render ──────────────────────────────────────────────────────────── */

    function renderGrid() {
      grid.innerHTML = '';

      if (contattiMap.size === 0) {
        const empty = document.createElement('div');
        empty.className = 'qksc-empty';
        empty.id = 'qksc-empty';
        empty.innerHTML =
          '<i class="fa-solid fa-address-book"></i>' +
          '<div>Nessun contatto ancora.<br>Usa il pulsante per aggiungerne uno.</div>';
        grid.appendChild(empty);
      } else {
        contattiMap.forEach(c => grid.appendChild(buildTile(c)));
      }

      // Tile "aggiungi"
      const addTile = document.createElement('div');
      addTile.className = 'qksc-tile qksc-tile-add';
      addTile.setAttribute('role', 'button');
      addTile.setAttribute('tabindex', '0');
      addTile.setAttribute('aria-label', 'Aggiungi contatto');
      addTile.innerHTML =
        '<i class="fa-solid fa-plus"></i>' +
        '<span>Aggiungi contatto</span>';
      addTile.addEventListener('click', () => openModal(null));
      addTile.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openModal(null); }
      });
      grid.appendChild(addTile);

      updateBadge();
    }

    function buildTile(c) {
      const tile = document.createElement('div');
      tile.className = 'qksc-tile';
      tile.dataset.id = c.id;

      const color = avatarColor(c.nome, c.cognome);
      const ini   = initials(c.nome, c.cognome);
      const nome  = [c.nome, c.cognome].filter(Boolean).join(' ') || '(senza nome)';

      let detailsHtml = '';
      if (c.email) {
        detailsHtml += `<div class="qksc-detail-row">
          <i class="fa-solid fa-envelope"></i>
          <a href="mailto:${esc(c.email)}" title="${esc(c.email)}">${esc(c.email)}</a>
        </div>`;
      }
      if (c.telefono) {
        detailsHtml += `<div class="qksc-detail-row">
          <i class="fa-solid fa-phone"></i>
          <a href="tel:${esc(c.telefono)}">${esc(c.telefono)}</a>
        </div>`;
      }
      if (c.cellulare) {
        detailsHtml += `<div class="qksc-detail-row">
          <i class="fa-solid fa-mobile-screen-button"></i>
          <a href="tel:${esc(c.cellulare)}">${esc(c.cellulare)}</a>
        </div>`;
      }

      tile.innerHTML = `
        <div class="qksc-tile-header">
          <div class="qksc-avatar" data-color="${color}">${esc(ini)}</div>
          <div>
            <div class="qksc-tile-name">${esc(nome)}</div>
            ${c.ruolo ? `<div class="qksc-tile-role">${esc(c.ruolo)}</div>` : ''}
          </div>
        </div>
        ${detailsHtml ? `<div class="qksc-tile-details">${detailsHtml}</div>` : ''}
        <div class="qksc-tile-actions">
          <button class="qksc-btn-action btn-edit" data-id="${c.id}" type="button">
            <i class="fa-solid fa-pen-to-square"></i> Modifica
          </button>
          <button class="qksc-btn-action danger btn-delete" data-id="${c.id}" type="button">
            <i class="fa-solid fa-trash"></i> Elimina
          </button>
        </div>`;

      tile.querySelector('.btn-edit').addEventListener('click', () => openModal(c.id));
      tile.querySelector('.btn-delete').addEventListener('click', () => confirmDelete(c.id));

      return tile;
    }

    function updateBadge() {
      if (!badge) return;
      const n = contattiMap.size;
      badge.textContent = n;
      badge.classList.toggle('d-none', n === 0);
    }

    /* ── Modal ───────────────────────────────────────────────────────────── */

    function openModal(contactId) {
      form.reset();
      hideModalErr();
      saveBtn.disabled = false;

      if (contactId) {
        const c = contattiMap.get(contactId);
        if (!c) return;
        modalTitle.textContent = 'Modifica contatto';
        form.elements['contact_id'].value  = c.id;
        form.elements['nome'].value        = c.nome      || '';
        form.elements['cognome'].value     = c.cognome   || '';
        form.elements['ruolo'].value       = c.ruolo     || '';
        form.elements['email'].value       = c.email     || '';
        form.elements['telefono'].value    = c.telefono  || '';
        form.elements['cellulare'].value   = c.cellulare || '';
      } else {
        modalTitle.textContent = 'Nuovo contatto';
        form.elements['contact_id'].value = '0';
      }

      bsModal.show();
    }

    function showModalErr(msg) {
      const span = errBox.querySelector('span') || errBox;
      span.textContent = msg;
      errBox.classList.remove('d-none');
    }

    function hideModalErr() {
      const span = errBox.querySelector('span') || errBox;
      span.textContent = '';
      errBox.classList.add('d-none');
    }

    /* ── Helper fetch JSON ───────────────────────────────────────────────── */

    /**
     * POST wrapper con gestione robusta degli errori.
     * DRY 27/07/2026 (PIANO qkApiFetch): validazione HTTP/content-type e
     * parsing delegati a window.qkApiFetch() (assets/js/app.js), condiviso
     * con altri 5 file che duplicavano la stessa logica. Contratto verso i
     * chiamanti invariato: su risposta non valida lancia un Error col
     * messaggio generico già mostrato in precedenza.
     */
    async function postJson(url, formData) {
      try {
        return await window.qkApiFetch(url, { method: 'POST', body: formData });
      } catch (e) {
        console.error('[customers-contacts]', e.message);
        throw new Error('Errore del server. Riprova o controlla la console.');
      }
    }

    /* ── Salvataggio ─────────────────────────────────────────────────────── */

    saveBtn.addEventListener('click', async function () {
      hideModalErr();
      saveBtn.disabled = true;

      const fd = new FormData(form);
      fd.set('customer_id', cfg.customerId);
      fd.set('csrf_token',  cfg.csrfToken);

      try {
        const data = await postJson(cfg.apiSave, fd);

        if (!data.ok) {
          showModalErr(data.error || 'Errore sconosciuto.');
          saveBtn.disabled = false;
          return;
        }

        if (!data.contact) {
          // Fallback: ricarica tutta la lista dalla API
          const list = await postJson(cfg.apiList.replace('POST', 'GET') || cfg.apiList, new FormData());
          window.location.reload();
          return;
        }

        // Aggiorna lo stato locale e re-renderizza
        contattiMap.set(data.contact.id, data.contact);
        renderGrid();
        bsModal.hide();

      } catch (e) {
        console.error('[customers-contacts] Errore salvataggio:', e);
        showModalErr(e.message || 'Errore di rete. Riprova.');
        saveBtn.disabled = false;
      }
    });

    /* ── Eliminazione ────────────────────────────────────────────────────── */

    async function confirmDelete(contactId) {
      const c = contattiMap.get(contactId);
      if (!c) return;

      const nome = [c.nome, c.cognome].filter(Boolean).join(' ') || 'questo contatto';
      if (!confirm(`Eliminare ${nome}?`)) return;

      const fd = new FormData();
      fd.set('customer_id', cfg.customerId);
      fd.set('contact_id',  contactId);
      fd.set('csrf_token',  cfg.csrfToken);

      try {
        const data = await postJson(cfg.apiDelete, fd);

        if (!data.ok) {
          alert('Errore: ' + (data.error || 'impossibile eliminare.'));
          return;
        }

        contattiMap.delete(contactId);
        renderGrid();

      } catch (e) {
        console.error('[customers-contacts] Errore eliminazione:', e);
        alert(e.message || 'Errore di rete. Riprova.');
      }
    }

    /* ── Init ────────────────────────────────────────────────────────────── */
    renderGrid();

  }); // DOMContentLoaded

})();
