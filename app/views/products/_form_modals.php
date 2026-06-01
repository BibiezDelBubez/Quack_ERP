<?php
/**
 * products/_form_modals.php
 * Bootstrap modal dialogs for the product form.
 * Variables expected (from parent _form.php): $isEdit
 */
?>
<!-- ═══════════════════════ MODALE: Nuova categoria ═════════════════════ -->
<div class="modal fade" id="modal-new-cat" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title"><i class="fa-solid fa-folder-plus me-2"></i>Nuova categoria</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Nome <span class="text-danger">*</span></label>
        <input type="text" id="new-cat-nome" class="form-control"
               placeholder="es. Prodotti per la disinfezione"
               maxlength="100" autocomplete="off">
        <div id="new-cat-error" class="form-text text-danger d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btn-crea-cat">
          <i class="fa-solid fa-plus me-1"></i>Crea
        </button>
      </div>
    </div>
  </div>
</div>


<?php if ($isEdit): ?>
<!-- ═══════════════════════ MODALE: Fornitore ══════════════════════════
     Contiene SOLO i metadati del rapporto fornitore–prodotto.
     I prezzi (scaglioni, listini) sono gestiti inline nel tab Fornitori
     da assets/js/products-suppliers.js — non sono più in questo modale.
════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modal-fornitore" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modal-fornitore-label">Fornitore</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Campo hidden: id del record prodotti_fornitori (0 = insert) -->
        <input type="hidden" id="mf-id" value="">

        <!-- Fornitore — Many2one autocomplete ───────────────────────── -->
        <div class="row g-3 mb-3">
          <div class="col-sm-8">
            <label class="form-label">Fornitore <span class="text-danger">*</span></label>
            <input type="hidden" id="mf-fornitore-id" value="">
            <div class="qk-m2o-wrap" id="mf-m2o-wrap">
              <div class="qk-m2o-input-row">
                <input type="text" id="mf-fornitore-label" class="qk-m2o-input"
                       autocomplete="off" placeholder="Cerca fornitore…">
                <button type="button" id="mf-fornitore-clear"
                        class="qk-m2o-clear d-none" title="Rimuovi">
                  <i class="fa-solid fa-xmark fa-xs"></i>
                </button>
              </div>
              <div class="qk-m2o-dropdown" id="mf-m2o-dd"></div>
            </div>
          </div>
          <div class="col-sm-4">
            <label class="form-label">SKU fornitore</label>
            <input type="text" id="mf-sku" class="form-control font-monospace"
                   placeholder="Codice articolo fornitore" maxlength="50">
          </div>
        </div>

        <!-- Nome commerciale + ordine preferenza ────────────────────── -->
        <div class="row g-3 mb-3">
          <div class="col-sm-8">
            <label class="form-label">Nome prodotto presso il fornitore</label>
            <input type="text" id="mf-nome-prod-forn" class="form-control"
                   placeholder="Come lo chiama il fornitore" maxlength="150">
          </div>
          <div class="col-sm-4">
            <label class="form-label">
              Ordine preferenza
              <span class="text-muted small" style="font-weight:normal">(1 = preferito)</span>
            </label>
            <input type="number" id="mf-ordine-pref" class="form-control"
                   value="1" min="1" step="1">
          </div>
        </div>

        <!-- MOQ + Lead time ─────────────────────────────────────────── -->
        <div class="row g-3 mb-3">
          <div class="col-sm-4">
            <label class="form-label">Q.tà minima ordine (MOQ)</label>
            <input type="number" id="mf-qta-min" class="form-control"
                   placeholder="0,00" min="0" step="0.001">
          </div>
          <div class="col-sm-4">
            <label class="form-label">Lead time (giorni)</label>
            <input type="number" id="mf-lead-time" class="form-control"
                   placeholder="es. 7" min="0" step="1">
          </div>
          <div class="col-sm-4 d-flex align-items-end pb-1">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="mf-attivo" value="1" checked>
              <label class="form-check-label" for="mf-attivo">Attivo</label>
            </div>
          </div>
        </div>

        <!-- RRP consigliato ─────────────────────────────────────────── -->
        <div class="row g-3 mb-3">
          <div class="col-sm-4">
            <label class="form-label">RRP min. consigliato</label>
            <input type="number" id="mf-rrp-min" class="form-control"
                   placeholder="0,00" min="0" step="0.0001">
          </div>
          <div class="col-sm-4">
            <label class="form-label">RRP max. consigliato</label>
            <input type="number" id="mf-rrp-max" class="form-control"
                   placeholder="0,00" min="0" step="0.0001">
          </div>
        </div>

        <!-- Note ────────────────────────────────────────────────────── -->
        <div class="row g-3 mb-3">
          <div class="col-12">
            <label class="form-label">Note</label>
            <textarea id="mf-note" class="form-control" rows="2"
                      placeholder="Note specifiche per questo fornitore…"></textarea>
          </div>
        </div>

        <!-- Info prezzi ─────────────────────────────────────────────── -->
        <div class="d-flex align-items-center gap-2 small text-muted mt-1">
          <i class="fa-solid fa-circle-info text-primary opacity-75"></i>
          I prezzi si gestiscono direttamente nel tab <strong>Fornitori</strong>:
          usa il pulsante <strong>+ Prezzo</strong> accanto al nome del fornitore.
        </div>

        <!-- Area errore ──────────────────────────────────────────────── -->
        <div id="mf-error" class="qk-alert qk-alert-danger d-none mt-3">
          <i class="fa-solid fa-circle-xmark"></i>
          <span id="mf-error-msg"></span>
        </div>
      </div><!-- /modal-body -->
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btn-salva-forn">
          <i class="fa-solid fa-floppy-disk me-1"></i>Salva
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════ MODALE: Componente kit ═════════════════════ -->
<div class="modal fade" id="modal-componente" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modal-componente-label">Componente kit</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- Prodotto — QkM2O -->
        <div class="mb-3">
          <label class="form-label">Prodotto <span class="text-danger">*</span></label>
          <input type="hidden" id="mc-prodotto-id" value="">
          <div class="qk-m2o-wrap" id="mc-m2o-wrap">
            <div class="qk-m2o-input-row">
              <input type="text" id="mc-prodotto-label" class="qk-m2o-input"
                     autocomplete="off" placeholder="Cerca prodotto per nome o SKU…">
              <button type="button" id="mc-prodotto-clear"
                      class="qk-m2o-clear d-none" title="Rimuovi">
                <i class="fa-solid fa-xmark fa-xs"></i>
              </button>
            </div>
            <div class="qk-m2o-dropdown" id="mc-m2o-dd"></div>
          </div>
          <div id="mc-edit-info" class="form-text text-muted d-none">
            Il prodotto non può essere modificato in fase di modifica. Rimuovi e ricrea il componente.
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label">Quantità <span class="text-danger">*</span></label>
            <input type="number" id="mc-quantita" class="form-control"
                   placeholder="es. 1" min="0.001" step="0.001">
            <div class="form-text text-muted" style="font-size:0.75rem">Nell'unità di stoccaggio del prodotto da aggiungere</div>
          </div>
          <div class="col-sm-6">
            <label class="form-label">Note</label>
            <input type="text" id="mc-note" class="form-control"
                   placeholder="Opzionale" maxlength="255">
          </div>
        </div>

        <div id="mc-error" class="qk-alert qk-alert-danger d-none mt-2">
          <i class="fa-solid fa-circle-xmark"></i>
          <span id="mc-error-msg"></span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btn-salva-componente">
          <i class="fa-solid fa-floppy-disk me-1"></i>Salva
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
