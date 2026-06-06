<?php
/**
 * sidebar.php — navigazione laterale principale
 * Incluso sempre da header.php, che garantisce già config.php e functions.php caricati.
 */
?>
<nav class="qk-sidebar" id="sidebar" aria-label="Navigazione principale">
  <div class="qk-sidebar-inner">
    <ul class="list-unstyled mb-0">

      <!-- ── Dashboard ───────────────────────────────────── -->
      <li class="qk-nav-item">
        <a href="<?= url('dashboard') ?>" title="Dashboard"
           class="<?= nav_active('/dashboard') ?>">
          <i class="fa-solid fa-gauge-high"></i>
          <span class="qk-nav-label">Dashboard</span>
        </a>
      </li>

      <li><div class="qk-nav-divider"></div></li>

      <!-- ── ANAGRAFICHE ─────────────────────────────────── -->
      <li class="qk-nav-section">Anagrafiche</li>

      <li class="qk-nav-item">
        <a href="<?= url('customers') ?>" title="Clienti"
           class="<?= nav_active('/customers') ?>">
          <i class="fa-solid fa-address-book"></i>
          <span class="qk-nav-label">Clienti</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('suppliers') ?>" title="Fornitori"
           class="<?= nav_active('/suppliers') ?>">
          <i class="fa-solid fa-handshake"></i>
          <span class="qk-nav-label">Fornitori</span>
        </a>
      </li>

      <!-- Sottomenu Prodotti -->
      <li class="qk-nav-item">
        <button class="qk-nav-link <?= is_current_section('/products') ? 'active' : '' ?>"
                title="Prodotti"
                data-bs-toggle="collapse"
                data-bs-target="#nav-prodotti"
                aria-expanded="<?= is_current_section('/products') ? 'true' : 'false' ?>"
                aria-controls="nav-prodotti">
          <i class="fa-solid fa-flask"></i>
          <span class="qk-nav-label">Prodotti</span>
          <i class="fa-solid fa-chevron-right qk-nav-arrow ms-auto"></i>
        </button>
        <ul class="qk-nav-sub collapse <?= is_current_section('/products') ? 'show' : '' ?>"
            id="nav-prodotti">
          <li class="qk-nav-item">
            <a href="<?= url('products') ?>" title="Lista prodotti"
               class="<?= nav_active('/products') ?>">
              <i class="fa-solid fa-list fa-fw"></i>
              <span class="qk-nav-label">Lista prodotti</span>
            </a>
          </li>
          <li class="qk-nav-item">
            <a href="<?= url('products/categories') ?>" title="Categorie prodotti"
               class="<?= nav_active('/products/categories') ?>">
              <i class="fa-solid fa-folder-open fa-fw"></i>
              <span class="qk-nav-label">Categorie</span>
            </a>
          </li>
          <li class="qk-nav-item">
            <a href="<?= url('products/tags') ?>" title="Tag prodotti"
               class="<?= nav_active('/products/tags') ?>">
              <i class="fa-solid fa-hashtag fa-fw"></i>
              <span class="qk-nav-label">Tag</span>
            </a>
          </li>
        </ul>
      </li>

      <!-- Sottomenu Anagrafiche ADR -->
      <li class="qk-nav-item">
        <button class="qk-nav-link <?= is_current_section('/adr') ? 'active' : '' ?>"
                title="Anagrafiche ADR"
                data-bs-toggle="collapse"
                data-bs-target="#nav-adr"
                aria-expanded="<?= is_current_section('/adr') ? 'true' : 'false' ?>"
                aria-controls="nav-adr">
          <i class="fa-solid fa-biohazard"></i>
          <span class="qk-nav-label">Anagrafiche ADR</span>
          <i class="fa-solid fa-chevron-right qk-nav-arrow ms-auto"></i>
        </button>
        <ul class="qk-nav-sub collapse <?= is_current_section('/adr') ? 'show' : '' ?>"
            id="nav-adr">
          <li class="qk-nav-item">
            <a href="<?= url('adr?tab=codici') ?>" title="Codici ONU">
              <i class="fa-solid fa-list-ol fa-fw"></i>
              <span class="qk-nav-label">Codici ONU</span>
            </a>
          </li>
          <li class="qk-nav-item">
            <a href="<?= url('adr?tab=classi') ?>" title="Classi di pericolo">
              <i class="fa-solid fa-fire-flame-curved fa-fw"></i>
              <span class="qk-nav-label">Classi pericolo</span>
            </a>
          </li>
          <li class="qk-nav-item">
            <a href="<?= url('adr?tab=imballaggio') ?>" title="Gruppi imballaggio">
              <i class="fa-solid fa-box-taped fa-fw"></i>
              <span class="qk-nav-label">Gruppi imballaggio</span>
            </a>
          </li>
          <li class="qk-nav-item">
            <a href="<?= url('adr?tab=gallerie') ?>" title="Codici galleria">
              <i class="fa-solid fa-car-tunnel fa-fw"></i>
              <span class="qk-nav-label">Codici galleria</span>
            </a>
          </li>
          <li class="qk-nav-item">
            <a href="<?= url('adr?tab=categorie') ?>" title="Categorie di trasporto">
              <i class="fa-solid fa-truck fa-fw"></i>
              <span class="qk-nav-label">Categorie trasporto</span>
            </a>
          </li>
          <li class="qk-nav-item">
            <a href="<?= url('adr?tab=classificazioni') ?>" title="Codici classificazione">
              <i class="fa-solid fa-barcode fa-fw"></i>
              <span class="qk-nav-label">Codici classificazione</span>
            </a>
          </li>
          <li class="qk-nav-item">
            <a href="<?= url('adr/sostanze') ?>" title="Sostanze ADR">
              <i class="fa-solid fa-flask-vial fa-fw"></i>
              <span class="qk-nav-label">Sostanze ADR</span>
            </a>
          </li>
        </ul>
      </li>

      <li><div class="qk-nav-divider"></div></li>

      <!-- ── ACQUISTI ───────────────────────────────────────── -->
      <li class="qk-nav-section">Acquisti</li>

      <li class="qk-nav-item">
        <a href="<?= url('purchases') ?>" title="Acquisti"
           class="<?= nav_active('/purchases') ?>">
          <i class="fa-solid fa-arrow-down-to-bracket"></i>
          <span class="qk-nav-label">Acquisti</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('purchases/quotes') ?>" title="Preventivi fornitori"
           class="<?= nav_active('/purchases/quotes') ?>">
          <i class="fa-solid fa-file-invoice-dollar"></i>
          <span class="qk-nav-label">Preventivi fornitori</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('purchases/orders') ?>" title="Ordini d'acquisto"
           class="<?= nav_active('/purchases/orders') ?>">
          <i class="fa-solid fa-file-circle-check"></i>
          <span class="qk-nav-label">Ordini d'acquisto</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('purchases/pricelists') ?>" title="Listini acquisto"
           class="<?= nav_active('/purchases/pricelists') ?>">
          <i class="fa-solid fa-file-spreadsheet"></i>
          <span class="qk-nav-label">Listini acquisto</span>
        </a>
      </li>

      <li><div class="qk-nav-divider"></div></li>

      <!-- ── MAGAZZINO ───────────────────────────────────────── -->
      <li class="qk-nav-section">Magazzino</li>

      <li class="qk-nav-item">
        <a href="<?= url('warehouse') ?>" title="Magazzino"
           class="<?= nav_active('/warehouse') ?>">
          <i class="fa-solid fa-warehouse"></i>
          <span class="qk-nav-label">Magazzino</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('warehouse/stock') ?>" title="Giacenze"
           class="<?= nav_active('/warehouse/stock') ?>">
          <i class="fa-solid fa-cubes"></i>
          <span class="qk-nav-label">Giacenze</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('warehouse/movements') ?>" title="Movimenti"
           class="<?= nav_active('/warehouse/movements') ?>">
          <i class="fa-solid fa-arrow-right-arrow-left"></i>
          <span class="qk-nav-label">Movimenti</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('warehouse/inventories') ?>" title="Inventario fis."
           class="<?= nav_active('/warehouse/inventories') ?>">
          <i class="fa-solid fa-clipboard-list"></i>
          <span class="qk-nav-label">Inventario fis.</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('warehouse/reorder') ?>" title="Riordino stock"
           class="<?= nav_active('/warehouse/reorder') ?>">
          <i class="fa-solid fa-bell-on"></i>
          <span class="qk-nav-label">Riordino stock</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('purchases/receipts') ?>" title="Ricezione merci"
           class="<?= nav_active('/purchases/receipts') ?>">
          <i class="fa-solid fa-dolly"></i>
          <span class="qk-nav-label">Ricezione merci</span>
        </a>
      </li>

      <li><div class="qk-nav-divider"></div></li>

      <!-- ── VENDITE ────────────────────────────────────────── -->
      <li class="qk-nav-section">Vendite</li>

      <li class="qk-nav-item">
        <a href="<?= url('sales') ?>" title="Vendite"
           class="<?= nav_active('/sales') ?>">
          <i class="fa-solid fa-arrow-up-from-bracket"></i>
          <span class="qk-nav-label">Vendite</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('sales/quotes') ?>" title="Preventivi"
           class="<?= nav_active('/sales/quotes') ?>">
          <i class="fa-solid fa-file-invoice"></i>
          <span class="qk-nav-label">Preventivi</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('sales/orders') ?>" title="Ordini"
           class="<?= nav_active('/sales/orders') ?>">
          <i class="fa-solid fa-file-invoice-dollar"></i>
          <span class="qk-nav-label">Ordini</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('sales/pricelists') ?>" title="Listini vendita"
           class="<?= nav_active('/sales/pricelists') ?>">
          <i class="fa-solid fa-badge-percent"></i>
          <span class="qk-nav-label">Listini vendita</span>
        </a>
      </li>

      <li class="qk-nav-item">
        <a href="<?= url('ecommerce/sales.php') ?>" title="Vendite in-sito"
           class="<?= nav_active('/ecommerce/sales') ?>">
          <i class="fa-solid fa-cart-shopping"></i>
          <span class="qk-nav-label">Vendite in-sito</span>
        </a>
      </li>

      <li><div class="qk-nav-divider"></div></li>

      <!-- ── E-COMMERCE ──────────────────────────────────── -->
      <li class="qk-nav-section">E-commerce</li>

      <li class="qk-nav-item">
        <a href="<?= url('ecommerce/sync') ?>" title="Sincronizzazione WooCommerce"
           class="<?= nav_active('/ecommerce/sync') ?>">
          <i class="fa-solid fa-rotate"></i>
          <span class="qk-nav-label">Sincronizzazione WC</span>
        </a>
      </li>

      <li><div class="qk-nav-divider"></div></li>

      <!-- ── SISTEMA ─────────────────────────────────────── -->
      <li class="qk-nav-section">Sistema</li>

      <?php if (can('manage_users')): ?>
      <li class="qk-nav-item">
        <a href="<?= url('users') ?>" title="Utenti"
           class="<?= nav_active('/users') ?>">
          <i class="fa-solid fa-users-gear"></i>
          <span class="qk-nav-label">Utenti</span>
        </a>
      </li>
      <?php endif; ?>

      <li class="qk-nav-item">
        <a href="<?= url('settings') ?>" title="Impostazioni"
           class="<?= nav_active('/settings') ?>">
          <i class="fa-solid fa-gear"></i>
          <span class="qk-nav-label">Impostazioni</span>
        </a>
      </li>

    </ul>
  </div>
</nav>
