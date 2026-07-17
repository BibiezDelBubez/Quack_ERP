<?php
/**
 * ESEGUI QUESTO FILE PER CREARE O INIZIALIZZARE IL DATABASE SQLITE.
 * URL: http://localhost/Quack/db/crea_db_sqlite.php
 */

declare(strict_types=1);

// Legge la configurazione reale dell'app (DB_DIR, APP_ROOT_DIR, ecc.) invece di
// ridefinire percorsi propri: altrimenti questo script può creare/aggiornare un
// file .sqlite diverso da quello che l'app usa davvero a runtime (vedi includes/db.php).
require_once dirname(__DIR__) . '/includes/config.php';

$sqlFile = APP_ROOT_DIR . '/db/init_sqlite.sql';

// Stesso nome file usato da includes/db.php, così questo tool inizializza
// davvero il database che l'app leggerà.
$dbname  = $_POST['dbname'] ?? 'database.sqlite';
$error   = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_readable($sqlFile)) {
            throw new Exception("Impossibile leggere il file SQL di inizializzazione in: {$sqlFile}");
        }

        if (!is_dir(DB_DIR) && !@mkdir(DB_DIR, 0775, true)) {
            throw new Exception("Impossibile creare la cartella del database: " . DB_DIR);
        }

        $safeDbName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $dbname);
        $dbPath = DB_DIR . '/' . $safeDbName;

        $dsn = "sqlite:{$dbPath}";
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Abilita il supporto alle chiavi esterne per SQLite
        $pdo->exec("PRAGMA foreign_keys = ON;");

        // Legge lo script SQL
        $rawSql = file_get_contents($sqlFile);

        // SQLite tramite PDO supporta l'esecuzione multi-query nativa in un colpo solo.
        // Questo evita il problema dei parser basilari che si rompono sui "BEGIN ... END;" dei TRIGGER.
        $pdo->exec($rawSql);

        $success = true;
    } catch (Exception $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Inizializzazione Database SQLite - Quack ERP</title>
    <style>
        body {
            font-family: 'Segoe UI', -apple-system, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            margin: 0;
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .card {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            width: 100%;
            max-width: 480px;
            padding: 2rem;
        }
        h1 {
            font-size: 1.25rem;
            margin-top: 0;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0.75rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #475569;
        }
        input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }
        input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        button {
            width: 100%;
            background: #3b82f6;
            color: #ffffff;
            border: none;
            padding: 0.75rem;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.95rem;
            margin-top: 0.5rem;
        }
        button:hover {
            background: #2563eb;
        }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            margin-bottom: 1rem;
            line-height: 1.4;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #86efac;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>🦆 Quack ERP — Setup SQLite</h1>
    
    <?php if ($error !== null): ?>
        <div class="alert alert-error">
            <strong>Errore di installazione:</strong><br>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>✅ Database creato con successo!</strong><br>
            Il database <strong><?= htmlspecialchars($dbname) ?></strong> è stato creato/aggiornato in <code><?= htmlspecialchars(DB_DIR) ?></code>.<br><br>
            <strong>Credenziali di default:</strong><br>
            Username: <code>admin</code><br>
            Password: <code>admin123</code><br>
            <em>(Cambiala subito dopo il primo accesso).</em>
        </div>
        <p style="text-align: center; margin-top: 1.5rem;">
            <a href="../index.php" style="color: #3b82f6; text-decoration: none; font-weight: bold;">Vai alla Login page →</a>
        </p>
    <?php else: ?>
        <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 1.5rem;">
            Questo script crea il database SQLite specificato dentro <code><?= htmlspecialchars(DB_DIR) ?></code>
            (la cartella configurata in <code>DB_DIR</code>) ed esegue le query presenti in <code>db/init_sqlite.sql</code>.
            Se il file esiste già con le tabelle già presenti, le query <code>CREATE TABLE IF NOT EXISTS</code>
            non le modificano: per applicare uno schema aggiornato a un database esistente serve una migrazione
            dedicata, non questo script.
        </p>
        <form method="post">
            <div class="form-group">
                <label for="dbname">Nome File Database</label>
                <input type="text" id="dbname" name="dbname" value="<?= htmlspecialchars($dbname) ?>" required>
            </div>
            <button type="submit">Crea e Popola Database</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
