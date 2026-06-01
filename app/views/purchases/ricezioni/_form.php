<?php
/**
 * purchases/ricezioni/_form.php
 * Form di conferma ricezione merce.
 * Variabili attese: $detail (array), $page_title (string)
 */

$rc = $detail['ricezione'];
$righe = $detail['righe'];
$id = (int)$rc['id'];
$isBozza = $rc['stato'] === 'bozza';
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="qk-alert qk-alert-success mb-3" data-auto-dismiss="4000">
  <i class="fa-solid fa-circle-check"></i>
  <span><?= e($_SESSION['flash_success']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_success']); endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="qk-alert qk-alert-danger mb-3" data-auto-dismiss="6000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($_SESSION['flash_error']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>

<!-- StatusBar/Actions -->
<div class="qk-statusbar d-flex justify-content-between align-items-center mb-4 p-2 bg-light border rounded">
  <div class="qk-statusbar-buttons d-flex gap-2">
    <a href="<?= url('purchases/ricezioni') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-arrow-left me-1"></i> Lista Ricezioni
    </a>
    <?php if (!empty($rc['ordine_id'])): ?>
      <a href="<?= url('purchases/ordini/edit') . '?id=' . $rc['ordine_id'] ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
        <i class="fa-solid fa-file-contract me-1"></i> Vai all'Ordine
      </a>
    <?php endif; ?>
  </div>

  <ul class="qk-status-path">
    <li class="qk-status-path-item <?= $isBozza ? 'active' : 'completed' ?>">
      Bozza (In attesa)
    </li>
    <li class="qk-status-path-item <?= !$isBozza ? 'active' : 'pending' ?>">
      Completata (Ricevuta)
    </li>
  </ul>
</div>

<form action="<?= url('purchases/ricezioni/conferma') ?>" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="ricezione_id" value="<?= $id ?>">

  <div class="qk-card mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title"><i class="fa-solid fa-dolly me-2"></i> Ricezione Merce <?= e($rc['numero']) ?></span>
    </div>
    <div class="qk-card-body">
      <div class="qk-form-grid">
        <div>
          <div class="qk-form-group">
            <label class="qk-form-label">Fornitore</label>
            <input type="text" class="qk-form-control" value="<?= e($rc['fornitore_nome']) ?>" readonly>
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Ordine di Origine</label>
            <input type="text" class="qk-form-control" value="<?= e($rc['ordine_numero'] ?? '—') ?>" readonly>
          </div>
        </div>
        <div>
          <div class="qk-form-group">
            <label class="qk-form-label">Data Creazione</label>
            <input type="text" class="qk-form-control" value="<?= e($rc['created_at']) ?>" readonly>
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Data Effettiva Ricezione</label>
            <input type="text" class="qk-form-control" value="<?= e($rc['data_effettiva'] ?? 'Bozza non ancora confermata') ?>" readonly>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="qk-card mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title"><i class="fa-solid fa-clipboard-list me-2"></i> Controllo Articoli in Ingresso</span>
    </div>
    <div class="qk-table-wrap">
      <table class="qk-table" style="font-size:0.875rem">
        <thead>
          <tr>
            <th>Prodotto</th>
            <th class="qk-text-right" style="width:90px">Ordinata</th>
            <th class="qk-text-right" style="width:90px">Già Ric.</th>
            <th class="qk-text-right" style="width:110px">Accettata <span style="color:var(--qk-danger)">*</span></th>
            <th class="qk-text-right" style="width:110px">Rifiutata</th>
            <th>Motivo Rifiuto (se applicabile)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($righe as $i => $r): ?>
            <tr class="riga-ricezione" data-traccia-lotti="<?= (int)$r['traccia_lotti'] ?>">
              <td>
                <input type="hidden" name="riga_id[]" value="<?= $r['id'] ?>">
                <strong><?= e($r['sku_interno']) ?></strong> - <?= e($r['prodotto_nome']) ?>
                
                <?php if ($r['traccia_lotti'] == 1 && $isBozza): ?>
                  <div class="qk-card mt-2 p-2 bg-light border-dashed" style="margin:0; font-size:0.82rem">
                    <strong class="text-warning"><i class="fa-solid fa-triangle-exclamation"></i> Questo prodotto richiede la tracciabilità per lotti.</strong>
                    <div class="mt-2">
                      <label class="d-block mb-1">Seleziona Lotto:</label>
                      <select name="riga_lotto_id[]" class="qk-form-control mb-2 select-lotto" onchange="toggleNuovoLotto(this, <?= $i ?>)">
                        <option value="0">- Crea un NUOVO lotto -</option>
                        <?php
                          $stLotti = $this->pdo->prepare('SELECT id, codice_lotto_interno, data_scadenza FROM lotti WHERE prodotto_id = ?');
                          $stLotti->execute([(int)$r['prodotto_id']]);
                          foreach ($stLotti->fetchAll(PDO::FETCH_ASSOC) as $lot):
                        ?>
                          <option value="<?= $lot['id'] ?>"><?= e($lot['codice_lotto_interno']) ?> (Scad: <?= e($lot['data_scadenza'] ?? 'N/D') ?>)</option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="nuovo-lotto-fields" id="nuovo-lotto-fields-<?= $i ?>">
                      <div class="row g-2">
                        <div class="col-6">
                          <label class="small">Codice Lotto Interno <span style="color:var(--qk-danger)">*</span></label>
                          <input type="text" name="lotto_codice_interno[]" class="qk-form-control qk-form-control-xs lotto-obbligatorio" placeholder="Es. LOT-2026-A">
                        </div>
                        <div class="col-6">
                          <label class="small">Codice Lotto Fornitore</label>
                          <input type="text" name="lotto_codice_fornitore[]" class="qk-form-control qk-form-control-xs" placeholder="Es. BATCH-1234">
                        </div>
                        <div class="col-6">
                          <label class="small">Data Scadenza</label>
                          <input type="date" name="lotto_data_scadenza[]" class="qk-form-control qk-form-control-xs">
                        </div>
                        <div class="col-6">
                          <label class="small">Numero DDT</label>
                          <input type="text" name="lotto_numero_ddt[]" class="qk-form-control qk-form-control-xs" placeholder="Es. DDT/45">
                        </div>
                      </div>
                    </div>
                  </div>
                <?php elseif ($r['traccia_lotti'] == 1 && !$isBozza): ?>
                  <div class="mt-1 small">
                    Lotto Assegnato: <strong><?= e($r['lotto_codice'] ?? '—') ?></strong>
                  </div>
                <?php endif; ?>
              </td>
              <td class="qk-text-right"><?= e((float)$r['quantita_ordinata']) ?> <?= e($r['uom']) ?></td>
              <td class="qk-text-right text-success"><?= e((float)$r['qta_gia_ricevuta']) ?> <?= e($r['uom']) ?></td>
              <td class="qk-text-right">
                <input type="number" name="riga_qta_accettata[]" class="qk-form-control qk-form-control-sm text-end input-qty" 
                       step="0.001" min="0" value="<?= e((float)$r['quantita_accettata']) ?>" <?= $isBozza ? 'required' : 'readonly' ?>>
              </td>
              <td class="qk-text-right">
                <input type="number" name="riga_qta_rifiutata[]" class="qk-form-control qk-form-control-sm text-end input-qty" 
                       step="0.001" min="0" value="<?= e((float)$r['quantita_rifiutata']) ?>" <?= $isBozza ? '' : 'readonly' ?>>
              </td>
              <td>
                <input type="text" name="riga_motivo_rifiuto[]" class="qk-form-control qk-form-control-sm" 
                       value="<?= e($r['motivo_rifiuto'] ?? '') ?>" placeholder="Es. Danneggiato, scaduto" <?= $isBozza ? '' : 'readonly' ?>>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($isBozza): ?>
    <div class="d-flex justify-content-between align-items-center mb-5">
      <span class="qk-text-muted small"><i class="fa-solid fa-circle-info"></i> Nota: La conferma della ricezione aggiornerà immediatamente le giacenze fisiche in magazzino.</span>
      <button type="submit" class="btn-qk btn-qk-success px-4 fw-bold">
        <i class="fa-solid fa-circle-check me-1"></i> Conferma Ricezione e Carica Magazzino
      </button>
    </div>
  <?php endif; ?>
</form>

<script>
function toggleNuovoLotto(select, idx) {
    const fields = document.getElementById('nuovo-lotto-fields-' + idx);
    const codeInput = fields.querySelector('.lotto-obbligatorio');
    if (select.value === '0') {
        fields.style.display = 'block';
        codeInput.required = true;
    } else {
        fields.style.display = 'none';
        codeInput.required = false;
        codeInput.value = '';
    }
}
</script>
