<?php
/**
 * sales/pricelists/_riga.php
 * Riga della tabella prezzi. Usata dal loop PHP (righe esistenti).
 * Ha due stati gestiti da CSS: view (.pl-view) e edit (.pl-edit).
 * Lo stato è controllato dalla classe .is-editing sul <tr>.
 *
 * Variabili: $item (array con id, prodotto_id, prodotto_nome, sku_interno,
 *            unita_misura, quantita_minima, prezzo_unitario, sconto_perc, note)
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
    data-qmin="<?= $_qmin ?>"
    data-prezzo="<?= $_prezzo ?>"
    data-sconto="<?= $_sconto ?>"
    data-note="<?= e($_note) ?>">

  <!-- ── Prodotto ── -->
  <td class="pl-td-product">
    <div class="pl-view">
      <a href="<?= url('products/edit?id=' . (int)$_pid) ?>" class="fw-semibold qk-text-accent" tabindex="-1">
        <?= e($_nome) ?>
      </a>
      <div class="pl-sku"><?= e($_sku) ?></div>
    </div>
    <!-- In edit mode il prodotto è bloccato; si elimina e ricrea per cambiarlo -->
    <div class="pl-edit">
      <div class="fw-semibold" style="font-size:.875rem"><?= e($_nome) ?></div>
      <div class="pl-sku"><?= e($_sku) ?></div>
    </div>
  </td>

  <!-- ── UM ── -->
  <td class="pl-td-um">
    <span class="pl-um-label"><?= e($_um) ?></span>
  </td>

  <!-- ── Q.tà minima ── -->
  <td class="pl-td-num">
    <span class="pl-view pl-num"><?= number_format($_qmin, 3, ',', '.') ?></span>
    <input class="pl-edit pl-input r-qmin" type="number" min="0" step="0.001"
           value="<?= $_qmin ?>">
  </td>

  <!-- ── Prezzo unitario ── -->
  <td class="pl-td-num">
    <span class="pl-view pl-num pl-prezzo-val"><?= number_format($_prezzo, 4, ',', '.') ?></span>
    <input class="pl-edit pl-input r-prezzo" type="number" min="0" step="0.0001"
           value="<?= $_prezzo ?>">
  </td>

  <!-- ── Sconto % ── -->
  <td class="pl-td-num">
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
    <div class="pl-view pl-actions-view">
      <button type="button" class="pl-btn-icon btn-edit-riga" title="Modifica">
        <i class="fa-solid fa-pencil"></i>
      </button>
      <button type="button" class="pl-btn-icon pl-btn-danger btn-delete-riga" title="Rimuovi">
        <i class="fa-solid fa-trash-can"></i>
      </button>
    </div>
    <div class="pl-edit pl-actions-edit">
      <button type="button" class="pl-btn-icon pl-btn-confirm btn-confirm-riga" title="Salva">
        <i class="fa-solid fa-check"></i>
      </button>
      <button type="button" class="pl-btn-icon pl-btn-cancel btn-cancel-riga" title="Annulla">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </td>

</tr>
