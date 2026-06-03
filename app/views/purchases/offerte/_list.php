<?php
/**
 * purchases/offerte/_list.php
 * Elenco preventivi / offerte ricevute dai fornitori.
 * Variabili attese: $offerte_list (array), $search (string), $stato (string)
 */

function offerta_badge(string $stato): string {
    $map = [
        'in_valutazione' => ['pending',   'In valutazione'],
        'accettata'      => ['confirmed', 'Accettata'],
        'rifiutata'      => ['draft',     'Rifiutata'],
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}

$statiLabels = [
    ''               => 'Tutti',
    'in_valutazione' => 'In Valutazione',
    'accettata'      => 'Accettati',
    'rifiutata'      => 'Rifiutati',
];
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'offerte'; include __DIR__ . '/../_subnav.php'; ?>

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



<div class="qk-card">
  <!-- Toolbar: ricerca + filtri -->
  <div class="qk-table-toolbar">
    <form method="get" action="<?= url('purchases/offerte') ?>" class="qk-search-form">
      <div class="qk-search-wrap">
        <i class="fa-solid fa-magnifying-glass qk-search-icon"></i>
        <input type="text" name="q" class="qk-form-control qk-search-input"
               placeholder="Cerca per numero o fornitore…"
               value="<?= e($search) ?>" autocomplete="off">
      </div>
      <?php if ($stato !== ''): ?>
        <input type="hidden" name="stato" value="<?= e($stato) ?>">
      <?php endif; ?>
    </form>

    <div class="qk-filter-pills">
      <?php foreach ($statiLabels as $k => $lbl): ?>
      <a href="<?= url('purchases/offerte') . ($k !== '' || $search !== '' ? '?' . http_build_query(array_filter(['q' => $search, 'stato' => $k])) : '') ?>"
         class="qk-filter-pill <?= $stato === $k ? 'active' : '' ?>">
        <?= e($lbl) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Tabella -->
  <div class="qk-table-wrap">
    <table class="qk-table">
      <thead>
        <tr>
          <th>Numero preventivo</th>
          <th>RFQ di Origine</th>
          <th>Fornitore</th>
          <th>Stato</th>
          <th>Data Ricezione</th>
          <th class="qk-text-right">Imponibile</th>
          <th class="qk-text-right">Totale</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($offerte_list)): ?>
        <tr>
          <td colspan="8" class="qk-table-empty">
            <i class="fa-solid fa-inbox"></i> Nessun preventivo trovato.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($offerte_list as $o): ?>
        <tr>
          <td>
            <a href="<?= url('purchases/offerte/edit') . '?id=' . $o['id'] ?>" class="qk-link-strong">
              <?= e($o['numero']) ?>
            </a>
          </td>
          <td>
            <?php if (!empty($o['rfq_id'])): ?>
              <a href="<?= url('purchases/rfq/edit') . '?id=' . $o['rfq_id'] ?>" class="qk-link-strong">
                #<?= e($o['rfq_numero'] ?: $o['rfq_id']) ?>
              </a>
            <?php else: ?>
              <span class="qk-text-muted">Spontaneo</span>
            <?php endif; ?>
          </td>
          <td><?= e($o['fornitore_nome'] ?? '—') ?></td>
          <td><?= offerta_badge($o['stato']) ?></td>
          <td><?= e($o['data_offerta'] ?? '—') ?></td>
          <td class="qk-text-right">
            <?= $o['imponibile'] !== null ? '€ ' . number_format((float)$o['imponibile'], 2, ',', '.') : '—' ?>
          </td>
          <td class="qk-text-right" style="font-weight:600">
            <?= $o['totale'] !== null ? '€ ' . number_format((float)$o['totale'], 2, ',', '.') : '—' ?>
          </td>
          <td class="qk-table-actions">
            <a href="<?= url('purchases/offerte/edit') . '?id=' . $o['id'] ?>"
               class="btn-qk btn-qk-xs btn-qk-secondary" title="Apri / Modifica">
              <i class="fa-solid fa-pencil"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
