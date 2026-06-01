<?php
/**
 * purchases/rfq/_form_riga.php
 * Riga del form RFQ (usata sia in creazione/modifica bozza che in sola lettura).
 * Variabili:
 *   $idx      int      indice riga
 *   $r        array    dati riga prodotto
 *   $canEdit  bool     se il form è modificabile
 *   $readonly string   'readonly' o vuoto
 *   $disabled string   'disabled' o vuoto
 */
?>
<tr class="rfq-riga" data-idx="<?= $idx ?>" 
    data-uom-stock="<?= e($r['uom_stock'] ?? $r['uom'] ?? '—') ?>" 
    data-uom-stock-id="<?= e($r['unita_misura_stock_id'] ?? $r['unita_misura_id'] ?? '') ?>"
    data-uom-commercial="<?= e($r['uom_commercial'] ?? $r['uom'] ?? '—') ?>" 
    data-uom-commercial-id="<?= e($r['unita_misura_commercial_id'] ?? $r['unita_misura_id'] ?? '') ?>"
    <?= $canEdit ? 'draggable="true"' : '' ?>>
  <td class="col-drag">
    <?php if ($canEdit): ?>
      <i class="fa-solid fa-grip-vertical" title="Trascina per riordinare"></i>
    <?php else: ?>
      <span class="qk-text-muted">—</span>
    <?php endif; ?>
  </td>
  <td>
    <input type="hidden" name="riga_prodotto_id[]" class="r-prodotto-id" value="<?= e($r['prodotto_id']) ?>">
    <div class="qk-m2o-wrap">
      <input type="text" class="qk-form-control qk-m2o-input r-prodotto-label"
             placeholder="Cerca prodotto…" autocomplete="off"
             value="<?= e(($r['prodotto_nome'] ?? '') . (!empty($r['sku_interno']) ? ' (' . $r['sku_interno'] . ')' : '')) ?>"
             <?= $disabled ?>>
      <button type="button" class="qk-m2o-clear <?= $canEdit && $r['prodotto_id'] ? '' : 'd-none' ?> r-prodotto-clear" <?= $disabled ?>>&times;</button>
      <div class="qk-m2o-dropdown shadow-sm r-prodotto-dropdown"></div>
    </div>
  </td>
  <td class="col-formato">
    <span class="r-formato qk-text-muted" style="font-size:.85rem"><?= e($r['formato'] ?? '—') ?></span>
  </td>
  <td>
    <input type="text" name="riga_sku_fornitore[]" class="qk-form-control r-sku" placeholder="SKU"
           value="<?= e($r['sku_fornitore'] ?? '') ?>" <?= $readonly ?>>
  </td>
  <td>
    <input type="text" name="riga_descrizione[]" class="qk-form-control r-desc" placeholder="Note riga…"
           value="<?= e($r['descrizione'] ?? '') ?>" <?= $readonly ?>>
  </td>
  <td>
    <input type="number" name="riga_quantita[]" class="qk-form-control r-qty"
           step="0.001" min="0.001" value="<?= isset($r['quantita_richiesta']) ? (float)$r['quantita_richiesta'] : '1' ?>" required <?= $readonly ?>>
  </td>
  <td>
    <input type="hidden" name="riga_unita_misura_id[]" class="r-uom-id" value="<?= e($r['unita_misura_id'] ?? '') ?>">
    <span class="r-uom-label qk-text-muted" style="font-size:.85rem"><?= e(!empty($r['uom']) ? $r['uom'] : '—') ?></span>
  </td>
  <td class="col-prezzo">
    <input type="number" name="riga_prezzo_indicativo[]" class="qk-form-control r-prezzo"
           step="0.0001" min="0" placeholder="0,0000"
           value="<?= ($r['prezzo_indicativo'] !== null && $r['prezzo_indicativo'] !== '') ? (float)$r['prezzo_indicativo'] : '' ?>" <?= $readonly ?>>
  </td>
  <td>
    <input type="number" name="riga_iva_perc[]" class="qk-form-control r-iva"
           step="0.01" min="0" max="100" placeholder="22"
           value="<?= isset($r['iva_perc']) ? (float)$r['iva_perc'] : '22' ?>" <?= $readonly ?>>
  </td>
  <td class="col-data-consegna col-hidden">
    <input type="date" name="riga_data_consegna_richiesta[]" class="qk-form-control r-data-consegna"
           value="<?= e($r['data_consegna_richiesta'] ?? '') ?>" <?= $readonly ?>>
  </td>
  <td class="col-rifiutato col-hidden text-center">
    <input type="hidden" name="riga_e_rifiutato[]" class="r-e-rifiutato" value="<?= (int)($r['e_rifiutato'] ?? 0) ?>">
    <input type="checkbox" class="r-e-rifiutato-check" <?= ($r['e_rifiutato'] ?? 0) ? 'checked' : '' ?> <?= $disabled ?> style="transform: scale(1.15);">
  </td>
  <td class="text-end py-3 px-3 col-totale">
    <span class="r-totale-riga fw-semibold" style="font-size:.9rem">0,00</span>
  </td>
  <?php if ($canEdit): ?>
  <td>
    <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost r-rimuovi" title="Rimuovi riga">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </td>
  <?php endif; ?>
</tr>
