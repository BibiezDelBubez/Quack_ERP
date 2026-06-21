<?php
/**
 * suppliers/_form.php
 * Variabili attese: $mode ('create'|'edit'), $entity_id (?int),
 *   $values (array), $errors (array), $record_meta (?array),
 *   $contatti_preload (array, solo in edit),
 *   $indirizzi_preload (array, solo in edit)
 */
$isEdit = $mode === 'edit';
$v = $values;
$contatti_preload  = $contatti_preload  ?? [];
$indirizzi_preload = $indirizzi_preload ?? [];
$nContatti  = count($contatti_preload);
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
    <ul class="nav nav-tabs qk-tabs mb-0" id="supplierTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="stab-btn-dati"
                data-bs-toggle="tab" data-bs-target="#stab-dati"
                type="button" role="tab" aria-selected="true">
          <i class="fa-solid fa-building fa-fw me-1"></i>Dati fornitore
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="stab-btn-indirizzi"
                data-bs-toggle="tab" data-bs-target="#stab-indirizzi"
                type="button" role="tab" aria-selected="false">
          <i class="fa-solid fa-map-location-dot fa-fw me-1"></i>Sedi e indirizzi
          <span class="badge bg-secondary ms-1 <?= ($isEdit && $nIndirizzi > 0) ? '' : 'd-none' ?>"
                id="badge-indirizzi"><?= $nIndirizzi ?></span>
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="stab-btn-contatti"
                data-bs-toggle="tab" data-bs-target="#stab-contatti"
                type="button" role="tab" aria-selected="false">
          <i class="fa-solid fa-address-book fa-fw me-1"></i>Contatti
          <span class="badge bg-secondary ms-1 <?= ($isEdit && $nContatti > 0) ? '' : 'd-none' ?>"
                id="badge-contatti"><?= $nContatti ?></span>
        </button>
      </li>
      <?php if ($isEdit): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="stab-btn-listini"
                data-bs-toggle="tab" data-bs-target="#stab-listini"
                type="button" role="tab" aria-selected="false">
          <i class="fa-solid fa-tags fa-fw me-1"></i>Listini acquisto
        </button>
      </li>
      <?php endif; ?>
    </ul>

    <div class="tab-content mt-0" id="supplierTabsContent">

      <!-- TAB 1: DATI FORNITORE -->
      <div class="tab-pane fade show active" id="stab-dati" role="tabpanel">

        <form method="post" id="formFornitore" data-qk-form
              action="<?= $isEdit ? url('suppliers/edit?id=' . $entity_id) : url('suppliers/create') ?>">
          <?= csrf_field() ?>

          <!-- Dati azienda -->
          <div class="qk-card mb-3 mt-3">
            <div class="qk-card-header"><span class="qk-card-title">Dati azienda</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Ragione sociale <span class="required">*</span></label>
                  <input type="text" name="company" class="qk-form-control <?= isset($errors['company']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['company']) ?>" placeholder="Fornitore S.p.A.">
                  <?php if (isset($errors['company'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['company']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Recapiti azienda -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Recapiti azienda</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Email principale</label>
                  <input type="email" name="email" class="qk-form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['email']) ?>" placeholder="info@fornitore.it">
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
                  <label class="qk-form-label">Sito web</label>
                  <input type="text" name="website" class="qk-form-control"
                         value="<?= e($v['website']) ?>" placeholder="www.fornitore.it">
                </div>
              </div>
            </div>
          </div>

          <!-- Dati fiscali -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Dati fiscali</span></div>
            <div class="qk-card-body">
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
                  <label class="qk-form-label">Codice fiscale</label>
                  <input type="text" name="tax_code" class="qk-form-control"
                         value="<?= e($v['tax_code']) ?>">
                </div>
              </div>
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">PEC</label>
                  <input type="email" name="pec" class="qk-form-control"
                         value="<?= e($v['pec']) ?>" placeholder="pec@pec.it">
                </div>
              </div>
            </div>
          </div>

          <!-- Condizioni commerciali -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Condizioni commerciali</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Lead time (giorni)</label>
                  <input type="number" name="lead_time" class="qk-form-control"
                         value="<?= e($v['lead_time']) ?>" placeholder="7" min="0" step="1">
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Minimo ordine (€)</label>
                  <input type="number" name="min_order" class="qk-form-control"
                         value="<?= e($v['min_order']) ?>" placeholder="0.00" min="0" step="0.01">
                </div>
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Condizioni di pagamento</label>
                <input type="text" name="payment_terms" class="qk-form-control"
                       value="<?= e($v['payment_terms']) ?>" placeholder="30 gg d.f.f.m.">
              </div>
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Valuta di fatturazione</label>
                  <select name="currency" class="qk-form-control">
                    <option value="EUR" <?= $v['currency'] === 'EUR' ? 'selected' : '' ?>>EUR — Euro</option>
                    <option value="USD" <?= $v['currency'] === 'USD' ? 'selected' : '' ?>>USD — Dollaro USA</option>
                    <option value="GBP" <?= $v['currency'] === 'GBP' ? 'selected' : '' ?>>GBP — Sterlina</option>
                    <option value="CHF" <?= $v['currency'] === 'CHF' ? 'selected' : '' ?>>CHF — Franco Svizzero</option>
                  </select>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Codice cliente presso fornitore</label>
                  <input type="text" name="our_customer_code" class="qk-form-control"
                         value="<?= e($v['our_customer_code']) ?>" placeholder="Es. C10940">
                </div>
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Portale ordini fornitore (URL)</label>
                <input type="text" name="orders_portal_url" class="qk-form-control"
                       value="<?= e($v['orders_portal_url']) ?>" placeholder="portale.fornitore.com/login">
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Categorie merceologiche fornite</label>
                <textarea name="supply_categories" class="qk-form-control" rows="2"
                          placeholder="Es. Trattamento acqua, Pompe, Accessori piscina..."><?= e($v['supply_categories']) ?></textarea>
              </div>
            </div>
          </div>

          <!-- Note generali -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Note generali</span></div>
            <div class="qk-card-body">
              <div class="qk-form-group mb-0">
                <textarea name="notes" class="qk-form-control" rows="4"
                          placeholder="Note generali del fornitore..."><?= e($v['notes']) ?></textarea>
              </div>
            </div>
          </div>

          <!-- Note interne -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Note interne commerciali</span></div>
            <div class="qk-card-body">
              <div class="qk-form-group mb-0">
                <textarea name="notes_intern" class="qk-form-control" rows="3"
                          placeholder="Note interne commerciali specifiche del fornitore..."><?= e($v['notes_intern']) ?></textarea>
              </div>
            </div>
          </div>

        </form>

      </div><!-- /stab-dati -->

      <!-- TAB 2: CONTATTI -->
      <div class="tab-pane fade" id="stab-contatti" role="tabpanel">

        <?php if (!$isEdit): ?>
        <div class="qk-card mt-3">
          <div class="qk-card-body text-center py-4" style="color:var(--qk-text-muted)">
            <i class="fa-solid fa-address-book fa-2x mb-2 d-block" style="opacity:.3"></i>
            Salva il fornitore per poter aggiungere contatti.
          </div>
        </div>
        <?php else: ?>
        <div class="qk-card mt-3" style="overflow:hidden">
          <div id="qksc-grid" class="qksc-grid">
            <!-- Tiles iniettate da suppliers-contacts.js -->
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /stab-contatti -->

      <!-- TAB 3: SEDI E INDIRIZZI -->
      <div class="tab-pane fade" id="stab-indirizzi" role="tabpanel">

        <?php if (!$isEdit): ?>
        <div class="qk-card mt-3">
          <div class="qk-card-body text-center py-4" style="color:var(--qk-text-muted)">
            <i class="fa-solid fa-map-location-dot fa-2x mb-2 d-block" style="opacity:.3"></i>
            Salva il fornitore per poter aggiungere sedi e indirizzi.
          </div>
        </div>
        <?php else: ?>
        <div class="qk-card mt-3" style="overflow:hidden">
          <div id="qksa-grid" class="qksa-grid">
            <!-- Tiles iniettate da suppliers-addresses.js -->
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /stab-indirizzi -->

      <!-- TAB 4: LISTINI ACQUISTO -->
      <?php if ($isEdit): ?>
      <div class="tab-pane fade" id="stab-listini" role="tabpanel">
        
        <div class="qk-card mt-3 mb-3">
          <div class="qk-card-header qk-flex-row qk-justify-between">
            <div>
              <span class="qk-card-title"><i class="fa-solid fa-tags me-1"></i>Listini Acquisto Contrattualizzati</span>
              <span class="qk-card-subtitle" style="display:block; font-size:.78rem; color:var(--qk-text-muted); margin-top:.2rem;">
                Visualizza i listini d'acquisto contrattualizzati con questo fornitore. I listini e i relativi prezzi speciali degli articoli si gestiscono in <a href="<?= url('purchases/pricelists') ?>" target="_blank">Gestione Listini Acquisto <i class="fa-solid fa-external-link"></i></a>.
              </span>
            </div>
            <a href="<?= url('purchases/pricelists/create') ?>?fornitore_id=<?= $entity_id ?>&fornitore_label=<?= urlencode($v['company']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
              <i class="fa-solid fa-plus"></i> Nuovo listino
            </a>
          </div>
          <div class="qk-card-body p-0">
            <table class="qk-table mb-0" id="table-listini-fornitore">
              <thead>
                <tr>
                  <th>Nome Listino</th>
                  <th style="width: 140px;">Valido Dal</th>
                  <th style="width: 140px;">Valido Al</th>
                  <th style="width: 120px; text-align: center;">Stato</th>
                  <th>Note</th>
                  <th style="width: 80px; text-align: center;">Azione</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($listini_acquisto)): ?>
                  <tr>
                    <td colspan="6" class="text-center py-4" style="color: var(--qk-text-muted);">
                      Nessun listino d'acquisto registrato per questo fornitore.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($listini_acquisto as $la): ?>
                    <tr>
                      <td>
                        <a href="<?= url('purchases/pricelists/edit?id=' . $la['id']) ?>" style="font-weight: 600;">
                          <?= e($la['nome_listino']) ?>
                        </a>
                      </td>
                      <td><?= $la['valido_dal'] ? fmt_date($la['valido_dal']) : '—' ?></td>
                      <td><?= $la['valido_al'] ? fmt_date($la['valido_al']) : '—' ?></td>
                      <td class="text-center">
                        <?php 
                        $oggi = date('Y-m-d');
                        $isActive = (!$la['valido_dal'] || $la['valido_dal'] <= $oggi) && (!$la['valido_al'] || $la['valido_al'] >= $oggi);
                        ?>
                        <span class="qk-badge <?= $isActive ? 'active' : 'inactive' ?>">
                          <?= $isActive ? 'Attivo' : 'Scaduto' ?>
                        </span>
                      </td>
                      <td style="font-size: .8rem; color: var(--qk-text-muted); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?= e($la['note'] ?? '') ?>
                      </td>
                      <td class="text-center">
                        <a href="<?= url('purchases/pricelists/edit?id=' . $la['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-xs" title="Modifica listino">
                          <i class="fa-solid fa-pen-to-square"></i>
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- /stab-listini -->
      <?php endif; ?>

    </div><!-- /tab-content -->

  </div><!-- /colonna principale -->

  <!-- Pannello stato (colonna destra) -->
  <div class="qk-status-panel">
    <div class="qk-status-panel-hd">Stato anagrafica</div>
    <div class="qk-status-panel-body">
      <div class="qk-form-group mb-3">
        <label class="qk-form-label">Stato</label>
        <select name="status" form="formFornitore" class="qk-form-control">
          <option value="active"    <?= $v['status'] === 'active'    ? 'selected' : '' ?>>Attivo</option>
          <option value="inactive"  <?= $v['status'] === 'inactive'  ? 'selected' : '' ?>>Inattivo</option>
          <option value="suspended" <?= $v['status'] === 'suspended' ? 'selected' : '' ?>>Sospeso</option>
        </select>
      </div>
      <div class="qk-status-panel-actions">
        <button type="submit" form="formFornitore" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk"></i>
          <?= $isEdit ? 'Salva modifiche' : 'Crea fornitore' ?>
        </button>
        <a href="<?= url('suppliers') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
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

      <?php if ($isEdit): ?>
      <div class="mt-3 pt-3 border-top text-center">
        <form action="<?= url('suppliers/delete') ?>" method="post" onsubmit="return confirm('Eliminare definitivamente questo fornitore? Questa operazione non può essere annullata.');">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$entity_id ?>">
          <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm w-100">
            <i class="fa-solid fa-trash me-1"></i> Elimina fornitore
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /qk-form-grid -->

<?php if ($isEdit): ?>
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
            <input type="email" name="email" class="qk-form-control" placeholder="mario.rossi@fornitore.it">
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
window.QkSupplier = {
  supplierId: <?= (int) $entity_id ?>,
  contatti:   <?= json_encode(array_values($contatti_preload), JSON_UNESCAPED_UNICODE) ?>,
  apiList:    <?= json_encode(url('suppliers/api/contatti')) ?>,
  apiSave:    <?= json_encode(url('suppliers/api/contatti/save')) ?>,
  apiDelete:  <?= json_encode(url('suppliers/api/contatti/delete')) ?>,
  csrfToken:  <?= json_encode(csrf_token()) ?>,
};
window.QkSupplierAddr = {
  supplierId: <?= (int) $entity_id ?>,
  indirizzi:  <?= json_encode(array_values($indirizzi_preload), JSON_UNESCAPED_UNICODE) ?>,
  apiSave:    <?= json_encode(url('suppliers/api/indirizzi/save')) ?>,
  apiDelete:  <?= json_encode(url('suppliers/api/indirizzi/delete')) ?>,
  csrfToken:  <?= json_encode(csrf_token()) ?>,
};
</script>
<script src="<?= url('assets/js/suppliers-contacts.js') ?>"></script>
<script src="<?= url('assets/js/suppliers-addresses.js') ?>"></script>

<?php endif; ?>
