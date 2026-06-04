<?php
/**
 * sales/pricelists/_modal_riga.php — Modal aggiunta/modifica riga prezzo.
 * Variabili attese: $values (array, per leggere valuta)
 */
?>
<div class="modal fade" id="modalRiga" tabindex="-1" aria-labelledby="modalRigaLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content qk-modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="modalRigaLabel">Prezzo prodotto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="mr-riga-id" value="0">

        <div class="qk-form-group">
          <label class="qk-form-label">Prodotto <span class="required">*</span></label>
          <div class="qk-m2o-wrap" style="position:relative">
            <input type="hidden" id="mr-prodotto-id">
            <input type="text" class="qk-form-control qk-m2o-input" id="mr-prodotto-label"
                   placeholder="Cerca prodotto per nome o SKU…" autocomplete="off">
            <button type="button" class="qk-m2o-clear d-none" id="mr-prodotto-clear">&times;</button>
            <div class="qk-m2o-dropdown shadow-sm" id="mr-prodotto-dropdown"></div>
          </div>
        </div>

        <div class="qk-form-row" style="grid-template-columns:1fr 1fr 1fr">
          <div class="qk-form-group">
            <label class="qk-form-label">Q.tà minima</label>
            <input type="number" id="mr-qmin" class="qk-form-control" min="0" step="0.001" value="0">
            <div class="qk-form-hint">0 = valido per qualsiasi quantità</div>
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Prezzo unitario <span class="required">*</span></label>
            <div style="display:flex;gap:.4rem;align-items:center">
              <input type="number" id="mr-prezzo" class="qk-form-control" min="0" step="0.0001" placeholder="0.0000">
              <span style="font-size:.78rem;color:var(--qk-text-muted);white-space:nowrap"><?= e($values['valuta'] ?? 'EUR') ?></span>
            </div>
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Sconto %</label>
            <div style="display:flex;gap:.4rem;align-items:center">
              <input type="number" id="mr-sconto" class="qk-form-control" min="0" max="100" step="0.01" value="0">
              <span style="font-size:.78rem;color:var(--qk-text-muted)">%</span>
            </div>
          </div>
        </div>

        <div class="qk-form-group mb-0">
          <label class="qk-form-label">Note</label>
          <input type="text" id="mr-note" class="qk-form-control" placeholder="Note aggiuntive…">
        </div>

        <div id="mr-error" class="qk-alert qk-alert-danger mt-3 d-none"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btn-salva-riga">
          <i class="fa-solid fa-floppy-disk me-1"></i>Salva
        </button>
      </div>

    </div>
  </div>
</div>
