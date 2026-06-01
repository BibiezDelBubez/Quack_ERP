/**
 * customers-addresses.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Gestione AJAX della tab "Sedi e Indirizzi" nella scheda cliente.
 *
 * Dipendenze
 * ──────────
 *   window.QkCustomerAddr  — iniettato da customers/_form.php (solo in edit mode)
 *     .customerId  (int)    — id del cliente corrente
 *     .indirizzi   (array)  — indirizzi pre-caricati da PHP
 *     .apiSave     (string) — URL POST salva indirizzo
 *     .apiDelete   (string) — URL POST elimina indirizzo
 *     .csrfToken   (string) — token CSRF corrente
 *
 * customers-addresses.css — incluso da CustomersController via $extra_css
 */

/* global QkCustomerAddr, bootstrap */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    const cfg = window.QkCustomerAddr;
    if (!cfg) return; // non siamo in edit mode

    /* ── Riferimenti DOM ─────────────────────────────────────────────────── */
    const grid      = document.getElementById('qksa-grid');
    const badge     = document.getElementById('badge-indirizzi');
    const modalEl   = document.getElementById('modalIndirizzo');
    const form      = document.getElementById('formIndirizzo');
    const modalTitle = document.getElementById('modalIndirizzoTitle');
    const errBox    = document.getElementById('modalIndirizzoErr');
    const saveBtn   = document.getElementById('btnSalvaIndirizzo');

    if (!grid || !modalEl || !form) return;

    const bsModal = new bootstrap.Modal(modalEl);

    /* ── Stato locale ────────────────────────────────────────────────────── */
    /** @type {Map<number, Object>} */
    let indMap = new Map();

    (cfg.indirizzi || []).forEach(a => indMap.set(a.id, a));

    /* ── Dizionari ───────────────────────────────────────────────────────── */
    const TIPO_LABEL = {
      sede_legale:    'Sede legale',
      sede_operativa: 'Sede operativa',
      spedizione:     'Spedizione',
      fatturazione:   'Fatturazione',
      magazzino:      'Magazzino',
    };

    const TIPO_ICON = {
      sede_legale:    'fa-solid fa-landmark',
      sede_operativa: 'fa-solid fa-building',
      spedizione:     'fa-solid fa-truck',
      fatturazione:   'fa-solid fa-file-invoice',
      magazzino:      'fa-solid fa-warehouse',
    };

    /* ── Utilities ───────────────────────────────────────────────────────── */

    function esc(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function formatAddress(a) {
      const parts = [];
      if (a.presso)   parts.push('c/o ' + a.presso);
      const street = [a.via, a.civico].filter(Boolean).join(' ');
      if (street)     parts.push(street);
      const locality = [a.cap, a.citta, a.provincia].filter(Boolean).join(' ');
      if (locality)   parts.push(locality);
      if (a.nazione && a.nazione !== 'IT') parts.push(a.nazione);
      return parts.join('\n');
    }

    /* ── Render ──────────────────────────────────────────────────────────── */

    function renderGrid() {
      grid.innerHTML = '';

      if (indMap.size === 0) {
        const empty = document.createElement('div');
        empty.className = 'qksa-empty';
        empty.innerHTML =
          '<i class="fa-solid fa-map-location-dot"></i>' +
          '<div>Nessuna sede registrata.<br>' +
          'Aggiungi almeno la <strong>Sede legale</strong> per completare il profilo del cliente.</div>';
        grid.appendChild(empty);
      } else {
        // Ordina: predefiniti prima, poi per tipo
        const sorted = [...indMap.values()].sort((a, b) => {
          if (b.predefinito !== a.predefinito) return b.predefinito ? 1 : -1;
          return (TIPO_LABEL[a.tipo] || a.tipo).localeCompare(TIPO_LABEL[b.tipo] || b.tipo);
        });
        sorted.forEach(a => grid.appendChild(buildTile(a)));
      }

      // Tile "aggiungi"
      const addTile = document.createElement('div');
      addTile.className = 'qksa-tile qksa-tile-add';
      addTile.setAttribute('role', 'button');
      addTile.setAttribute('tabindex', '0');
      addTile.setAttribute('aria-label', 'Aggiungi sede o indirizzo');
      addTile.innerHTML =
        '<i class="fa-solid fa-plus"></i>' +
        '<span>Aggiungi sede / indirizzo</span>';
      addTile.addEventListener('click', () => openModal(null));
      addTile.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openModal(null); }
      });
      grid.appendChild(addTile);

      updateBadge();
    }

    function buildTile(a) {
      const tile = document.createElement('div');
      tile.className = 'qksa-tile';
      tile.dataset.id = a.id;

      const tipoLabel = esc(TIPO_LABEL[a.tipo] || a.tipo);
      const tipoIcon  = TIPO_ICON[a.tipo] || 'fa-solid fa-map-pin';
      const address   = esc(formatAddress(a)).replace(/\n/g, '<br>');
      const alias     = a.nome_sede
        ? `<div class="qksa-tile-alias">${esc(a.nome_sede)}</div>`
        : '';

      let deliveryHtml = '';
      if (a.referente_consegna) {
        deliveryHtml += `<div class="qksa-delivery-row">
          <i class="fa-solid fa-user-tie"></i>
          <span>${esc(a.referente_consegna)}</span>
        </div>`;
      }
      if (a.telefono_consegna) {
        deliveryHtml += `<div class="qksa-delivery-row">
          <i class="fa-solid fa-phone"></i>
          <a href="tel:${esc(a.telefono_consegna)}">${esc(a.telefono_consegna)}</a>
        </div>`;
      }

      const noteHtml = a.note_consegna
        ? `<div class="qksa-tile-note" title="${esc(a.note_consegna)}">${esc(a.note_consegna)}</div>`
        : '';

      tile.innerHTML = `
        <div class="qksa-tile-header">
          <div class="qksa-tile-header-text">
            <span class="qksa-badge" data-tipo="${esc(a.tipo)}">
              <i class="${tipoIcon}"></i> ${tipoLabel}
            </span>
            ${alias}
          </div>
          <i class="fa-solid fa-star qksa-star ${a.predefinito ? 'is-predefinito' : ''}"
             title="${a.predefinito ? 'Indirizzo predefinito' : 'Non predefinito'}"></i>
        </div>
        <div class="qksa-tile-address">
          <i class="fa-solid fa-location-dot"></i>
          <div>${address}</div>
        </div>
        ${deliveryHtml ? `<div class="qksa-tile-delivery">${deliveryHtml}</div>` : ''}
        ${noteHtml}
        <div class="qksa-tile-actions">
          <button class="qksa-btn-action btn-edit" data-id="${a.id}" type="button">
            <i class="fa-solid fa-pen-to-square"></i> Modifica
          </button>
          <button class="qksa-btn-action danger btn-delete" data-id="${a.id}" type="button">
            <i class="fa-solid fa-trash"></i> Elimina
          </button>
        </div>`;

      tile.querySelector('.btn-edit').addEventListener('click', () => openModal(a.id));
      tile.querySelector('.btn-delete').addEventListener('click', () => confirmDelete(a.id));

      return tile;
    }

    function updateBadge() {
      if (!badge) return;
      const n = indMap.size;
      badge.textContent = n;
      badge.classList.toggle('d-none', n === 0);
    }

    /* ── Modal ───────────────────────────────────────────────────────────── */

    function openModal(addressId) {
      form.reset();
      hideModalErr();
      saveBtn.disabled = false;
      // Reset nazione default
      form.elements['nazione'].value = 'IT';

      if (addressId) {
        const a = indMap.get(addressId);
        if (!a) return;
        modalTitle.textContent = 'Modifica indirizzo';
        form.elements['address_id'].value          = a.id;
        form.elements['tipo'].value                = a.tipo;
        form.elements['nome_sede'].value           = a.nome_sede          || '';
        form.elements['predefinito'].checked       = !!a.predefinito;
        form.elements['presso'].value              = a.presso             || '';
        form.elements['via'].value                 = a.via                || '';
        form.elements['civico'].value              = a.civico             || '';
        form.elements['cap'].value                 = a.cap                || '';
        form.elements['citta'].value               = a.citta              || '';
        form.elements['provincia'].value           = a.provincia          || '';
        form.elements['nazione'].value             = a.nazione            || 'IT';
        form.elements['referente_consegna'].value  = a.referente_consegna || '';
        form.elements['telefono_consegna'].value   = a.telefono_consegna  || '';
        form.elements['note_consegna'].value       = a.note_consegna      || '';
      } else {
        modalTitle.textContent = 'Nuovo indirizzo';
        form.elements['address_id'].value = '0';
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

    async function postJson(url, formData) {
      const res = await fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (_) {
        console.error('[customers-addresses] Risposta non-JSON da', url);
        console.error('[customers-addresses] HTTP status:', res.status);
        console.error('[customers-addresses] Body raw:', text);
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
      // Checkbox non invia valore se deselezionata — forziamo esplicitamente
      fd.set('predefinito', form.elements['predefinito'].checked ? '1' : '0');

      try {
        const data = await postJson(cfg.apiSave, fd);

        if (!data.ok) {
          showModalErr(data.error || 'Errore sconosciuto.');
          saveBtn.disabled = false;
          return;
        }

        // Il server restituisce l'elenco aggiornato completo
        if (data.all) {
          indMap.clear();
          data.all.forEach(a => indMap.set(a.id, a));
        } else if (data.address) {
          indMap.set(data.address.id, data.address);
        }

        renderGrid();
        bsModal.hide();

      } catch (e) {
        console.error('[customers-addresses] Errore salvataggio:', e);
        showModalErr(e.message || 'Errore di rete. Riprova.');
        saveBtn.disabled = false;
      }
    });

    /* ── Eliminazione ────────────────────────────────────────────────────── */

    async function confirmDelete(addressId) {
      const a = indMap.get(addressId);
      if (!a) return;

      const label = a.nome_sede
        || [a.via, a.civico].filter(Boolean).join(' ')
        || 'questo indirizzo';

      if (!confirm(`Eliminare "${label}"?`)) return;

      const fd = new FormData();
      fd.set('customer_id', cfg.customerId);
      fd.set('address_id',  addressId);
      fd.set('csrf_token',  cfg.csrfToken);

      try {
        const data = await postJson(cfg.apiDelete, fd);

        if (!data.ok) {
          alert('Errore: ' + (data.error || 'impossibile eliminare.'));
          return;
        }

        indMap.delete(addressId);
        renderGrid();

      } catch (e) {
        console.error('[customers-addresses] Errore eliminazione:', e);
        alert(e.message || 'Errore di rete. Riprova.');
      }
    }

    /* ── Init ────────────────────────────────────────────────────────────── */
    renderGrid();

  }); // DOMContentLoaded

})();
