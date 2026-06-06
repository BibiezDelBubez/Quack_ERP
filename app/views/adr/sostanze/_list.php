<?php /** @var array $sostanze @var string|null $search */ ?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input"
                 placeholder="Nome, nome tecnico, N° ONU…"
                 value="<?= e($search ?? '') ?>">
        </div>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-filter"></i> Filtra
        </button>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted me-3"><?= count($sostanze) ?> sostan<?= count($sostanze) === 1 ? 'za' : 'ze' ?></small>
      <a href="<?= url('adr/sostanze/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm">
        <i class="fa-solid fa-plus"></i> Nuova sostanza
      </a>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($sostanze)): ?>
      <div class="qk-empty">
        <i class="fa-solid fa-flask-vial"></i>
        <p>Nessuna sostanza ADR registrata.<?= $search ? ' Prova a cambiare i filtri.' : '' ?></p>
        <?php if (!$search): ?>
        <a href="<?= url('adr/sostanze/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm mt-2">
          <i class="fa-solid fa-plus"></i> Aggiungi sostanza
        </a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <table class="qk-table" style="font-size:0.875rem">
        <thead>
          <tr>
            <th>Nome sostanza</th>
            <th>N° ONU</th>
            <th>Classe</th>
            <th>G.I.</th>
            <th>Concentrazione</th>
            <th>Flag</th>
            <th>Schede SDS</th>
            <th>Prodotti</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sostanze as $s): ?>
          <tr>
            <td>
              <a href="<?= url('adr/sostanze/edit?id=' . $s['id']) ?>" class="qk-text-accent fw-semibold text-decoration-none">
                <?= e($s['nome']) ?>
              </a>
              <?php if ($s['nome_tecnico']): ?>
              <div class="small qk-text-muted"><?= e($s['nome_tecnico']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($s['codice_onu']): ?>
              <span class="qk-badge adr">UN<?= e($s['codice_onu']) ?></span>
              <?php else: ?>
              <span class="qk-text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= $s['classe_pericolo'] ? e($s['classe_pericolo']) : '—' ?></td>
            <td>
              <?php if ($s['gruppo_imballaggio']): ?>
              <span class="fw-semibold"><?= e($s['gruppo_imballaggio']) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="small">
              <?php if ($s['concentrazione_min_pct'] !== null || $s['concentrazione_max_pct'] !== null): ?>
                <?= $s['concentrazione_min_pct'] !== null ? number_format((float)$s['concentrazione_min_pct'], 1) . '%' : '' ?>
                <?php if ($s['concentrazione_min_pct'] !== null && $s['concentrazione_max_pct'] !== null): ?> – <?php endif; ?>
                <?= $s['concentrazione_max_pct'] !== null ? number_format((float)$s['concentrazione_max_pct'], 1) . '%' : '' ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="small">
              <?php if ($s['e_soluzione']): ?><span class="qk-badge draft me-1">Soluz.</span><?php endif; ?>
              <?php if ($s['e_rifiuto']): ?><span class="qk-badge cancelled me-1">Rifiuto</span><?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ((int)$s['n_schede'] > 0): ?>
              <span class="qk-badge confirmed"><?= (int)$s['n_schede'] ?></span>
              <?php else: ?>
              <span class="qk-text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ((int)$s['n_prodotti'] > 0): ?>
              <span class="qk-badge pending"><?= (int)$s['n_prodotti'] ?></span>
              <?php else: ?>
              <span class="qk-text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="<?= url('adr/sostanze/edit?id=' . $s['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
                <i class="fa-solid fa-pen-to-square"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
