<?php
/**
 * sales/pricelists_list.php — Lista dei Listini Vendita.
 */
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'pricelists'; include __DIR__ . '/_subnav.php'; ?>

<?php if (isset($_SESSION['flash'])): ?>
<div class="qk-alert qk-alert-<?= e($_SESSION['flash']['type']) ?> mb-3">
  <i class="fa-solid <?= $_SESSION['flash']['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
  <span><?= e($_SESSION['flash']['message']) ?></span>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<?php $base = url('sales/pricelists'); $sort = $pag['sort']; $dir = $pag['dir']; ?>
<!-- ── LISTA PRINCIPALE ── -->
<div class="qk-card">
  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input" placeholder="Cerca per nome…" value="<?= e($search ?? '') ?>" autocomplete="off">
      </div>
      <?php if ($sort !== 'nome'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
      <?php if ($dir  !== 'asc'):  ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
      <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Cerca"><i class="fa-solid fa-magnifying-glass"></i></button>
      <?php if (!empty($search)): ?><a href="<?= $base ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Rimuovi filtri"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
    </form>
    <div class="qk-table-toolbar-right">
      <div class="qk-filter-pills">
        <a href="<?= e(qk_list_url($base, ['attivo' => null])) ?>" class="qk-filter-pill <?= ($filtro_attivo ?? '') === '' ? 'active' : '' ?>">Tutti</a>
        <a href="<?= e(qk_list_url($base, ['attivo' => '1'])) ?>" class="qk-filter-pill <?= ($filtro_attivo ?? '') === '1' ? 'active' : '' ?>">Attivi</a>
        <a href="<?= e(qk_list_url($base, ['attivo' => '0'])) ?>" class="qk-filter-pill <?= ($filtro_attivo ?? '') === '0' ? 'active' : '' ?>">Non attivi</a>
      </div>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($pricelists)): ?>
    <div class="qk-empty">
      <i class="fa-solid fa-badge-percent fs-1 text-muted mb-3"></i>
      <p>Nessun listino vendita trovato.</p>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'nome', $sort, $dir)) ?>">Nome Listino<?= qk_sort_icon('nome', $sort, $dir) ?></a></th>
          <th class="qk-sortable" style="width:60px"><a href="<?= e(qk_sort_url($base, 'valuta', $sort, $dir)) ?>">Valuta<?= qk_sort_icon('valuta', $sort, $dir) ?></a></th>
          <th style="width:80px" class="text-center">Stato</th>
          <th class="qk-sortable" style="width:160px"><a href="<?= e(qk_sort_url($base, 'dal', $sort, $dir)) ?>">Valido Dal<?= qk_sort_icon('dal', $sort, $dir) ?></a></th>
          <th class="qk-sortable" style="width:160px"><a href="<?= e(qk_sort_url($base, 'al', $sort, $dir)) ?>">Valido Al<?= qk_sort_icon('al', $sort, $dir) ?></a></th>
          <th class="qk-sortable" style="width:90px" class="text-end"><a href="<?= e(qk_sort_url($base, 'articoli', $sort, $dir)) ?>">Articoli<?= qk_sort_icon('articoli', $sort, $dir) ?></a></th>
          <th class="col-actions" style="width:100px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pricelists as $ls): ?>
        <?php
          $oggi = date('Y-m-d');
          $isAttivo = (bool)$ls['attivo'];
          $inRange  = (!$ls['valido_dal'] || $ls['valido_dal'] <= $oggi)
                   && (!$ls['valido_al']  || $ls['valido_al']  >= $oggi);
          $statoEffettivo = $isAttivo && $inRange;
        ?>
        <tr>
          <td>
            <a href="<?= url('sales/pricelists/edit?id=' . $ls['id']) ?>" class="fw-bold qk-text-accent">
              <?= e($ls['nome']) ?>
            </a>
            <?php if (!empty($ls['descrizione'])): ?>
              <div class="small qk-text-muted"><?= e($ls['descrizione']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <code class="text-monospace"><?= e($ls['valuta']) ?></code>
          </td>
          <td class="text-center">
            <?php if ($statoEffettivo): ?>
              <span class="badge bg-success">Attivo</span>
            <?php elseif (!$isAttivo): ?>
              <span class="badge bg-secondary">Disattivato</span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Scaduto</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="text-monospace small">
              <?= !empty($ls['valido_dal']) ? date('d/m/Y', strtotime($ls['valido_dal'])) : '—' ?>
            </span>
          </td>
          <td>
            <span class="text-monospace small">
              <?= !empty($ls['valido_al']) ? date('d/m/Y', strtotime($ls['valido_al'])) : '—' ?>
            </span>
          </td>
          <td class="text-end">
            <span class="badge bg-light text-dark border"><?= (int)$ls['nr_articoli'] ?></span>
          </td>
          <td class="text-end">
            <a href="<?= url('sales/pricelists/edit?id=' . $ls['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-xs" title="Modifica">
              <i class="fa-solid fa-pencil"></i>
            </a>
            <form action="<?= url('sales/pricelists/delete') ?>" method="post" class="d-inline"
                  onsubmit="return confirm('Sicuro di voler eliminare questo listino? Le righe prezzo associate saranno rimosse.')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $ls['id'] ?>">
              <button type="submit" class="btn-qk btn-qk-danger btn-qk-xs ms-1" title="Elimina">
                <i class="fa-solid fa-trash-can"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
