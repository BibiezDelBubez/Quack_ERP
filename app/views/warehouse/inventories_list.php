<?php
/**
 * app/views/warehouse/inventories_list.php
 * Storico delle sessioni di inventario fisico.
 */
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'inventories'; include __DIR__ . '/_subnav.php'; ?>

<div class="qk-card shadow-sm mb-4">
  <div class="qk-card-header d-flex justify-content-between align-items-center">
    <span class="qk-card-title">
      <i class="fa-solid fa-clipboard-list me-2 text-primary"></i>Sessioni Inventario Fisico
    </span>
    <a href="<?= url('warehouse/inventories/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm">
      <i class="fa-solid fa-plus me-1"></i>Nuovo Inventario Fisico
    </a>
  </div>
  <div class="qk-table-wrap">
    <table class="qk-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Data Inventario</th>
          <th>Stato</th>
          <th>Aperto Da</th>
          <th>Note</th>
          <th>Data Creazione</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($inventari)): ?>
        <tr>
          <td colspan="7" class="qk-table-empty">Nessuna sessione di inventario registrata. Clicca su "Nuovo" per iniziare.</td>
        </tr>
        <?php else: ?>
        <?php foreach ($inventari as $inv): ?>
        <tr>
          <td class="fw-semibold">#<?= $inv['id'] ?></td>
          <td><?= fmt_date($inv['data_inventario']) ?></td>
          <td>
            <?php if ($inv['stato'] === 'convalidato'): ?>
              <span class="qk-badge confirmed">Convalidato</span>
            <?php else: ?>
              <span class="qk-badge draft">In Bozza</span>
            <?php endif; ?>
          </td>
          <td><?= e($inv['creatore_username']) ?></td>
          <td><?= e($inv['note']) ?></td>
          <td class="qk-text-muted"><?= fmt_datetime($inv['created_at']) ?></td>
          <td class="text-end">
            <a href="<?= url('warehouse/inventories/create') . '?id=' . $inv['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary">
              <i class="fa-solid <?= $inv['stato'] === 'convalidato' ? 'fa-eye' : 'fa-pen' ?>"></i>
              <?= $inv['stato'] === 'convalidato' ? 'Visualizza' : 'Modifica' ?>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
