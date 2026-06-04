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

<!-- ── LISTA PRINCIPALE ── -->
<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left d-flex align-items-center gap-3">
      <a href="<?= url('sales/pricelists/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm">
        <i class="fa-solid fa-plus"></i> Nuovo
      </a>
      <h2 class="mb-0 text-nowrap">Listini vendita</h2>
    </div>

    <!-- Ricerca & Filtri -->
    <div class="qk-table-toolbar-right">
      <form action="<?= url('sales/pricelists') ?>" method="get" class="d-flex align-items-center gap-2">

        <select name="attivo" class="qk-form-control qk-form-control-sm" onchange="this.form.submit()" style="width:auto">
          <option value=""  <?= ($filtro_attivo ?? '') === ''  ? 'selected' : '' ?>>Tutti</option>
          <option value="1" <?= ($filtro_attivo ?? '') === '1' ? 'selected' : '' ?>>Attivi</option>
          <option value="0" <?= ($filtro_attivo ?? '') === '0' ? 'selected' : '' ?>>Non attivi</option>
        </select>

        <div class="qk-search-input">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" placeholder="Cerca per nome..." value="<?= e($search ?? '') ?>">
        </div>

        <?php if (!empty($search) || ($filtro_attivo ?? '') !== ''): ?>
          <a href="<?= url('sales/pricelists') ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Pulisci filtri">
            <i class="fa-solid fa-xmark"></i>
          </a>
        <?php endif; ?>
      </form>
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
          <th>Nome Listino</th>
          <th style="width:60px">Valuta</th>
          <th style="width:80px" class="text-center">Stato</th>
          <th style="width:160px">Valido Dal</th>
          <th style="width:160px">Valido Al</th>
          <th style="width:90px" class="text-end">Articoli</th>
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
