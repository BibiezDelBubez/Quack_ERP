<?php
/**
 * customers/_form.php
 * Variabili attese: $mode ('create'|'edit'), $entity_id (?int),
 *   $values (array), $errors (array), $record_meta (?array),
 *   $indirizzi_preload (array, solo in edit)
 */
$isEdit = $mode === 'edit';
$v = $values;
$indirizzi_preload = $indirizzi_preload ?? [];
$nIndirizzi = count($indirizzi_preload);
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

    <!-- Tab nav -->
    <ul class="nav nav-tabs qk-tabs mb-0" id="customerTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="ctab-btn-dati"
                data-bs-toggle="tab" data-bs-target="#ctab-dati"
                type="button" role="tab" aria-selected="true">
          <i class="fa-solid fa-user fa-fw me-1"></i>Dati cliente
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="ctab-btn-indirizzi"
                data-bs-toggle="tab" data-bs-target="#ctab-indirizzi"
                type="button" role="tab" aria-selected="false">
          <i class="fa-solid fa-map-location-dot fa-fw me-1"></i>Sedi e indirizzi
          <span class="badge bg-secondary ms-1 <?= ($isEdit && $nIndirizzi > 0) ? '' : 'd-none' ?>"
                id="badge-indirizzi"><?= $nIndirizzi ?></span>
        </button>
      </li>
    </ul>

    <div class="tab-content mt-0" id="customerTabsContent">

      <!-- TAB 1: DATI CLIENTE -->
      <div class="tab-pane fade show active" id="ctab-dati" role="tabpanel">

        <form method="post" id="formCliente" data-qk-form action="<?= $isEdit ? url('customers/edit?id=' . $entity_id) : url('customers/create') ?>">
          <?= csrf_field() ?>

          <!-- Tipo soggetto -->
          <div class="qk-card mb-3 mt-3">
            <div class="qk-card-header"><span class="qk-card-title">Tipo soggetto</span></div>
            <div class="qk-card-body">
              <div class="d-flex gap-2 flex-wrap">
                <label class="qk-radio-card <?= $v['type'] === 'privato' ? 'is-selected' : '' ?>">
                  <input type="radio" name="type" value="privato" <?= $v['type'] === 'privato' ? 'checked' : '' ?>>
                  <i class="fa-solid fa-user"></i> Privato
                </label>
                <label class="qk-radio-card <?= $v['type'] === 'azienda' ? 'is-selected' : '' ?>">
                  <input type="radio" name="type" value="azienda" <?= $v['type'] === 'azienda' ? 'checked' : '' ?>>
                  <i class="fa-solid fa-building"></i> Azienda
                </label>
                <label class="qk-radio-card <?= $v['type'] === 'libpro' ? 'is-selected' : '' ?>">
                  <input type="radio" name="type" value="libpro" <?= $v['type'] === 'libpro' ? 'checked' : '' ?>>
                  <i class="fa-solid fa-briefcase"></i> Libero professionista
                </label>
              </div>
            </div>
          </div>

          <!-- Dati anagrafici -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Dati anagrafici</span></div>
            <div class="qk-card-body">

              <!-- Privato: nome + cognome -->
              <div id="fields-privato" class="qk-form-row" style="<?= $v['type'] !== 'privato' ? 'display:none' : '' ?>">
                <div class="qk-form-group">
                  <label class="qk-form-label">Nome <span class="required">*</span></label>
                  <input type="text" name="first_name" class="qk-form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['first_name']) ?>" placeholder="Mario">
                  <?php if (isset($errors['first_name'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['first_name']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Cognome <span class="required">*</span></label>
                  <input type="text" name="last_name" class="qk-form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['last_name']) ?>" placeholder="Rossi">
                  <?php if (isset($errors['last_name'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['last_name']) ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Azienda / Libero professionista -->
              <div id="fields-azienda" style="<?= $v['type'] === 'privato' ? 'display:none' : '' ?>">
                <div class="qk-form-group">
                  <label class="qk-form-label">Ragione sociale <span class="required">*</span></label>
                  <input type="text" name="company" class="qk-form-control <?= isset($errors['company']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['company']) ?>" placeholder="Acme S.r.l.">
                  <?php if (isset($errors['company'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['company']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="qk-form-row">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Partita IVA <span class="required">*</span></label>
                    <input type="text" name="vat" class="qk-form-control <?= isset($errors['vat']) ? 'is-invalid' : '' ?>"
                           value="<?= e($v['vat']) ?>" placeholder="IT12345678901">
                    <?php if (isset($errors['vat'])): ?>
                    <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['vat']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="qk-form-group">
                    <label class="qk-form-label">Referente</label>
                    <input type="text" name="referent" class="qk-form-control"
                           value="<?= e($v['referent']) ?>" placeholder="Nome referente">
                  </div>
                </div>
              </div>

              <!-- Contatti -->
              <div class="qk-form-row mt-2">
                <div class="qk-form-group">
                  <label class="qk-form-label">Email</label>
                  <input type="email" name="email" class="qk-form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['email']) ?>" placeholder="email@esempio.it">
                  <?php if (isset($errors['email'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['email']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Telefono</label>
                  <input type="tel" name="phone" class="qk-form-control"
                         value="<?= e($v['phone']) ?>" placeholder="+39 02 1234567">
                </div>
              </div>
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Cellulare</label>
                  <input type="tel" name="mobile" class="qk-form-control"
                         value="<?= e($v['mobile']) ?>" placeholder="+39 340 1234567">
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Sito web</label>
                  <input type="url" name="website" class="qk-form-control"
                         value="<?= e($v['website']) ?>" placeholder="https://www.esempio.it">
                </div>
              </div>
            </div>
          </div>

          <!-- Dati fiscali e commerciali -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Dati fiscali e commerciali</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Codice fiscale</label>
                  <input type="text" name="tax_code" class="qk-form-control"
                         value="<?= e($v['tax_code']) ?>" placeholder="RSSMRA80A01H501U">
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Codice SDI</label>
                  <input type="text" name="sdi_code" class="qk-form-control"
                         value="<?= e($v['sdi_code']) ?>" placeholder="0000000" maxlength="7">
                </div>
              </div>
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">PEC</label>
                  <input type="email" name="pec" class="qk-form-control"
                         value="<?= e($v['pec']) ?>" placeholder="pec@pec.it">
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Condizioni di pagamento</label>
                  <input type="text" name="payment_terms" class="qk-form-control"
                         value="<?= e($v['payment_terms']) ?>" placeholder="30 gg d.f.f.m.">
                </div>
              </div>
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Categoria cliente</label>
                  <select name="category" class="qk-form-control">
                    <option value="standard" <?= $v['category'] === 'standard' ? 'selected' : '' ?>>Standard (End user)</option>
                    <option value="premium"  <?= $v['category'] === 'premium'  ? 'selected' : '' ?>>Premium (Dropshipper)</option>
                    <option value="reseller" <?= $v['category'] === 'reseller' ? 'selected' : '' ?>>Rivenditore</option>
                    <option value="wholesale"<?= $v['category'] === 'wholesale'? 'selected' : '' ?>>Grossista</option>
                  </select>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Sconto extra (%)</label>
                  <input type="number" name="discount" class="qk-form-control"
                         value="<?= e($v['discount']) ?>" placeholder="0" min="0" max="100" step="0.1">
                </div>
              </div>
              <div class="qk-form-group">
                <label class="d-flex align-items-center gap-2" style="font-size:.875rem;cursor:pointer">
                  <input type="checkbox" name="newsletter" value="1" <?= $v['newsletter'] === '1' ? 'checked' : '' ?>>
                  Iscritto alla newsletter
                </label>
              </div>
            </div>
          </div>

          <!-- Note -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Note interne</span></div>
            <div class="qk-card-body">
              <div class="qk-form-group mb-0">
                <textarea name="notes" class="qk-form-control" rows="4"
                          placeholder="Note visibili solo internamente…"><?= e($v['notes']) ?></textarea>
              </div>
            </div>
          </div>

        </form>

      </div><!-- /ctab-dati -->

      <!-- TAB 2: SEDI E INDIRIZZI -->
      <div class="tab-pane fade" id="ctab-indirizzi" role="tabpanel">

        <?php if (!$isEdit): ?>
        <div class="qk-card mt-3">
          <div class="qk-card-body text-center py-4" style="color:var(--qk-text-muted)">
            <i class="fa-solid fa-map-location-dot fa-2x mb-2 d-block" style="opacity:.3"></i>
            Salva il cliente per poter aggiungere sedi e indirizzi.
          </div>
        </div>
        <?php else: ?>
        <div class="qk-card mt-3" style="overflow:hidden">
          <div id="qksa-grid" class="qksa-grid">
            <!-- Tiles iniettate da customers-addresses.js -->
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /ctab-indirizzi -->

    </div><!-- /tab-content -->

  </div><!-- /colonna principale -->

  <!-- Pannello stato -->
  <div class="qk-status-panel">
    <div class="qk-status-panel-hd">Stato anagrafica</div>
    <div class="qk-status-panel-body">
      <div class="qk-form-group mb-3">
        <label class="qk-form-label">Stato</label>
        <select name="status" form="formCliente" class="qk-form-control">
          <option value="active"    <?= $v['status'] === 'active'    ? 'selected' : '' ?>>Attivo</option>
          <option value="inactive"  <?= $v['status'] === 'inactive'  ? 'selected' : '' ?>>Inattivo</option>
          <option value="suspended" <?= $v['status'] === 'suspended' ? 'selected' : '' ?>>Sospeso</option>
        </select>
      </div>
      <div class="qk-status-panel-actions">
        <button type="submit" form="formCliente" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk"></i>
          <?= $isEdit ? 'Salva modifiche' : 'Crea cliente' ?>
        </button>
        <a href="<?= url('customers') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
      </div>
      <?php if ($isEdit && $record_meta): ?>
      <dl class="qk-status-meta mt-3">
        <?php if ($record_meta['codice']): ?>
        <dt>Codice</dt><dd><?= e($record_meta['codice']) ?></dd>
        <?php endif; ?>
        <?php if ($record_meta['created_at']): ?>
        <dt>Creato</dt><dd><?= fmt_datetime($record_meta['created_at']) ?></dd>
        <?php endif; ?>
        <?php if ($record_meta['updated_at']): ?>
        <dt>Aggiornato</dt><dd><?= fmt_datetime($record_meta['updated_at']) ?></dd>
        <?php endif; ?>
      </dl>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /qk-form-grid -->

<script>
(function () {
  var radios  = document.querySelectorAll('input[name="type"]');
  var privato = document.getElementById('fields-privato');
  var azienda = document.getElementById('fields-azienda');
  var cards   = document.querySelectorAll('.qk-radio-card');

  function toggle(val) {
    if (!privato || !azienda) return;
    privato.style.display = val === 'privato' ? '' : 'none';
    azienda.style.display = val === 'privato' ? 'none' : '';
    cards.forEach(function (c) {
      var inp = c.querySelector('input[type="radio"]');
      c.classList.toggle('is-selected', inp && inp.value === val);
    });
  }

  radios.forEach(function (r) {
    r.addEventListener('change', function () { toggle(r.value); });
  });
})();
</script>

<?php if ($isEdit): ?>
<!-- Modal: Aggiungi / Modifica indirizzo -->
<div class="modal fade" id="modalIndirizzo" tabindex="-1"
     aria-labelledby="modalIndirizzoTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalIndirizzoTitle">Indirizzo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="modalIndirizzoErr" class="qk-alert qk-alert-danger d-none mb-3" role="alert">
          <i class="fa-solid fa-circle-xmark"></i>
          <span></span>
        </div>
        <form id="formIndirizzo" novalidate autocomplete="off">
          <input type="hidden" name="address_id" value="0">

          <!-- Tipo + alias + predefinito -->
          <div class="qk-form-row mb-3">
            <div class="qk-form-group">
              <label class="qk-form-label">Tipo sede <span class="required">*</span></label>
              <select name="tipo" class="qk-form-control">
                <option value="sede_legale">Sede legale</option>
                <option value="sede_operativa">Sede operativa</option>
                <option value="spedizione">Indirizzo di spedizione</option>
                <option value="fatturazione">Indirizzo di fatturazione</option>
                <option value="magazzino">Magazzino</option>
              </select>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Alias / Nome sede</label>
              <input type="text" name="nome_sede" class="qk-form-control"
                     placeholder='Es. "Magazzino Nord", "Ufficio Roma"'>
            </div>
          </div>

          <div class="qksa-predefinito-row mb-3">
            <label class="qksa-check-label">
              <input type="checkbox" name="predefinito" value="1" id="chkPredefinito">
              <span>Imposta come indirizzo predefinito per questo tipo</span>
            </label>
          </div>

          <!-- Indirizzo -->
          <fieldset class="qksa-fieldset mb-3">
            <legend>Indirizzo</legend>
            <div class="qk-form-group mb-2">
              <label class="qk-form-label">Presso (c/o)</label>
              <input type="text" name="presso" class="qk-form-control"
                     placeholder="c/o Nome persona o ufficio">
            </div>
            <div class="qk-form-row mb-2">
              <div class="qk-form-group" style="flex:3">
                <label class="qk-form-label">Via / Indirizzo <span class="required">*</span></label>
                <input type="text" name="via" class="qk-form-control" placeholder="Via Roma">
              </div>
              <div class="qk-form-group" style="flex:1">
                <label class="qk-form-label">Civico</label>
                <input type="text" name="civico" class="qk-form-control" placeholder="12/A">
              </div>
            </div>
            <div class="qk-form-row mb-2">
              <div class="qk-form-group" style="flex:1">
                <label class="qk-form-label">CAP <span class="required">*</span></label>
                <input type="text" name="cap" class="qk-form-control" placeholder="20100" maxlength="10">
              </div>
              <div class="qk-form-group" style="flex:2">
                <label class="qk-form-label">Città <span class="required">*</span></label>
                <input type="text" name="citta" class="qk-form-control" placeholder="Milano">
              </div>
              <div class="qk-form-group" style="flex:1">
                <label class="qk-form-label">Prov.</label>
                <input type="text" name="provincia" class="qk-form-control" placeholder="MI"
                       maxlength="2" style="text-transform:uppercase">
              </div>
              <div class="qk-form-group" style="flex:1">
                <label class="qk-form-label">Paese</label>
                <input type="text" name="nazione" class="qk-form-control" placeholder="IT"
                       maxlength="2" style="text-transform:uppercase" value="IT">
              </div>
            </div>
          </fieldset>

          <!-- Dettagli consegna -->
          <fieldset class="qksa-fieldset">
            <legend>Dettagli consegna</legend>
            <div class="qk-form-row mb-2">
              <div class="qk-form-group">
                <label class="qk-form-label">Referente</label>
                <input type="text" name="referente_consegna" class="qk-form-control"
                       placeholder='Es. "Chiedere di Marco"'>
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Telefono consegna</label>
                <input type="tel" name="telefono_consegna" class="qk-form-control"
                       placeholder="+39 333 1234567">
              </div>
            </div>
            <div class="qk-form-group mb-0">
              <label class="qk-form-label">Note consegna</label>
              <textarea name="note_consegna" class="qk-form-control" rows="2"
                        placeholder="Orari di scarico, accesso al magazzino, ecc."></textarea>
            </div>
          </fieldset>

        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btnSalvaIndirizzo">
          <i class="fa-solid fa-floppy-disk"></i> Salva indirizzo
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Config PHP → JS -->
<script>
window.QkCustomerAddr = {
  customerId: <?= (int) $entity_id ?>,
  indirizzi:  <?= json_encode(array_values($indirizzi_preload), JSON_UNESCAPED_UNICODE) ?>,
  apiSave:    <?= json_encode(url('customers/api/indirizzi/save')) ?>,
  apiDelete:  <?= json_encode(url('customers/api/indirizzi/delete')) ?>,
  csrfToken:  <?= json_encode(csrf_token()) ?>,
};
</script>
<script src="<?= url('assets/js/customers-addresses.js') ?>"></script>

<?php endif; ?>
