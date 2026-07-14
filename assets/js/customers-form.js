/**
 * customers-form.js — logica del form cliente (customers/_form.php)
 *
 * Estratto da due <script> inline nella View (Revisione Incrociata §7.1.2):
 *  1) Gestione righe tabella "Listini" (aggiungi/rimuovi/reindicizza)
 *  2) Toggle campi privato/azienda + visibilità sidebar in base al tab attivo
 *
 * I dati che dipendono da PHP (elenco listini disponibili) arrivano tramite
 * l'oggetto window.QkCustomerListini, impostato da un piccolo <script> inline
 * nella View subito prima di questo file (pattern identico a window.QkCustomer
 * usato per contatti/indirizzi/compliance).
 */
(function () {
  'use strict';

  // ───────────────────────────────────────────────────────────────────────
  // 1) Tabella Listini
  // ───────────────────────────────────────────────────────────────────────
  function initListini() {
    var tbody = document.getElementById('tbody-listini-clienti');
    var btnAdd = document.getElementById('btn-add-listino');
    if (!tbody && !btnAdd) {
      return;
    }
    var listiniData = (window.QkCustomerListini && window.QkCustomerListini.data) || [];

    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
    }

    function reindexRows() {
      var rows = tbody.querySelectorAll('.listino-row');
      rows.forEach(function (row, idx) {
        row.setAttribute('data-index', idx);
        row.querySelectorAll('[name]').forEach(function (input) {
          var nameAttr = input.getAttribute('name');
          var newName = nameAttr.replace(/listini\[\d+\]/, 'listini[' + idx + ']');
          input.setAttribute('name', newName);
        });
      });

      var placeholder = tbody.querySelector('.empty-row-placeholder');
      if (rows.length === 0) {
        if (!placeholder) {
          var tr = document.createElement('tr');
          tr.className = 'empty-row-placeholder';
          tr.innerHTML = '<td colspan="5" class="text-center py-3" style="color: var(--qk-text-muted);">Nessun listino specifico associato. Verrà applicato il Listino Standard.</td>';
          tbody.appendChild(tr);
        }
      } else if (placeholder) {
        placeholder.remove();
      }
    }

    if (btnAdd) {
      btnAdd.addEventListener('click', function () {
        var nextIdx = tbody.querySelectorAll('.listino-row').length;

        var tr = document.createElement('tr');
        tr.className = 'listino-row';
        tr.setAttribute('data-index', nextIdx);

        var optionsHtml = '<option value="">— Seleziona Listino —</option>';
        listiniData.forEach(function (lst) {
          optionsHtml += '<option value="' + lst.id + '">' + escapeHtml(lst.nome) + ' (' + escapeHtml(lst.valuta) + ')</option>';
        });

        tr.innerHTML =
          '<td>' +
            '<select name="listini[' + nextIdx + '][listino_id]" form="formCliente" class="qk-form-control qk-form-control-sm searchable-select" required>' +
              optionsHtml +
            '</select>' +
          '</td>' +
          '<td>' +
            '<input type="number" name="listini[' + nextIdx + '][priorita]" form="formCliente" class="qk-form-control qk-form-control-sm" value="10" min="1" step="1" required>' +
          '</td>' +
          '<td>' +
            '<input type="date" name="listini[' + nextIdx + '][data_inizio]" form="formCliente" class="qk-form-control qk-form-control-sm">' +
          '</td>' +
          '<td>' +
            '<input type="date" name="listini[' + nextIdx + '][data_fine]" form="formCliente" class="qk-form-control qk-form-control-sm">' +
          '</td>' +
          '<td class="text-center">' +
            '<button type="button" class="btn-qk btn-qk-danger btn-qk-sm btn-delete-listino-row">' +
              '<i class="fa-solid fa-trash-can"></i>' +
            '</button>' +
          '</td>';

        tbody.appendChild(tr);
        reindexRows();

        // Inizializza il searchable-select per la nuova riga
        var newSelect = tr.querySelector('.searchable-select');
        if (newSelect && typeof SearchableSelect !== 'undefined') {
          new SearchableSelect(newSelect);
        }
      });
    }

    if (tbody) {
      // Inizializza i select già presenti al caricamento
      tbody.querySelectorAll('.searchable-select').forEach(function (el) {
        if (typeof SearchableSelect !== 'undefined') {
          new SearchableSelect(el);
        }
      });

      tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete-listino-row');
        if (btn) {
          var row = btn.closest('.listino-row');
          if (row) {
            row.remove();
            reindexRows();
          }
        }
      });
    }
  }

  // ───────────────────────────────────────────────────────────────────────
  // 2) Toggle tipo privato/azienda + visibilità sidebar per tab attivo
  // ───────────────────────────────────────────────────────────────────────
  function initTypeAndSidebarToggle() {
    var radios = document.querySelectorAll('input[name="type"]');
    var privato = document.getElementById('fields-privato');
    var azienda = document.getElementById('fields-azienda');
    var cards = document.querySelectorAll('.qk-radio-card');

    function toggle(val) {
      if (!privato || !azienda) return;
      privato.style.display = val === 'privato' ? '' : 'none';
      azienda.style.display = val === 'privato' ? 'none' : '';
      cards.forEach(function (c) {
        var inp = c.querySelector('input[type="radio"]');
        c.classList.toggle('is-selected', inp && inp.value === val);
      });
    }

    radios.forEach(function (r) {
      r.addEventListener('change', function () { toggle(r.value); });
    });

    // Gestione visibilità sidebar basata sul tab attivo
    var grid = document.querySelector('.qk-form-grid');
    var sidebar = document.querySelector('.qk-status-panel');
    var tabs = document.querySelectorAll('#customerTabs button[data-bs-toggle="tab"]');

    function updateSidebar(tabId) {
      if (!grid || !sidebar) return;
      var hideSidebar = ['ctab-btn-indirizzi', 'ctab-btn-contatti', 'ctab-btn-compliance'].indexOf(tabId) !== -1;
      if (hideSidebar) {
        grid.classList.add('no-sidebar');
        sidebar.style.display = 'none';
      } else {
        grid.classList.remove('no-sidebar');
        sidebar.style.display = '';
      }
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('shown.bs.tab', function (e) {
        updateSidebar(e.target.id);
      });
    });

    // Esegui all'avvio se c'è un tab attivo pre-selezionato
    var activeTab = document.querySelector('#customerTabs button.active');
    if (activeTab) {
      updateSidebar(activeTab.id);
    }
  }

  initListini();
  initTypeAndSidebarToggle();
})();
