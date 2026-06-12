<?php
/**
 * sales/pricelists/_sidebar.php
 * Modal ed handler JS per associazione clienti.
 * Variabili attese: $isEdit (bool), $clienti_assegnati (array)
 */
?>
<?php if ($isEdit): ?>
<!-- Modal per assegnazioni massive clienti -->
<div class="modal fade" id="modalAssegnaClienti" tabindex="-1" aria-labelledby="modalAssegnaClientiTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalAssegnaClientiTitle"><i class="fa-solid fa-users-gear me-2"></i>Associazione Massiva Clienti</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="modalAssegnaClientiErr" class="qk-alert qk-alert-danger d-none mb-3">
          <i class="fa-solid fa-circle-xmark"></i>
          <span></span>
        </div>
        <div class="mb-3">
          <input type="text" id="assegna-search-clienti" class="qk-form-control" placeholder="Cerca cliente per ragione sociale o partita IVA...">
        </div>
        <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--qk-card-border); border-radius: var(--qk-border-radius);">
          <table class="qk-table mb-0">
            <thead>
              <tr>
                <th style="width: 40px; text-align: center;">
                  <input type="checkbox" id="chk-assegna-seleziona-tutti">
                </th>
                <th>Ragione Sociale / Nominativo</th>
                <th>Partita IVA</th>
              </tr>
            </thead>
            <tbody id="tbody-assegna-clienti">
              <tr>
                <td colspan="3" class="text-center py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i> Caricamento clienti...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btn-save-assegna-clienti">
          <i class="fa-solid fa-floppy-disk"></i> Applica modifiche
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const btnGestisci = document.getElementById('btn-gestisci-assegnazioni');
  if (!btnGestisci) return;

  const modalEl = document.getElementById('modalAssegnaClienti');
  const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const tbody = document.getElementById('tbody-assegna-clienti');
  const searchInput = document.getElementById('assegna-search-clienti');
  const chkAll = document.getElementById('chk-assegna-seleziona-tutti');
  const btnSave = document.getElementById('btn-save-assegna-clienti');
  const errBox = document.getElementById('modalAssegnaClientiErr');
  const sidebarClientsList = document.getElementById('sidebar-assigned-clients');
  const countBadge = document.querySelector('.qk-card-header .bg-secondary');

  const listinoId = <?= (int)($id ?? 0) ?>;
  const csrfToken = <?= json_encode(csrf_token()) ?>;
  const baseUrl = <?= json_encode(url()) ?>;

  let allClienti = [];

  btnGestisci.addEventListener('click', async function() {
    bsModal.show();
    tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i> Caricamento clienti...</td></tr>';
    errBox.classList.add('d-none');
    searchInput.value = '';
    chkAll.checked = false;

    try {
      const res = await fetch(`${baseUrl}/sales/pricelists/api/clienti-assegnazione?listino_id=${listinoId}`);
      const data = await res.json();
      if (data.ok) {
        allClienti = data.clienti;
        renderClienti();
      } else {
        showErr(data.error || 'Errore nel caricamento dei clienti.');
      }
    } catch(e) {
      showErr(e.message || 'Errore di rete.');
    }
  });

  function renderClienti() {
    const q = searchInput.value.toLowerCase().trim();
    const filtered = allClienti.filter(c => 
      c.nome_cliente.toLowerCase().includes(q) || 
      c.partita_iva.toLowerCase().includes(q)
    );

    if (filtered.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" class="text-center py-3 text-muted">Nessun cliente trovato</td></tr>';
      return;
    }

    tbody.innerHTML = filtered.map(c => `
      <tr>
        <td class="text-center">
          <input type="checkbox" class="chk-cliente" data-id="${c.id}" ${c.assegnato ? 'checked' : ''}>
        </td>
        <td><strong>${escapeHtml(c.nome_cliente)}</strong></td>
        <td><code>${escapeHtml(c.partita_iva)}</code></td>
      </tr>
    `).join('');

    // Ricalcola lo stato del "Seleziona Tutti"
    const checkboxes = tbody.querySelectorAll('.chk-cliente');
    chkAll.checked = checkboxes.length > 0 && Array.from(checkboxes).every(cb => cb.checked);
  }

  searchInput.addEventListener('input', renderClienti);

  chkAll.addEventListener('change', function() {
    const checked = chkAll.checked;
    const checkboxes = tbody.querySelectorAll('.chk-cliente');
    checkboxes.forEach(cb => {
      cb.checked = checked;
      const id = parseInt(cb.dataset.id);
      const cli = allClienti.find(c => c.id === id);
      if (cli) cli.assegnato = checked;
    });
  });

  tbody.addEventListener('change', function(e) {
    if (e.target.classList.contains('chk-cliente')) {
      const id = parseInt(e.target.dataset.id);
      const cli = allClienti.find(c => c.id === id);
      if (cli) cli.assegnato = e.target.checked;
      
      const checkboxes = tbody.querySelectorAll('.chk-cliente');
      chkAll.checked = Array.from(checkboxes).every(cb => cb.checked);
    }
  });

  btnSave.addEventListener('click', async function() {
    const selectedIds = allClienti.filter(c => c.assegnato).map(c => c.id);
    
    btnSave.disabled = true;
    btnSave.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Salvataggio...';
    errBox.classList.add('d-none');

    try {
      const fd = new FormData();
      fd.append('listino_id', listinoId);
      fd.append('csrf_token', csrfToken);
      selectedIds.forEach(id => fd.append('cliente_ids[]', id));

      const res = await fetch(`${baseUrl}/sales/pricelists/api/assegna-clienti`, {
        method: 'POST',
        body: fd
      });
      const data = await res.json();
      if (data.ok) {
        bsModal.hide();
        if (sidebarClientsList) {
          if (data.clienti_assegnati.length > 0) {
            sidebarClientsList.innerHTML = data.clienti_assegnati.map(cl => `
              <tr>
                <td>
                  <a href="${baseUrl}/customers/edit?id=${cl.id}" class="fw-semibold qk-text-accent" style="text-decoration:none">
                    <i class="fa-solid fa-user me-2 text-muted" style="font-size:.85rem"></i>${escapeHtml(cl.nome_cliente)}
                  </a>
                </td>
                <td style="text-align:right">
                  <span class="badge bg-secondary-subtle text-secondary small">Assegnato</span>
                </td>
              </tr>
            `).join('');
          } else {
            sidebarClientsList.innerHTML = '<tr><td colspan="2" class="py-4 text-center text-muted"><i class="fa-solid fa-users-slash me-2"></i>Nessun cliente assegnato direttamente a questo listino.</td></tr>';
          }
        }
        if (countBadge) {
          countBadge.textContent = data.clienti_assegnati.length;
        }
      } else {
        showErr(data.error || 'Errore nel salvataggio.');
      }
    } catch(e) {
      showErr(e.message || 'Errore di rete.');
    } finally {
      btnSave.disabled = false;
      btnSave.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Applica modifiche';
    }
  });

  function showErr(msg) {
    errBox.querySelector('span').textContent = msg;
    errBox.classList.remove('d-none');
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&#039;");
  }
});
</script>
<?php endif; ?>

                                        