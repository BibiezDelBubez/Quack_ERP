<?php
/**
 * products/_list.php — Vista HTML della lista prodotti.
 * Variabili attese: $products, $total, $search, $category, $adr_only, $categories
 */
?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input" placeholder="Cerca nome, SKU…" value="<?= e($search) ?>">
        </div>
        <?php if (!empty($categories)): ?>
        <select name="cat" class="qk-filter-input" style="width:auto">
          <option value="">Tutte le categorie</option>
          <?php foreach ($categories as $cat): ?><option value="<?= e($cat['id']) ?>" <?= (string)$category === (string)$cat['id'] ? 'selected' : '' ?>><?= e($cat['label']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
<?php if (!empty($suppliers)): ?>
    <select name="fornitore" class="qk-filter-input" style="width:auto">
        <option value="">Tutti i fornitori</option>
        <?php foreach ($suppliers as $sup): ?>
            <option value="<?= e($sup['id']) ?>" <?= (string)$supplier === (string)$sup['id'] ? 'selected' : '' ?>><?= e($sup['ragione_sociale']) ?></option>
        <?php endforeach; ?>
    </select>
<?php endif; ?>
        <label class="d-flex align-items-center gap-1 fw-medium" style="font-size:.85rem;cursor:pointer">
          <input type="checkbox" name="adr" value="1" <?= $adr_only ? 'checked' : '' ?>> Solo ADR
        </label>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
        <?php if ($search || $category || $adr_only): ?><a href="<?= url('products') ?>" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-xmark"></i> Pulisci</a><?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right"><small class="qk-text-muted"><?= $total ?> prodotti</small></div>
  </div>
  <div class="qk-table-wrap">
    <?php if (empty($products)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-tags"></i>
      <p><?= ($search || $category || $adr_only) ? 'Nessun prodotto trovato con i filtri selezionati.' : 'Nessun prodotto ancora.' ?></p>
      <?php if (!$search && !$category && !$adr_only): ?><a href="<?= url('products/create') ?>" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Aggiungi prodotto</a><?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead><tr><th>SKU</th><th>Nome prodotto</th><th>Categoria</th><th class="text-center">Giacenza</th><th class="text-end">Prezzo</th><th>ADR</th><th class="col-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><code class="qk-text-secondary" style="font-size:.8rem"><?= e($p['sku_interno'] ?? '') ?></code></td>
          <td class="fw-semibold"><?= e($p['nome'] ?? '') ?></td>
          <td><?php if ($p['categoria']): ?><span class="qk-badge draft"><?= e($p['categoria']) ?></span><?php else: ?><span class="qk-text-muted">—</span><?php endif; ?></td>
          <td class="text-center"><?= $p['quantita_disponibile'] !== null ? (int)$p['quantita_disponibile'] : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="text-end"><?php if (!empty($p['prezzo_min'])) { echo e(number_format($p['prezzo_min'], 2, ',', '.')); } else { echo '—'; } ?></td>
          <td><?= !empty($p['is_adr']) ? '<span class="qk-badge warning">ADR</span>' : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="col-actions">
            <a href="<?= url('products/edit?id=' . $p['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-pencil"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
  <div class="qk-table-footer mt-2">
    <span class="qkwh-count-label"><?= $total ?> prodotti</span>
    <div class="qk-pagination">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
      <?php
        $params = array_filter([
          'q' => $search,
          'cat' => $category,
          'adr' => $adr_only ? '1' : '',
          'fornitore' => $supplier ?: '',
          'page' => $p > 1 ? $p : ''
        ]);
        $qs = http_build_query($params);
      ?>
      <a href="<?= url('products') . ($qs ? '?' . $qs : '') ?>"
         class="qk-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
