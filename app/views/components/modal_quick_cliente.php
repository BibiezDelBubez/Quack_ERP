<?php
/**
 * components/modal_quick_cliente.php
 *
 * PIANO - Correzione DRY-MVC Viste Documenti Vendita e Acquisto.md, Fase 1.
 * Estratto da sales/orders/_create.php e sales/quotes/_form.php, dove era
 * duplicato byte-per-byte a parte il prefisso degli id (verificato in questa
 * sessione rileggendo entrambi i blocchi per intero: nessuna altra
 * differenza reale a parte la presenza di aria-labelledby/id sul titolo in
 * quotes/_form.php, assente in _create.php — preservata qui come parametro
 * per non introdurre nessun cambiamento di comportamento rispetto
 * all'originale di ciascun chiamante).
 *
 * Variabili richieste dal chiamante, PRIMA dell'include (stesso pattern già
 * in uso in questo progetto per purchases/rfq/_form_riga.php — lo scope PHP
 * di un include eredita le variabili del file chiamante):
 *   string $qkMncIdPrefix        Prefisso id, es. 'ord-' o '' (stringa vuota).
 *   string|null $qkMncTitleId    Se non null, aggiunge id={$qkMncTitleId} sul
 *                                 <h5> e aria-labelledby sul div esterno
 *                                 (comportamento attuale di quotes/_form.php;
 *                                 _create.php passa null, comportamento
 *                                 invariato — non aveva questo attributo).
 */
$p = $qkMncIdPrefix;
?>
<div class="modal fade" id="<?= $p ?>modal-nuovo-cliente" tabindex="-1"<?= $qkMncTitleId ? ' aria-labelledby="' . $qkMncTitleId . '"' : '' ?> aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--qk-card-bg);color:var(--qk-text-primary);border:1px solid var(--qk-border)">
      <div class="modal-header" style="border-color:var(--qk-border)">
        <h5 class="modal-title"<?= $qkMncTitleId ? ' id="' . $qkMncTitleId . '"' : '' ?>><i class="fa-solid fa-user-plus me-2"></i>Nuovo cliente rapido</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="<?= $p ?>mnc-error" class="qk-alert qk-alert-danger mb-3" style="display:none">
          <i class="fa-solid fa-circle-xmark"></i> <span id="<?= $p ?>mnc-error-msg"></span>
        </div>
        <form id="<?= $p ?>form-nuovo-cliente" novalidate>
          <div class="qk-form-group">
            <label class="qk-form-label">Tipo soggetto</label>
            <div class="qk-flex-row qk-gap-sm">
              <label class="qk-radio-card is-selected" id="<?= $p ?>rc-azienda">
                <input type="radio" name="tipo_soggetto" value="azienda" checked>
                <i class="fa-solid fa-building"></i> Azienda
              </label>
              <label class="qk-radio-card" id="<?= $p ?>rc-privato">
                <input type="radio" name="tipo_soggetto" value="privato">
                <i class="fa-solid fa-user"></i> Privato
              </label>
            </div>
          </div>
          <div class="qk-form-group" id="<?= $p ?>mnc-rs-wrap">
            <label class="qk-form-label">Ragione sociale <span class="required">*</span></label>
            <input type="text" name="ragione_sociale" id="<?= $p ?>mnc-ragione-sociale" class="qk-form-control" placeholder="Acme S.r.l.">
          </div>
          <div class="d-none" id="<?= $p ?>mnc-nc-wrap">
            <div class="qk-form-row">
              <div class="qk-form-group">
                <label class="qk-form-label">Nome <span class="required">*</span></label>
                <input type="text" name="nome" class="qk-form-control" placeholder="Mario">
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Cognome <span class="required">*</span></label>
                <input type="text" name="cognome" class="qk-form-control" placeholder="Rossi">
              </div>
            </div>
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Email</label>
              <input type="email" name="email" class="qk-form-control" placeholder="info@azienda.it">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Telefono</label>
              <input type="text" name="telefono" class="qk-form-control" placeholder="+39 0000 000000">
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer" style="border-color:var(--qk-border)">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="<?= $p ?>btn-salva-cliente">
          <i class="fa-solid fa-floppy-disk"></i> Crea cliente
        </button>
      </div>
    </div>
  </div>
</div>
