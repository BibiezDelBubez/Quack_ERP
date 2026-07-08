/**
 * warehouse-transfer.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Logica interattiva del form "Nuovo trasferimento tra magazzini":
 *   - Righe ripetibili (aggiungi/rimuovi prodotto da trasferire)
 *   - Ricerca prodotto con autocomplete per riga (debounced)
 *   - Select lotto per riga, filtrata sul magazzino di ORIGINE selezionato
 *     (un prodotto può avere lotti disponibili in un magazzino e non in un altro)
 *   - Avviso se origine e destinazione coincidono
 *
 * Dipendenze: window.QkTrasferimenti (iniettato da transfers_form.php)
 */

/* global QkTrasferimenti */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.QkTrasferimenti;
    if (!cfg) return;

    const righeBody     = document.getElementById('righeBody');
    const btnAddRiga     = document.getElementById('btnAddRiga');
    const selectOrigine  = document.getElementById('selectOrigine');
    const selectDest     = document.getElementById('selectDestinazione');
    const warnStesso     = document.getElementById('stessoMagazzinoWarn');
    const form           = document.getElementById('formTrasferimento');

    let rigaSeq = 0;

    function checkStessoMagazzino() {
      const o = selectOrigine.value;
      const d = selectDest.value;
      const stesso = o !== '' && d !== '' && o === d;
      warnStesso.hidden = !stesso;
      return stesso;
    }

    selectOrigine.addEventListener('change', function () {
      checkStessoMagazzino();
      // Il magazzino di origine determina quali lotti sono disponibili:
      // ricarica le select lotto di tutte le righe con un prodotto già scelto.
      righeBody.querySelectorAll('tr').forEach(function (tr) {
        const prodId = tr.querySelector('.riga-prodotto-id').value;
        if (prodId) caricaLottiRiga(tr, prodId);
      });
    });
    selectDest.addEventListener('change', checkStessoMagazzino);

    if (form) {
      form.addEventListener('submit', function (e) {
        if (checkStessoMagazzino()) {
          e.preventDefault();
          return;
        }
        if (!righeBody.querySelector('tr')) {
          e.preventDefault();
          alert('Aggiungi almeno una riga prodotto.');
        }
      });
    }

    function debounce(fn, wait) {
      let t;
      return function (...args) {
        clearTimeout(t);
        t = setTimeout(function () { fn.apply(null, args); }, wait);
      };
    }

    function caricaLottiRiga(tr, prodottoId) {
      const selectLotto = tr.querySelector('.riga-lotto-select');
      const magazzinoId = selectOrigine.value;
      selectLotto.innerHTML = '<option value="">— nessun lotto —</option>';
      selectLotto.disabled = true;
      if (!prodottoId || !magazzinoId) return;

      fetch(cfg.apiLottiPerMagazzino + '?prodotto_id=' + encodeURIComponent(prodottoId) + '&magazzino_id=' + encodeURIComponent(magazzinoId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) return;
          selectLotto.disabled = false;
          (data.lotti || []).forEach(function (l) {
            const opt = document.createElement('option');
            opt.value = l.id;
            const codice = l.codice_lotto_interno || l.codice_lotto || ('#' + l.id);
            const qta = (l.quantita_disponibile !== undefined) ? parseFloat(l.quantita_disponibile).toFixed(3) : '';
            opt.textContent = codice + (qta !== '' ? ' — disp. ' + qta : '');
            selectLotto.appendChild(opt);
          });
        })
        .catch(function () { /* silenzioso: il campo lotto resta vuoto */ });
    }

    function creaRiga() {
      rigaSeq++;
      const tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' +
          '<div class="qkwh-prod-search-wrap" style="position:relative">' +
            '<input type="text" class="qk-form-control riga-prodotto-search" placeholder="Cerca per nome o SKU…" autocomplete="off">' +
            '<input type="hidden" class="riga-prodotto-id" name="riga_prodotto_id[]" value="">' +
            '<div class="qkwh-dropdown riga-prodotto-dropdown" hidden style="position:absolute;z-index:20;background:var(--qk-bg);border:1px solid var(--qk-border);width:100%;max-height:220px;overflow-y:auto"></div>' +
          '</div>' +
        '</td>' +
        '<td>' +
          '<select class="qk-form-control riga-lotto-select" name="riga_lotto_id[]" disabled>' +
            '<option value="">— nessun lotto —</option>' +
          '</select>' +
        '</td>' +
        '<td><input type="number" class="qk-form-control riga-quantita" name="riga_quantita[]" step="0.001" min="0" placeholder="0"></td>' +
        '<td><button type="button" class="qk-btn-icon riga-remove" title="Rimuovi riga"><i class="fa-solid fa-trash"></i></button></td>';

      righeBody.appendChild(tr);

      const search   = tr.querySelector('.riga-prodotto-search');
      const hiddenId = tr.querySelector('.riga-prodotto-id');
      const dropdown = tr.querySelector('.riga-prodotto-dropdown');
      const removeBtn = tr.querySelector('.riga-remove');

      const doSearch = debounce(function () {
        const q = search.value.trim();
        if (q.length < 1) { dropdown.hidden = true; dropdown.innerHTML = ''; return; }
        fetch(cfg.apiProdottiSearch + '?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            dropdown.innerHTML = '';
            const prodotti = (data.ok && data.prodotti) ? data.prodotti : [];
            if (!prodotti.length) { dropdown.hidden = true; return; }
            prodotti.forEach(function (p) {
              const item = document.createElement('div');
              item.className = 'qkwh-dropdown-item';
              item.style.padding = '.4rem .6rem';
              item.style.cursor = 'pointer';
              item.textContent = p.sku_interno ? (p.nome + ' — ' + p.sku_interno) : p.nome;
              item.addEventListener('click', function () {
                hiddenId.value = p.id;
                search.value = p.nome;
                dropdown.hidden = true;
                caricaLottiRiga(tr, p.id);
              });
              dropdown.appendChild(item);
            });
            dropdown.hidden = false;
          })
          .catch(function () { dropdown.hidden = true; });
      }, 250);

      search.addEventListener('input', function () {
        hiddenId.value = ''; // invalida la selezione precedente finché non si sceglie di nuovo
        doSearch();
      });
      search.addEventListener('blur', function () {
        setTimeout(function () { dropdown.hidden = true; }, 150);
      });

      removeBtn.addEventListener('click', function () {
        tr.remove();
      });

      return tr;
    }

    btnAddRiga.addEventListener('click', function () { creaRiga(); });

    // Almeno una riga alla partenza
    creaRiga();
  });
})();
