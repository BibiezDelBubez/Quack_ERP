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
$isSuperato     = $currStato === 'superato';

$statoFatturazione = $o['stato_fatturazione'] ?? 'da_fatturare';
$isFatturato = $statoFatturazione === 'fatturato';

// Tappa 6 (PIANO - Revisione Ordini Clienti): 'superato' è uno stato
// terminale e congelato esattamente come 'annullato' (§2: "l'originale passa
// a 'superato', mai più modificabile") — va escluso ovunque questo file
// escludeva già 'annullato'/'completato', altrimenti un ordine superato
// mostrerebbe ancora i bottoni di modifica (che il model bloccherebbe
// comunque via TransitionGuard, ma con un errore invece che nasconderli).
$canSave = !$isCompletato && !$isAnnullato && !$isSuperato;

// PIANO - Architettura UX Preventivo-Ordine-Spedizione (§10, §11 — 11-12/08/2026):
// quantità totali ordinate/spedite sull'ordine, usate per il testo dinamico
// della guidance box (Passo 3-5), per il badge in alto (Passo 2/Diff 4b) e
// per il testo del popup di conferma di "Chiudi Ordine" (Passo 6, Situazione
// B). Non serve per decidere QUALI bottoni mostrare — quello usa
// $isInLavorazione/$isParzSpedito/$isSpedito, già calcolati sopra e già
// garantiti coerenti con le quantità spedite da
// SpedizioneCalculatorService::creaSpedizione() (verificato leggendo
// app/Services/SpedizioneCalculatorService.php, riga 413: lo stato è sempre
// ricalcolato da lì ad ogni spedizione, mai lasciato disallineato).
$progressoSpedizione = qk_calcola_progresso_spedizione_ordine($righe);
$totUnitaOrdinate = $progressoSpedizione['tot_ordinate'];
$totUnitaSpedite  = $progressoSpedizione['tot_spedite'];
$unitaResidue     = $progressoSpedizione['residue'];

// Ultima spedizione registrata, per il testo dinamico della guidance box
// (Passo 4/5). $shipments è già ordinato DESC per data_spedizione, id
// (verificato in includes/sales_orders.php, funzione
// qk_get_ordine_cliente_spedizioni(), righe 1111-1122) — quindi il primo
// elemento è sempre il più recente, nessun sort aggiuntivo necessario qui.
$ultimaSpedizione = $shipments[0] ?? null;
$ultimoDdt = null;
if ($ultimaSpedizione) {
    foreach (($ddts ?? []) as $d) {
        if ((int)$d['spedizione_id'] === (int)$ultimaSpedizione['id']) {
            $ultimoDdt = $d;
            break;
        }
    }
}

$soSteps = [
  ['in_lavorazione',       'In Lavorazione'],
  ['parzialmente_spedito', 'Parz. Spedito'],
  ['spedito',              'Spedito'],
  ['completato',           'Completato'],
];
if ($currStato === 'annullato') {
  $soSteps[] = ['annullato', 'Annullato'];
}
if ($currStato === 'superato') {
  $soSteps[] = ['superato', 'Superato'];
}
$soCurrentIdx = 0;
foreach ($soSteps as $idx => [$s, $lbl]) {
  if ($currStato === $s) {
    $soCurrentIdx = $idx;
    break;
  }
}
?>


<!-- Guidance Box -->
<div class="qk-guidance-box mb-4">
  <div class="fs-4"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold">Stato Avanzamento Documento</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if ($isInLavorazione): ?>
        Ordine confermato dal cliente<?php if (!empty($o['preventivo_numero'])): ?>, generato dal preventivo <strong><?= e($o['preventivo_numero']) ?></strong><?php endif; ?>. Prossimo passo: organizzare la spedizione. Nessuna unità ancora spedita su <strong><?= e($totUnitaOrdinate) ?></strong> totali.
      <?php elseif ($isParzSpedito): ?>
        <?php if ($ultimaSpedizione): ?>
          Spedizione <strong><?= e($ultimaSpedizione['numero_spedizione']) ?></strong> registrata (<?= e($totUnitaSpedite) ?> di <?= e($totUnitaOrdinate) ?> unità).
          <?php if ($ultimoDdt): ?>
            DDT n. <strong><?= e($ultimoDdt['numero_documento_trasporto']) ?></strong> generato — <a href="<?= url('sales/shipments/ddt/view?id=' . $ultimoDdt['id']) ?>" target="_blank">apri il DDT</a>.
          <?php endif; ?>
        <?php endif; ?>
        Prossimo passo: restano <strong><?= e($unitaResidue) ?></strong> unità da spedire.
      <?php elseif ($isSpedito): ?>
        Tutte le <strong><?= e($totUnitaOrdinate) ?></strong> unità sono state spedite (<?= count($shipments) ?> spedizion<?= count($shipments) === 1 ? 'e' : 'i' ?>, <?= count($ddts ?? []) ?> DDT). Prossimo passo: fatturazione.
      <?php elseif ($isCompletato): ?>
        L'ordine è <strong>completato</strong>. Il ciclo operativo di questo documento è terminato.
      <?php elseif ($isAnnullato): ?>
        L'ordine è <strong>annullato</strong> e inattivo.
      <?php elseif ($isSuperato): ?>
        L'ordine è stato <strong>superato</strong> da una nuova versione<?= !empty($ordineFiglio) ? ' (<a href="' . e(url('sales/orders/edit?id=' . (int)$ordineFiglio['id'])) . '">' . e($ordineFiglio['numero_ordine']) . '</a>)' : '' ?> ed è congelato: non più modificabile.
      <?php else: ?>
        Stato attuale: <?= e($currStato) ?>.
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- Barra Azioni Sticky -->
<div class="qk-sticky-action-bar mb-4">
  <div class="qk-sticky-action-bar-left">
    <?php // PIANO - Architettura UX Preventivo-Ordine-Spedizione (§10 punto 2,
          // 11/08/2026): "Nuova Spedizione" promosso da link secondario dentro
          // la card in fondo pagina a bottone primario qui. Visibile solo se
          // resta qualcosa da spedire (!$isSpedito) — quando tutto è già
          // spedito la schermata di destinazione si rifiuterebbe comunque
          // (verificato: SpedizioniController.php, righe 84-87), questo evita
          // solo di mostrare un bottone che porterebbe a un rifiuto certo. ?>
    <?php if (!$isAnnullato && !$isCompletato && !$isSuperato && !$isSpedito): ?>
      <a href="<?= url('sales/shipments/new') ?>?order_id=<?= $id ?>"
         class="btn-qk btn-qk-primary btn-qk-sm fw-bold">
        <i class="fa-solid fa-plus me-1"></i> Nuova Spedizione
      </a>
    <?php endif; ?>

    <?php // Situazione A (PIANO §6 Passo 6, deciso 11/08/2026): tutto spedito,
          // nessun residuo — "Completa Ordine", click diretto, nessuna
          // conferma (non c'è nulla di distruttivo: è già tutto spedito). ?>
    <?php if ($isSpedito): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="completa">
        <button type="submit" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-check me-1"></i> Completa Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php // Situazione B (PIANO §6 Passo 6, deciso 11/08/2026): residuo non
          // spedito ma si vuole chiudere comunque (tipicamente: il cliente ha
          // rinunciato al resto). Visibile SOLO da 'parzialmente_spedito', non
          // da 'in_lavorazione' con zero spedito — in quel caso l'azione
          // corretta per abbandonare l'ordine è "Annulla Ordine" (bottone già
          // esistente, invariato, barra destra), non questa: conseguenza
          // diretta del testo del Passo 3 di questo piano ("Completa Ordine
          // non compare più qui, in questo stato specifico"), non una nuova
          // decisione presa qui. Stessa azione server-side "completa" di
          // "Completa Ordine" sopra — nessuna nuova transizione di stato
          // introdotta (PIANO §6 Passo 6, ultimo paragrafo). ?>
    <?php if ($isParzSpedito): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('Restano <?= e($unitaResidue) ?> unità non spedite su questo ordine. Chiudendo ora, questo residuo non verrà più evaso automaticamente: dovrai creare un nuovo ordine se il cliente lo richiederà in futuro. Confermi la chiusura?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="completa">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-box-archive me-1"></i> Chiudi Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if ($canSave): ?>
      <button type="submit" form="form-edit-ordine" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-floppy-disk"></i> Salva Note
      </button>
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

    <?php if ($isInLavorazione): ?>
      <!-- PIANO - Revisione Ordini Clienti, Tappa 6: stesso bottone, stessa
           azione "nuova_versione", stessa classe/icona già in produzione per
           i Preventivi (app/views/sales/quotes/_form.php) — nessun componente
           nuovo, solo lo stesso pattern copiato identico (§4.5). Visibile
           solo da 'in_lavorazione' (§4.2), coerente col guard server-side in
           qk_ordine_cliente_crea_revisione(). -->
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="nuova_versione">
        <button type="submit" class="btn-qk btn-qk-secondary" title="Crea una nuova versione di questo ordine">
          <i class="fa-solid fa-code-branch"></i> Crea Nuova Versione
        </button>
      </form>
    <?php endif; ?>

    <?php if (!$isFatturato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_fatturato">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm fw-bold">
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

    <?php if ($isFatturato): ?>
      <!-- PIANO - Revisione Ordini Clienti (Nuova Versione), §7.7/Tappa 9
           (26/07/2026): "Crea Nuova Versione" resta bloccato per un ordine
           già fatturato (§4.3) — questo è lo strumento sostitutivo, con
           approvazione, mai una riscrittura diretta dell'ordine. -->
      <a href="<?= url('sales/orders/correzioni/new') ?>?ordine_id=<?= $id ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> Richiedi Correzione
      </a>
    <?php endif; ?>
  </div>

  <?php $richiesteCorrezione = qk_correzioni_ordine_lista($this->pdo, $id); ?>
  <?php if (!empty($richiesteCorrezione)): ?>
    <div class="qk-card mt-3">
      <div class="qk-card-header">Richieste di Correzione</div>
      <div class="qk-card-body">
        <?php foreach ($richiesteCorrezione as $rc): ?>
          <div class="qk-correzione-item mb-3">
            <p>
              <strong>Richiesta #<?= (int)$rc['id'] ?></strong>
              — stato: <strong><?= e($rc['stato']) ?></strong>
              — creata da <?= e($rc['creato_da_completo'] ?? '—') ?> il <?= e($rc['created_at']) ?>
              <?php if (!empty($rc['motivo'])): ?><br>Motivo: <?= e($rc['motivo']) ?><?php endif; ?>
              <?php if ($rc['stato'] === 'approvata'): ?><br>Riferimento documento esterno: <?= e($rc['riferimento_doc_esterno']) ?><?php endif; ?>
              <?php if ($rc['stato'] === 'rifiutata'): ?><br>Motivo rifiuto: <?= e($rc['motivo_rifiuto']) ?><?php endif; ?>
            </p>

            <?php if ($rc['stato'] === 'in_approvazione' && can('approvare_correzioni')): ?>
              <form action="<?= url('sales/orders/correzioni/action') ?>" method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="azione" value="approva">
                <input type="hidden" name="ordine_id" value="<?= $id ?>">
                <input type="hidden" name="richiesta_id" value="<?= (int)$rc['id'] ?>">
                <input type="text" name="riferimento_doc_esterno" class="qk-form-control d-inline-block" style="width:auto" placeholder="Numero nota di credito esterna" required>
                <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm">Approva</button>
              </form>
              <form action="<?= url('sales/orders/correzioni/action') ?>" method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="azione" value="rifiuta">
                <input type="hidden" name="ordine_id" value="<?= $id ?>">
                <input type="hidden" name="richiesta_id" value="<?= (int)$rc['id'] ?>">
                <input type="text" name="motivo_rifiuto" class="qk-form-control d-inline-block" style="width:auto" placeholder="Motivo del rifiuto" required>
                <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm text-danger">Rifiuta</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="qk-sticky-action-bar-right">
    <?php if (!$isAnnullato && !$isCompletato && !$isSuperato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('Sei sicuro di voler annullare questo ordine di vendita?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="annulla">
        <button type="submit" class="btn-qk btn-qk-secondary text-danger btn-qk-sm">
          <i class="fa-solid fa-ban me-1"></i> Annulla Ordine
        </button>
      </form>
    <?php endif; ?>

    <a href="<?= url('sales/orders') ?>" class="btn-qk btn-qk-secondary">
      <i class="fa-solid fa-arrow-left"></i> Torna alla lista
    </a>

    <ul class="qk-status-path d-none d-sm-flex" style="margin:0; padding:0;">
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
</div>

<form action="<?= url('sales/orders/edit') . '?id=' . $id ?>" method="post" id="form-edit-ordine">
  <?= csrf_field() ?>

  <div class="qk-card mb-4">
    <div class="qk-card-header qk-flex-row qk-justify-between">
      <span class="qk-card-title">
        <i class="fa-solid fa-file-contract me-2"></i> Ordine Cliente <?= e($o['numero_ordine']) ?>
        <?php if ((int)($o['versione'] ?? 1) > 1 || !empty($ordineFiglio)): ?>
          <span class="qk-badge draft ms-1" style="font-size:0.75rem" title="Numero di versione di questo ordine">
            <i class="fa-solid fa-code-branch me-1"></i>Versione <?= (int)($o['versione'] ?? 1) ?>
          </span>
        <?php endif; ?>
      </span>
      <div class="qk-flex-row qk-gap-sm">
        <?php if (!empty($ordinePadre)): ?>
          <a href="<?= url('sales/orders/edit?id=' . (int)$ordinePadre['id']) ?>" class="qk-badge draft text-decoration-none" style="font-size:0.75rem" title="Ordine da cui è nata questa versione">
            <i class="fa-solid fa-arrow-left me-1"></i>Versione precedente: <?= e($ordinePadre['numero_ordine']) ?>
          </a>
        <?php endif; ?>
        <?php if (!empty($ordineFiglio)): ?>
          <a href="<?= url('sales/orders/edit?id=' . (int)$ordineFiglio['id']) ?>" class="qk-badge confirmed text-decoration-none" style="font-size:0.75rem" title="Questo ordine è stato superato da una versione più recente">
            <i class="fa-solid fa-arrow-right me-1"></i>Versione successiva: <?= e($ordineFiglio['numero_ordine']) ?>
          </a>
        <?php endif; ?>
        <a href="#spedizioni-ddt" class="qk-badge <?= !empty($shipments) ? 'confirmed' : 'draft' ?> fw-bold text-decoration-none" style="font-size:0.85rem" title="Vai alla sezione Spedizioni e DDT, in fondo alla pagina">
          <i class="fa-solid fa-truck-fast me-1"></i>
          Spedizioni: <?= count($shipments) ?> registrat<?= count($shipments) === 1 ? 'a' : 'e' ?><?php if ($totUnitaOrdinate > 0): ?> — <?= e($totUnitaSpedite) ?>/<?= e($totUnitaOrdinate) ?> unità spedite<?php endif; ?>
        </a>
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

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Listino di Vendita Applicato</label>
            <input type="text" class="qk-form-control" value="<?= e($o['listino_nome'] ?: 'Listino Base') ?>" readonly>
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



</form>

<!-- ═══════════════════════════════════════════════════════════════════
     SEZIONE SPEDIZIONI — resta in questa posizione (PIANO §10 punto 5,
     12/08/2026): nessuno dei 4 ERP confrontati (SAP, NetSuite, Business
     Central, Odoo) promuove questa tabella sopra le righe ordine. Il
     rimando "in alto" sta nell'intestazione dell'ordine (badge sopra, righe
     256+), che punta qui via ancora HTML.
     ═══════════════════════════════════════════════════════════════════ -->
<div class="qk-card mb-4" id="spedizioni-ddt" style="scroll-margin-top: calc(var(--qk-topbar-h) + 90px)">
  <div class="qk-card-header">
    <span class="qk-card-title"><i class="fa-solid fa-truck-fast me-2"></i> Spedizioni e DDT</span>
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

