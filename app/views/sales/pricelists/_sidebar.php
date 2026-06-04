<?php
/**
 * sales/pricelists/_sidebar.php
 * Colonna destra del qk-form-grid: info box + clienti assegnati.
 * Variabili attese: $isEdit (bool), $clienti_assegnati (array)
 */
?>
<div>

  <div class="qk-card shadow-sm mb-3" style="border-left:3px solid var(--qk-info)">
    <div class="qk-card-header" style="padding:.75rem 1rem">
      <span class="qk-card-title" style="font-size:.8rem">
        <i class="fa-solid fa-circle-info me-2" style="color:var(--qk-info)"></i>Come funzionano i listini
      </span>
    </div>
    <div class="qk-card-body" style="padding:.85rem 1rem;font-size:.82rem;color:var(--qk-text-muted);line-height:1.55">
      <p style="margin:0 0 .5rem">Prezzi unitari per prodotto con supporto scaglioni di quantità e sconto %.</p>
      <p style="margin:0 0 .5rem">Assegnabile come predefinito a un cliente o selezionabile manualmente su ogni preventivo.</p>
      <p style="margin:0">Se un prodotto non ha un prezzo in listino è sempre possibile inserirlo manualmente nel preventivo.</p>
    </div>
  </div>

  <div class="qk-card shadow-sm">
    <div class="qk-card-header d-flex justify-content-between align-items-center" style="padding:.75rem 1rem">
      <span class="qk-card-title" style="font-size:.8rem">
        <i class="fa-solid fa-address-book me-2" style="color:var(--qk-accent)"></i>Clienti assegnati
      </span>
      <span class="badge bg-secondary"><?= count($clienti_assegnati) ?></span>
    </div>
    <div class="qk-card-body" style="padding:.75rem 1rem">
      <?php if ($isEdit): ?>
        <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm w-100 mb-3" id="btn-gestisci-assegnazioni">
          <i class="fa-solid fa-users-gear me-1"></i> Gestisci assegnazioni
        </button>
      <?php endif; ?>
      
      <div id="sidebar-assigned-clients">
        <?php if (!empty($clienti_assegnati)): ?>
          <?php foreach ($clienti_assegnati as $cl): ?>
          <a href="<?= url('customers/edit?id=' . $cl['id']) ?>"
             style="display:flex;align-items:center;gap:.5rem;padding:.3rem 0;font-size:.83rem;
                    color:var(--qk-accent-text);text-decoration:none;border-bottom:1px solid var(--qk-card-border)">
            <i class="fa-solid fa-user" style="font-size:.72rem;color:var(--qk-text-muted);flex-shrink:0"></i>
            <?= e($cl['nome_cliente']) ?>
          </a>
          <?php endforeach; ?>
        <?php else: ?>
          <span style="font-size:.83rem;color:var(--qk-text-muted)">Nessun cliente assegnato.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

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
        // Aggiorna la sidebar
        if (sidebarClientsList) {
          if (data.clienti_assegnati.length > 0) {
            sidebarClientsList.innerHTML = data.clienti_assegnati.map(cl => `
              <a href="${baseUrl}/customers/edit?id=${cl.id}"
                 style="display:flex;align-items:center;gap:.5rem;padding:.3rem 0;font-size:.83rem;
                        color:var(--qk-accent-text);text-decoration:none;border-bottom:1px solid var(--qk-card-border)">
                <i class="fa-solid fa-user" style="font-size:.72rem;color:var(--qk-text-muted);flex-shrink:0"></i>
                \${escapeHtml(cl.nome_cliente)}
              </a>
            `).join('');
          } else {
            sidebarClientsList.innerHTML = '<span style="font-size:.83rem;color:var(--qk-text-muted)">Nessun cliente assegnato.</span>';
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

