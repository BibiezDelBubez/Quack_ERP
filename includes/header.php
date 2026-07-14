<?php
/**
 * header.php — HTML head + topbar
 *
 * Variabili attese (da impostare prima dell'include):
 *   $page_title    string   Titolo della pagina (obbligatorio)
 *   $page_subtitle string   Sottotitolo (opzionale)
 *   $page_actions  string   HTML dei bottoni azione in alto a destra (opzionale)
 *   $breadcrumbs   array    Es: [['label'=>'Clienti','url'=>'/customers/'],['label'=>'Nuovo']]
 */

// Fallback config se header.php viene incluso direttamente
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/functions.php';
}

$_flash = flash_get();
$_current_theme = ACTIVE_THEME;

require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/magazzino.php';
global $pdo;
// Risoluzione azienda attiva centralizzata in qk_resolve_azienda_attiva_sessione()
// (Revisione Incrociata §7.1.1): prima la stessa logica (query + fallback +
// scrittura $_SESSION) viveva qui, inline nella View.
$aziende_topbar = qk_aziende_list($pdo);
$attiva_azienda = qk_resolve_azienda_attiva_sessione($pdo, $aziende_topbar);

// Selettore magazzino attivo (Piano Multi-Magazzino §3.3/§3.10) — stesso
// pattern del selettore azienda sopra, con qk_magazzino_attivo_id() come
// unica fonte di verità per la risoluzione predefinita/fallback (§3.8).
// Solo fisici radice (Trasferimenti tra Magazzini come Documento.md, §7.2):
// una sede virtuale/conto_terzi/conto_lavoro non deve mai poter diventare la
// sede "attiva" di sessione, anche se resta selezionabile come origine/
// destinazione in un trasferimento (vedi qk_magazzini_list()).
$magazzini_topbar = qk_magazzini_list($pdo, true, true);
$attivo_magazzino = null;
if (!empty($magazzini_topbar)) {
    try {
        $magazzinoAttivoId = qk_magazzino_attivo_id($pdo);
        foreach ($magazzini_topbar as $mg) {
            if ((int)$mg['id'] === $magazzinoAttivoId) {
                $attivo_magazzino = $mg;
                break;
            }
        }
    } catch (\Throwable $e) {
        $attivo_magazzino = null;
    }
    if (!$attivo_magazzino) {
        $attivo_magazzino = $magazzini_topbar[0];
    }
    $_SESSION['magazzino_attivo_id'] = (int)$attivo_magazzino['id'];
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="<?= e($_current_theme) ?>">
<head>
  <!-- Anti-flicker sidebar e tema: sincrono, gira PRIMA del render -->
  <script>
    (function () {
      var html = document.documentElement;
      if (localStorage.getItem('qk_sidebar_collapsed') === '1') {
        html.classList.add('sidebar-collapsed');
      }
      var theme = localStorage.getItem('qk_theme');
      if (theme) {
        html.setAttribute('data-theme', theme);
      }
      // Blocca le transizioni durante il caricamento iniziale;
      // verranno riabilitate da app.js dopo il primo frame.
      html.classList.add('no-sb-transition');
    })();
  </script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($page_title ?? 'Dashboard') ?> — <?= e(APP_NAME) ?></title>

  <!-- Bootstrap 5 — usato per utility (d-flex, gap-*, col-*, modal JS) e componenti interattivi -->
  <link rel="stylesheet" href="<?= asset('css/bootstrap.min.css') ?>">

  <!--
    Framework alternativi disponibili in assets/css/ ma non caricati:
    - Bulma  (assets/css/bulma.min.css)  — CSS-only, nessun JS, ottimo per prototipi rapidi
    - Pico   (assets/css/pico.min.css)   — minimal semantic CSS, ottimo per form semplici
    Per usarli: decommentare la riga corrispondente e verificare conflitti con Bootstrap/qk-*.
    <link rel="stylesheet" href="<?= asset('css/bulma.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/pico.min.css') ?>">
  -->

  <!-- FontAwesome Pro -->
  <link rel="stylesheet" href="<?= asset('fontawesome/css/all.min.css') ?>">
  <!-- Quack: tema e layout -->
    <link rel="stylesheet" href="<?= asset('css/theme.css') ?>?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">

  <?php
  /**
   * CSS aggiuntivi per-pagina.
   * Impostare $extra_css come array di path relativi alla cartella assets/css/
   * prima di includere header.php. Es: $extra_css = ['products-suppliers.css'];
   */
  if (!empty($extra_css) && is_array($extra_css)):
    foreach ($extra_css as $_ecss): ?>
  <link rel="stylesheet" href="<?= asset('css/' . e($_ecss)) ?>">
  <?php endforeach; endif; ?>

    <!-- ✅ FAVICON QUI -->
    <link rel="icon" href="<?= asset('img/logo_quack.png') ?>" type="image/png">


  <!-- Passa BASE_URL, lista temi e alias a JS — generati da config.php (fonte unica) -->
  <script>
    const QK_BASE         = '<?= BASE_URL ?>';
    const QK_THEMES       = <?= json_encode(array_column(QK_THEMES, 'slug')) ?>;
    const QK_SESSION_THEME = '<?= e($_current_theme) ?>';
  </script>
</head>
<body>

<!-- ═══════════════════════════════ TOPBAR ════════════════════════════════ -->
<header class="qk-topbar">

  <div class="qk-topbar-left">
    <!-- Toggle sidebar -->
    <button class="qk-sidebar-toggle" id="sidebarToggle" aria-label="Apri/chiudi menu">
      <i class="fa-solid fa-bars"></i>
    </button>
    <!-- Brand -->
    <a href="<?= url('dashboard') ?>" class="qk-brand">
      <i class="fa-solid fa-duck qk-brand-icon"></i>
      <span class="qk-brand-name"><?= e(APP_NAME) ?></span>
    </a>
  </div>

  <div class="qk-topbar-center">
    <?php if (!empty($page_title)): ?>
    <span class="qk-topbar-title"><?= e($page_title) ?></span>
    <?php endif; ?>
    <div class="qk-search">
      <i class="fa-solid fa-magnifying-glass qk-search-icon"></i>
      <input type="search" placeholder="Cerca ordini, clienti, prodotti…" autocomplete="off">
    </div>
  </div>

  <div class="qk-topbar-right">

    <!-- Selettore Azienda Acquirente -->
    <?php if ($attiva_azienda): ?>
      <?php if (count($aziende_topbar) > 1): ?>
        <div class="dropdown">
          <button class="qk-icon-btn dropdown-toggle border-0" data-bs-toggle="dropdown" aria-expanded="false" title="Azienda Acquirente Attiva" style="display:flex; align-items:center; gap:.4rem; font-size:.85rem; font-weight:600; padding:4px 10px; width:auto; border-radius:6px; background:var(--qk-bg-subtle);">
            <i class="fa-solid fa-building" style="font-size:1rem;"></i>
            <span style="max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e($attiva_azienda['ragione_sociale']) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><span class="dropdown-header">Seleziona azienda attiva</span></li>
            <?php foreach ($aziende_topbar as $az): ?>
              <li>
                <a class="dropdown-item d-flex justify-content-between align-items-center <?= (int)$az['id'] === (int)$attiva_azienda['id'] ? 'active' : '' ?>"
                   href="?switch_azienda_id=<?= $az['id'] ?>">
                  <span><?= e($az['ragione_sociale']) ?></span>
                  <?php if ((int)$az['id'] === (int)$attiva_azienda['id']): ?>
                    <i class="fa-solid fa-check ms-2"></i>
                  <?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php else: ?>
        <!-- Singola azienda (visualizza statico senza dropdown) -->
        <div class="d-none d-md-flex align-items-center gap-1 text-muted" style="font-size:.8rem; font-weight:600; padding:6px 12px; border-radius:6px; background:var(--qk-bg-subtle);">
          <i class="fa-solid fa-building"></i>
          <span><?= e($attiva_azienda['ragione_sociale']) ?></span>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Selettore Magazzino Attivo: RIMOSSO dalla topbar globale (Opzione A,
         Magazzino Attivo - Analisi e Alternative.md §9.3/§9.5). Il magazzino
         non è un dato davvero globale come l'azienda sopra — riguarda solo
         un sottoinsieme di schermate (giacenze, movimenti, riordino,
         trasferimenti), dove ora ha un campo/filtro esplicito in pagina
         (Fix 1-4, §8). Nessuno dei 4 ERP di riferimento (SAP, NetSuite,
         Dynamics, Odoo) ha un widget "sede corrente" sempre in vista — la
         risoluzione dati ($magazzini_topbar/$attivo_magazzino sopra) resta
         invariata e alimenta ancora qk_magazzino_attivo_id(); cambia solo
         il fatto che non esiste più un punto dedicato in astratto per
         modificarla: si aggiorna come effetto collaterale delle scelte
         esplicite fatte nelle pagine che la richiedono. -->

    <!-- Cambio tema -->
    <div class="dropdown">
      <button class="qk-icon-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Cambia tema">
        <i class="fa-solid fa-palette"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><span class="dropdown-header">Tema interfaccia</span></li>
        <?php foreach (QK_THEMES as $_t): ?>
        <li>
          <a class="dropdown-item <?= $_current_theme === $_t['slug'] ? 'active' : '' ?>"
             href="#" data-theme-set="<?= e($_t['slug']) ?>">
            <span class="qk-theme-swatch me-2" style="background:<?= e($_t['swatch']) ?><?= isset($_t['swatch_border']) ? ';border:' . e($_t['swatch_border']) : '' ?>"></span><?= e($_t['label']) ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <!-- Informazioni sull'app -->
    <button class="qk-icon-btn" title="Informazioni su <?= e(APP_NAME) ?>"
            aria-label="Info" data-bs-toggle="modal" data-bs-target="#qkInfoModal">
      <i class="fa-solid fa-circle-info"></i>
    </button>

    <!-- Profilo utente -->
    <?php
      $_u     = current_user();
      $_dname = $_u['display_name'] ?? 'Utente';
      $_words = array_filter(explode(' ', trim($_dname)));
      $_ini   = mb_strtoupper(
          mb_substr(array_values($_words)[0] ?? 'U', 0, 1) .
          mb_substr(array_values($_words)[1] ?? '',  0, 1)
      );
    ?>
    <div class="dropdown">
      <button class="qk-avatar-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Profilo">
        <span class="qk-avatar"><?= e($_ini) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end" style="min-width:200px">
        <li><span class="dropdown-header"><?= e($_dname) ?></span></li>
        <li><a class="dropdown-item" href="<?= url('profile') ?>"><i class="fa-solid fa-user fa-fw me-2"></i>Profilo</a></li>
        <li><a class="dropdown-item" href="<?= url('settings') ?>"><i class="fa-solid fa-gear fa-fw me-2"></i>Impostazioni</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="<?= url('logout') ?>"><i class="fa-solid fa-right-from-bracket fa-fw me-2"></i>Esci</a></li>
      </ul>
    </div>

  </div>
</header>
<!-- /topbar -->

<!-- Backdrop mobile -->
<div class="qk-sidebar-backdrop" id="sidebarBackdrop"></div>

<?php require_once INCLUDES_DIR . '/sidebar.php'; ?>

<!-- ═══════════════════════════════ MAIN ══════════════════════════════════ -->
<main class="qk-main" id="mainContent">

  <!-- Container per toast dinamici -->
  <div id="qk-toast-container" style="position:fixed;top:72px;right:1rem;z-index:2000;width:340px;pointer-events:none;"></div>

  <!-- Flash message da sessione -->
  <?php if ($_flash): ?>
  <?php 
    $autoDismissAttr = ($_flash['type'] === 'success' || $_flash['type'] === 'info') ? 'data-auto-dismiss="5000"' : ''; 
  ?>
  <div class="qk-alert qk-alert-<?= e($_flash['type']) ?>" <?= $autoDismissAttr ?>>
    <?php
      $icon_map = ['success'=>'circle-check','danger'=>'circle-xmark','warning'=>'triangle-exclamation','info'=>'circle-info'];
      $icon = $icon_map[$_flash['type']] ?? 'circle-info';
    ?>
    <i class="fa-solid fa-<?= $icon ?>" aria-hidden="true"></i>
    <span><?= e($_flash['message']) ?></span>
    <button class="qk-alert-close" aria-label="Chiudi"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <?php endif; ?>

  <!-- Page header -->
  <?php if (!empty($page_title) && empty($hide_default_header)): ?>
  <div class="qk-page-header">
    <div class="qk-page-header-info">
      <?php if (!empty($breadcrumbs)): ?>
      <ol class="qk-breadcrumb">
        <li><a href="<?= url('dashboard') ?>"><i class="fa-solid fa-house fa-xs"></i></a></li>
        <?php foreach ($breadcrumbs as $crumb): ?>
          <li>
            <?php if (!empty($crumb['url'])): ?>
              <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
            <?php else: ?>
              <?= e($crumb['label']) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
      <h1><?= e($page_title) ?></h1>
      <?php if (!empty($page_subtitle)): ?>
      <p class="qk-page-subtitle"><?= e($page_subtitle) ?></p>
      <?php endif; ?>
    </div>
    <?php if (!empty($page_actions)): ?>
    <div class="qk-page-header-actions">
      <?= $page_actions /* HTML già formato, non escapare */ ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Contenuto della pagina segue qui -->
