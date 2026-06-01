<?php
/**
 * purchases/rfq/_list.php
 * Elenco Richieste di Offerta.
 * Variabili: $rfq_list (array), $search (string), $stato (string)
 */

// Mappa stato → badge CSS + etichetta
function rfq_badge(string $stato): string {
    $map = [
        'bozza'      => ['draft',     'Bozza'],
        'inviata'    => ['confirmed', 'Inviata'],
        'revisionata'=> ['pending',   'Revisionata'],
        'chiusa'     => ['delivered', 'Chiusa'],
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}

$statiLabels = ['' => 'Tutti', 'bozza' => 'Bozza', 'inviata' => 'Inviate',
                'revisionata' => 'Revisionate', 'chiusa' => 'Chiuse'];
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



<div class="qk-card">
  <!-- Toolbar: ricerca + filtro stato -->
  <div class="qk-table-toolbar">
    <form method="get" action="<?= url('purchases/rfq') ?>" class="qk-search-form">
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
      <a href="<?= url('purchases/rfq') . ($k !== '' || $search !== '' ? '?' . http_build_query(array_filter(['q' => $search, 'stato' => $k])) : '') ?>"
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
          <th>Numero</th>
          <th>Ver.</th>
          <th>Fornitore</th>
          <th>Stato</th>
          <th>Data emissione</th>
          <th>Scadenza</th>
          <th>Totale</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rfq_list)): ?>
        <tr>
          <td colspan="8" class="qk-table-empty">
            <i class="fa-solid fa-inbox"></i> Nessuna RFQ trovata.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($rfq_list as $r): ?>
        <tr>
          <td>
            <a href="<?= url('purchases/rfq/edit') . '?id=' . $r['id'] ?>" class="qk-link-strong">
              <?= e($r['numero']) ?>
            </a>
          </td>
          <td class="qk-text-muted">v<?= (int)$r['versione'] ?></td>
          <td><?= e($r['fornitore_nome'] ?? '—') ?></td>
          <td><?= rfq_badge($r['stato']) ?></td>
          <td><?= e($r['data_emissione'] ?? '—') ?></td>
          <td><?php
            if ($r['data_scadenza']) {
                $scad  = new DateTime($r['data_scadenza']);
                $oggi  = new DateTime();
                $diff  = $oggi->diff($scad);
                $scaduta = $scad < $oggi;
                echo '<span' . ($scaduta ? ' class="qk-text-danger"' : '') . '>';
                echo e($r['data_scadenza']);
                echo '</span>';
            } else { echo '—'; }
          ?></td>
          <td class="qk-text-right">
            <?= $r['totale'] !== null ? '€ ' . number_format((float)$r['totale'], 2, ',', '.') : '—' ?>
          </td>
          <td class="qk-table-actions">
            <a href="<?= url('purchases/rfq/edit') . '?id=' . $r['id'] ?>"
               class="btn-qk btn-qk-xs btn-qk-secondary" title="Apri">
              <i class="fa-solid fa-pencil"></i>
            </a>
            <?php if ($r['stato'] === 'bozza'): ?>
            <form method="post" action="<?= url('purchases/rfq/delete') ?>" class="d-inline"
                  onsubmit="return confirm('Eliminare la RFQ <?= e($r['numero']) ?>?')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button type="submit" class="btn-qk btn-qk-xs btn-qk-ghost" title="Elimina">
                <i class="fa-solid fa-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
