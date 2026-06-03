<?php
/**
 * app/views/warehouse/reorder.php
 * Proposte di riordino per gli articoli sottoscorta.
 */
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'reorder'; include __DIR__ . '/_subnav.php'; ?>

<div class="qk-card shadow-sm">
  <div class="qk-card-header d-flex justify-content-between align-items-center">
    <span class="qk-card-title">
      <i class="fa-solid fa-circle-exclamation me-2 text-warning"></i>Proposte di Riordino
    </span>
    <span class="qk-text-muted fs-7">Articoli con disponibilità libera inferiore alla scorta minima</span>
  </div>
  <div class="qk-table-wrap">
    <table class="qk-table">
      <thead>
        <tr>
          <th>Codice (SKU)</th>
          <th>Prodotto</th>
          <th class="text-end">Disponibile</th>
          <th class="text-end">Riservata</th>
          <th class="text-end">In Arrivo</th>
          <th class="text-end">Libera</th>
          <th class="text-end">Scorta Minima</th>
          <th class="text-end">Q.tà Consigliata</th>
          <th>Fornitore Preferito</th>
          <th class="text-end">Ultimo Prezzo</th>
          <th class="text-end">Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($proposals)): ?>
        <tr>
          <td colspan="11" class="qk-table-empty">Nessun articolo sottoscorta o proposte di riordino necessarie.</td>
        </tr>
        <?php else: ?>
        <?php foreach ($proposals as $p): ?>
        <?php
          $qtaConsigliata = max(0, $p['scorta_minima'] - $p['qta_libera']);
        ?>
        <tr>
          <td><code><?= e($p['sku_interno']) ?></code></td>
          <td class="fw-semibold"><?= e($p['nome']) ?></td>
          <td class="text-end"><?= number_format((float)$p['qta_disponibile'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end text-muted"><?= number_format((float)$p['qta_riservata'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end text-success"><?= number_format((float)$p['qta_in_arrivo'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end text-danger fw-bold"><?= number_format((float)$p['qta_libera'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end text-muted"><?= number_format((float)$p['scorta_minima'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end fw-bold text-primary"><?= number_format((float)$qtaConsigliata, 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td>
            <?php if ($p['fornitore_nome']): ?>
              <?= e($p['fornitore_nome']) ?>
            <?php else: ?>
              <span class="qk-text-muted">— non impostato —</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($p['ultimo_prezzo'] !== null): ?>
              <?= fmt_money((float)$p['ultimo_prezzo']) ?>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($p['fornitore_id']): ?>
              <a href="<?= url('purchases/rfq/create') . '?fornitore_id=' . $p['fornitore_id'] . '&prodotto_id=' . $p['id'] . '&quantita=' . $qtaConsigliata ?>" 
                 class="btn-qk btn-qk-primary btn-qk-xs" title="Crea Richiesta di Preventivo">
                <i class="fa-solid fa-file-invoice-dollar"></i> Genera RFQ
              </a>
            <?php else: ?>
              <a href="<?= url('purchases/rfq/create') . '?prodotto_id=' . $p['id'] . '&quantita=' . $qtaConsigliata ?>" 
                 class="btn-qk btn-qk-secondary btn-qk-xs" title="Crea Richiesta di Preventivo">
                <i class="fa-solid fa-file-invoice-dollar"></i> Genera RFQ
              </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
