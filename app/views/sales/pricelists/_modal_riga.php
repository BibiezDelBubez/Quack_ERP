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
            <div class="qk-flex-row qk-gap-xs">
              <input type="number" id="mr-prezzo" class="qk-form-control" min="0" step="0.0001" placeholder="0.0000">
              <span style="font-size:.78rem;color:var(--qk-text-muted);white-space:nowrap"><?= e($values['valuta'] ?? 'EUR') ?></span>
            </div>
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Sconto %</label>
            <div class="qk-flex-row qk-gap-xs">
              <input type="number" id="mr-sconto" class="qk-form-control" min="0" max="100" step="0.01" value="0">
              <span style="font-size:.78rem;color:var(--qk-text-muted)">%</span>
            </div>
          </div>
        </div>

        <div class="qk-form-group d-none" id="mr-rrp-container" style="background:var(--qk-accent-light, rgba(37,99,235,0.05));padding:.5rem .75rem;border-radius:var(--qk-radius-sm);margin-top:-.5rem;margin-bottom:1rem">
          <span style="font-size:.78rem;color:var(--qk-text-muted)">RRP Consigliato (Fornitore Preferito):</span>
          <strong id="mr-rrp-range" style="color:var(--qk-accent);margin-left:.25rem">—</strong>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
  const inputEl = document.getElementById('mr-prodotto-label');
  const hiddenEl = document.getElementById('mr-prodotto-id');
  const dropdownEl = document.getElementById('mr-prodotto-dropdown');
  const clearBtn = document.getElementById('mr-prodotto-clear');
  const rrpContainer = document.getElementById('mr-rrp-container');
  const rrpRange = document.getElementById('mr-rrp-range');

  if (!inputEl) return;

  new QkM2O({
    inputEl,
    hiddenEl,
    dropdownEl,
    clearBtn,
    apiUrl: (typeof QK_BASE !== 'undefined' ? QK_BASE : '') + '/sales/pricelists/api/prodotti',
    labelKey: 'label',
    columns: [
      { key: 'label', label: 'Prodotto' },
      { key: 'sku', label: 'SKU' }
    ],
    onSelect: (item) => {
      if (!item) {
        if (rrpContainer) rrpContainer.classList.add('d-none');
        if (rrpRange) rrpRange.textContent = '—';
        return;
      }
      if (item.rrp_min !== null || item.rrp_max !== null) {
        const rrpMinFmt = item.rrp_min !== null ? parseFloat(item.rrp_min).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) + ' €' : '—';
        const rrpMaxFmt = item.rrp_max !== null ? parseFloat(item.rrp_max).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) + ' €' : '—';
        if (rrpRange) rrpRange.textContent = `${rrpMinFmt} - ${rrpMaxFmt}`;
        if (rrpContainer) rrpContainer.classList.remove('d-none');
      } else {
        if (rrpContainer) rrpContainer.classList.add('d-none');
        if (rrpRange) rrpRange.textContent = '—';
      }
    },
    onClear: () => {
      if (rrpContainer) rrpContainer.classList.add('d-none');
      if (rrpRange) rrpRange.textContent = '—';
    }
  });
});
</script>

    </div>
  </div>
</div>
