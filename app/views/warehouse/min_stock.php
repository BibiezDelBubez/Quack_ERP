<?php
/**
 * warehouse/min_stock.php
 * Griglia editabile per la scorta minima (Piano Multi-Magazzino, Parte 5 §5.2 —
 * modello Odoo "Regole di Riordino"). Due modalità:
 *  - standard: un magazzino (obbligatorio, mai "tutti"), tutti i prodotti;
 *  - pivot (?prodotto_id=...): un prodotto, tutte le sedi.
 * Variabili: $magazzini, $magazzinoId, $prodottoFiltro, $search, $righe
 */
$base = url('warehouse/min-stock');
?>
<?php $active_tab = 'min-stock'; include __DIR__ . '/_subnav.php'; ?>

<?php if ($prodottoFiltro): ?>
<div class="qk-alert qk-alert-info mb-3">
  <i class="fa-solid fa-filter"></i>
  <span>Vista per prodotto: <strong><?= e($prodottoFiltro['nome']) ?></strong> (<?= e($prodottoFiltro['sku_interno']) ?>) su tutte le sedi.
  <a href="<?= e($base) ?>">Torna alla vista per magazzino</a></span>
</div>
<?php else: ?>
<div class="qk-card mb-3">
  <div class="qk-card-body">
    <form method="get" action="<?= e($base) ?>" class="qk-table-toolbar">
      <div class="qk-form-group mb-0" style="min-width:260px">
        <label class="qk-form-label">Magazzino <span class="required">*</span></label>
        <select name="magazzino_id" class="qk-form-control" onchange="this.form.submit()">
          <?php foreach ($magazzini as $mg): ?>
          <option value="<?= (int) $mg['id'] ?>" <?= $magazzinoId === (int) $mg['id'] ? 'selected' : '' ?>>
            <?= e($mg['nome']) ?><?= $mg['tipo'] !== 'fisico' ? ' (' . e($mg['tipo']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="qk-filter-wrap mt-auto">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input" placeholder="Nome o SKU…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm mt-auto" title="Filtra">
        <i class="fa-solid fa-magnifying-glass"></i>
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="qk-card">
  <div class="qk-table-wrap">
    <table class="qk-table" id="tabellaSoglie">
      <thead>
        <tr>
          <th>SKU</th>
          <th>Prodotto</th>
          <?php if ($prodottoFiltro): ?><th>Magazzino</th><?php endif; ?>
          <th>UoM</th>
          <th class="text-end">Giacenza</th>
          <th style="width:160px">Scorta minima</th>
          <th style="width:40px"></th>
        </tr>
      </thead>
      <tbody id="soglieBody">
        <?php if (empty($righe)): ?>
        <tr><td colspan="7" class="qk-table-empty"><i class="fa-solid fa-boxes-stacked"></i> Nessun prodotto trovato.</td></tr>
        <?php else: foreach ($righe as $r): ?>
        <tr data-prodotto-id="<?= (int) $r['prodotto_id'] ?>"
            data-magazzino-id="<?= $prodottoFiltro ? (int) $r['magazzino_id'] : (int) $magazzinoId ?>">
          <td class="font-monospace"><?= e($r['sku_interno'] ?? '') ?></td>
          <td><?= e($r['prodotto_nome'] ?? '') ?></td>
          <?php if ($prodottoFiltro): ?><td><?= e($r['magazzino_nome']) ?></td><?php endif; ?>
          <td><?= e($r['uom'] ?? '') ?></td>
          <td class="text-end"><?= fmt_qty((float) $r['qta_disponibile']) ?></td>
          <td class="flat-grid-td">
            <input type="number" class="flat-grid-input inp-scorta-minima" value="<?= e((string) $r['scorta_minima']) ?>"
                   step="0.001" min="0" placeholder="0.000">
          </td>
          <td class="text-center flat-grid-td">
            <i class="fa-solid fa-spinner fa-spin d-none icon-saving text-primary"></i>
            <i class="fa-solid fa-check d-none icon-saved text-success"></i>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
window.QkMinStock = {
  apiSave:   <?= json_encode(url('warehouse/min-stock/save')) ?>,
  csrfToken: <?= json_encode(csrf_token()) ?>,
};
</script>
<script src="<?= url('assets/js/warehouse-min-stock.js') ?>"></script>
