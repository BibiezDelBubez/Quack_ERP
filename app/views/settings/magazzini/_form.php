<?php
/**
 * settings/magazzini/_form.php
 * Variabili attese: $mode ('create'|'edit'), $entity_id (?int),
 *   $values (array), $errors (array), $aziende (array),
 *   $radici (array, fisici radice per il selettore genitore, esclude se stesso),
 *   $soggetti (array, clienti_fornitori per il selettore conto_terzi/conto_lavoro)
 */
$isEdit = $mode === 'edit';
$v = $values;
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger" data-auto-dismiss="6000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<div class="qk-form-grid">

  <!-- Colonna principale -->
  <div>
    <form method="post" id="formMagazzino" data-qk-form
          action="<?= $isEdit ? url('settings/magazzini/edit?id=' . $entity_id) : url('settings/magazzini/create') ?>">
      <?= csrf_field() ?>

      <!-- Dati identificativi -->
      <div class="qk-card mb-3 mt-3">
        <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-warehouse me-2"></i>Dati magazzino</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Codice <span class="required">*</span></label>
              <input type="text" name="codice" class="qk-form-control <?= isset($errors['codice']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['codice']) ?>" placeholder="MAG-01">
              <?php if (isset($errors['codice'])): ?>
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['codice']) ?></span>
              <?php endif; ?>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Nome <span class="required">*</span></label>
              <input type="text" name="nome" class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['nome']) ?>" placeholder="Deposito Principale">
              <?php if (isset($errors['nome'])): ?>
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['nome']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Tipo <span class="required">*</span></label>
              <select name="tipo" id="selectTipoMagazzino" class="qk-form-control <?= isset($errors['tipo']) ? 'is-invalid' : '' ?>">
                <option value="fisico" <?= $v['tipo'] === 'fisico' ? 'selected' : '' ?>>Fisico</option>
                <option value="virtuale" <?= $v['tipo'] === 'virtuale' ? 'selected' : '' ?>>Virtuale (es. quarantena, reso da verificare)</option>
                <option value="conto_terzi" <?= $v['tipo'] === 'conto_terzi' ? 'selected' : '' ?>>Conto terzi (gestito da un 3PL)</option>
                <option value="conto_lavoro" <?= $v['tipo'] === 'conto_lavoro' ? 'selected' : '' ?>>Conto lavoro (merce inviata a un terzista)</option>
              </select>
              <?php if (isset($errors['tipo'])): ?>
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['tipo']) ?></span>
              <?php endif; ?>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Azienda interna</label>
              <select name="azienda_interna_id" class="qk-form-control">
                <option value="">— Nessuna specifica —</option>
                <?php foreach ($aziende as $az): ?>
                <option value="<?= $az['id'] ?>" <?= (int)$v['azienda_interna_id'] === (int)$az['id'] ? 'selected' : '' ?>>
                  <?= e($az['ragione_sociale']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <span class="qk-form-hint mt-1 d-block">Da quale azienda interna vengono emessi i documenti (DDT) partiti da questo sito. Necessaria per censire l'indirizzo sotto.</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Gerarchia spaziale (Trasferimenti tra Magazzini come Documento.md, §7.1) -->
      <div class="qk-card mb-3" id="cardGerarchia">
        <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-sitemap me-2"></i>Gerarchia spaziale</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group" id="groupParentId">
              <label class="qk-form-label">Magazzino genitore</label>
              <select name="parent_id" id="selectParentId" class="qk-form-control <?= isset($errors['parent_id']) ? 'is-invalid' : '' ?>">
                <option value="">— Nessuno (sede esterna indipendente) —</option>
                <?php foreach ($radici as $r): ?>
                <option value="<?= $r['id'] ?>" <?= (int) $v['parent_id'] === (int) $r['id'] ? 'selected' : '' ?>>
                  <?= e($r['nome']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['parent_id'])): ?>
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['parent_id']) ?></span>
              <?php endif; ?>
              <span class="qk-form-hint mt-1 d-block">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Se selezioni un genitore, stai dichiarando che questo magazzino condivide la stessa sede fisica: gli
                spostamenti tra i due diventeranno istantanei (trasferimento diretto), senza documento di trasporto.
              </span>
            </div>
            <div class="qk-form-group" id="groupSoggettoEsterno">
              <label class="qk-form-label">Gestito da (soggetto esterno)</label>
              <select name="soggetto_esterno_id" class="qk-form-control <?= isset($errors['soggetto_esterno_id']) ? 'is-invalid' : '' ?>">
                <option value="">— Nessuno —</option>
                <?php foreach ($soggetti as $s): ?>
                <option value="<?= $s['id'] ?>" <?= (int) $v['soggetto_esterno_id'] === (int) $s['id'] ? 'selected' : '' ?>>
                  <?= e($s['nome_display']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['soggetto_esterno_id'])): ?>
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['soggetto_esterno_id']) ?></span>
              <?php endif; ?>
              <span class="qk-form-hint mt-1 d-block">Il terzista/3PL che gestisce fisicamente questo sito (conto terzi/conto lavoro).</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Indirizzo -->
      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-map-location-dot me-2"></i>Indirizzo del sito</span></div>
        <div class="qk-card-body">
          <div class="qk-form-group">
            <label class="qk-form-label">Via</label>
            <input type="text" name="via" class="qk-form-control" value="<?= e($v['via']) ?>" placeholder="Via/Piazza">
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Civico</label>
              <input type="text" name="civico" class="qk-form-control" value="<?= e($v['civico']) ?>">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">CAP</label>
              <input type="text" name="cap" class="qk-form-control" value="<?= e($v['cap']) ?>">
            </div>
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Città</label>
              <input type="text" name="citta" class="qk-form-control" value="<?= e($v['citta']) ?>">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Provincia</label>
              <input type="text" name="provincia" class="qk-form-control" value="<?= e($v['provincia']) ?>" maxlength="2" style="max-width:100px">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Nazione</label>
              <input type="text" name="nazione" class="qk-form-control" value="<?= e($v['nazione'] ?: 'IT') ?>" maxlength="2" style="max-width:100px">
            </div>
          </div>
          <?php if (empty($v['azienda_interna_id'])): ?>
          <span class="qk-form-hint d-block"><i class="fa-solid fa-circle-info"></i> Seleziona un'azienda interna sopra per poter salvare l'indirizzo di questo sito.</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Note -->
      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-note-sticky me-2"></i>Note</span></div>
        <div class="qk-card-body">
          <div class="qk-form-group">
            <textarea name="note" class="qk-form-control" rows="3"><?= e($v['note']) ?></textarea>
          </div>
        </div>
      </div>
    </form>
  </div>

  <!-- Pannello stato (destro) -->
  <div class="qk-status-panel">
    <div class="qk-status-panel-hd">Stato magazzino</div>
    <div class="qk-status-panel-body">
      <div class="qk-form-group mb-3">
        <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
          <input type="checkbox" name="predefinito" form="formMagazzino" value="1" <?= !empty($v['predefinito']) ? 'checked' : '' ?>>
          Magazzino predefinito
        </label>
        <span class="qk-form-hint mt-1 d-block">Preselezionato di default in ogni schermata finché l'utente non sceglie diversamente.</span>
      </div>
      <div class="qk-form-group mb-3">
        <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
          <input type="checkbox" name="attivo" form="formMagazzino" value="1" <?= !empty($v['attivo']) ? 'checked' : '' ?>>
          Attivo
        </label>
        <span class="qk-form-hint mt-1 d-block">Un magazzino disattivato non compare più nei selettori per nuove operazioni, ma resta negli storici.</span>
      </div>

      <div class="qk-status-panel-actions">
        <button type="submit" form="formMagazzino" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk"></i>
          <?= $isEdit ? 'Salva modifiche' : 'Crea magazzino' ?>
        </button>
        <a href="<?= url('settings/magazzini') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
      </div>
    </div>
  </div>

</div><!-- /qk-form-grid -->

<script>
(function () {
  'use strict';
  var selectTipo   = document.getElementById('selectTipoMagazzino');
  var groupParent  = document.getElementById('groupParentId');
  var selectParent = document.getElementById('selectParentId');
  var groupSogg    = document.getElementById('groupSoggettoEsterno');
  var form         = document.getElementById('formMagazzino');
  if (!selectTipo || !form) return;

  var parentIniziale = selectParent.value;

  function aggiornaVisibilita() {
    var tipo = selectTipo.value;
    var isFisico = tipo === 'fisico';
    groupParent.hidden = isFisico;
    if (isFisico) selectParent.value = '';
    groupSogg.hidden = !(tipo === 'conto_terzi' || tipo === 'conto_lavoro');
  }

  selectTipo.addEventListener('change', aggiornaVisibilita);
  aggiornaVisibilita();

  // Avviso esplicito (Trasferimenti tra Magazzini come Documento.md, §9.3):
  // impostare/cambiare un parent_id dichiara che i due magazzini sono la
  // stessa sede fisica, rendendo istantanei (trasferimento diretto) gli
  // spostamenti tra loro — è una decisione strutturale, va confermata consapevolmente.
  form.addEventListener('submit', function (e) {
    if (selectParent.value !== '' && selectParent.value !== parentIniziale) {
      var ok = window.confirm(
        'Stai dichiarando che questi due magazzini sono la stessa sede fisica: ' +
        'gli spostamenti tra loro diventeranno istantanei, senza documento di trasporto. Continuare?'
      );
      if (!ok) {
        e.preventDefault();
      }
    }
  });
})();
</script>
