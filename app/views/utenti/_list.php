<?php
/**
 * utenti/_list.php — Vista lista utenti.
 * Variabili attese: $utenti, $total, $search, $ruolo, $me
 */
?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="qk-filter-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" class="qk-filter-input" placeholder="Cerca nome o email…" value="<?= e($search) ?>"></div>
        <select name="ruolo" class="qk-filter-input" style="width:auto">
          <option value="">Tutti i ruoli</option>
          <?php foreach (['admin','commerciale','magazziniere','readonly'] as $r): ?><option value="<?= $r ?>" <?= $ruolo === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
        <?php if ($search || $ruolo): ?><a href="<?= url('utenti') ?>" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-xmark"></i> Pulisci</a><?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right"><small class="qk-text-muted"><?= $total ?> utent<?= $total === 1 ? 'e' : 'i' ?></small></div>
  </div>
  <div class="qk-table-wrap">
    <?php if (empty($utenti)): ?>
    <div class="qk-empty"><i class="fa-regular fa-users"></i><p><?= ($search || $ruolo) ? 'Nessun utente trovato con i filtri selezionati.' : 'Nessun utente.' ?></p></div>
    <?php else: ?>
    <table class="qk-table">
      <thead><tr><th>Nome</th><th>Email</th><th>Ruolo</th><th>Stato</th><th>Creato il</th><th class="col-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($utenti as $u): $is_me = ((int)$u['id'] === (int)($me['id'] ?? 0)); ?>
        <tr>
          <td><span class="fw-semibold"><?= e($u['nome'] . ' ' . $u['cognome']) ?></span><?php if ($is_me): ?><span class="qk-badge ms-1" style="font-size:.65rem">Tu</span><?php endif; ?></td>
          <td><a href="mailto:<?= e($u['email']) ?>" class="qk-text-secondary text-decoration-none"><?= e($u['email']) ?></a></td>
          <td><span class="qk-badge <?= $u['ruolo'] === 'admin' ? 'confirmed' : 'draft' ?>"><?= e($u['ruolo']) ?></span></td>
          <td><?= status_badge(($u['attivo'] ?? 0) ? 'active' : 'inactive') ?></td>
          <td class="qk-text-muted"><?= fmt_date($u['created_at']) ?></td>
          <td class="col-actions">
            <div class="dropdown">
              <button class="btn-qk btn-qk-secondary btn-qk-sm px-2" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis-vertical"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= url('utenti/edit?id=' . (int)$u['id']) ?>"><i class="fa-solid fa-pen fa-fw me-2"></i>Modifica</a></li>
                <?php if (!$is_me): ?>
                <li><form method="post" action="<?= url('utenti/delete') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="<?= $u['attivo'] ? 'disable' : 'enable' ?>"><button type="submit" class="dropdown-item"><i class="fa-solid fa-<?= $u['attivo'] ? 'ban' : 'circle-check' ?> fa-fw me-2"></i><?= $u['attivo'] ? 'Disabilita' : 'Riabilita' ?></button></form></li>
                <li><hr class="dropdown-divider"></li>
                <li><form method="post" action="<?= url('utenti/delete') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="delete"><button type="submit" class="dropdown-item text-danger"><i class="fa-solid fa-trash fa-fw me-2"></i>Elimina</button></form></li>
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
