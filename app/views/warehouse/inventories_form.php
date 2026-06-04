<?php
/**
 * app/views/warehouse/inventories_form.php
 * Form/Gestione sessione di inventario fisico.
 */
$isConvalidato = isset($inventario) && $inventario['stato'] === 'convalidato';
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'inventories'; include __DIR__ . '/_subnav.php'; ?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
</div>
<?php endif; ?>

<form method="post" action="<?= url('warehouse/inventories/create') . (isset($inventario) ? '?id=' . $inventario['id'] : '') ?>">
  <?= csrf_field() ?>

  <div class="qk-card mb-4 mt-3">
    <div class="qk-card-header"><span class="qk-card-title">Dati Inventario</span></div>
    <div class="qk-card-body">
      <div class="qk-form-row">
        <div class="qk-form-group">
          <label class="qk-form-label">Data Rilevamento <span class="required">*</span></label>
          <input type="date" name="data_inventario" class="qk-form-control" 
                 value="<?= e($values['data_inventario'] ?? date('Y-m-d')) ?>" 
                 <?= $isConvalidato ? 'disabled' : '' ?>>
        </div>
        <div class="qk-form-group" style="flex: 2;">
          <label class="qk-form-label">Note / Descrizione</label>
          <input type="text" name="note" class="qk-form-control" 
                 value="<?= e($values['note'] ?? '') ?>" 
                 placeholder="Es. Inventario Straordinario Chimici, Riconciliazione Fine Anno..."
                 <?= $isConvalidato ? 'disabled' : '' ?>>
        </div>
      </div>
    </div>
  </div>

  <div class="qk-card mb-4">
    <div class="qk-card-header d-flex justify-content-between align-items-center">
      <span class="qk-card-title">Articoli e Lotti da Conteggiare</span>
      <?php if ($isConvalidato): ?>
        <span class="qk-badge confirmed">Convalidato / Sigillato</span>
      <?php else: ?>
        <span class="qk-text-muted fs-7">Inserisci la quantità reale rilevata fisicamente</span>
      <?php endif; ?>
    </div>
    <div class="qk-table-wrap">
      <table class="qk-table" id="inventarioTable">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Prodotto / Lotto</th>
            <th class="text-end" style="width: 150px;">Giacenza Teorica</th>
            <th class="text-end" style="width: 180px;">Quantità Contata</th>
            <th class="text-end" style="width: 150px;">Scostamento / Rettifica</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($prodotti_inventario)): ?>
          <tr>
            <td colspan="5" class="qk-table-empty">Nessun prodotto attivo da conteggiare.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($prodotti_inventario as $p): ?>
            <?php
              // Chiave unica per l'input: prodotto_id:lotto_id (lotto_id può essere vuoto o null)
              $key = $p['prodotto_id'] . ':' . ($p['lotto_id'] ?? '');
              $val = $values['righe'][$key] ?? null;
              
              // Se non c'è valore inserito (bozza appena aperta), precompiliamo col teorico
              if ($val === null) {
                  $val = $p['giacenza_teorica'];
              }
              
              $scostamento = (float)$val - (float)$p['giacenza_teorica'];
              $scostamentoClass = '';
              if ($scostamento > 0) {
                  $scostamentoClass = 'text-success fw-bold';
                  $scostamentoText = '+' . number_format($scostamento, 3, ',', '.');
              } elseif ($scostamento < 0) {
                  $scostamentoClass = 'text-danger fw-bold';
                  $scostamentoText = number_format($scostamento, 3, ',', '.');
              } else {
                  $scostamentoClass = 'text-muted';
                  $scostamentoText = '0,000';
              }
            ?>
            <tr data-row-key="<?= e($key) ?>" data-teorico="<?= (float)$p['giacenza_teorica'] ?>">
              <td><code><?= e($p['sku_interno']) ?></code></td>
              <td>
                <span class="fw-semibold"><?= e($p['nome_prodotto']) ?></span>
                <?php if ($p['codice_lotto_interno']): ?>
                  <span class="qkwh-badge-lotti ms-2"><i class="fa-solid fa-tag"></i> Lotto: <?= e($p['codice_lotto_interno']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end fw-semibold text-muted">
                <?= number_format((float)$p['giacenza_teorica'], 3, ',', '.') ?> <?= e($p['uom']) ?>
              </td>
              <td class="text-end">
                <input type="number" 
                       name="righe[<?= e($key) ?>]" 
                       class="qk-form-control text-end qk-inventario-input" 
                       value="<?= e($val) ?>" 
                       step="0.001" 
                       style="max-width: 150px; display: inline-block;"
                       <?= $isConvalidato ? 'disabled' : '' ?>>
                <span class="ms-1 text-muted"><?= e($p['uom']) ?></span>
              </td>
              <td class="text-end qk-scostamento-cell <?= $scostamentoClass ?>">
                <?= $scostamentoText ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-5">
    <a href="<?= url('warehouse/inventories') ?>" class="btn-qk btn-qk-secondary">
      <i class="fa-solid fa-arrow-left me-1"></i> Torna alla lista
    </a>
    
    <?php if (!$isConvalidato): ?>
      <div class="d-flex gap-2">
        <button type="submit" name="azione" value="salva" class="btn-qk btn-qk-secondary">
          <i class="fa-solid fa-floppy-disk me-1"></i> Salva in Bozza
        </button>
        <button type="submit" name="azione" value="convalida" class="btn-qk btn-qk-primary" 
                onclick="return confirm('Sei sicuro di voler convalidare l\'inventario? Questa azione applicherà le rettifiche di magazzino in modo definitivo e non sarà più modificabile.');">
          <i class="fa-solid fa-check me-1"></i> Convalida e Chiudi
        </button>
      </div>
    <?php endif; ?>
  </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.qk-inventario-input');
    
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            const tr = this.closest('tr');
            const teorico = parseFloat(tr.dataset.teorico) || 0;
            const contata = parseFloat(this.value) || 0;
            const scostamento = contata - teorico;
            
            const cell = tr.querySelector('.qk-scostamento-cell');
            
            cell.className = 'text-end qk-scostamento-cell';
            
            if (scostamento > 0) {
                cell.classList.add('text-success', 'fw-bold');
                cell.textContent = '+' + scostamento.toLocaleString('it-IT', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
            } else if (scostamento < 0) {
                cell.classList.add('text-danger', 'fw-bold');
                cell.textContent = scostamento.toLocaleString('it-IT', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
            } else {
                cell.classList.add('text-muted');
                cell.textContent = '0,000';
            }
        });
    });
});
</script>
