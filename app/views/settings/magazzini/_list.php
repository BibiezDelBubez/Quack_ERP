<?php
/**
 * settings/magazzini/_list.php
 * Variabili attese: $magazzini, $total
 */
$tipoLabel = [
    'fisico'      => 'Fisico',
    'virtuale'    => 'Virtuale',
    'conto_terzi' => 'Conto terzi',
];
?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <h2 class="mb-0 text-nowrap" style="font-size:1.1rem;font-weight:600">Elenco Magazzini</h2>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted"><?= $total ?> magazzini configurati</small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($magazzini)): ?>
    <div class="qk-empty">
      <i class="fa-solid fa-warehouse"></i>
      <p>Nessun magazzino configurato nel sistema.</p>
      <a href="<?= url('settings/magazzini/create') ?>" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-plus"></i> Configura il primo magazzino
      </a>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th>Codice / Nome</th>
          <th>Tipo</th>
          <th>Azienda interna</th>
          <th>Sede</th>
          <th class="text-center" style="width:110px">Predefinito</th>
          <th class="text-center" style="width:100px">Stato</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($magazzini as $m): ?>
        <tr<?= !$m['attivo'] ? ' class="qk-text-muted"' : '' ?>>
          <td>
            <div class="fw-semibold">
              <a href="<?= url('settings/magazzini/edit?id=' . $m['id']) ?>">
                <?= e($m['nome']) ?>
              </a>
            </div>
            <code style="font-size:.75rem"><?= e($m['codice']) ?></code>
          </td>
          <td><?= e($tipoLabel[$m['tipo']] ?? $m['tipo']) ?></td>
          <td><?= $m['azienda_interna_nome'] ? e($m['azienda_interna_nome']) : '<span class="qk-text-muted">—</span>' ?></td>
          <td>
            <?php if (!empty($m['indirizzo_citta'])): ?>
              <?= e($m['indirizzo_citta']) ?> <span class="qk-text-muted">(<?= e($m['indirizzo_via']) ?>)</span>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($m['predefinito']): ?>
              <span class="badge bg-success" style="font-size:.7rem"><i class="fa-solid fa-circle-check"></i> Si</span>
            <?php elseif ($m['attivo']): ?>
              <form action="<?= url('settings/magazzini/set-default') ?>" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" style="font-size:.7rem;padding:2px 8px">Rendi predefinito</button>
              </form>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($m['attivo']): ?>
              <span class="badge bg-success" style="font-size:.7rem">Attivo</span>
            <?php else: ?>
              <span class="badge bg-secondary" style="font-size:.7rem">Disattivato</span>
            <?php endif; ?>
          </td>
          <td class="col-actions">
            <div class="dropdown">
              <button class="qk-btn-icon" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fa-solid fa-ellipsis-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li>
                  <a class="dropdown-item" href="<?= url('settings/magazzini/edit?id=' . $m['id']) ?>">
                    <i class="fa-solid fa-pen-to-square fa-fw me-2 text-secondary"></i>Modifica
                  </a>
                </li>
                <?php if ($m['attivo']): ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form action="<?= url('settings/magazzini/delete') ?>" method="post"
                        onsubmit="return confirm('Disattivare questo magazzino? Resterà visibile negli storici ma non più selezionabile per nuove operazioni.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                    <button type="submit" class="dropdown-item text-danger">
                      <i class="fa-solid fa-ban fa-fw me-2"></i>Disattiva
                    </button>
                  </form>
                </li>
                <?php else: ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form action="<?= url('settings/magazzini/reactivate') ?>" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                    <button type="submit" class="dropdown-item">
                      <i class="fa-solid fa-rotate-left fa-fw me-2 text-secondary"></i>Riattiva
                    </button>
                  </form>
                </li>
                <?php endif; ?>
              </ul>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
