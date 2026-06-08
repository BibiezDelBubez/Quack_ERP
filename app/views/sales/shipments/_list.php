<?php
/**
 * @var array       $shipments  risultati query spedizioni
 * @var string|null $search     filtro ricerca
 * @var string|null $stato      filtro stato
 */
?>
<?php include APP_ROOT_DIR . '/app/views/sales/_subnav.php'; ?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input"
                 placeholder="Cerca spedizione, ordine, cliente…" value="<?= e($search ?? '') ?>">
        </div>
        <select name="stato" class="qk-form-control" style="width:auto">
          <option value="">Tutti gli stati</option>
          <option value="preparazione" <?= ($stato ?? '') === 'preparazione' ? 'selected' : '' ?>>Preparazione</option>
          <option value="spedita"      <?= ($stato ?? '') === 'spedita'      ? 'selected' : '' ?>>Spedita</option>
          <option value="consegnata"   <?= ($stato ?? '') === 'consegnata'   ? 'selected' : '' ?>>Consegnata</option>
          <option value="reso"         <?= ($stato ?? '') === 'reso'         ? 'selected' : '' ?>>Reso</option>
        </select>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-filter"></i> Filtra
        </button>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted"><?= count($shipments) ?> spedizioni</small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($shipments)): ?>
    <div class="qk-empty">
      <i class="fa-solid fa-truck"></i>
      <p>Nessuna spedizione trovata.</p>
    </div>
    <?php else: ?>
    <table class="qk-table" style="font-size:0.875rem">
      <thead>
        <tr>
          <th>N° Spedizione</th>
          <th>Data</th>
          <th>Ordine</th>
          <th>Cliente</th>
          <th>Vettore</th>
          <th>Tracking</th>
          <th>Stato</th>
          <th>DDT</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($shipments as $s):
          $statoClass = match($s['stato']) {
              'spedita'    => 'shipped',
              'consegnata' => 'delivered',
              'reso'       => 'cancelled',
              default      => 'draft',
          };
        ?>
        <tr>
          <td class="fw-semibold"><?= e($s['numero_spedizione']) ?></td>
          <td><?= fmt_date($s['data_spedizione']) ?></td>
          <td>
            <a href="<?= url('sales/orders/edit?id=' . $s['ordine_id']) ?>"
               class="qk-text-accent text-decoration-none fw-semibold">
              <?= e($s['numero_ordine']) ?>
            </a>
          </td>
          <td><?= e($s['cliente_nome']) ?></td>
          <td><?= e($s['vettore'] ?: '—') ?></td>
          <td>
            <?php if ($s['tracking']): ?>
            <code style="font-size:0.78rem"><?= e($s['tracking']) ?></code>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><span class="qk-badge <?= $statoClass ?>"><?= e(ucfirst($s['stato'])) ?></span></td>
          <td>
            <?php if ($s['numero_documento_trasporto']): ?>
            <a href="<?= url('sales/shipments/ddt/view?id=' . $s['ddt_id']) ?>"
               class="btn-qk btn-qk-secondary btn-qk-sm" target="_blank">
              <i class="fa-solid fa-file-lines me-1"></i><?= e($s['numero_documento_trasporto']) ?>
            </a>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
