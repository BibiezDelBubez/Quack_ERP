<?php
/**
 * settings/aziende/_list.php
 * Variabili attese: $companies, $total
 */
?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <h2 class="mb-0 text-nowrap" style="font-size:1.1rem;font-weight:600">Elenco Aziende Interne</h2>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted"><?= $total ?> aziende configurate</small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($companies)): ?>
    <div class="qk-empty">
      <i class="fa-solid fa-building-circle-exclamation"></i>
      <p>Nessuna azienda interna configurata nel sistema.</p>
      <a href="<?= url('settings/aziende/create') ?>" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-plus"></i> Configura la prima azienda
      </a>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th>Azienda / Ragione Sociale</th>
          <th>Partita IVA</th>
          <th>Sede</th>
          <th>Contatti</th>
          <th class="text-center" style="width:120px">Predefinita</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $c): ?>
        <tr>
          <td>
            <div class="fw-semibold">
              <a href="<?= url('settings/aziende/edit?id=' . $c['id']) ?>">
                <?= e($c['ragione_sociale']) ?>
              </a>
            </div>
          </td>
          <td><code style="font-size:.8rem"><?= e($c['partita_iva']) ?></code></td>
          <td>
            <?php if (!empty($c['citta'])): ?>
              <?= e($c['citta']) ?> <span class="qk-text-muted">(<?= e($c['via']) ?>)</span>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="small">
              <?php if (!empty($c['email'])): ?>
                <div><i class="fa-solid fa-envelope fa-fw qk-text-muted me-1"></i><?= e($c['email']) ?></div>
              <?php endif; ?>
              <?php if (!empty($c['telefono'])): ?>
                <div><i class="fa-solid fa-phone fa-fw qk-text-muted me-1"></i><?= e($c['telefono']) ?></div>
              <?php endif; ?>
            </div>
          </td>
          <td class="text-center">
            <?php if ($c['predefinita']): ?>
              <span class="badge bg-success" style="font-size:.7rem"><i class="fa-solid fa-circle-check"></i> Si</span>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="col-actions">
            <div class="dropdown">
              <button class="qk-btn-icon" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fa-solid fa-ellipsis-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li>
                  <a class="dropdown-item" href="<?= url('settings/aziende/edit?id=' . $c['id']) ?>">
                    <i class="fa-solid fa-pen-to-square fa-fw me-2 text-secondary"></i>Modifica
                  </a>
                </li>
                <?php if (!$c['predefinita']): ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form action="<?= url('settings/aziende/delete') ?>" method="post" 
                        onsubmit="return confirm('Sei sicuro di voler eliminare questa azienda interna? Questa azione disattiverà anche la relativa anagrafica.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <button type="submit" class="dropdown-item text-danger">
                      <i class="fa-solid fa-trash fa-fw me-2"></i>Elimina
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
