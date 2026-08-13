<?php
/**
 * components/modal_quick_prodotto.php
 *
 * PIANO - Correzione DRY-MVC Viste Documenti Vendita e Acquisto.md, Fase 1.
 * Estratto da sales/orders/_create.php e sales/quotes/_form.php. A
 * differenza della modale cliente, qui c'era anche una vera differenza
 * funzionale, non solo di id (verificato rileggendo entrambi i blocchi per
 * intero in questa sessione): la select "Unità di misura" ha 3 opzioni in
 * _create.php (pz/kg/l) e 5 in quotes/_form.php (pz/kg/l/g/ml) — preservata
 * qui come parametro per non cambiare il comportamento di nessuno dei due
 * chiamanti. Il testo di avviso finale cambia "dell'ordine"/"dell'offerta"
 * a seconda del documento — anche questo parametrizzato.
 *
 * Variabili richieste dal chiamante, PRIMA dell'include:
 *   string $qkMnpIdPrefix        Prefisso id, es. 'ord-' o '' (stringa vuota).
 *   string|null $qkMnpTitleId    Come in modal_quick_cliente.php: se non
 *                                 null aggiunge id+aria-labelledby sul
 *                                 titolo (quotes/_form.php); _create.php
 *                                 passa null, comportamento invariato.
 *   array $qkMnpUnitaMisura      Coppie codice => etichetta per la select
 *                                 UdM, nell'ordine in cui vanno mostrate.
 *   string $qkMnpContestoLabel   "ordine" o "offerta" — usato nella frase
 *                                 finale "...nella riga dell'{$contesto}".
 */
$p = $qkMnpIdPrefix;
?>
<div class="modal fade" id="<?= $p ?>modal-nuovo-prodotto" tabindex="-1"<?= $qkMnpTitleId ? ' aria-labelledby="' . $qkMnpTitleId . '"' : '' ?> aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--qk-card-bg);color:var(--qk-text-primary);border:1px solid var(--qk-border)">
      <div class="modal-header" style="border-color:var(--qk-border)">
        <h5 class="modal-title"<?= $qkMnpTitleId ? ' id="' . $qkMnpTitleId . '"' : '' ?>><i class="fa-solid fa-box-open me-2"></i>Nuovo prodotto rapido</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="<?= $p ?>mnp-error" class="qk-alert qk-alert-danger mb-3" style="display:none">
          <i class="fa-solid fa-circle-xmark"></i> <span id="<?= $p ?>mnp-error-msg"></span>
        </div>
        <form id="<?= $p ?>form-nuovo-prodotto" novalidate>
          <div class="qk-form-group">
            <label class="qk-form-label">Nome prodotto <span class="required">*</span></label>
            <input type="text" name="nome" id="<?= $p ?>mnp-nome" class="qk-form-control" placeholder="Es. Flocculante liquido 5 Kg">
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">SKU interno</label>
              <input type="text" name="sku_interno" class="qk-form-control" placeholder="Lascia vuoto per auto-generare">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Unità di misura</label>
              <select name="unita_misura" class="qk-form-control">
                <?php foreach ($qkMnpUnitaMisura as $codice => $etichetta): ?>
                <option value="<?= e($codice) ?>"><?= e($etichetta) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="qk-text-muted" style="font-size:.82rem">
            <i class="fa-solid fa-circle-info me-1"></i>Il prezzo verrà impostato direttamente nella riga dell'<?= e($qkMnpContestoLabel) ?>.
          </p>
        </form>
      </div>
      <div class="modal-footer" style="border-color:var(--qk-border)">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="<?= $p ?>btn-salva-prodotto">
          <i class="fa-solid fa-floppy-disk"></i> Crea prodotto
        </button>
      </div>
    </div>
  </div>
</div>
