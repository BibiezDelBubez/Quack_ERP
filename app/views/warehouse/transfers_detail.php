<?php
/**
 * warehouse/transfers_detail.php
 * Variabili: $detail (array: testata, righe)
 */
$t = $detail['testata'];
$righe = $detail['righe'];
$id = (int) $t['id'];

$isBozza      = $t['stato'] === 'bozza';
$isInTransito = $t['stato'] === 'in_transito';
$isCompletato = $t['stato'] === 'completato';
$isAnnullato  = $t['stato'] === 'annullato';

$statoBadgeMap = [
    'bozza'       => ['label' => 'Bozza',       'cls' => 'draft'],
    'in_transito' => ['label' => 'In transito', 'cls' => 'pending'],
    'completato'  => ['label' => 'Completato',  'cls' => 'confirmed'],
    'annullato'   => ['label' => 'Annullato',   'cls' => 'cancelled'],
];
$bd = $statoBadgeMap[$t['stato']] ?? ['label' => $t['stato'], 'cls' => 'draft'];
?>

<!-- Guidance Box -->
<div class="qk-guidance-box mb-4 p-3">
  <div class="fs-4 text-primary"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold text-primary">Cosa fare adesso?</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if ($isBozza): ?>
        Il trasferimento è in bozza. Verifica le righe, quindi registra la partenza: la giacenza verrà scaricata dal magazzino di origine.
      <?php elseif ($isInTransito): ?>
        La merce è in transito (già scaricata dall'origine). Quando arriva a destinazione, conferma l'arrivo — puoi correggere le quantità in caso di discrepanze.
      <?php elseif ($isCompletato): ?>
        Trasferimento completato: la giacenza è stata caricata nel magazzino di destinazione.
      <?php elseif ($isAnnullato): ?>
        Questo trasferimento è stato annullato.
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- StatusBar -->
<div class="qk-statusbar qk-flex-row qk-justify-between mb-4" style="padding:.5rem .75rem;background:var(--qk-table-head-bg);border:1px solid var(--qk-card-border);border-radius:var(--qk-radius)">
  <div class="qk-statusbar-buttons qk-flex-row qk-gap-sm">
    <?php if ($isBozza): ?>
      <form action="<?= url('warehouse/transfers/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('Registrare la partenza? La giacenza verrà scaricata dal magazzino di origine.');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="partenza">
        <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm fw-bold">
          <i class="fa-solid fa-truck-ramp-box me-1"></i> Registra Partenza
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isBozza || $isInTransito): ?>
      <form action="<?= url('warehouse/transfers/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('<?= $isInTransito ? 'La merce già scaricata dall\'origine verrà ripristinata. ' : '' ?>Sei sicuro di voler annullare questo trasferimento?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="annulla">
        <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm">
          <i class="fa-solid fa-ban me-1"></i> Annulla
        </button>
      </form>
    <?php endif; ?>

    <a href="<?= url('warehouse/transfers') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-arrow-left me-1"></i> Lista Trasferimenti
    </a>
  </div>

  <span class="qk-badge <?= $bd['cls'] ?>" style="font-size:.85rem"><?= e($bd['label']) ?></span>
</div>

<!-- Testata -->
<div class="qk-card mb-4">
  <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-truck-arrow-right me-2"></i><?= e($t['numero']) ?></span></div>
  <div class="qk-card-body">
    <div class="qk-form-row">
      <div class="qk-form-group mb-2">
        <label class="qk-form-label">Origine</label>
        <input type="text" class="qk-form-control" value="<?= e($t['magazzino_origine_nome']) ?>" readonly>
      </div>
      <div class="qk-form-group mb-2">
        <label class="qk-form-label">Destinazione</label>
        <input type="text" class="qk-form-control" value="<?= e($t['magazzino_destinazione_nome']) ?>" readonly>
      </div>
    </div>
    <div class="qk-form-row">
      <div class="qk-form-group mb-2">
        <label class="qk-form-label">Data richiesta</label>
        <input type="text" class="qk-form-control" value="<?= e(fmt_date($t['data_richiesta'])) ?>" readonly>
      </div>
      <div class="qk-form-group mb-2">
        <label class="qk-form-label">Data partenza</label>
        <input type="text" class="qk-form-control" value="<?= $t['data_partenza'] ? e(fmt_date($t['data_partenza'])) : '—' ?>" readonly>
      </div>
      <div class="qk-form-group mb-2">
        <label class="qk-form-label">Data arrivo</label>
        <input type="text" class="qk-form-control" value="<?= $t['data_arrivo'] ? e(fmt_date($t['data_arrivo'])) : '—' ?>" readonly>
      </div>
    </div>
    <?php if (!empty($t['note'])): ?>
    <div class="qk-form-group mb-0">
      <label class="qk-form-label">Note</label>
      <textarea class="qk-form-control" rows="2" readonly><?= e($t['note']) ?></textarea>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Righe -->
<div class="qk-card mb-4">
  <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-list-check me-2"></i>Prodotti</span></div>
  <div class="qk-card-body p-0">
    <?php if ($isInTransito): ?>
    <form action="<?= url('warehouse/transfers/action') ?>" method="post" id="formConfermaArrivo">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="azione" value="conferma_arrivo">
    <?php endif; ?>

    <table class="qk-table">
      <thead>
        <tr>
          <th>Prodotto</th>
          <th>Lotto</th>
          <th class="qk-text-right">Quantità richiesta</th>
          <?php if ($isInTransito): ?>
          <th class="qk-text-right" style="width:160px">Quantità arrivata</th>
          <th style="min-width:200px">Nota discrepanza</th>
          <?php elseif ($isCompletato): ?>
          <th class="qk-text-right">Quantità arrivata</th>
          <th>Nota discrepanza</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($righe as $r): ?>
        <tr>
          <td>
            <?= e($r['prodotto_nome']) ?>
            <span class="qk-text-muted" style="font-size:.78rem;margin-left:.3rem"><?= e($r['sku_interno']) ?></span>
          </td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= e($r['codice_lotto'] ?? '—') ?></td>
          <td class="qk-text-right"><?= number_format((float)$r['quantita_richiesta'], 3, ',', '.') ?> <span class="qk-text-muted"><?= e($r['uom'] ?? '') ?></span></td>
          <?php if ($isInTransito): ?>
          <td class="qk-text-right">
            <input type="hidden" name="riga_id[]" value="<?= $r['id'] ?>">
            <input type="number" name="quantita_arrivata[]" class="qk-form-control qk-text-right"
                   step="0.001" min="0" value="<?= e((string)$r['quantita_richiesta']) ?>">
          </td>
          <td><input type="text" name="nota_discrepanza[]" class="qk-form-control" placeholder="Solo se la quantità differisce"></td>
          <?php elseif ($isCompletato): ?>
          <td class="qk-text-right"><?= $r['quantita_arrivata'] !== null ? number_format((float)$r['quantita_arrivata'], 3, ',', '.') : '—' ?></td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= e($r['note_discrepanza'] ?? '—') ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($isInTransito): ?>
      <div class="p-3 text-end">
        <button type="submit" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-check me-1"></i> Conferma Arrivo
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
