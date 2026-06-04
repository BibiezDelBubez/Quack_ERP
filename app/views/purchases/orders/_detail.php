<?php
/**
 * purchases/orders/_detail.php
 * Dettaglio ordine di acquisto.
 * Variabili attese: $detail (array), $errors (array), $saved (bool), $page_title (string)
 */

$o     = $detail['ordine'];
$righe = $detail['righe'];
$ricezioni = $detail['ricezioni'] ?? [];
$id    = (int)$o['id'];

$currStato = $o['stato'];
$isBozza        = $currStato === 'bozza';
$isInAppr       = $currStato === 'in_approvazione';
$isApprovato    = $currStato === 'approvato';
$isInviato      = $currStato === 'inviato';
$isPartRicevuto = $currStato === 'parzialmente_ricevuto';
$isCompletato   = $currStato === 'completato';
$isAnnullato    = $currStato === 'annullato';

$canSave = $isBozza || $isInAppr || $isApprovato;
$canEditLogistics = $isBozza || $isInAppr || $isApprovato;

// Find draft reception if any
$draftRicezione = null;
foreach ($ricezioni as $rc) {
    if ($rc['stato'] === 'bozza') {
        $draftRicezione = $rc;
        break;
    }
}
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="qk-alert qk-alert-success mb-3" data-auto-dismiss="4000">
  <i class="fa-solid fa-circle-check"></i>
  <span><?= e($_SESSION['flash_success']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_success']); endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="qk-alert qk-alert-danger mb-3" data-auto-dismiss="6000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($_SESSION['flash_error']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>

<!-- Guidance Box -->
<div class="qk-guidance-box mb-4 p-3 bg-light border-start border-primary border-4 rounded d-flex align-items-center gap-3">
  <div class="fs-4 text-primary"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold text-primary">Cosa fare adesso?</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if ($isBozza): ?>
        L'ordine è in stato di Bozza. Verifica i dati di testata e delle righe, quindi approvalo per procedere.
      <?php elseif ($isInAppr): ?>
        L'ordine è in attesa di approvazione. Verifica i dati e clicca su "Approva Ordine".
      <?php elseif ($isApprovato): ?>
        L'ordine è stato approvato! Clicca su <strong>"Conferma e Invia Ordine"</strong> per inviarlo formalmente al fornitore e sbloccare la ricezione merci.
      <?php elseif ($isInviato): ?>
        L'ordine è stato confermato ed è attivo. È stata generata una bozza di ricezione: clicca su <strong>"Vedi Bozza Ricevimento"</strong> o vai in magazzino per registrare l'arrivo della merce.
      <?php elseif ($isPartRicevuto): ?>
        Alcuni articoli sono stati ricevuti. Se la merce rimanente non arriverà più, clicca su <strong>"Forza Chiusura Ordine"</strong> per completare l'ordine senza attendere altre consegne.
      <?php elseif ($isCompletato): ?>
        Tutta la merce è stata ricevuta o l'ordine è stato chiuso forzatamente. Il ciclo per questo documento è concluso.
      <?php elseif ($isAnnullato): ?>
        Questo ordine è stato annullato e non è più attivo.
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- StatusBar -->
<div class="qk-statusbar d-flex justify-content-between align-items-center mb-4 p-2 bg-light border rounded">
  
  <div class="qk-statusbar-buttons d-flex gap-2">
    <?php if ($isBozza || $isInAppr): ?>
      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="approva">
        <button type="submit" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-check me-1"></i> Approva Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isApprovato): ?>
      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="invia">
        <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm fw-bold">
          <i class="fa-solid fa-circle-check me-1"></i> Conferma e Invia Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isInviato || $isPartRicevuto): ?>
      <?php if ($draftRicezione): ?>
        <a href="<?= url('purchases/receipts/edit') . '?id=' . $draftRicezione['id'] ?>" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-boxes-packing me-1"></i> Vedi Bozza Ricevimento
        </a>
      <?php else: ?>
        <a href="<?= url('purchases/receipts/create') . '?ordine_id=' . $id ?>" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-boxes-packing me-1"></i> Registra Ricevimento
        </a>
      <?php endif; ?>

      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('La chiusura forzata completerà l\'ordine annullando i residui attesi e azzerando la quantità in arrivo a magazzino. Procedere?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="forza_chiusura">
        <button type="submit" class="btn-qk btn-qk-warning btn-qk-sm fw-bold">
          <i class="fa-solid fa-circle-xmark me-1"></i> Forza Chiusura Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if (!$isCompletato && !$isAnnullato): ?>
      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('Sei sicuro di voler annullare questo ordine?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="annulla">
        <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm">
          <i class="fa-solid fa-ban me-1"></i> Annulla
        </button>
      </form>
    <?php endif; ?>
    
    <a href="<?= url('purchases/orders/docx') . '?id=' . $id ?>" class="btn-qk btn-qk-secondary btn-qk-sm" target="_blank">
      <i class="fa-solid fa-file-word text-primary me-1"></i> Word (DOCX)
    </a>
    
    <a href="<?= url('purchases/orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-arrow-left me-1"></i> Lista Ordini
    </a>
  </div>

  <?php
  $poSteps = [
    ['bozza',                 'Bozza'],
    ['approvato',             'Approvato'],
    ['inviato',               'Inviato'],
    ['parzialmente_ricevuto', 'Parz. Ricevuto'],
    ['completato',            'Completato'],
  ];
  if ($currStato === 'annullato') {
    $poSteps[] = ['annullato', 'Annullato'];
  }
  $poCurrentIdx = 0;
  foreach ($poSteps as $idx => [$s, $lbl]) {
    if ($currStato === $s) {
      $poCurrentIdx = $idx;
      break;
    }
  }
  ?>
  <ul class="qk-status-path">
    <?php foreach ($poSteps as $idx => [$s, $lbl]):
      if ($currStato === $s) {
        $statusClass = 'active';
      } elseif ($currStato === 'in_approvazione' && $idx === 0) {
        $statusClass = 'completed';
      } elseif ($currStato === 'in_approvazione' && $idx > 0) {
        $statusClass = 'pending';
      } elseif ($idx < $poCurrentIdx) {
        $statusClass = 'completed';
      } else {
        $statusClass = 'pending';
      }
    ?>
      <li class="qk-status-path-item <?= $statusClass ?>">
        <?= $lbl ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<form action="<?= url('purchases/orders/edit') . '?id=' . $id ?>" method="post">
  <?= csrf_field() ?>

  <?php if (!empty($errors['_global'])): ?>
    <div class="qk-alert qk-alert-danger mb-3">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <span><?= e($errors['_global']) ?></span>
    </div>
  <?php endif; ?>

  <div class="qk-card mb-4">
    <div class="qk-card-header d-flex justify-content-between align-items-center">
      <span class="qk-card-title">
        <i class="fa-solid fa-file-contract me-2"></i> Ordine d'Acquisto <?= e($o['numero']) ?>
      </span>
      <span class="badge bg-secondary text-monospace"><?= e($o['valuta']) ?></span>
    </div>

    <div class="qk-card-body">
      <!-- Grid a 3 colonne per Dati Testata -->
      <div class="row g-3">
        <!-- Colonna 1: Fornitore & Contatto -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Fornitore</label>
            <input type="text" class="qk-form-control" value="<?= e($o['fornitore_nome']) ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Alla Cortese Attenzione (Referente)</label>
            <input type="text" class="qk-form-control" value="<?= e($o['contatto_nome'] ?: '—') ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Contatti Referente</label>
            <div class="d-flex gap-2">
              <input type="text" class="qk-form-control" style="font-size:0.8rem;" value="<?= e($o['contatto_telefono'] ?: $o['fornitore_telefono'] ?: '—') ?>" placeholder="Telefono" readonly>
              <input type="text" class="qk-form-control" style="font-size:0.8rem;" value="<?= e($o['contatto_email'] ?: $o['fornitore_email'] ?: '—') ?>" placeholder="Email" readonly>
            </div>
          </div>
        </div>

        <!-- Colonna 2: Dati Commerciali & Pagamento -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Metodo di Pagamento</label>
            <input type="text" class="qk-form-control" value="<?= e($o['metodo_pagamento_descrizione'] ?: $o['condizioni_pagamento'] ?: '—') ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Listino di Acquisto</label>
            <input type="text" class="qk-form-control" value="<?= e($o['listino_acquisto_nome'] ?: 'Nessun listino collegato') ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Resa Porto</label>
            <input type="text" class="qk-form-control" value="<?= e($o['resa_porto'] ?: '—') ?>" readonly>
          </div>
        </div>

        <!-- Colonna 3: Riferimenti & Date -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Data Ordine</label>
            <input type="date" class="qk-form-control" value="<?= e($o['data_ordine']) ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Data Consegna Prevista (Iniziale)</label>
            <input type="date" name="data_consegna_prevista" class="qk-form-control" 
                   value="<?= e($o['data_consegna_prevista'] ?? '') ?>"
                   <?= $canEditLogistics ? '' : 'readonly' ?>>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Riferimento Preventivo Fornitore</label>
            <input type="text" name="riferimento_fornitore" class="qk-form-control" 
                   value="<?= e($o['riferimento_fornitore'] ?? '') ?>" 
                   placeholder="Rif. preventivo del fornitore"
                   <?= $canEditLogistics ? '' : 'readonly' ?>>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Altri Riferimenti / Note Logistiche</label>
            <input type="text" name="altri_riferimenti" class="qk-form-control" 
                   value="<?= e($o['altri_riferimenti'] ?? '') ?>"
                   placeholder="Es. DDT di riferimento logistico, note corriere"
                   <?= $canEditLogistics ? '' : 'readonly' ?>>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Prodotti dell'ordine -->
  <div class="qk-card mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title"><i class="fa-solid fa-list-check me-2"></i> Articoli Ordinati</span>
    </div>
    <div class="qk-table-wrap">
      <table class="qk-table" style="font-size:0.875rem">
        <thead>
          <tr>
            <th>Prodotto</th>
            <th>Descrizione</th>
            <th>SKU Fornitore</th>
            <th class="qk-text-right">Ordinata</th>
            <th class="qk-text-right">Ricevuta</th>
            <th class="qk-text-center">U.M.</th>
            <th class="qk-text-right">Prezzo Unit.</th>
            <th class="qk-text-center">Sconto</th>
            <th class="qk-text-center">IVA</th>
            <th class="qk-text-right">Imponibile</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($righe as $r): ?>
            <tr>
              <td>
                <strong><?= e($r['sku_interno']) ?></strong><br>
                <span class="text-muted"><?= e($r['prodotto_nome']) ?></span>
              </td>
              <td><?= e($r['descrizione'] ?? '') ?></td>
              <td><?= e($r['sku_fornitore'] ?? '—') ?></td>
              <td class="qk-text-right" style="font-weight:600"><?= e((float)$r['quantita_ordinata']) ?></td>
              <td class="qk-text-right text-success" style="font-weight:600"><?= e((float)$r['quantita_ricevuta']) ?></td>
              <td class="qk-text-center"><span class="qk-badge draft"><?= e($r['uom'] ?? 'N/D') ?></span></td>
              <td class="qk-text-right">€ <?= number_format((float)$r['prezzo_unitario'], 2, ',', '.') ?></td>
              <td class="qk-text-center"><?= $r['sconto_perc'] > 0 ? (float)$r['sconto_perc'] . '%' : '—' ?></td>
              <td class="qk-text-center"><?= (float)$r['iva_perc'] ?>%</td>
              <td class="qk-text-right" style="font-weight:600">€ <?= number_format((float)$r['imponibile_riga'], 2, ',', '.') ?></td>
            </tr>
            <!-- Sezione pianificazione tranche della riga -->
            <?php if (!empty($r['pianificazione'])): ?>
              <tr style="background-color:rgba(0,0,0,0.01)">
                <td colspan="10" style="padding-left: 2rem; border-top:none">
                  <div class="small">
                    <strong>Pianificazione consegne (Tranche):</strong>
                    <div class="d-flex flex-wrap gap-3 mt-1">
                      <?php foreach ($r['pianificazione'] as $p): ?>
                        <span class="qk-badge <?= $p['stato'] === 'completata' ? 'confirmed' : 'pending' ?>" style="font-size:0.75rem">
                          Data: <strong><?= e($p['data_consegna_prevista'] ?? 'Non definita') ?></strong> |
                          Qta: <strong><?= e((float)$p['quantita_pianificata']) ?></strong> |
                          Ricevuta: <strong><?= e((float)$p['quantita_ricevuta']) ?></strong>
                          (<?= e($p['stato']) ?>)
                        </span>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Totali -->
    <div class="d-flex justify-content-end p-3">
      <div class="qk-card" style="width:300px; margin:0">
        <div class="qk-card-body" style="padding: 0.75rem 1rem">
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Imponibile:</span>
            <span class="fw-semibold">€ <?= number_format((float)$o['imponibile'], 2, ',', '.') ?></span>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">IVA:</span>
            <span class="fw-semibold">€ <?= number_format((float)$o['totale_iva'], 2, ',', '.') ?></span>
          </div>
          <hr class="my-2">
          <div class="d-flex justify-content-between">
            <span class="fw-bold">Totale:</span>
            <span class="fw-bold h5 mb-0" style="color:var(--qk-accent-text)">€ <?= number_format((float)$o['totale'], 2, ',', '.') ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Note ed allegati -->
  <div class="qk-form-grid mb-4">
    <div class="qk-card" style="margin:0">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-comment-dots me-2 text-muted"></i>Note Fornitore</span>
      </div>
      <div class="qk-card-body">
        <textarea name="note_fornitore" class="qk-form-control" rows="3" 
                  <?= $canEditLogistics ? '' : 'readonly' ?>><?= e($o['note_fornitore'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="qk-card" style="margin:0">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-lock me-2 text-muted"></i>Note Interne</span>
      </div>
      <div class="qk-card-body">
        <textarea name="note_interne" class="qk-form-control" rows="3" 
                  <?= $canEditLogistics ? '' : 'readonly' ?>><?= e($o['note_interne'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <!-- Ricezioni collegate -->
  <?php if (!empty($ricezioni)): ?>
    <div class="qk-card mb-4">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-boxes-stacked me-2 text-muted"></i> Ricezioni collegate</span>
      </div>
      <div class="qk-table-wrap">
        <table class="qk-table" style="font-size:0.875rem">
          <thead>
            <tr>
              <th>Numero Ricezione</th>
              <th>Stato</th>
              <th>DDT Numero</th>
              <th>Data Creazione</th>
              <th>Data Ricezione</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ricezioni as $rc): ?>
              <tr>
                <td><strong><?= e($rc['numero']) ?></strong></td>
                <td>
                  <span class="qk-badge <?= $rc['stato'] === 'completata' ? 'confirmed' : 'draft' ?>">
                    <?= e($rc['stato']) ?>
                  </span>
                </td>
                <td><?= e($rc['ddt_numero'] ?? '—') ?></td>
                <td><?= e($rc['created_at']) ?></td>
                <td><?= e($rc['data_effettiva'] ?? '—') ?></td>
                <td class="qk-text-right">
                  <a href="<?= url('purchases/receipts/edit') . '?id=' . $rc['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary">
                    <i class="fa-solid fa-pencil"></i> Apri
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($canSave): ?>
    <div class="d-flex justify-content-end mb-5">
      <button type="submit" class="btn-qk btn-qk-primary px-4 fw-bold">
        <i class="fa-solid fa-floppy-disk me-1"></i> Salva Note e Riferimenti
      </button>
    </div>
  <?php endif; ?>

</form>
