<?php
/**
 * app/views/warehouse/scadenze.php
 * Lotti già scaduti o in scadenza entro la soglia scelta, con giacenza residua.
 * Variabili: $lotti, $total, $base, $pag, $search, $magazzini, $magazzinoId,
 *            $giorni, $giorniValidi
 * — Revisione Gestione Magazzino e Lotti §5.
 */
$sort = $pag['sort'];
$dir  = $pag['dir'];
?>

<?php $active_tab = 'scadenze'; include __DIR__ . '/_subnav.php'; ?>

<!-- Selettore magazzino + soglia: sempre espliciti in pagina, mai un default silenzioso
     (stesso principio già applicato a Giacenze/Riordino) -->
<div class="qk-card mb-3">
  <div class="qk-card-body py-2">
    <form method="get" action="<?= e($base) ?>" class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($sort !== 'scadenza'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
      <?php if ($dir  !== 'asc'):      ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
      <?php if ($search !== ''):       ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>

      <label class="qk-form-label mb-0">Magazzino</label>
      <select name="magazzino_id" class="qk-form-control qk-form-control-sm" style="max-width:260px" onchange="this.form.submit()">
        <option value="0" <?= $magazzinoId === 0 ? 'selected' : '' ?>>Tutti i magazzini</option>
        <?php foreach ($magazzini as $mg): ?>
        <option value="<?= (int) $mg['id'] ?>" <?= $magazzinoId === (int) $mg['id'] ? 'selected' : '' ?>><?= e($mg['nome']) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="qk-form-label mb-0 ms-2">Entro</label>
      <select name="giorni" class="qk-form-control qk-form-control-sm" style="max-width:160px" onchange="this.form.submit()">
        <?php foreach ($giorniValidi as $g): ?>
        <option value="<?= $g ?>" <?= $giorni === $g ? 'selected' : '' ?>><?= $g ?> giorni</option>
        <?php endforeach; ?>
      </select>
      <span class="qk-form-hint">(include anche i lotti già scaduti)</span>
    </form>
  </div>
</div>

<!-- Ricerca -->
<div class="qk-toolbar mb-3">
  <form method="get" action="<?= e($base) ?>" class="qk-search-form">
    <?php if ($sort !== 'scadenza'):      ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
    <?php if ($dir  !== 'asc'):           ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
    <?php if ($magazzinoId !== 0):        ?><input type="hidden" name="magazzino_id" value="<?= (int) $magazzinoId ?>"><?php endif; ?>
    <?php if ($giorni !== 30):            ?><input type="hidden" name="giorni" value="<?= (int) $giorni ?>"><?php endif; ?>
    <div class="qk-search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= e($search) ?>"
             placeholder="Cerca prodotto o SKU…" class="qk-form-control" autocomplete="off">
    </div>
  </form>

  <?php if ($total > 0): ?>
    <span class="qk-text-muted" style="font-size:.85rem">
      <?= $total ?> lott<?= $total !== 1 ? 'i' : 'o' ?> da controllare
    </span>
  <?php endif; ?>
</div>

<!-- Tabella -->
<div class="qk-card">
  <div class="qk-card-body p-0">
    <table class="qk-table">
      <thead>
        <tr>
          <th>Lotto</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'prodotto', $sort, $dir)) ?>">Prodotto<?= qk_sort_icon('prodotto', $sort, $dir) ?></a></th>
          <?php if ($magazzinoId === 0): ?>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'magazzino', $sort, $dir)) ?>">Magazzino<?= qk_sort_icon('magazzino', $sort, $dir) ?></a></th>
          <?php endif; ?>
          <th class="text-end qk-sortable"><a href="<?= e(qk_sort_url($base, 'quantita', $sort, $dir)) ?>">Disponibile<?= qk_sort_icon('quantita', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'scadenza', $sort, $dir)) ?>">Scadenza<?= qk_sort_icon('scadenza', $sort, $dir) ?></a></th>
          <th>Stato</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($lotti)): ?>
        <tr>
          <td colspan="6" class="qk-table-empty">
            <?php if ($search !== ''): ?>
              Nessun lotto in scadenza corrisponde alla ricerca.
            <?php else: ?>
              Nessun lotto scaduto o in scadenza entro <?= $giorni ?> giorni — tutto a posto.
            <?php endif; ?>
          </td>
        </tr>
        <?php else: foreach ($lotti as $l):
          // giorni_rimanenti/scaduto calcolati dal controller (Fase 3, microtappa 3.7).
          $giorniRimanenti = $l['giorni_rimanenti'];
          $scaduto = $l['scaduto'];
        ?>
        <tr>
          <td class="font-monospace"><?= e($l['codice_lotto_interno'] ?: $l['codice_lotto_fornitore'] ?: ('#' . $l['lotto_id'])) ?></td>
          <td>
            <a href="<?= url('products/edit?id=' . $l['prodotto_id']) ?>" class="qk-text-accent text-decoration-none fw-medium">
              <?= e($l['prodotto_nome']) ?>
            </a>
            <div class="qk-text-muted" style="font-size:.8rem"><?= e($l['sku_interno'] ?? '') ?></div>
          </td>
          <?php if ($magazzinoId === 0): ?>
          <td><?= e($l['magazzino_nome']) ?></td>
          <?php endif; ?>
          <td class="text-end"><?= number_format((float) $l['quantita_disponibile'], 3, ',', '.') ?> <?= e($l['uom'] ?? '') ?></td>
          <td><?= date('d/m/Y', strtotime($l['data_scadenza'])) ?></td>
          <td>
            <?php if ($scaduto): ?>
              <span class="qk-badge cancelled">
                <i class="fa-solid fa-circle-exclamation"></i> Scaduto da <?= abs($giorniRimanenti) ?> g.
              </span>
            <?php else: ?>
              <span class="qk-badge pending">
                <i class="fa-solid fa-triangle-exclamation"></i> Scade tra <?= $giorniRimanenti ?> g.
              </span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Paginazione -->
<?php
$pagUrl = $base . '?' . http_build_query(array_filter([
    'q'            => $search,
    'sort'         => $sort !== 'scadenza' ? $sort : null,
    'dir'          => $dir  !== 'asc'      ? $dir  : null,
    'magazzino_id' => $magazzinoId !== 0   ? $magazzinoId : null,
    'giorni'       => $giorni !== 30       ? $giorni : null,
], static fn($v) => $v !== null && $v !== ''));
qk_paginate($total, $pag['page'], $pag['limit'], $pagUrl);
?>
