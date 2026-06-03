<?php
/**
 * modals/info.php — Modale "Informazioni su Quack ERP"
 *
 * Incluso da footer.php, quindi disponibile su ogni pagina.
 * Attivato dal bottone nella topbar (header.php).
 *
 * Raccoglie a render-time:
 *   - versione app e schema (da costanti config.php)
 *   - versione PHP e server
 *   - versione SQLite e dimensione file DB (via $pdo se disponibile)
 *   - utente corrente (da current_user())
 *   - librerie PHP (da composer.lock)
 *   - librerie front-end JS/CSS (rilevate dai file locali in assets/)
 */

// ── Dati DB ──────────────────────────────────────────────────────────────────
$_info_sqlite_ver  = '—';
$_info_db_size     = '—';
$_info_db_path     = '—';

if (isset($pdo)) {
    try {
        $_info_sqlite_ver = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    } catch (Exception $_e) {}
}

$_db_file = APP_ROOT_DIR . '/db/database.sqlite';
if (file_exists($_db_file)) {
    $_info_db_size = number_format(filesize($_db_file) / 1024, 1, ',', '.') . ' KB';
    $_info_db_path = realpath($_db_file);
}

// ── Librerie PHP (composer.lock) ─────────────────────────────────────────────
// Legge solo i package di primo livello (no dipendenze interne delle dipendenze)
// confrontando composer.json require con composer.lock packages.
$_info_php_libs = [];
$_composer_lock = APP_ROOT_DIR . '/composer.lock';
$_composer_json = APP_ROOT_DIR . '/composer.json';

if (file_exists($_composer_lock) && file_exists($_composer_json)) {
    $_cjson  = json_decode(file_get_contents($_composer_json), true);
    $_clock  = json_decode(file_get_contents($_composer_lock),  true);
    // nomi dei require diretti (escludo require-dev)
    $_direct = array_keys($_cjson['require'] ?? []);

    foreach (($_clock['packages'] ?? []) as $_pkg) {
        if (in_array($_pkg['name'], $_direct, true)) {
            $_info_php_libs[] = [
                'name'    => $_pkg['name'],
                'version' => $_pkg['version'],
                'desc'    => $_pkg['description'] ?? '',
            ];
        }
    }
}
unset($_composer_lock, $_composer_json, $_cjson, $_clock, $_direct, $_pkg);

// ── Librerie front-end (rilevate dai file locali in assets/) ──────────────────
// Strategia: cerca il commento di versione nella prima riga di ogni file noto.
$_info_fe_libs = [];

$_fe_sources = [
    // [label, percorso file, regex per estrarre versione]
    ['Bootstrap',      APP_ROOT_DIR . '/assets/js/bootstrap.bundle.js',        '/Bootstrap v([\d.]+)/'],
    ['Font Awesome',   APP_ROOT_DIR . '/assets/fontawesome/css/all.min.css',   '/Font Awesome\S*\s+([\d.]+)/'],
];

foreach ($_fe_sources as [$_fe_name, $_fe_file, $_fe_regex]) {
    if (!file_exists($_fe_file)) continue;
    // Legge solo i primi 512 byte (il commento di versione è sempre all'inizio)
    $_fe_head = file_get_contents($_fe_file, false, null, 0, 512);
    if (preg_match($_fe_regex, $_fe_head, $_fe_m)) {
        $_info_fe_libs[] = ['name' => $_fe_name, 'version' => $_fe_m[1]];
    } else {
        $_info_fe_libs[] = ['name' => $_fe_name, 'version' => '—'];
    }
}
unset($_fe_sources, $_fe_name, $_fe_file, $_fe_regex, $_fe_head, $_fe_m);

// ── Dati utente e server ──────────────────────────────────────────────────────
$_info_user   = current_user();
$_info_uname  = $_info_user['display_name'] ?? ($_info_user['username'] ?? '—');
$_info_server = $_SERVER['SERVER_SOFTWARE'] ?? '—';
$_info_host   = $_SERVER['SERVER_NAME']     ?? gethostname();

// Cleanup
unset($_db_file, $_e, $_info_user);
?>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODALE INFO — #qkInfoModal
     Attivato da: <button data-bs-toggle="modal" data-bs-target="#qkInfoModal">
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="qkInfoModal" tabindex="-1"
     aria-labelledby="qkInfoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
    <div class="modal-content qk-modal-content">

      <!-- Header -->
      <div class="modal-header border-0 pb-0">
        <div class="d-flex align-items-center gap-3">
		<div class="qk-info-logo">
		  <img src="<?= asset('img/logo.svg') ?>" alt="Logo Quack" style="height: 40px; width: auto; object-fit: contain;">
		</div>
          <div>
            <h5 class="modal-title mb-0 fw-semibold" id="qkInfoModalLabel">
              <?= e(APP_NAME) ?>
            </h5>
            <span class="text-muted" style="font-size:.8rem">
              Versione <?= e(APP_VERSION) ?>
              &nbsp;·&nbsp;
              Schema DB <?= e(DB_SCHEMA_VERSION) ?>
            </span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                aria-label="Chiudi"></button>
      </div>

      <!-- Body -->
      <div class="modal-body pt-3">
        <table class="qk-info-table w-100">
          <tbody>

            <!-- ── Applicazione ── -->
            <tr class="qk-info-section-row">
              <td colspan="2">Applicazione</td>
            </tr>
            <tr>
              <th>Versione</th>
              <td><code><?= e(APP_VERSION) ?></code></td>
            </tr>
            <tr>
              <th>Schema DB</th>
              <td><code><?= e(DB_SCHEMA_VERSION) ?></code></td>
            </tr>
            <tr>
              <th>Ambiente</th>
              <td>
                <?php if (APP_ENV === 'production'): ?>
                  <span class="badge bg-success">production</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark"><?= e(APP_ENV) ?></span>
                <?php endif; ?>
              </td>
            </tr>

            <!-- ── Database ── -->
            <tr class="qk-info-section-row">
              <td colspan="2">Database</td>
            </tr>
            <tr>
              <th>Tipo</th>
              <td>SQLite</td>
            </tr>
            <tr>
              <th>Versione SQLite</th>
              <td><code><?= e($_info_sqlite_ver) ?></code></td>
            </tr>
            <tr>
              <th>Dimensione file</th>
              <td><?= e($_info_db_size) ?></td>
            </tr>
            <tr>
              <th>Percorso</th>
              <td style="font-size:.75rem;word-break:break-all;color:var(--qk-text-muted)">
                <?= e($_info_db_path) ?>
              </td>
            </tr>

            <!-- ── Server ── -->
            <tr class="qk-info-section-row">
              <td colspan="2">Server</td>
            </tr>
            <tr>
              <th>PHP</th>
              <td><code><?= e(phpversion()) ?></code></td>
            </tr>
            <tr>
              <th>Server</th>
              <td style="font-size:.8rem"><?= e($_info_server) ?></td>
            </tr>
            <tr>
              <th>Host</th>
              <td><code><?= e($_info_host) ?></code></td>
            </tr>

            <!-- ── Librerie PHP ── -->
            <tr class="qk-info-section-row">
              <td colspan="2">Librerie PHP</td>
            </tr>
            <?php foreach ($_info_php_libs as $_lib): ?>
            <tr>
              <th><?= e($_lib['name']) ?></th>
              <td>
                <code><?= e($_lib['version']) ?></code>
                <?php if ($_lib['desc']): ?>
                  <span class="qk-info-lib-desc"><?= e($_lib['desc']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($_info_php_libs)): ?>
            <tr><td colspan="2" style="color:var(--qk-text-muted);font-size:.8rem">composer.lock non trovato</td></tr>
            <?php endif; ?>

            <!-- ── Librerie Front-end ── -->
            <tr class="qk-info-section-row">
              <td colspan="2">Librerie Front-end</td>
            </tr>
            <?php foreach ($_info_fe_libs as $_felib): ?>
            <tr>
              <th><?= e($_felib['name']) ?></th>
              <td><code><?= e($_felib['version']) ?></code></td>
            </tr>
            <?php endforeach; ?>

            <!-- ── Sessione ── -->
            <tr class="qk-info-section-row">
              <td colspan="2">Sessione</td>
            </tr>
            <tr>
              <th>Utente</th>
              <td><?= e($_info_uname) ?></td>
            </tr>
            <tr>
              <th>Tema attivo</th>
              <td><code id="qk-active-theme-info"><?= e(ACTIVE_THEME) ?></code></td>
            </tr>

          </tbody>
        </table>
      </div>

      <!-- Footer -->
      <div class="modal-footer border-0 pt-0 justify-content-between">
        <span style="font-size:.75rem;color:var(--qk-text-muted)">
          &copy; <?= date('Y') ?> Quack ERP
        </span>
        <button type="button" class="btn btn-sm btn-secondary"
                data-bs-dismiss="modal">Chiudi</button>
      </div>

    </div><!-- /modal-content -->
  </div>
</div>

<style>
/* ── Stili modale info ───────────────────────────────────────────────────────
   Usa solo var(--qk-*) per compatibilità con tutti i temi.
─────────────────────────────────────────────────────────────────────────── */
.qk-info-table {
  border-collapse: collapse;
  font-size: .85rem;
}
.qk-info-table th,
.qk-info-table td {
  padding: .3rem .5rem;
  vertical-align: top;
}
.qk-info-table th {
  width: 38%;
  color: var(--qk-text-muted);
  font-weight: 500;
  white-space: nowrap;
}
.qk-info-table td {
  color: var(--qk-text-primary);
}
.qk-info-table code {
  font-size: .82rem;
  color: var(--qk-accent);
  background: transparent;
  padding: 0;
}

/* Descrizione libreria (testo grigio sotto la versione) */
.qk-info-lib-desc {
  display: block;
  font-size: .72rem;
  color: var(--qk-text-muted);
  line-height: 1.3;
  margin-top: .1rem;
}

/* Riga di separazione sezione */
.qk-info-section-row td {
  padding-top: .9rem;
  padding-bottom: .2rem;
  font-size: .7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--qk-text-muted);
  border-top: 1px solid var(--qk-border);
}
.qk-info-table tbody tr:first-child .qk-info-section-row td,
.qk-info-section-row:first-child td {
  border-top: none;
  padding-top: .25rem;
}
</style>
