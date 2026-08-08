<?php
/**
 * warehouse/transfers_diretto.php
 * Form "Trasferimento diretto" — spostamento istantaneo tra due magazzini con
 * la stessa radice fisica (Trasferimenti tra Magazzini come Documento.md,
 * §7.2, Regola A — nome allineato al lessico SAP/Dynamics 365 per questo tipo
 * di movimento (uscita/entrata simultanea, senza transito tracciato).
 * Struttura e JS di riga (prodotto/lotto/quantità) identici a transfers_form.php:
 * riusa la stessa window.QkTrasferimenti + assets/js/warehouse-transfer.js.
 * Variabili: $values (array), $errors (array), $magazzini (array)
 */
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3" data-auto-dismiss="6000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<div class="qkwh-form-layout">

  <!-- Colonna principale -->
  <div>
    <form method="post" id="formTrasferimento" data-qk-form
          action="<?= url('warehouse/transfers/diretto') ?>">
      <?= csrf_field() ?>

      <!-- Origine / Destinazione -->
      <div class="qk-card mb-3 mt-3">
        <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-bolt me-2"></i>Origine e destinazione</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Magazzino Origine <span class="required">*</span></label>
              <select name="magazzino_origine_id" id="selectOrigine"
                      class="qk-form-control <?= isset($errors['magazzino_origine_id']) ? 'is-invalid' : '' ?>">
                <option value="">— seleziona —</option>
                <?php foreach ($magazzini as $mg): ?>
                <option value="<?= $mg['id'] ?>" data-radice="<?= (int) ($mg['parent_id'] ?? $mg['id']) ?>"
                        <?= (string)$values['magazzino_origine_id'] === (string)$mg['id'] ? 'selected' : '' ?>>
                  <?= e($mg['nome']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Magazzino Destinazione <span class="required">*</span></label>
              <select name="magazzino_destinazione_id" id="selectDestinazione"
                      class="qk-form-control <?= isset($errors['magazzino_destinazione_id']) ? 'is-invalid' : '' ?>">
                <option value="">— seleziona —</option>
                <?php foreach ($magazzini as $mg): ?>
                <option value="<?= $mg['id'] ?>" data-radice="<?= (int) ($mg['parent_id'] ?? $mg['id']) ?>"
                        <?= (string)$values['magazzino_destinazione_id'] === (string)$mg['id'] ? 'selected' : '' ?>>
                  <?= e($mg['nome']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div id="stessoMagazzinoWarn" class="qk-alert qk-alert-danger mt-2" hidden>
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Origine e destinazione non possono coincidere.</span>
          </div>
          <div id="radiceDiversaWarn" class="qk-alert qk-alert-danger mt-2" hidden>
            <i class="fa-solid fa-truck-arrow-right"></i>
            <span>Origine e destinazione non condividono la stessa sede fisica: il trasferimento diretto non è ammesso. Usa un <a href="<?= url('warehouse/transfers/create') ?>">trasferimento con transito</a>.</span>
          </div>

          <div class="qk-form-group mt-2">
            <label class="qk-form-label">Data</label>
            <input type="date" name="data_richiesta" class="qk-form-control"
                   value="<?= e($values['data_richiesta']) ?>">
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Note</label>
            <textarea name="note" class="qk-form-control" rows="2"
                      placeholder="Motivo dello spostamento, riferimenti…"><?= e($values['note']) ?></textarea>
          </div>
        </div>
      </div>

      <!-- Righe -->
      <div class="qk-card mb-3">
        <div class="qk-card-header d-flex justify-content-between align-items-center">
          <span class="qk-card-title"><i class="fa-solid fa-list-check me-2"></i>Prodotti da spostare</span>
          <button type="button" id="btnAddRiga" class="btn-qk btn-qk-secondary btn-qk-sm">
            <i class="fa-solid fa-plus"></i> Aggiungi riga
          </button>
        </div>
        <div class="qk-card-body p-0">
          <table class="qk-table" id="tabellaRighe">
            <thead>
              <tr>
                <th style="min-width:260px">Prodotto</th>
                <th style="min-width:200px">Lotto</th>
                <th style="width:140px">Quantità</th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody id="righeBody">
              <!-- righe iniettate da JS -->
            </tbody>
          </table>
          <?php if (isset($errors['righe'])): ?>
          <div class="qk-form-error p-2"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['righe']) ?></div>
          <?php endif; ?>
        </div>
      </div>

    </form>
  </div><!-- /colonna principale -->

  <!-- Pannello azione -->
  <div class="qk-status-panel">
    <div class="qk-status-panel-hd">Trasferimento diretto</div>
    <div class="qk-status-panel-body">
      <p class="qk-form-hint">
        Ammesso solo tra magazzini che condividono la stessa radice fisica (es. una sede e una sua
        ubicazione virtuale). Al click su Conferma la giacenza viene spostata <strong>istantaneamente</strong>:
        il documento generato salta lo stato <em>in transito</em> e nasce già <strong>completato</strong>,
        ma resta tracciabile con un numero <code>TRF/…</code> come ogni altro trasferimento.
      </p>
      <div class="qk-status-panel-actions mt-3">
        <button type="submit" form="formTrasferimento" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-bolt"></i> Conferma trasferimento diretto
        </button>
        <a href="<?= url('warehouse/transfers') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
      </div>
    </div>
  </div>

</div><!-- /qkwh-form-layout -->

<script>
window.QkTrasferimenti = {
  apiProdottiSearch:     <?= json_encode(url('warehouse/api/prodotti-search')) ?>,
  apiLottiPerMagazzino:  <?= json_encode(url('warehouse/api/lotti-per-magazzino')) ?>,
};
</script>
<!-- asset(), non url(): cache-busting automatico via filemtime() (vedi
     audit/piano-correzione-bug-righe-perse-vendita-scoperto-ordini.md
     §11.2/§11.3, 08/08/2026). -->
<script src="<?= asset('js/warehouse-transfer.js') ?>"></script>
<script>
(function () {
  'use strict';
  var selectOrigine = document.getElementById('selectOrigine');
  var selectDest    = document.getElementById('selectDestinazione');
  var warnRadice    = document.getElementById('radiceDiversaWarn');
  var form          = document.getElementById('formTrasferimento');
  if (!selectOrigine || !selectDest || !form) return;

  function radiceDi(select) {
    var opt = select.options[select.selectedIndex];
    return opt ? opt.getAttribute('data-radice') : null;
  }

  function checkRadice() {
    var ro = radiceDi(selectOrigine);
    var rd = radiceDi(selectDest);
    var diversa = selectOrigine.value !== '' && selectDest.value !== '' && ro !== rd;
    warnRadice.hidden = !diversa;
    return diversa;
  }

  selectOrigine.addEventListener('change', checkRadice);
  selectDest.addEventListener('change', checkRadice);

  form.addEventListener('submit', function (e) {
    if (checkRadice()) {
      e.preventDefault();
    }
  });
})();
</script>
