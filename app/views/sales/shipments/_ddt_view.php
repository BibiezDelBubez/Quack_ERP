<?php
/**
 * sales/shipments/_ddt_view.php
 * Vista stampabile del Documento di Trasporto (DDT)
 */
$d    = $ddt;
$isAdr = !empty(array_filter($righe, fn($r) => (int)$r['is_adr'] === 1));
?>

<!-- Toolbar stampa (non stampata) -->
<div class="qk-flex-row qk-justify-between mb-4 no-print">
  <a href="<?= url('sales/shipments') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
    <i class="fa-solid fa-arrow-left me-1"></i> Lista Spedizioni
  </a>
  <div class="qk-flex-row qk-gap-sm">
    <a href="<?= url('sales/orders/edit?id=' . (int)$d['ordine_id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-file-contract me-1"></i> Vedi Ordine <?= e($d['numero_ordine']) ?>
    </a>
    <button class="btn-qk btn-qk-primary btn-qk-sm fw-bold" onclick="window.print()">
      <i class="fa-solid fa-print me-1"></i> Stampa DDT
    </button>
  </div>
</div>

<!-- ═══════════ DOCUMENTO STAMPABILE ═══════════ -->
<div class="qk-ddt-doc" id="ddtDocument">

  <!-- Intestazione -->
  <div class="qk-ddt-header">
    <div class="qk-ddt-mittente">
      <div class="qk-ddt-company-name"><?= e($d['mittente_nome'] ?? 'Azienda') ?></div>
      <?php if (!empty($d['mittente_via'])): ?>
        <div class="qk-ddt-address-line">
          <?= e($d['mittente_via']) ?><?= !empty($d['mittente_civico']) ? ', ' . e($d['mittente_civico']) : '' ?>
        </div>
        <div class="qk-ddt-address-line">
          <?= e($d['mittente_cap'] ?? '') ?> <?= e($d['mittente_citta'] ?? '') ?>
          <?= !empty($d['mittente_provincia']) ? '(' . e($d['mittente_provincia']) . ')' : '' ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($d['mittente_piva'])): ?>
        <div class="qk-ddt-address-line">P.IVA: <?= e($d['mittente_piva']) ?></div>
      <?php endif; ?>
    </div>
    <div class="qk-ddt-titolo-wrap">
      <div class="qk-ddt-titolo">DOCUMENTO DI TRASPORTO</div>
      <table class="qk-ddt-meta-table">
        <tr>
          <th>N° DDT</th>
          <td><strong><?= e($d['numero_documento_trasporto']) ?></strong></td>
        </tr>
        <tr>
          <th>Data</th>
          <td><?= fmt_date($d['data']) ?></td>
        </tr>
        <tr>
          <th>N° Ordine</th>
          <td><?= e($d['numero_ordine']) ?></td>
        </tr>
        <?php if (!empty($d['numero_spedizione'])): ?>
        <tr>
          <th>N° Spedizione</th>
          <td><?= e($d['numero_spedizione']) ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <hr class="qk-ddt-divider">

  <!-- Destinatario -->
  <div class="qk-ddt-indirizzi">
    <div class="qk-ddt-block">
      <div class="qk-ddt-block-label">DESTINATARIO</div>
      <div class="qk-ddt-block-content">
        <strong><?= e($d['destinatario_nome']) ?></strong><br>
        <?php if (!empty($d['nome_sede'])): ?>
          <?= e($d['nome_sede']) ?><br>
        <?php endif; ?>
        <?php if (!empty($d['via'])): ?>
          <?= e($d['via']) ?><?= !empty($d['civico']) ? ', ' . e($d['civico']) : '' ?><br>
          <?= e($d['cap'] ?? '') ?> <?= e($d['citta'] ?? '') ?>
          <?= !empty($d['provincia']) ? '(' . e($d['provincia']) . ')' : '' ?><br>
          <?= e($d['nazione'] ?? 'Italia') ?>
        <?php endif; ?>
        <?php if (!empty($d['destinatario_piva'])): ?>
          <br>P.IVA: <?= e($d['destinatario_piva']) ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="qk-ddt-block">
      <div class="qk-ddt-block-label">DATI TRASPORTO</div>
      <div class="qk-ddt-block-content">
        <table class="qk-ddt-info-table">
          <tr><th>Causale:</th><td><?= e($d['causale_trasporto'] ?: '—') ?></td></tr>
          <tr><th>Porto:</th><td><?= e(ucfirst($d['porto'] ?: '—')) ?></td></tr>
          <tr><th>Vettore:</th><td><?= e($d['vettore'] ?: ($d['s_vettore'] ?: '—')) ?></td></tr>
          <tr><th>Aspetto beni:</th><td><?= e($d['aspetto_beni'] ?: '—') ?></td></tr>
          <tr><th>N° colli:</th><td><?= e($d['numero_colli'] ?: '—') ?></td></tr>
          <tr><th>Peso lordo:</th><td><?= $d['peso_lordo_kg'] ? e($d['peso_lordo_kg']) . ' kg' : '—' ?></td></tr>
          <?php if (!empty($d['tracking'])): ?>
          <tr><th>Tracking:</th><td><?= e($d['tracking']) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

  <hr class="qk-ddt-divider">

  <!-- Righe merce -->
  <table class="qk-ddt-table">
    <thead>
      <tr>
        <th style="width:5%">N°</th>
        <th style="width:15%">Codice</th>
        <th>Descrizione</th>
        <th style="width:12%">Lotto</th>
        <th style="width:8%" class="text-end">Qtà</th>
        <th style="width:6%" class="text-center">U.M.</th>
        <?php if ($isAdr): ?>
          <th style="width:8%">ONU</th>
          <th style="width:8%">Classe ADR</th>
          <th style="width:7%">Gr. Imb.</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($righe as $idx => $r): ?>
        <tr <?= (int)$r['is_adr'] ? 'class="qk-ddt-adr-row"' : '' ?>>
          <td><?= $idx + 1 ?></td>
          <td><?= e($r['prodotto_codice'] ?? '') ?></td>
          <td>
            <?= e($r['descrizione'] ?: ($r['prodotto_nome'] ?? '')) ?>
            <?php if ((int)$r['is_adr']): ?>
              <span class="qk-ddt-adr-badge">ADR</span>
            <?php endif; ?>
          </td>
          <td><?= e($r['codice_lotto'] ?: '—') ?></td>
          <td class="text-end fw-semibold"><?= e($r['quantita']) ?></td>
          <td class="text-center"><?= e($r['uom_codice'] ?? '') ?></td>
          <?php if ($isAdr): ?>
            <td><?= e($r['numero_onu'] ?: '—') ?></td>
            <td><?= e($r['classe_adr'] ?: '—') ?></td>
            <td><?= e($r['packing_group'] ?: '—') ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($isAdr): ?>
  <div class="qk-ddt-adr-notice">
    <i class="fa-solid fa-triangle-exclamation me-2"></i>
    <strong>MERCI PERICOLOSE (ADR):</strong>
    Il trasporto delle merci contrassegnate è soggetto alla normativa ADR.
    Assicurarsi che il conducente sia in possesso della documentazione prescritta.
  </div>
  <?php endif; ?>

  <!-- Firme -->
  <div class="qk-ddt-firme">
    <div class="qk-ddt-firma-box">
      <div class="qk-ddt-firma-label">Firma Mittente / Vettore</div>
      <div class="qk-ddt-firma-line"></div>
    </div>
    <div class="qk-ddt-firma-box">
      <div class="qk-ddt-firma-label">Firma Destinatario per ricevuta merce</div>
      <div class="qk-ddt-firma-line"></div>
    </div>
    <div class="qk-ddt-firma-box">
      <div class="qk-ddt-firma-label">Data e ora ritiro / consegna</div>
      <div class="qk-ddt-firma-line"></div>
    </div>
  </div>

</div><!-- .qk-ddt-doc -->
