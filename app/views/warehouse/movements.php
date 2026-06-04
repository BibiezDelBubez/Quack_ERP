<?php
/**
 * warehouse/_movements.php
 * Storico movimenti magazzino.
 * Variabili: $movements, $total, $page, $pages, $search, $tipo, $prodotto_id, $limit
 */

$tipoLabels = [
    ''               => 'Tutti i tipi',
    'carico'         => 'Carico',
    'scarico'        => 'Scarico',
    'rettifica'      => 'Rettifica',
    'reso_cliente'   => 'Reso cliente',
    'reso_fornitore' => 'Reso fornitore',
    'riserva'        => 'Riserva',
    'svincolo'       => 'Svincolo',
];

function wh_tipo_badge(string $tipo): string {
    $map = [
        'carico'         => ['label' => 'Carico',         'cls' => 'success'],
        'scarico'        => ['label' => 'Scarico',        'cls' => 'danger'],
        'rettifica'      => ['label' => 'Rettifica',      'cls' => 'warning'],
        'reso_cliente'   => ['label' => 'Reso cliente',   'cls' => 'info'],
        'reso_fornitore' => ['label' => 'Reso fornitore', 'cls' => 'info'],
        'riserva'        => ['label' => 'Riserva',        'cls' => 'muted'],
        'svincolo'       => ['label' => 'Svincolo',       'cls' => 'muted'],
    ];
    $d = $map[$tipo] ?? ['label' => $tipo, 'cls' => 'muted'];
    return '<span class="qkwh-tipo-badge qkwh-tipo-' . $d['cls'] . '">' . e($d['label']) . '</span>';
}
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'movements'; include __DIR__ . '/_subnav.php'; ?>

<!-- Filtri -->
<div class="qk-toolbar mb-3">
  <form method="get" action="<?= url('warehouse/movements') ?>" class="qkwh-filter-form">
    <select name="tipo" class="qk-form-control qkwh-select-tipo" onchange="this.form.submit()">
      <?php foreach ($tipoLabels as $k => $lbl): ?>
      <option value="<?= e($k) ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>

    <div class="qk-search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= e($search) ?>"
             placeholder="Prodotto, SKU, note, riferimento…" class="qk-form-control" autocomplete="off">
    </div>
    <?php if ($prodotto_id): ?>
    <input type="hidden" name="prodotto_id" value="<?= (int)$prodotto_id ?>">
    <?php endif; ?>
    <button type="submit" class="btn-qk btn-qk-secondary">
      <i class="fa-solid fa-filter"></i> Filtra
    </button>
  </form>

  <a href="<?= url('warehouse/stock') ?>" class="btn-qk btn-qk-ghost">
    <i class="fa-solid fa-boxes-stacked"></i> Giacenze
  </a>
</div>

<?php if ($prodotto_id): ?>
<div class="qk-alert qk-alert-info mb-3">
  <i class="fa-solid fa-filter"></i>
  <span>Movimenti filtrati per prodotto ID #<?= (int)$prodotto_id ?> —
    <a href="<?= url('warehouse/movements') ?>">rimuovi filtro</a>
  </span>
</div>
<?php endif; ?>

<!-- Tabella movimenti -->
<div class="qk-card">
  <div class="qk-card-body p-0">
    <?php if (empty($movements)): ?>
    <div class="qk-empty-state">
      <i class="fa-solid fa-clock-rotate-left"></i>
      <p>Nessun movimento trovato.</p>
    </div>
    <?php else: ?>
    <table class="qk-table qkwh-movements-table">
      <thead>
        <tr>
          <th>Data</th>
          <th>Tipo</th>
          <th>Prodotto</th>
          <th>Lotto</th>
          <th class="text-end">Quantità</th>
          <th>Origine</th>
          <th>Note / Riferimento</th>
          <th>Utente</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($movements as $m): ?>
        <tr>
          <td class="qkwh-date text-nowrap">
            <?= e(fmt_datetime($m['created_at'])) ?>
          </td>
          <td><?= wh_tipo_badge($m['tipo_movimento']) ?></td>
          <td>
            <div class="qkwh-prod-name">
              <a href="<?= url('products/edit?id=' . $m['prodotto_id']) ?>" class="qk-text-accent text-decoration-none fw-semibold">
                <?= e($m['prodotto_nome']) ?>
              </a>
            </div>
            <div class="qkwh-sku-small"><code><?= e($m['sku_interno']) ?></code></div>
          </td>
          <td>
            <?= $m['codice_lotto_interno'] !== ''
                ? '<span class="qkwh-lotto-tag"><i class="fa-solid fa-tag"></i> ' . e($m['codice_lotto_interno']) . '</span>'
                : '<span class="qkwh-qty-muted">—</span>' ?>
          </td>
          <td class="text-end text-nowrap">
            <span class="qkwh-qty-delta qkwh-delta-<?= e($m['tipo_movimento']) ?>">
              <?= $m['segno'] ?><?= fmt_qty($m['quantita']) ?>
            </span>
            <span class="qkwh-uom-small"><?= e($m['uom']) ?></span>
          </td>
          <td>
            <?php if ($m['canale_origine'] === 'manuale'): ?>
            <span class="qkwh-origine-badge">
              <i class="fa-solid fa-hand"></i> Manuale
            </span>
            <?php else: ?>
            <span class="qkwh-origine-badge qkwh-origine-auto">
              <i class="fa-solid fa-gears"></i> <?= e($m['canale_origine']) ?>
            </span>
            <?php endif; ?>
            <?php if ($m['documento_tipo']): ?>
            <div class="qkwh-doc-ref"><?= e($m['documento_tipo']) ?> <?= $m['documento_id'] ? '#' . $m['documento_id'] : '' ?></div>
            <?php endif; ?>
          </td>
          <td class="qkwh-note-cell">
            <?php if ($m['riferimento_esterno']): ?>
            <div class="qkwh-rif-ext"><?= e($m['riferimento_esterno']) ?></div>
            <?php endif; ?>
            <?php if ($m['note']): ?>
            <div class="qkwh-note-text" title="<?= e($m['note']) ?>">
              <?= e(mb_strimwidth($m['note'], 0, 60, '…')) ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="qkwh-utente"><?= e($m['creato_da_username']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Paginazione e conteggio -->
<div class="qk-table-footer mt-2">
  <span class="qkwh-count-label"><?= $total ?> moviment<?= $total === 1 ? 'o' : 'i' ?></span>
  <?php if ($pages > 1): ?>
  <div class="qk-pagination">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
    <?php
      $params = array_filter(['q' => $search, 'tipo' => $tipo, 'prodotto_id' => $prodotto_id ?: '', 'page' => $p > 1 ? $p : '']);
      $qs = http_build_query(array_filter($params));
    ?>
    <a href="<?= url('warehouse/movements') . ($qs ? '?' . $qs : '') ?>"
       class="qk-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
