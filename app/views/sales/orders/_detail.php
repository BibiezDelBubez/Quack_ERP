<?php
/**
 * sales/orders/_detail.php
 * Dettaglio ordine cliente (Sales Order).
 */

$o     = $order;
$righe = $lines;
$id    = (int)$o['id'];

$currStato = $o['stato'];
$isBozza        = $currStato === 'bozza'; // sales order starts as in_lavorazione, but let's support any
$isInLavorazione = $currStato === 'in_lavorazione';
$isParzSpedito  = $currStato === 'parzialmente_spedito';
$isSpedito      = $currStato === 'spedito';
$isCompletato   = $currStato === 'completato';
$isAnnullato    = $currStato === 'annullato';

$statoFatturazione = $o['stato_fatturazione'] ?? 'da_fatturare';
$isFatturato = $statoFatturazione === 'fatturato';

$canSave = !$isCompletato && !$isAnnullato;
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
<div class="qk-guidance-box qk-flex-row qk-gap-md mb-4" style="padding:.75rem 1rem;background:var(--qk-info-light,rgba(37,99,235,.06));border-left:4px solid var(--qk-info);border-radius:var(--qk-radius)">
  <div style="font-size:1.4rem;color:var(--qk-info)"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold" style="color:var(--qk-info)">Stato Avanzamento Documento</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if ($isInLavorazione): ?>
        L'ordine è <strong>in lavorazione</strong>. Verifica la merce ed effettua le spedizioni necessarie. Puoi contrassegnarlo come completato o fatturato.
      <?php elseif ($isParzSpedito): ?>
        L'ordine è stato <strong>parzialmente spedito</strong>. In attesa di completare le restanti consegne.
      <?php elseif ($isSpedito): ?>
        Tutta la merce è stata <strong>spedita</strong>. Puoi completare il flusso dell'ordine.
      <?php elseif ($isCompletato): ?>
        L'ordine è <strong>completato</strong>. Il ciclo operativo di questo documento è terminato.
      <?php elseif ($isAnnullato): ?>
        L'ordine è <strong>annullato</strong> e inattivo.
      <?php else: ?>
        Stato attuale: <?= e($currStato) ?>.
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- StatusBar -->
<div class="qk-statusbar qk-flex-row qk-justify-between mb-4" style="padding:.5rem .75rem;background:var(--qk-table-head-bg);border:1px solid var(--qk-card-border);border-radius:var(--qk-radius)">
  
  <div class="qk-statusbar-buttons qk-flex-row qk-gap-sm">
    <?php if (!$isCompletato && !$isAnnullato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="completa">
        <button type="submit" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-check me-1"></i> Completa Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isCompletato && !$isAnnullato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="riapri">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-folder-open me-1"></i> Rimetti in Lavorazione
        </button>
      </form>
    <?php endif; ?>

    <!-- Fatturazione Semplificata -->
    <?php if (!$isFatturato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_fatturato">
        <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm fw-bold">
          <i class="fa-solid fa-file-invoice-dollar me-1"></i> Segna come Fatturato
        </button>
      </form>
    <?php else: ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_da_fatturare">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm text-danger">
          <i class="fa-solid fa-undo me-1"></i> Ripristina "Da Fatturare"
        </button>
      </form>
    <?php endif; ?>

    <?php if (!$isAnnullato && !$isCompletato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('Sei sicuro di voler annullare questo ordine di vendita?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="annulla">
        <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm">
          <i class="fa-solid fa-ban me-1"></i> Annulla
        </button>
      </form>
    <?php endif; ?>
    
    <a href="<?= url('sales/orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-arrow-left me-1"></i> Lista Ordini
    </a>
  </div>

  <?php
  $soSteps = [
    ['in_lavorazione',       'In Lavorazione'],
    ['parzialmente_spedito', 'Parz. Spedito'],
    ['spedito',              'Spedito'],
    ['completato',           'Completato'],
  ];
  if ($currStato === 'annullato') {
    $soSteps[] = ['annullato', 'Annullato'];
  }
  $soCurrentIdx = 0;
  foreach ($soSteps as $idx => [$s, $lbl]) {
    if ($currStato === $s) {
      $soCurrentIdx = $idx;
      break;
    }
  }
  ?>
  <ul class="qk-status-path">
    <?php foreach ($soSteps as $idx => [$s, $lbl]):
      if ($currStato === $s) {
        $statusClass = 'active';
      } elseif ($idx < $soCurrentIdx) {
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

<form action="<?= url('sales/orders/edit') . '?id=' . $id ?>" method="post">
  <?= csrf_field() ?>

  <div class="qk-card mb-4">
    <div class="qk-card-header qk-flex-row qk-justify-between">
      <span class="qk-card-title">
        <i class="fa-solid fa-file-contract me-2"></i> Ordine Cliente <?= e($o['numero_ordine']) ?>
      </span>
      <div class="qk-flex-row qk-gap-sm">
        <span class="qk-badge <?= $isFatturato ? 'confirmed' : 'draft' ?> fw-bold" style="font-size:0.85rem">
          <i class="fa-solid <?= $isFatturato ? 'fa-circle-check' : 'fa-hourglass' ?> me-1"></i>
          Fatturazione: <?= $isFatturato ? 'Fatturato' : 'Da Fatturare' ?>
        </span>
      </div>
    </div>

    <div class="qk-card-body">
      <!-- Grid a 3 colonne per Dati Testata -->
      <div class="row g-3">
        <!-- Colonna 1: Cliente & Riferimenti -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Cliente</label>
            <input type="text" class="qk-form-control" value="<?= e($o['cliente_nome']) ?>" readonly>
          </div>

          <?php if (!empty($o['preventivo_id'])): ?>
            <div class="qk-form-group mb-2">
              <label class="qk-form-label">Preventivo Originale</label>
              <div class="qk-flex-row qk-gap-sm">
                <a href="<?= url('sales/quotes/edit?id=' . $o['preventivo_id']) ?>" class="qk-text-accent fw-bold text-decoration-none">
                  <i class="fa-solid fa-file-invoice-dollar me-1"></i> <?= e($o['preventivo_numero'] ?? 'Vedi Preventivo') ?>
                </a>
              </div>
            </div>
          <?php endif; ?>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Contatti Cliente</label>
            <div class="qk-flex-row qk-gap-sm">
              <input type="text" class="qk-form-control" style="font-size:0.8rem;" value="<?= e($o['cliente_telefono'] ?: '—') ?>" placeholder="Telefono" readonly>
              <input type="text" class="qk-form-control" style="font-size:0.8rem;" value="<?= e($o['cliente_email'] ?: '—') ?>" placeholder="Email" readonly>
            </div>
          </div>
        </div>

        <!-- Colonna 2: Indirizzo di Spedizione -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Destinazione Merce</label>
            <?php if ($address): ?>
              <div style="padding:.5rem .75rem;background:var(--qk-table-head-bg);border:1px solid var(--qk-card-border);border-radius:var(--qk-radius);font-size:.85rem;line-height:1.4">
                <?php if (!empty($address['nome_sede'])): ?>
                  <strong><?= e($address['nome_sede']) ?></strong><br>
                <?php endif; ?>
                <?= e($address['via']) ?><?= !empty($address['civico']) ? ', ' . e($address['civico']) : '' ?><br>
                <?= e($address['cap']) ?> - <?= e($address['citta']) ?> (<?= e($address['provincia']) ?>)<br>
                <?= e($address['nazione']) ?><br>
                <?php if (!empty($address['referente_consegna']) || !empty($address['telefono_consegna'])): ?>
                  <span class="text-muted"><i class="fa-solid fa-user-tag me-1"></i> <?= e($address['referente_consegna']) ?> (<?= e($address['telefono_consegna']) ?>)</span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <input type="text" class="qk-form-control" value="Sede Principale / Default" readonly>
            <?php endif; ?>
          </div>
        </div>

        <!-- Colonna 3: Date e Creatore -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Data Ordine</label>
            <input type="date" class="qk-form-control" value="<?= e($o['data_ordine']) ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Data Consegna Prevista</label>
            <input type="date" name="data_consegna_prevista" class="qk-form-control" 
                   value="<?= e($o['data_consegna_prevista'] ?? '') ?>"
                   <?= $canSave ? '' : 'readonly' ?>>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Creato Da</label>
            <input type="text" class="qk-form-control" value="<?= e($o['creato_da_username'] ?? 'Sistema') ?>" readonly>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Prodotti dell'ordine -->
  <div class="qk-card mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title"><i class="fa-solid fa-list-check me-2"></i> Articoli in Ordine</span>
    </div>
    <div class="qk-table-wrap">
      <table class="qk-table" style="font-size:0.875rem">
        <thead>
          <tr>
            <th>Codice / Prodotto</th>
            <th>Descrizione</th>
            <th class="qk-text-right">Ordinata</th>
            <th class="qk-text-right">Spedita</th>
            <th class="qk-text-center">U.M.</th>
            <th class="qk-text-right">Prezzo Unit.</th>
            <th class="qk-text-right">Costo Unit.</th>
            <th class="qk-text-right">Margine Unit.</th>
            <th class="qk-text-right">Imponibile</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($righe as $r): ?>
            <tr>
              <td>
                <strong><?= e($r['prodotto_codice'] ?? 'N/D') ?></strong><br>
                <span class="text-muted"><?= e($r['prodotto_nome'] ?? '') ?></span>
              </td>
              <td><?= e($r['descrizione'] ?? '') ?></td>
              <td class="qk-text-right" style="font-weight:600"><?= e((float)$r['quantita_ordinata']) ?></td>
              <td class="qk-text-right text-success" style="font-weight:600"><?= e((float)$r['quantita_spedita']) ?></td>
              <td class="qk-text-center"><span class="qk-badge draft"><?= e($r['uom_simbolo'] ?? 'N/D') ?></span></td>
              <td class="qk-text-right">€ <?= number_format((float)$r['prezzo_unitario'], 2, ',', '.') ?></td>
              <td class="qk-text-right text-muted">€ <?= number_format((float)$r['costo_unitario'], 2, ',', '.') ?></td>
              <td class="qk-text-right fw-semibold <?= ((float)($r['margine_unit'] ?? 0.0)) >= 0 ? 'text-success' : 'text-danger' ?>">
                € <?= number_format((float)($r['margine_unit'] ?? 0.0), 2, ',', '.') ?>
                <small>(<?= number_format((float)($r['margine_perc'] ?? 0.0), 1) ?>%)</small>
              </td>
              <td class="qk-text-right" style="font-weight:600">€ <?= number_format((float)($r['imponibile_riga'] ?? 0.0), 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Totali & Margine Globale -->
    <div class="qk-flex-row qk-justify-between" style="padding:.75rem 1rem">
      <div>
        <span class="text-muted">Margine Globale dell'Ordine:</span>
        <strong class="h6 mb-0 ms-2 <?= ((float)($margineTot ?? 0.0)) >= 0 ? 'text-success' : 'text-danger' ?>">
          € <?= number_format((float)($margineTot ?? 0.0), 2, ',', '.') ?> (<?= number_format((float)($margineTotPerc ?? 0.0), 1) ?>%)
        </strong>
      </div>
      <div class="qk-card" style="width:300px; margin:0">
        <div class="qk-card-body" style="padding: 0.75rem 1rem">
          <div class="qk-flex-row qk-justify-between mb-2">
            <span class="text-muted">Imponibile:</span>
            <span class="fw-semibold">€ <?= number_format((float)($totImponibile ?? 0.0), 2, ',', '.') ?></span>
          </div>
          <div class="qk-flex-row qk-justify-between mb-2">
            <span class="text-muted">Costo Totale:</span>
            <span class="fw-semibold text-muted">€ <?= number_format((float)($totCosto ?? 0.0), 2, ',', '.') ?></span>
          </div>
          <hr class="my-2">
          <div class="qk-flex-row qk-justify-between">
            <span class="fw-bold">Totale Ordine:</span>
            <span class="fw-bold h5 mb-0" style="color:var(--qk-accent-text)">€ <?= number_format((float)($totImponibile ?? 0.0), 2, ',', '.') ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Note -->
  <div class="qk-card mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title"><i class="fa-solid fa-comment-dots me-2 text-muted"></i> Note Ordine</span>
    </div>
    <div class="qk-card-body">
      <textarea name="note" class="qk-form-control" rows="3" 
                placeholder="Inserisci note relative all'ordine..."
                <?= $canSave ? '' : 'readonly' ?>><?= e($o['note'] ?? '') ?></textarea>
    </div>
  </div>

  <?php if ($canSave): ?>
    <div class="qk-flex-row qk-justify-end mb-5">
      <button type="submit" class="btn-qk btn-qk-primary px-4 fw-bold">
        <i class="fa-solid fa-floppy-disk me-1"></i> Salva Note e Data Consegna
      </button>
    </div>
  <?php endif; ?>

</form>

<!-- ═══════════════════════════════════════════════════════════════════
     SEZIONE SPEDIZIONI
     ═══════════════════════════════════════════════════════════════════ -->
<div class="qk-card mb-4">
  <div class="qk-card-header qk-flex-row qk-justify-between">
    <span class="qk-card-title"><i class="fa-solid fa-truck-fast me-2"></i> Spedizioni e DDT</span>
    <?php if (!$isAnnullato && !$isCompletato): ?>
      <a href="<?= url('sales/shipments/new') ?>?order_id=<?= $id ?>"
         class="btn-qk btn-qk-primary btn-qk-sm fw-bold">
        <i class="fa-solid fa-plus me-1"></i> Nuova Spedizione
      </a>
    <?php endif; ?>
  </div>

  <?php if (empty($shipments)): ?>
    <div class="qk-empty py-4">
      <i class="fa-solid fa-box-open"></i>
      <p>Nessuna spedizione registrata per questo ordine.</p>
    </div>
  <?php else: ?>
    <div class="qk-table-wrap">
      <table class="qk-table" style="font-size:0.875rem">
        <thead>
          <tr>
            <th>N° Spedizione</th>
            <th>Data</th>
            <th>Vettore</th>
            <th>Tracking</th>
            <th>Stato</th>
            <th>Foto Pallet</th>
            <th>DDT</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($shipments as $s):
            $dttsForSped = array_filter($ddts, fn($d) => (int)$d['spedizione_id'] === (int)$s['id']);
            $statoClass  = match($s['stato']) {
              'spedita'    => 'shipped',
              'consegnata' => 'delivered',
              'reso'       => 'cancelled',
              default      => 'draft',
            };
          ?>
            <tr>
              <td class="fw-semibold"><?= e($s['numero_spedizione']) ?></td>
              <td><?= fmt_date($s['data_spedizione']) ?></td>
              <td><?= e($s['vettore'] ?: '—') ?></td>
              <td><?= !empty($s['tracking']) ? '<code>' . e($s['tracking']) . '</code>' : '—' ?></td>
              <td><span class="qk-badge <?= $statoClass ?>"><?= e(ucfirst($s['stato'])) ?></span></td>
              <td>
                <?php 
                $photos = $shipment_photos[(int)$s['id']] ?? [];
                if (!empty($photos)): 
                ?>
                  <div class="qk-flex-row qk-gap-xs">
                    <?php foreach ($photos as $ph): ?>
                      <a href="<?= url('uploads') ?>?path=<?= urlencode($ph['file_path']) ?>" target="_blank" title="<?= e($ph['file_name']) ?>">
                        <img src="<?= url('uploads') ?>?path=<?= urlencode($ph['file_path']) ?>" style="width:32px;height:32px;object-fit:cover;border-radius:var(--qk-radius-sm);border:1px solid var(--qk-card-border)" alt="Pallet">
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php foreach ($dttsForSped as $ddt): ?>
                  <a href="<?= url('sales/shipments/ddt/view?id=' . $ddt['id']) ?>"
                     class="qk-text-accent fw-semibold text-decoration-none me-2" target="_blank">
                    <i class="fa-solid fa-file-lines me-1"></i><?= e($ddt['numero_documento_trasporto']) ?>
                  </a>
                <?php endforeach; ?>
                <?php if (empty($dttsForSped)): ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       