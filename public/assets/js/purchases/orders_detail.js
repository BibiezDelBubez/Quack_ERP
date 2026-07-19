function switchOrdineTab(tabId) {
    document.querySelectorAll('.qk-nav-tabs .nav-link').forEach(btn => {
      btn.classList.remove('active');
    });
    const activeBtn = document.getElementById('tab-ordine-' + tabId + '-btn');
    if (activeBtn) activeBtn.classList.add('active');

    document.querySelectorAll('.tab-content .tab-pane').forEach(pane => {
      pane.classList.add('d-none');
      pane.classList.remove('active');
    });
    const activePane = document.getElementById('pane-ordine-' + tabId);
    if (activePane) {
      activePane.classList.remove('d-none');
      activePane.classList.add('active');
    }
  }