<?php
/**
 * entity_picker_modal.php
 * Modale di ricerca riutilizzabile (stile Odoo) — incluso una sola volta in footer.php.
 * Usato da QkPicker (JS) in tutta l'app.
 */
?>
<!-- ═══════════════════════ QK ENTITY PICKER ═══════════════════════════════ -->
<div class="modal fade" id="qk-picker-modal" tabindex="-1"
     aria-labelledby="qk-picker-title" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header pb-2">
        <h5 class="modal-title" id="qk-picker-title">Cerca</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body pt-2">
        <!-- Search bar -->
        <div class="qk-search-wrap mb-3">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="search" id="qk-picker-search"
                 class="qk-form-control qk-search-input"
                 placeholder="Ricerca…" autocomplete="off">
        </div>

        <!-- Results -->
        <div id="qk-picker-results" style="min-height:200px;max-height:420px;overflow-y:auto">
          <div class="d-flex justify-content-center align-items-center" style="height:200px">
            <span class="text-muted small">Digita per cercare o scorri la lista</span>
          </div>
        </div>

        <!-- Pagination info -->
        <div id="qk-picker-pager" class="d-flex justify-content-between align-items-center mt-2 d-none"
             style="font-size:.82rem;color:var(--qk-text-muted)">
          <span id="qk-picker-count"></span>
          <div class="d-flex gap-1">
            <button class="btn-qk btn-qk-secondary btn-qk-sm" id="qk-picker-prev">&lsaquo; Prec.</button>
            <button class="btn-qk btn-qk-secondary btn-qk-sm" id="qk-picker-next">Succ. &rsaquo;</button>
          </div>
        </div>
      </div>

      <div class="modal-footer pt-2">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary d-none" id="qk-picker-btn-nuovo">
          <i class="fa-solid fa-plus me-1"></i>Nuovo
        </button>
      </div>

    </div>
  </div>
</div>

<!-- JS spostato in assets/js/components/entity-picker.js (Fase 3,
     audit/piano-correzione-bug-qkpicker-persistenza-selezione.md §8, 08/08/2026) — prima era
     inline qui e veniva ri-scaricato/ri-compilato ad ogni pagina invece di essere messo in cache
     dal browser come un file .js esterno vero (audit/audit-journal.json, "Segnalazione #60"). -->
<script src="<?= asset('js/components/entity-picker.js') ?>"></script>
