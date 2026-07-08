<?php
/**
 * settings/aziende/_form.php
 * Variabili attese: $mode ('create'|'edit'), $entity_id (?int),
 *   $values (array), $errors (array)
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

    <!-- Tab nav -->
    <ul class="nav nav-tabs qk-tabs mb-0" id="aziendaTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="atab-btn-dati"
                data-bs-toggle="tab" data-bs-target="#atab-dati"
                type="button" role="tab" aria-selected="true">
          <i class="fa-solid fa-building fa-fw me-1"></i>Dati azienda
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="atab-btn-indirizzi"
                data-bs-toggle="tab" data-bs-target="#atab-indirizzi"
                type="button" role="tab" aria-selected="false">
          <i class="fa-solid fa-map-location-dot fa-fw me-1"></i>Sedi e indirizzi
          <span class="badge bg-secondary ms-1 <?= ($isEdit && $nIndirizzi > 0) ? '' : 'd-none' ?>"
                id="badge-indirizzi"><?= $nIndirizzi ?></span>
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="atab-btn-contatti"
                data-bs-toggle="tab" data-bs-target="#atab-contatti"
                type="button" role="tab" aria-selected="false">
          <i class="fa-solid fa-address-book fa-fw me-1"></i>Contatti
          <span class="badge bg-secondary ms-1 <?= ($isEdit && $nContatti > 0) ? '' : 'd-none' ?>"
                id="badge-contatti"><?= $nContatti ?></span>
        </button>
      </li>
    </ul>

    <div class="tab-content mt-0" id="aziendaTabsContent">

      <!-- TAB 1: DATI AZIENDA -->
      <div class="tab-pane fade show active" id="atab-dati" role="tabpanel">

        <form method="post" enctype="multipart/form-data" id="formAzienda" data-qk-form 
              action="<?= $isEdit ? url('settings/aziende/edit?id=' . $entity_id) : url('settings/aziende/create') ?>">
          <?= csrf_field() ?>
          
          <?php if ($isEdit): ?>
          <input type="hidden" name="existing_logo_path" value="<?= e($v['logo_path']) ?>">
          <input type="hidden" name="existing_header_path" value="<?= e($v['header_path']) ?>">
          <?php endif; ?>

          <!-- Dati anagrafici azienda -->
          <div class="qk-card mb-3 mt-3">
            <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-building me-2"></i>Dati Societari</span></div>
            <div class="qk-card-body">
              <div class="qk-form-group">
                <label class="qk-form-label">Ragione Sociale <span class="required">*</span></label>
                <input type="text" name="ragione_sociale" class="qk-form-control <?= isset($errors['ragione_sociale']) ? 'is-invalid' : '' ?>"
                       value="<?= e($v['ragione_sociale']) ?>" placeholder="La Mia Azienda S.r.l.">
                <?php if (isset($errors['ragione_sociale'])): ?>
                <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['ragione_sociale']) ?></span>
                <?php endif; ?>
              </div>

              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Partita IVA <span class="required">*</span></label>
                  <input type="text" name="partita_iva" class="qk-form-control <?= isset($errors['partita_iva']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['partita_iva']) ?>" placeholder="IT00000000000">
                  <?php if (isset($errors['partita_iva'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['partita_iva']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Codice Fiscale</label>
                  <input type="text" name="codice_fiscale" class="qk-form-control"
                         value="<?= e($v['codice_fiscale']) ?>" placeholder="Codice Fiscale">
                </div>
              </div>
            </div>
          </div>

          <!-- Recapiti societari -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-address-book me-2"></i>Recapiti & Contatti</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Email principale</label>
                  <input type="email" name="email" class="qk-form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['email']) ?>" placeholder="amministrazione@azienda.it">
                  <?php if (isset($errors['email'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['email']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">PEC</label>
                  <input type="email" name="pec" class="qk-form-control"
                         value="<?= e($v['pec']) ?>" placeholder="amministrazione@pec.azienda.it">
                </div>
              </div>
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Telefono</label>
                  <input type="tel" name="telefono" class="qk-form-control"
                         value="<?= e($v['telefono']) ?>" placeholder="+39 02 123456">
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Cellulare</label>
                  <input type="tel" name="cellulare" class="qk-form-control"
                         value="<?= e($v['cellulare']) ?>" placeholder="+39 333 1234567">
                </div>
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Sito web</label>
                <input type="text" name="sito_web" class="qk-form-control"
                       value="<?= e($v['sito_web']) ?>" placeholder="www.azienda.it">
              </div>
            </div>
          </div>

          <!-- Dettagli Stampa e Configurazione Fiscale -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-file-invoice-dollar me-2"></i>Dati Fatturazione & Stampe</span></div>
            <div class="qk-card-body">
              
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Capitale Sociale (€)</label>
                  <input type="number" name="capitale_sociale" class="qk-form-control"
                         value="<?= e((string)$v['capitale_sociale']) ?>" placeholder="10000.00" min="0" step="0.01">
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Registro Imprese / REA</label>
                  <input type="text" name="registro_imprese" class="qk-form-control"
                         value="<?= e($v['registro_imprese']) ?>" placeholder="REA MI-123456">
                </div>
              </div>

              <div class="qk-form-row mt-2 mb-3">
                <div class="qk-form-group">
                  <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
                    <input type="checkbox" name="socio_unico" value="1" <?= !empty($v['socio_unico']) ? 'checked' : '' ?>>
                    SRL a socio unico
                  </label>
                </div>
                <div class="qk-form-group">
                  <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
                    <input type="checkbox" name="in_liquidazione" value="1" <?= !empty($v['in_liquidazione']) ? 'checked' : '' ?>>
                    In liquidazione
                  </label>
                </div>
              </div>

              <!-- Logo upload -->
              <div class="qk-form-group">
                <label class="qk-form-label">Logo Aziendale</label>
                <div id="logo-preview-container" class="mb-2 qk-flex-row qk-gap-sm" style="<?= empty($v['logo_path']) ? 'display:none !important;' : '' ?>">
                  <img id="logo-preview" src="<?= !empty($v['logo_path']) ? url('uploads?path=' . urlencode($v['logo_path'])) : '#' ?>" alt="Logo anteprima" style="max-height:60px;border:1px solid var(--qk-card-border);padding:2px;border-radius:4px">
                  <span class="small text-muted font-monospace" id="logo-status-text"><?= !empty($v['logo_path']) ? '<span class="text-success"><i class="fa-solid fa-circle-check"></i> Logo caricato: ' . e($v['logo_path']) . '</span>' : '' ?></span>
                </div>
                <input type="file" name="logo_file" id="logo-input" class="qk-form-control" accept="image/*">
                <span class="qk-form-hint">Formati supportati: JPEG, PNG, GIF, SVG.</span>
              </div>

              <!-- Header stampati upload -->
              <div class="qk-form-group">
                <label class="qk-form-label">Intestazione per Documenti (Header)</label>
                <div id="header-preview-container" class="mb-2 qk-flex-row qk-gap-sm" style="<?= empty($v['header_path']) ? 'display:none !important;' : '' ?>">
                  <img id="header-preview" src="<?= !empty($v['header_path']) ? url('uploads?path=' . urlencode($v['header_path'])) : '#' ?>" alt="Header anteprima" style="max-height:80px;border:1px solid var(--qk-card-border);padding:2px;border-radius:4px;width:auto;max-width:100%;">
                  <span class="small text-muted font-monospace" id="header-status-text"><?= !empty($v['header_path']) ? '<span class="text-success"><i class="fa-solid fa-circle-check"></i> Header caricato: ' . e($v['header_path']) . '</span>' : '' ?></span>
                </div>
                <input type="file" name="header_file" id="header-input" class="qk-form-control" accept="image/*">
                <span class="qk-form-hint">Viene stampato in testa ai preventivi e DDT.</span>
              </div>

            </div>
          </div>

          <!-- Dati Bancari di Default -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-piggy-bank me-2"></i>Coordinate Bancarie di Default</span></div>
            <div class="qk-card-body">
              <div class="qk-form-group">
                <label class="qk-form-label">Banca d'appoggio</label>
                <input type="text" name="banca_default" class="qk-form-control"
                       value="<?= e($v['banca_default']) ?>" placeholder="Es. Intesa Sanpaolo">
              </div>
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">IBAN</label>
                  <input type="text" name="iban_default" class="qk-form-control <?= isset($errors['iban_default']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['iban_default']) ?>" placeholder="IT00X0000000000000000000000" style="text-transform:uppercase">
                  <?php if (isset($errors['iban_default'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['iban_default']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="qk-form-group" style="flex:0 0 150px">
                  <label class="qk-form-label">BIC / SWIFT</label>
                  <input type="text" name="bic_swift_default" class="qk-form-control"
                         value="<?= e($v['bic_swift_default']) ?>" placeholder="XXXXXXXX" style="text-transform:uppercase">
                </div>
              </div>
            </div>
          </div>

        </form>

      </div><!-- /atab-dati -->

      <!-- TAB 2: SEDI E INDIRIZZI -->
      <div class="tab-pane fade" id="atab-indirizzi" role="tabpanel">

        <?php if (!$isEdit): ?>
        <div class="qk-card mt-3">
          <div class="qk-card-body text-center py-4" style="color:var(--qk-text-muted)">
            <i class="fa-solid fa-map-location-dot fa-2x mb-2 d-block" style="opacity:.3"></i>
            Salva l'azienda per poter aggiungere sedi e indirizzi.
          </div>
        </div>
        <?php else: ?>
        <div class="qk-card mt-3" style="overflow:hidden">
          <div id="qksa-grid" class="qksa-grid">
            <!-- Tiles iniettate da customers-addresses.js -->
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /atab-indirizzi -->

      <!-- TAB 3: CONTATTI -->
      <div class="tab-pane fade" id="atab-contatti" role="tabpanel">

        <?php if (!$isEdit): ?>
        <div class="qk-card mt-3">
          <div class="qk-card-body text-center py-4" style="color:var(--qk-text-muted)">
            <i class="fa-solid fa-address-book fa-2x mb-2 d-block" style="opacity:.3"></i>
            Salva l'azienda per poter aggiungere contatti.
          </div>
        </div>
        <?php else: ?>
        <div class="qk-card mt-3" style="overflow:hidden">
          <div id="qksc-grid" class="qksc-grid">
            <!-- Tiles iniettate da customers-contacts.js -->
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /atab-contatti -->

    </div><!-- /tab-content -->

  </div><!-- /colonna principale -->

  <!-- Pannello stato (destro) -->
  <div class="qk-status-panel">
    <div class="qk-status-panel-hd">Impostazioni ERP</div>
    <div class="qk-status-panel-body">
      <div class="qk-form-group mb-3">
        <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
          <input type="checkbox" name="predefinita" form="formAzienda" value="1" <?= !empty($v['predefinita']) ? 'checked' : '' ?>>
          Azienda principale (Default)
        </label>
        <span class="qk-form-hint mt-1 d-block">L'azienda principale viene suggerita come emittente predefinita nei documenti.</span>
      </div>

      <div class="qk-status-panel-actions">
        <button type="submit" form="formAzienda" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk"></i>
          <?= $isEdit ? 'Salva modifiche' : 'Crea azienda' ?>
        </button>
        <a href="<?= url('settings/aziende') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
      </div>
    </div>
  </div>

</div><!-- /qk-form-grid -->

<!-- Modals -->
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
              <span>Imposta come predefinito per questo tipo di sede</span>
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

<!-- Modal: Aggiungi / Modifica contatto -->
<div class="modal fade" id="modalContatto" tabindex="-1"
     aria-labelledby="modalContattoTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalContattoTitle">Contatto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="modalContattoErr" class="qk-alert qk-alert-danger d-none mb-3" role="alert">
          <i class="fa-solid fa-circle-xmark"></i>
          <span></span>
        </div>
        <form id="formContatto" novalidate autocomplete="off">
          <input type="hidden" name="contact_id" value="0">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Nome</label>
              <input type="text" name="nome" class="qk-form-control" placeholder="Mario">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Cognome</label>
              <input type="text" name="cognome" class="qk-form-control" placeholder="Rossi">
            </div>
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Ruolo / Funzione</label>
            <input type="text" name="ruolo" class="qk-form-control" placeholder="Es. Responsabile acquisti">
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Email</label>
            <input type="email" name="email" class="qk-form-control" placeholder="mario.rossi@esempio.it">
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Telefono</label>
              <input type="tel" name="telefono" class="qk-form-control" placeholder="+39 02 1234567">
            </div>
            <div class="qk-form-group mb-0">
              <label class="qk-form-label">Cellulare</label>
              <input type="tel" name="cellulare" class="qk-form-control" placeholder="+39 333 1234567">
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btnSalvaContatto">
          <i class="fa-solid fa-floppy-disk"></i> Salva contatto
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function setupPreview(inputId, containerId, previewId, statusId) {
        const input = document.getElementById(inputId);
        const container = document.getElementById(containerId);
        const preview = document.getElementById(previewId);
        const status = document.getElementById(statusId);
 
        if (!input) return;
 
        input.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    container.style.setProperty('display', 'flex', 'important');
                    status.innerHTML = '<span class="text-success font-monospace" style="font-weight:600;"><i class="fa-solid fa-circle-check"></i> Anteprima (' + (file.size / 1024).toFixed(1) + ' KB) pronta</span>';
                }
                reader.readAsDataURL(file);
            }
        });
    }
 
    setupPreview('logo-input', 'logo-preview-container', 'logo-preview', 'logo-status-text');
    setupPreview('header-input', 'header-preview-container', 'header-preview', 'header-status-text');
});
</script>

<?php if ($isEdit): ?>
<!-- Config PHP → JS -->
<script>
window.QkCustomer = {
  customerId: <?= (int) $soggetto_id ?>,
  contatti:   <?= json_encode(array_values($contatti_preload), JSON_UNESCAPED_UNICODE) ?>,
  apiList:    <?= json_encode(url('settings/aziende/api/contatti')) ?>,
  apiSave:    <?= json_encode(url('settings/aziende/api/contatti/save')) ?>,
  apiDelete:  <?= json_encode(url('settings/aziende/api/contatti/delete')) ?>,
  csrfToken:  <?= json_encode(csrf_token()) ?>,
};

window.QkCustomerAddr = {
  customerId: <?= (int) $soggetto_id ?>,
  indirizzi:  <?= json_encode(array_values($indirizzi_preload), JSON_UNESCAPED_UNICODE) ?>,
  apiSave:    <?= json_encode(url('settings/aziende/api/indirizzi/save')) ?>,
  apiDelete:  <?= json_encode(url('settings/aziende/api/indirizzi/delete')) ?>,
  csrfToken:  <?= json_encode(csrf_token()) ?>,
};
</script>
<script src="<?= url('assets/js/customers-addresses.js') ?>"></script>
<script src="<?= url('assets/js/customers-contacts.js') ?>"></script>
<?php endif; ?>
