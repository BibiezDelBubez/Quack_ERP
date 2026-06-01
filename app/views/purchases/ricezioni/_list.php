<?php
/**
 * purchases/ricezioni/_list.php
 * Elenco ricezioni merci.
 * Variabili attese: $ricezioni_list (array), $stato (string)
 */

function ricezione_badge(string $stato): string {
    $map = [
        'bozza'      => ['draft',     'Bozza'],
        'completata' => ['confirmed', 'Completata'],
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}

$statiLabels = [
    ''           => 'Tutti',
    'bozza'      => 'Bozze',
    'completata' => 'Completate',
];
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="qk-alert qk-alert-success mb-3" data-auto-dismiss="4000">
  <i class="fa-solid fa-circle-check"></i>
  <span><?= e($_SESSION['flash_success']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_success']); endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="qk-alert qk-alert-danger mb-3" data-auto-dismiss="6000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($_SESSION['flash_error']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>



<div class="qk-card">
  <!-- Toolbar: ricerca + filtri -->
  <div class="qk-table-toolbar">
    <div class="qk-filter-pills">
      <?php foreach ($statiLabels as $k => $lbl): ?>
      <a href="<?= url('purchases/ricezioni') . ($k !== '' ? '?' . http_build_query(['stato' => $k]) : '') ?>"
         class="qk-filter-pill <?= $stato === $k ? 'active' : '' ?>">
        <?= e($lbl) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Tabella -->
  <div class="qk-table-wrap">
    <table class="qk-table">
      <thead>
        <tr>
          <th>Numero Ricezione</th>
          <th>Ordine Collegato</th>
          <th>Fornitore</th>
          <th>Stato</th>
          <th>Data Creazione</th>
          <th>Data Ricezione</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ricezioni_list)): ?>
        <tr>
          <td colspan="7" class="qk-table-empty">
            <i class="fa-solid fa-inbox"></i> Nessuna ricezione trovata.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($ricezioni_list as $r): ?>
        <tr>
          <td>
            <a href="<?= url('purchases/ricezioni/edit') . '?id=' . $r['id'] ?>" class="qk-link-strong">
              <?= e($r['numero']) ?>
            </a>
          </td>
          <td>
            <?php if (!empty($r['ordine_numero'])): ?>
              <a href="<?= url('purchases/ordini/edit') . '?id=' . $r['ordine_id'] ?>" class="qk-link-strong">
                <?= e($r['ordine_numero']) ?>
              </a>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= e($r['fornitore_nome'] ?? '—') ?></td>
          <td><?= ricezione_badge($r['stato']) ?></td>
          <td><?= e($r['created_at']) ?></td>
          <td><?= e($r['data_effettiva'] ?? '—') ?></td>
          <td class="qk-table-actions">
            <a href="<?= url('purchases/ricezioni/edit') . '?id=' . $r['id'] ?>"
               class="btn-qk btn-qk-xs btn-qk-secondary" title="Apri / Conferma">
              <i class="fa-solid fa-pencil"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
