<?php
/**
 * purchases/pricelists_list.php — Lista dei Listini Acquisto.
 */
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'pricelists'; include __DIR__ . '/_subnav.php'; ?>

<?php if (isset($_SESSION['flash'])): ?>
<div class="qk-alert qk-alert-<?= e($_SESSION['flash']['type']) ?> mb-3">
  <i class="fa-solid <?= $_SESSION['flash']['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
  <span><?= e($_SESSION['flash']['message']) ?></span>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<!-- Banner Informativo Logica Listini Base -->
<div class="alert alert-info d-flex align-items-center mb-3" role="alert" style="background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; border-radius: var(--qk-radius); padding: 1rem;">
  <i class="fa-solid fa-circle-info me-3 fa-lg"></i>
  <div style="font-size: 0.9rem;">
    <strong>Listini Generati Automaticamente</strong><br>
    Potresti notare in questo elenco dei listini chiamati <em>"Condizioni Base"</em>. Questi vengono generati automaticamente dal sistema ogni volta che si inserisce un costo d'acquisto nella scheda di un prodotto senza specificare un contratto formale. Questo meccanismo garantisce che tutti i prezzi negoziati con i fornitori siano sempre raggruppati, rintracciabili e visibili in questa sezione.
  </div>
</div>

<!-- ── LISTA PRINCIPALE ── -->
<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left qk-flex-row qk-gap-md">
      <a href="<?= url('purchases/pricelists/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm">
        <i class="fa-solid fa-plus"></i> Nuovo
      </a>
      <h2 class="mb-0 text-nowrap">Listini acquisto</h2>
    </div>

    <!-- Ricerca & Filtri -->
    <div class="qk-table-toolbar-right">
      <form action="<?= url('purchases/pricelists') ?>" method="get" class="qk-flex-row qk-gap-sm">
        <div class="qk-search-input">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" placeholder="Cerca per listino o fornitore..." value="<?= e($search ?? '') ?>">
        </div>

        <?php if (!empty($search)): ?>
          <a href="<?= url('purchases/pricelists') ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Pulisci filtri">
            <i class="fa-solid fa-xmark"></i>
          </a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($pricelists)): ?>
    <div class="qk-empty">
      <i class="fa-solid fa-file-spreadsheet fs-1 text-muted mb-3"></i>
      <p>Nessun listino acquisto trovato.</p>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th>Nome Listino</th>
          <th>Fornitore</th>
          <th style="width:80px" class="text-center">Stato</th>
          <th style="width:180px">Valido Dal</th>
          <th style="width:180px">Valido Al</th>
          <th>Note</th>
          <th class="col-actions" style="width:100px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pricelists as $la): ?>
        <tr>
          <td>
            <a href="<?= url('purchases/pricelists/edit?id=' . $la['id']) ?>" class="fw-bold qk-text-accent">
              <?= e($la['nome_listino']) ?>
            </a>
          </td>
          <td>
            <div class="fw-semibold"><?= e($la['fornitore_nome'] ?? 'Fornitore non trovato') ?></div>
          </td>
          <td class="text-center">
            <?= status_badge($la['stato_effettivo']) ?>
          </td>
          <td>
            <span class="text-monospace small">
              <?= !empty($la['valido_dal']) ? date('d/m/Y', strtotime($la['valido_dal'])) : '—' ?>
            </span>
          </td>
          <td>
            <span class="text-monospace small">
              <?= !empty($la['valido_al']) ? date('d/m/Y', strtotime($la['valido_al'])) : '—' ?>
            </span>
          </td>
          <td class="qk-text-muted small">
            <?= e($la['note'] ?? '') ?>
          </td>
          <td class="text-end">
            <a href="<?= url('purchases/pricelists/edit?id=' . $la['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-xs" title="Modifica">
              <i class="fa-solid fa-pencil"></i>
            </a>
            <form action="<?= url('purchases/pricelists/delete') ?>" method="post" class="d-inline" onsubmit="return confirm('Sicuro di voler eliminare questo listino? Tutti i prezzi dei prodotti collegati a questo listino non faranno più riferimento ad esso.')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $la['id'] ?>">
              <button type="submit" class="btn-qk btn-qk-danger btn-qk-xs ms-1" title="Elimina">
                <i class="fa-solid fa-trash-can"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
