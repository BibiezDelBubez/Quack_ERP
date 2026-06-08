<?php
/**
 * @var array       $orders   risultati query ordini_clienti
 * @var int         $total    conteggio totale
 * @var string|null $search   filtro ricerca
 * @var string|null $status   filtro stato
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
                 placeholder="Cerca ordine, cliente…" value="<?= e($search ?? '') ?>">
        </div>
        <select name="status" class="qk-form-control" style="width:auto">
          <option value="">Tutti gli stati</option>
          <?php foreach (['in_lavorazione','parzialmente_spedito','spedito','completato','annullato'] as $s): ?>
          <option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>>
            <?= e(ucfirst(str_replace('_', ' ', $s))) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-filter"></i> Filtra
        </button>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted"><?= $total ?> ordini</small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($orders)): ?>
    <div class="qk-empty">
      <i class="fa-solid fa-file-invoice-dollar"></i>
      <p>Nessun ordine trovato.</p>
    </div>
    <?php else: ?>
    <table class="qk-table" style="font-size:.875rem">
      <thead>
        <tr>
          <th>N° Ordine</th>
          <th>Cliente</th>
          <th>Stato</th>
          <th>Data ordine</th>
          <th>Consegna prevista</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td class="fw-semibold">
            <a href="<?= url('sales/orders/edit?id=' . $o['id']) ?>"
               class="qk-text-accent text-decoration-none">
              <?= e($o['numero_ordine'] ?? '') ?>
            </a>
          </td>
          <td><?= e($o['cliente_nome'] ?? '') ?></td>
          <td><?= status_badge($o['stato'] ?? '') ?></td>
          <td class="qk-text-muted"><?= fmt_date($o['data_ordine'] ?? '') ?></td>
          <td class="qk-text-muted"><?= fmt_date($o['data_consegna_prevista'] ?? '') ?: '—' ?></td>
          <td class="text-end">
            <a href="<?= url('sales/orders/edit?id=' . $o['id']) ?>"
               class="btn-qk btn-qk-secondary btn-qk-sm" title="Apri">
              <i class="fa-solid fa-pen-to-square"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
