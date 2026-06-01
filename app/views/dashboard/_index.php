<!-- KPI grid -->
<div class="qk-kpi-grid">
<?php foreach ($kpi as $k): ?>
  <div class="qk-kpi-card">
    <div class="qk-kpi-icon <?= e($k['color']) ?>">
      <i class="fa-solid fa-<?= e($k['icon']) ?>"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= e($k['value']) ?></div>
      <div class="qk-kpi-label"><?= e($k['label']) ?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Due colonne: ultimi ordini + azioni rapide -->
<div class="qk-two-col">

  <!-- Ultimi ordini -->
  <div class="qk-card">
    <div class="qk-card-header">
      <span class="qk-card-title">
        <i class="fa-solid fa-box-open fa-sm me-2 qk-text-accent"></i>Ordini recenti
      </span>
      <a href="<?= url('orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
        Tutti gli ordini <i class="fa-solid fa-arrow-right fa-xs"></i>
      </a>
    </div>
    <?php if (empty($recent_orders)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-box-open"></i>
      <p>Nessun ordine recente.</p>
      <a href="<?= url('orders/create') ?>" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-plus"></i> Crea il primo ordine
      </a>
    </div>
    <?php else: ?>
    <div class="qk-table-wrap">
      <table class="qk-table">
        <thead>
          <tr>
            <th>N° Ordine</th>
            <th>Cliente</th>
            <th>Stato</th>
            <th class="text-end">Importo</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent_orders as $o): ?>
          <tr>
            <td>
              <a href="<?= url('orders/view?id=' . urlencode($o['id'])) ?>"
                 class="fw-semibold qk-text-accent text-decoration-none">
                <?= e($o['id']) ?>
              </a>
              <?= adr_badge($o['adr']) ?>
            </td>
            <td><?= e($o['customer']) ?></td>
            <td><?= status_badge($o['status']) ?></td>
            <td class="text-end fw-semibold"><?= fmt_money($o['amount']) ?></td>
            <td class="col-actions">
              <div class="dropdown">
                <button class="btn-qk btn-qk-secondary btn-qk-sm px-2"
                        data-bs-toggle="dropdown" aria-expanded="false">
                  <i class="fa-solid fa-ellipsis-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="<?= url('orders/view?id=' . urlencode($o['id'])) ?>">
                    <i class="fa-solid fa-eye fa-fw me-2"></i>Dettaglio</a></li>
                  <li><a class="dropdown-item" href="<?= url('orders/edit?id=' . urlencode($o['id'])) ?>">
                    <i class="fa-solid fa-pen fa-fw me-2"></i>Modifica</a></li>
                </ul>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Pannello azioni rapide -->
  <div>
    <div class="qk-card">
      <div class="qk-card-header">
        <span class="qk-card-title">
          <i class="fa-solid fa-bolt fa-sm me-2 qk-text-accent"></i>Azioni rapide
        </span>
      </div>
      <div class="qk-card-body" style="display:flex;flex-direction:column;gap:.6rem;">
        <a href="<?= url('offers/create') ?>" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-plus"></i> Nuova offerta
        </a>
        <a href="<?= url('orders/create') ?>" class="btn-qk btn-qk-secondary">
          <i class="fa-solid fa-plus"></i> Nuovo ordine
        </a>
        <a href="<?= url('customers/create') ?>" class="btn-qk btn-qk-secondary">
          <i class="fa-solid fa-user-plus"></i> Nuovo cliente
        </a>
        <a href="<?= url('products/create') ?>" class="btn-qk btn-qk-secondary">
          <i class="fa-solid fa-plus"></i> Nuovo prodotto
        </a>
      </div>
    </div>
  </div>

</div>
