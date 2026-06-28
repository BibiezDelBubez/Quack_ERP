<?php
/**
 * sales/pricelists_form.php — Assembler listino vendita.
 * Flash e breadcrumb gestiti da header.php (via redirect_with / flash_get).
 * Variabili: $isEdit, $id, $values, $errors, $items, $clienti_assegnati
 */
$_partials = __DIR__ . '/pricelists';
?>

<?php $active_tab = 'pricelists'; include __DIR__ . '/_subnav.php'; ?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-exclamation"></i>
  <span><?= e($errors['_global']) ?></span>
</div>
<?php endif; ?>

<form action="" method="post" class="mb-4">
  <?= csrf_field() ?>
  <?php include $_partials . '/_form_testata.php'; ?>
</form>

<?php if ($isEdit): ?>
  <!-- Horizontal Sub-tabs (Odoo-style) -->
  <div class="qk-tabs-container mb-4">
    <ul class="nav nav-tabs qk-tabs border-bottom-0" id="pricelistTabs" role="tablist" style="gap:4px">
      <li class="nav-item" role="presentation">
        <button class="nav-link active fw-semibold" id="items-tab" data-bs-toggle="tab" data-bs-target="#items-pane" type="button" role="tab" aria-controls="items-pane" aria-selected="true" style="padding:.75rem 1.25rem;border-radius:var(--qk-radius) var(--qk-radius) 0 0">
          <i class="fa-solid fa-file-spreadsheet me-2 text-primary"></i>Articoli e Tariffe
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold" id="clients-tab" data-bs-toggle="tab" data-bs-target="#clients-pane" type="button" role="tab" aria-controls="clients-pane" aria-selected="false" style="padding:.75rem 1.25rem;border-radius:var(--qk-radius) var(--qk-radius) 0 0">
          <i class="fa-solid fa-users me-2 text-primary"></i>Clienti Assegnati
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes-pane" type="button" role="tab" aria-controls="notes-pane" aria-selected="false" style="padding:.75rem 1.25rem;border-radius:var(--qk-radius) var(--qk-radius) 0 0">
          <i class="fa-solid fa-circle-info me-2 text-primary"></i>Note e Informazioni
        </button>
      </li>
    </ul>

    <div class="tab-content" id="pricelistTabsContent">
      
      <!-- TAB 1: Articoli e Tariffe -->
      <div class="tab-pane fade show active" id="items-pane" role="tabpanel" aria-labelledby="items-tab" tabindex="0">
        <?php include $_partials . '/_righe.php'; ?>
      </div>
      
      <!-- TAB 2: Clienti Assegnati -->
      <div class="tab-pane fade" id="clients-pane" role="tabpanel" aria-labelledby="clients-tab" tabindex="0">
        <?php if (!empty($values['is_default'])): ?>
          <div class="qk-card shadow-sm text-center py-5">
            <div class="qk-card-body">
              <i class="fa-solid fa-earth-americas text-muted mb-3" style="font-size: 3rem;"></i>
              <h5 class="text-secondary">Listino Pubblico Globale</h5>
              <p class="text-muted mb-0">Questo listino si applica automaticamente a tutti i clienti sprovvisti di listini dedicati o scaglioni specifici.<br>L'assegnazione manuale di singoli clienti non è pertanto possibile né necessaria.</p>
            </div>
          </div>
        <?php else: ?>
        <div class="qk-card shadow-sm">
          <div class="qk-card-header qk-flex-row qk-justify-between">
            <span class="qk-card-title">
              <i class="fa-solid fa-address-book me-2" style="color:var(--qk-accent)"></i>Clienti che usano questo listino
            </span>
            <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" id="btn-gestisci-assegnazioni">
              <i class="fa-solid fa-users-gear me-1"></i> Gestisci assegnazioni
            </button>
          </div>
          <div class="qk-card-body p-0">
            <div class="qk-table-wrap">
              <table class="qk-table mb-0">
                <thead>
                  <tr>
                    <th>Ragione Sociale / Nominativo</th>
                    <th style="width:120px;text-align:right">Stato</th>
                  </tr>
                </thead>
                <tbody id="sidebar-assigned-clients">
                  <?php if (!empty($clienti_assegnati)): ?>
                    <?php foreach ($clienti_assegnati as $cl): ?>
                      <tr>
                        <td>
                          <a href="<?= url('customers/edit?id=' . $cl['id']) ?>" class="fw-semibold qk-text-accent" style="text-decoration:none">
                            <i class="fa-solid fa-user me-2 text-muted" style="font-size:.85rem"></i><?= e($cl['nome_cliente']) ?>
                          </a>
                        </td>
                        <td style="text-align:right"><span class="badge bg-secondary-subtle text-secondary small">Assegnato</span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="2" class="py-4 text-center text-muted">
                        <i class="fa-solid fa-users-slash me-2"></i>Nessun cliente assegnato direttamente a questo listino.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- TAB 3: Note e Informazioni -->
      <div class="tab-pane fade" id="notes-pane" role="tabpanel" aria-labelledby="notes-tab" tabindex="0">
        <div class="row g-3">
          <div class="col-md-7">
            <div class="qk-card shadow-sm h-100">
              <div class="qk-card-header">
                <span class="qk-card-title"><i class="fa-solid fa-message me-2" style="color:var(--qk-accent)"></i>Note interne e descrizione</span>
              </div>
              <div class="qk-card-body">
                <p class="text-muted small">Queste note sono ad uso interno e non verranno visualizzate sui documenti commerciali inviati ai clienti.</p>
                <div class="qk-form-group mb-0">
                  <textarea class="qk-form-control" rows="6" readonly placeholder="Aggiungi una descrizione o delle note dettagliate per questo listino nel form principale sopra..."><?= e($values['descrizione'] ?? '') ?></textarea>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-5">
            <div class="qk-card shadow-sm h-100" style="border-left:3px solid var(--qk-info)">
              <div class="qk-card-header">
                <span class="qk-card-title"><i class="fa-solid fa-circle-info me-2" style="color:var(--qk-info)"></i>Informazioni e Guide</span>
              </div>
              <div class="qk-card-body" style="font-size:.85rem;color:var(--qk-text-muted);line-height:1.6">
                <h5 class="fw-semibold text-primary mb-2" style="font-size:.9rem">Funzionamento dei Listini di Vendita</h5>
                <ul class="ps-3 mb-3" style="list-style-type:disc">
                  <li>Puoi definire scaglioni di quantità minima e sconti percentuali specifici per ciascun prodotto.</li>
                  <li>I listini possono avere una valuta specifica e un periodo di validità temporale definito.</li>
                  <li>Se un prodotto non ha un prezzo definito all'interno di questo listino, il sistema utilizzerà i prezzi standard di listino generale o calcolerà un ricarico automatico.</li>
                </ul>
                <h5 class="fw-semibold text-primary mb-2" style="font-size:.9rem">Regole di Priorità dei Prezzi</h5>
                <p class="mb-0">Il motore di determinazione del prezzo seleziona la tariffa con la quantità minima maggiore che risulti inferiore o uguale alla quantità ordinata.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Include modal & script for client assignment -->
  <?php include $_partials . '/_sidebar.php'; ?>
<?php endif; ?>
