<?php
/**
 * warehouse/_movement_form.php
 * Form per registrare un movimento manuale di magazzino.
 * Variabili: $values (array), $errors (array)
 */
$prefillProdId = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
if ($prefillProdId && $values['prodotto_id'] === '') {
    $values['prodotto_id'] = (string) $prefillProdId;
}
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
    <form method="post" id="formMovimento" data-qk-form
          action="<?= url('warehouse/movements/create') ?>">
      <?= csrf_field() ?>

      <!-- Tipo movimento -->
      <div class="qk-card mb-3 mt-3">
        <div class="qk-card-header"><span class="qk-card-title">Tipo operazione</span></div>
        <div class="qk-card-body">
          <div class="qkwh-tipo-selector">

            <label class="qkwh-tipo-option <?= $values['tipo_movimento'] === 'carico' ? 'selected' : '' ?>">
              <input type="radio" name="tipo_movimento" value="carico"
                     <?= $values['tipo_movimento'] === 'carico' ? 'checked' : '' ?>>
              <i class="fa-solid fa-arrow-down"></i>
              <span class="qkwh-tipo-name">Carico</span>
              <span class="qkwh-tipo-desc">Aggiunge quantità alla giacenza</span>
            </label>

            <label class="qkwh-tipo-option <?= $values['tipo_movimento'] === 'scarico' ? 'selected' : '' ?>">
              <input type="radio" name="tipo_movimento" value="scarico"
                     <?= $values['tipo_movimento'] === 'scarico' ? 'checked' : '' ?>>
              <i class="fa-solid fa-arrow-up"></i>
              <span class="qkwh-tipo-name">Scarico</span>
              <span class="qkwh-tipo-desc">Rimuove quantità dalla giacenza</span>
            </label>

            <label class="qkwh-tipo-option <?= $values['tipo_movimento'] === 'rettifica' ? 'selected' : '' ?>">
              <input type="radio" name="tipo_movimento" value="rettifica"
                     <?= $values['tipo_movimento'] === 'rettifica' ? 'checked' : '' ?>>
              <i class="fa-solid fa-scale-balanced"></i>
              <span class="qkwh-tipo-name">Rettifica</span>
              <span class="qkwh-tipo-desc">Imposta la nuova giacenza esatta</span>
            </label>

          </div>
        </div>
      </div>

      <!-- Prodotto -->
      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Prodotto</span></div>
        <div class="qk-card-body">
          <div class="qk-form-group">
            <label class="qk-form-label">Prodotto <span class="required">*</span></label>
            <div class="qkwh-prod-search-wrap">
              <input type="text" id="prodottoSearch"
                     class="qk-form-control <?= isset($errors['prodotto_id']) ? 'is-invalid' : '' ?>"
                     placeholder="Cerca per nome o SKU…"
                     autocomplete="off"
                     value="">
              <input type="hidden" name="prodotto_id" id="prodottoId"
                     value="<?= e($values['prodotto_id']) ?>">
              <div id="prodottoDropdown" class="qkwh-dropdown" hidden></div>
            </div>
            <?php if (isset($errors['prodotto_id'])): ?>
            <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['prodotto_id']) ?></span>
            <?php endif; ?>
          </div>

          <!-- Info prodotto (iniettata da JS dopo selezione) -->
          <div id="prodottoInfo" class="qkwh-prodotto-info" hidden>
            <div class="qkwh-info-row">
              <span class="qkwh-info-label">Giacenza attuale</span>
              <span class="qkwh-info-value" id="infoGiacenza">—</span>
            </div>
            <div class="qkwh-info-row">
              <span class="qkwh-info-label">Unità di misura</span>
              <span class="qkwh-info-value" id="infoUom">—</span>
            </div>
            <div class="qkwh-info-row" id="infoLottiRow" hidden>
              <span class="qkwh-info-label">Tracciatura lotti</span>
              <span class="qkwh-info-value qkwh-lotti-active">
                <i class="fa-solid fa-tag"></i> Attiva — i campi lotto sono visibili in basso
              </span>
            </div>
            <div class="qkwh-info-row" id="infoLottiOffRow" hidden>
              <span class="qkwh-info-label">Tracciatura lotti</span>
              <span class="qkwh-info-value" style="color:var(--qk-text-muted)">
                <i class="fa-solid fa-tag-slash"></i> Non attiva
                <a id="linkModificaProdotto" href="#" target="_blank"
                   style="font-size:.75rem; color:var(--qk-accent-text); margin-left:.5rem; text-decoration:none;">
                  <i class="fa-solid fa-pen-to-square"></i> Abilita dal prodotto
                </a>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Quantità -->
      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Quantità</span></div>
        <div class="qk-card-body">
          <div id="qtaHint" class="qkwh-qta-hint mb-2" hidden></div>
          <div class="qk-form-group">
            <label class="qk-form-label" id="qtaLabel">
              Quantità <span class="required">*</span>
            </label>
            <div class="qkwh-qta-wrap">
              <input type="number" name="quantita" id="inputQuantita"
                     class="qk-form-control <?= isset($errors['quantita']) ? 'is-invalid' : '' ?>"
                     value="<?= e($values['quantita']) ?>"
                     step="0.001" min="0" placeholder="0">
              <span class="qkwh-uom-suffix" id="qtaUomSuffix"></span>
            </div>
            <?php if (isset($errors['quantita'])): ?>
            <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['quantita']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Lotto (visibile solo se traccia_lotti=1) -->
      <div id="lottoSection" class="qk-card mb-3" hidden>
        <div class="qk-card-header"><span class="qk-card-title">Lotto</span></div>
        <div class="qk-card-body">

          <!-- Switch "Lotto esistente / Nuovo lotto" — solo per Carico -->
          <div id="lottoModeWrap" class="mb-3" hidden>
            <div class="qkwh-tipo-selector" style="grid-template-columns:1fr 1fr">
              <label class="qkwh-tipo-option <?= $values['lotto_mode'] !== 'nuovo' ? 'selected' : '' ?>">
                <input type="radio" name="lotto_mode" value="esistente"
                       <?= $values['lotto_mode'] !== 'nuovo' ? 'checked' : '' ?>>
                <i class="fa-solid fa-boxes-stacked"></i>
                <span class="qkwh-tipo-name">Lotto esistente</span>
                <span class="qkwh-tipo-desc">Aggiunge quantità a un lotto già presente</span>
              </label>
              <label class="qkwh-tipo-option <?= $values['lotto_mode'] === 'nuovo' ? 'selected' : '' ?>">
                <input type="radio" name="lotto_mode" value="nuovo"
                       <?= $values['lotto_mode'] === 'nuovo' ? 'checked' : '' ?>>
                <i class="fa-solid fa-plus"></i>
                <span class="qkwh-tipo-name">Nuovo lotto</span>
                <span class="qkwh-tipo-desc">Crea un nuovo lotto e lo carica</span>
              </label>
            </div>
          </div>

          <!-- Select lotto esistente -->
          <div id="lottoEsistenteDiv">
            <div class="qk-form-group mb-0">
              <label class="qk-form-label">Lotto <span class="required">*</span></label>
              <select name="lotto_id" id="selectLotto"
                      class="qk-form-control <?= isset($errors['lotto_id']) ? 'is-invalid' : '' ?>">
                <option value="">— seleziona lotto —</option>
              </select>
              <?php if (isset($errors['lotto_id'])): ?>
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['lotto_id']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Campi nuovo lotto (solo per carico) -->
          <div id="lottoNuovoDiv" hidden>
            <div class="qk-form-row">
              <div class="qk-form-group">
                <label class="qk-form-label">Codice lotto <span class="required">*</span></label>
                <input type="text" name="nuovo_lotto_codice_interno" id="nuovoLottoInterno"
                       class="qk-form-control <?= isset($errors['nuovo_lotto_codice_interno']) ? 'is-invalid' : '' ?>"
                       value="<?= e($values['nuovo_lotto_codice_interno']) ?>"
                       placeholder="Es. LOT-2026-001">
                <?php if (isset($errors['nuovo_lotto_codice_interno'])): ?>
                <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['nuovo_lotto_codice_interno']) ?></span>
                <?php endif; ?>
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Codice lotto fornitore</label>
                <input type="text" name="nuovo_lotto_codice_fornitore"
                       class="qk-form-control"
                       value="<?= e($values['nuovo_lotto_codice_fornitore']) ?>"
                       placeholder="Es. BNT-LT-20260501">
              </div>
            </div>
            <div class="qk-form-row">
              <div class="qk-form-group">
                <label class="qk-form-label">Data scadenza</label>
                <input type="date" name="nuovo_lotto_scadenza"
                       class="qk-form-control"
                       value="<?= e($values['nuovo_lotto_scadenza']) ?>">
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Data arrivo</label>
                <input type="date" name="nuovo_lotto_arrivo"
                       class="qk-form-control"
                       value="<?= e($values['nuovo_lotto_arrivo']) ?>">
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- Note e riferimenti -->
      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Note e riferimenti</span></div>
        <div class="qk-card-body">
          <div class="qk-form-group">
            <label class="qk-form-label">Note</label>
            <textarea name="note" class="qk-form-control" rows="2"
                      placeholder="Motivo del movimento, osservazioni…"><?= e($values['note']) ?></textarea>
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Riferimento esterno</label>
              <input type="text" name="riferimento_esterno" class="qk-form-control"
                     value="<?= e($values['riferimento_esterno']) ?>"
                     placeholder="Es. DDT-2026-0042, WC-1890">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Tipo documento</label>
              <input type="text" name="documento_tipo" class="qk-form-control"
                     value="<?= e($values['documento_tipo']) ?>"
                     placeholder="Es. ddt, ordine_acquisto">
            </div>
          </div>
        </div>
      </div>

    </form>
  </div><!-- /colonna principale -->

  <!-- Pannello azione -->
  <div class="qk-status-panel">
    <div class="qk-status-panel-hd">Riepilogo movimento</div>
    <div class="qk-status-panel-body">

      <div id="riepilogoMovimento" class="qkwh-riepilogo">
        <div class="qkwh-riepilogo-row">
          <span class="qkwh-riepilogo-label">Tipo</span>
          <span class="qkwh-riepilogo-val" id="riepilogoTipo">—</span>
        </div>
        <div class="qkwh-riepilogo-row">
          <span class="qkwh-riepilogo-label">Prodotto</span>
          <span class="qkwh-riepilogo-val qkwh-riepilogo-prodotto" id="riepilogoProdotto">—</span>
        </div>
        <div class="qkwh-riepilogo-row">
          <span class="qkwh-riepilogo-label">Giacenza attuale</span>
          <span class="qkwh-riepilogo-val" id="riepilogoGiacAttuale">—</span>
        </div>
        <div class="qkwh-riepilogo-row" id="riepilogoNuovaRow" hidden>
          <span class="qkwh-riepilogo-label">Giacenza dopo</span>
          <strong class="qkwh-riepilogo-val qkwh-giacenza-nuova" id="riepilogoGiacNuova">—</strong>
        </div>
      </div>

      <div class="qk-status-panel-actions mt-3">
        <button type="submit" form="formMovimento" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-check"></i> Registra movimento
        </button>
        <a href="<?= url('warehouse/stock') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
      </div>
    </div>
  </div>

</div><!-- /qkwh-form-layout -->

<!-- Config JS -->
<script>
window.QkWarehouse = {
  apiProdottoInfo:   <?= json_encode(url('warehouse/api/prodotto-info')) ?>,
  apiProdottiSearch: <?= json_encode(url('warehouse/api/prodotti-search')) ?>,
  prefillProdottoId: <?= (int)($values['prodotto_id'] ?? 0) ?>,
  urlProdottoEdit:   <?= json_encode(url('products/edit')) ?>,
};
</script>
<script src="<?= url('assets/js/warehouse-movement.js') ?>"></script>
