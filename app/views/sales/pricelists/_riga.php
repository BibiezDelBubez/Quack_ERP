<?php
/**
 * sales/pricelists/_riga.php
 * Riga della tabella prezzi. Usata dal loop PHP (righe esistenti).
 * Ha due stati gestiti da CSS: view (.pl-view) e edit (.pl-edit).
 * Lo stato è controllato dalla classe .is-editing sul <tr>.
 *
 * Variabili: $item (array con id, prodotto_id, prodotto_nome, sku_interno,
 *            unita_misura, quantita_minima, prezzo_unitario, sconto_perc, note,
 *            uom_stock_id, uom_stock_code, uom_commercial_id, uom_commercial_code, unita_misura_id)
 */
$_id     = $item['id']              ?? '';
$_pid    = $item['prodotto_id']     ?? '';
$_nome   = $item['prodotto_nome']   ?? '';
$_sku    = $item['sku_interno']     ?? '';
$_um     = $item['unita_misura']    ?? '';
$_qmin   = (float)($item['quantita_minima']  ?? 0);
$_prezzo = (float)($item['prezzo_unitario']  ?? 0);
$_sconto = (float)($item['sconto_perc']      ?? 0);
$_note   = $item['note']            ?? '';
?>
<tr class="pl-riga"
    data-riga-id="<?= e($_id) ?>"
    data-prodotto-id="<?= e($_pid) ?>"
    data-prodotto-nome="<?= e($_nome) ?>"
    data-sku="<?= e($_sku) ?>"
    data-um="<?= e($_um) ?>"
    data-uom-stock-id="<?= e($item['uom_stock_id'] ?? '') ?>"
    data-uom-stock-code="<?= e($item['uom_stock_code'] ?? '') ?>"
    data-uom-commercial-id="<?= e($item['uom_commercial_id'] ?? '') ?>"
    data-uom-commercial-code="<?= e($item['uom_commercial_code'] ?? '') ?>"
    data-uom-selected-id="<?= e($item['unita_misura_id'] ?? '') ?>"
    data-qmin="<?= $_qmin ?>"
    data-prezzo="<?= $_prezzo ?>"
    data-sconto="<?= $_sconto ?>"
    data-note="<?= e($_note) ?>"
    data-costo="<?= (float)($item['costo'] ?? 0) ?>">

  <!-- ── Prodotto ── -->
  <td class="pl-td-product">
    <div class="pl-view">
      <a href="<?= url('products/edit?id=' . (int)$_pid) ?>" class="fw-semibold qk-text-accent" tabindex="-1">
        <?= e($_nome) ?>
      </a>
      <div class="pl-sku"><?= e($_sku) ?></div>
      <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
      </div>
    </div>
    <!-- In edit mode il prodotto è bloccato; si elimina e ricrea per cambiarlo -->
    <div class="pl-edit">
      <div class="fw-semibold" style="font-size:.875rem"><?= e($_nome) ?></div>
      <div class="pl-sku"><?= e($_sku) ?></div>
      <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
      </div>
    </div>
  </td>

  <!-- ── UM ── -->
  <td class="pl-td-um">
    <span class="pl-view pl-um-label"><?= e($_um) ?></span>
    <select class="pl-edit pl-input r-unita-misura-id" style="padding: .2rem .35rem; font-size: .8rem; text-align: left;">
      <option value="<?= e($item['uom_stock_id'] ?? '') ?>" <?= (int)($item['unita_misura_id'] ?? $item['uom_stock_id'] ?? 0) === (int)($item['uom_stock_id'] ?? 0) ? 'selected' : '' ?>>
        <?= e($item['uom_stock_code'] ?? 'pz') ?>
      </option>
      <?php if (!empty($item['uom_commercial_id'])): ?>
        <option value="<?= e($item['uom_commercial_id']) ?>" <?= (int)($item['unita_misura_id'] ?? 0) === (int)$item['uom_commercial_id'] ? 'selected' : '' ?>>
          <?= e($item['uom_commercial_code']) ?>
        </option>
      <?php endif; ?>
    </select>
  </td>

  <!-- ── Q.tà minima ── -->
  <td class="pl-td-qmin">
    <span class="pl-view pl-num"><?= number_format($_qmin, 3, ',', '.') ?></span>
    <input class="pl-edit pl-input r-qmin" type="number" min="0" step="0.001"
           value="<?= $_qmin ?>">
  </td>

  <!-- ── Prezzo unitario ── -->
  <td class="pl-td-prezzo position-relative">
    <div class="pl-view">
      <span class="pl-num pl-prezzo-val"><?= number_format($_prezzo, 4, ',', '.') ?></span>
    </div>
    <div class="pl-edit">
      <input class="pl-input r-prezzo" type="number" min="0" step="0.0001"
             value="<?= $_prezzo ?>">
    </div>
    <div class="pl-sottocosto-warning text-warning fw-semibold mt-1" style="font-size: 0.75rem; text-align: right; display: none;">
      <i class="fa-solid fa-triangle-exclamation me-1"></i>Sottocosto (<span class="pl-costo-val">0.00</span> €)
    </div>
  </td>

  <!-- ── Sconto % ── -->
  <td class="pl-td-sconto">
    <span class="pl-view pl-num"><?= number_format($_sconto, 2, ',', '.') ?> %</span>
    <input class="pl-edit pl-input r-sconto" type="number" min="0" max="100" step="0.01"
           value="<?= $_sconto ?>">
  </td>

  <!-- ── Note ── -->
  <td class="pl-td-note">
    <span class="pl-view pl-note-val"><?= e($_note) ?></span>
    <input class="pl-edit pl-input r-note" type="text" placeholder="Note…"
           value="<?= e($_note) ?>">
  </td>

  <!-- ── Azioni ── -->
  <td class="pl-td-actions">
    <button type="button" class="pl-btn-icon pl-btn-danger btn-delete-riga" title="Rimuovi" style="vertical-align: middle;">
      <i class="fa-solid fa-trash-can"></i>
    </button>
  </td>

</tr>
