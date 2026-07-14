/**
 * suppliers-form.js — logica del form fornitore (suppliers/_form.php)
 *
 * Estratto dal <script> inline nella View (Revisione Incrociata §7.1.2):
 * gestione visibilità sidebar in base al tab attivo. Nessuna dipendenza da
 * dati PHP (a differenza di window.QkSupplier/QkSupplierAddr, che restano
 * inline come semplice config dati — pattern corretto Controller→View→JS).
 */
(function () {
  'use strict';

  var grid = document.querySelector('.qk-form-grid');
  var sidebar = document.querySelector('.qk-status-panel');
  var tabs = document.querySelectorAll('#supplierTabs button[data-bs-toggle="tab"]');

  function updateSidebar(tabId) {
    if (!grid || !sidebar) return;
    var hideSidebar = ['stab-btn-indirizzi', 'stab-btn-contatti', 'stab-btn-listini'].indexOf(tabId) !== -1;
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

  var activeTab = document.querySelector('#supplierTabs button.active');
  if (activeTab) {
    updateSidebar(activeTab.id);
  }
})();
