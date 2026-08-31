<?php
/**
 * MIGRATION RUNNER — Quack ERP
 * URL: http://localhost/Quack/db/migrate.php  (oppure da riga di comando: php db/migrate.php)
 *
 * Cosa fa:
 *   1. Si connette al database attivo (stesso DB_DRIVER/DB_DIR di config.php — l'app vera).
 *   2. Si assicura che esista la tabella `schema_migrations` (già presente sui DB creati
 *      da init_sqlite.sql/init_mysql.sql; creata al volo sui DB più vecchi che non ce l'hanno).
 *   3. Confronta i file in db/migrations/*.php con quelli già registrati in schema_migrations.
 *   4. Mostra quali mancano. Su conferma (POST, o automaticamente da CLI), esegue solo quelli
 *      mancanti, in ordine alfabetico di nome file — quindi conviene nominarli
 *      AAAA_MM_GG_descrizione.php così l'ordine coincide con l'ordine cronologico.
 *   5. Ogni migrazione gira in un PROCESSO PHP SEPARATO (non un include nella stessa richiesta):
 *      così due migrazioni diverse possono benissimo avere entrambe una funzione che si chiama
 *      allo stesso modo (es. column_exists()) senza scontrarsi ("Cannot redeclare function").
 *      Se una migrazione fallisce (way exit code diverso da 0), il runner si ferma e NON la
 *      registra come applicata: va corretta e si può rilanciare lo script, che riparte da lì.
 *
 * Perché una migrazione esistente non viene rieseguita su un'installazione nuova: perché le sue
 * modifiche sono già scritte a mano nelle CREATE TABLE di init_sqlite.sql/init_mysql.sql, e il
 * suo nome è già nell'INSERT di baseline in quegli stessi file. Vedi i commenti lì.
 *
 * Scrivere una nuova migrazione:
 *   - Nuovo file in db/migrations/, nome tipo 2026_07_10_aggiungi_campo_x.php
 *   - Deve essere eseguibile standalone: require_once .../includes/config.php e
 *     .../includes/db.php in testa (vedi db/migrations/migrate_ordini_righe_uom.php come esempio),
 *     idempotente (controllare se la modifica esiste già prima di applicarla), e terminare con
 *     exit code 0 se tutto ok (il default di PHP se lo script arriva in fondo senza errori).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';

// Bloccato in produzione: nessuno di questi script ha un controllo di login,
// e sono raggiungibili direttamente via browser per esigenze di setup locale
// (vedi .htaccess). In produzione va usata un'altra via (CLI/SSH), mai HTTP aperto.
if (defined('APP_ENV') && APP_ENV === 'production') {
    http_response_code(403);
    die('Accesso disabilitato quando APP_ENV=production.');
}
require_once dirname(__DIR__) . '/includes/db.php'; // crea/apre $pdo sul DB reale dell'app

// ── Assicura che schema_migrations esista anche su DB creati prima di questa
//    funzionalità (sui DB nuovi la crea già init_sqlite.sql/init_mysql.sql, quindi qui
//    è un no-op) ──────────────────────────────────────────────────────────────────────
try {
    if (DB_DRIVER === 'mysql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                migration  VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                migration  VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
} catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
    die('<b>Errore creando schema_migrations:</b> ' . htmlspecialchars($e->getMessage()));
}

// ── Elenco migrazioni disponibili su disco, in ordine alfabetico ───────────────────────
$migrationsDir = __DIR__ . '/migrations';
$allFiles = glob($migrationsDir . '/*.php') ?: [];
sort($allFiles, SORT_STRING);

$applied = [];
$stmt = $pdo->query("SELECT migration, applied_at FROM schema_migrations ORDER BY id ASC");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $applied[$row['migration']] = $row['applied_at'];
}

$pending = [];
foreach ($allFiles as $file) {
    $name = basename($file, '.php');
    if (!isset($applied[$name])) {
        $pending[] = ['name' => $name, 'file' => $file];
    }
}

$isCli = (PHP_SAPI === 'cli');
$runNow = $isCli || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$log = [];
$failed = null;

if ($runNow && !empty($pending)) {
    foreach ($pending as $m) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($m['file']);
        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        $log[] = [
            'name'   => $m['name'],
            'output' => implode("\n", $output),
            'ok'     => $exitCode === 0,
        ];

        if ($exitCode !== 0) {
            $failed = $m['name'];
            break; // non procedere con le successive: l'ordine conta
        }

        $ins = $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
        $ins->execute([$m['name']]);
        $applied[$m['name']] = date('Y-m-d H:i:s');
    }

    // Ricalcola i pending residui dopo l'esecuzione
    $pending = array_values(array_filter($pending, fn($m) => !isset($applied[$m['name']])));
}

// ── Output CLI ───────────────────────────────────────────────────────────────────────
if ($isCli) {
    echo "Quack ERP — Migration runner\n";
    echo "Driver: " . DB_DRIVER . "\n\n";
    if (empty($log)) {
        echo "Nessuna migrazione da eseguire. Database aggiornato (" . count($applied) . " migrazioni applicate).\n";
    } else {
        foreach ($log as $l) {
            echo ($l['ok'] ? "[OK] " : "[FALLITA] ") . $l['name'] . "\n";
            if ($l['output'] !== '') {
                echo "  " . str_replace("\n", "\n  ", trim($l['output'])) . "\n";
            }
        }
        echo $failed
            ? "\nFermato per errore in '{$failed}'. Corretto il problema, rilancia lo script: riparte da lì.\n"
            : "\nCompletato. " . count($log) . " migrazioni eseguite.\n";
    }
    exit($failed ? 1 : 0);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Migration Runner - Quack ERP</title>
    <style>
        body { font-family: 'Segoe UI', -apple-system, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; padding: 2rem; display: flex; justify-content: center; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); width: 100%; max-width: 720px; padding: 2rem; }
        h1 { font-size: 1.25rem; margin-top: 0; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.75rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; margin: 1rem 0; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #e2e8f0; }
        th { color: #475569; font-weight: 600; }
        .badge { display: inline-block; padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-applied { background: #f0fdf4; color: #166534; }
        .badge-pending { background: #fffbeb; color: #92400e; }
        .badge-fail { background: #fef2f2; color: #991b1b; }
        pre { background: #0f172a; color: #e2e8f0; padding: 0.75rem; border-radius: 6px; font-size: 0.8rem; overflow-x: auto; }
        button { background: #3b82f6; color: #fff; border: none; padding: 0.75rem 1.25rem; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.95rem; }
        button:hover { background: #2563eb; }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.875rem; margin-bottom: 1rem; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .muted { color: #64748b; font-size: 0.85rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>🦆 Quack ERP — Migration Runner</h1>
    <p class="muted">Driver attivo: <code><?= htmlspecialchars(DB_DRIVER) ?></code> &nbsp;·&nbsp; Migrazioni applicate: <strong><?= count($applied) ?></strong></p>

    <?php if (!empty($log)): ?>
        <?php if ($failed): ?>
            <div class="alert alert-error"><strong>Migrazione fallita: <?= htmlspecialchars($failed) ?></strong><br>Le migrazioni successive non sono state eseguite. Correggi il problema e ricarica questa pagina: riparte da dove si è fermata.</div>
        <?php else: ?>
            <div class="alert alert-success"><strong>✅ <?= count($log) ?> migrazione/i eseguita/e con successo.</strong></div>
        <?php endif; ?>
        <?php foreach ($log as $l): ?>
            <p><span class="badge <?= $l['ok'] ? 'badge-applied' : 'badge-fail' ?>"><?= $l['ok'] ? 'OK' : 'FALLITA' ?></span> <code><?= htmlspecialchars($l['name']) ?></code></p>
            <?php if (trim($l['output']) !== ''): ?><pre><?= htmlspecialchars($l['output']) ?></pre><?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 style="font-size:1rem;">Migrazioni applicate</h2>
    <?php if (empty($applied)): ?>
        <p class="muted">Nessuna.</p>
    <?php else: ?>
        <table>
            <tr><th>Nome</th><th>Applicata il</th></tr>
            <?php foreach ($applied as $name => $when): ?>
                <tr><td><code><?= htmlspecialchars($name) ?></code></td><td><?= htmlspecialchars((string)$when) ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2 style="font-size:1rem;">Migrazioni in attesa</h2>
    <?php if (empty($pending)): ?>
        <p class="muted">Nessuna — database aggiornato.</p>
    <?php else: ?>
        <table>
            <tr><th>Nome</th></tr>
            <?php foreach ($pending as $m): ?>
                <tr><td><code><?= htmlspecialchars($m['name']) ?></code></td></tr>
            <?php endforeach; ?>
        </table>
        <form method="post">
            <button type="submit">Esegui le migrazioni in attesa</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
