<?php
/**
 * ESEGUI QUESTO FILE PER CREARE O INIZIALIZZARE IL DATABASE MYSQL.
 * URL: http://localhost/Quack/db/crea_db.php
 */

declare(strict_types=1);

// Legge la configurazione reale dell'app (DB_MYSQL_*) invece di ripetere valori
// a mano: altrimenti i default di questo form possono disallinearsi in silenzio
// da quelli usati davvero da includes/db.php a runtime.
require_once dirname(__DIR__) . '/includes/config.php';

// Bloccato in produzione: nessuno di questi script ha un controllo di login,
// e sono raggiungibili direttamente via browser per esigenze di setup locale
// (vedi .htaccess). In produzione va usata un'altra via (CLI/SSH), mai HTTP aperto.
if (defined('APP_ENV') && APP_ENV === 'production') {
    http_response_code(403);
    die('Accesso disabilitato quando APP_ENV=production.');
}

$sqlFile = APP_ROOT_DIR . '/db/init_mysql.sql';

$host     = $_POST['host'] ?? DB_MYSQL_HOST;
$dbname   = $_POST['dbname'] ?? DB_MYSQL_NAME;
$username = $_POST['username'] ?? DB_MYSQL_USER;
$password = $_POST['password'] ?? DB_MYSQL_PASS;
$error    = null;
$success  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_readable($sqlFile)) {
            throw new Exception("Impossibile leggere il file SQL di inizializzazione in: {$sqlFile}");
        }

        // Connessione preliminare a MySQL senza specificare il database (che potrebbe non esistere)
        $dsn = "mysql:host={$host};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Crea il database se non esiste
        $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbname);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Seleziona il database
        $pdo->exec("USE `{$safeDbName}`");

        // Legge lo script SQL
        $rawSql = file_get_contents($sqlFile);

        // Parsing dello script SQL ed esecuzione istruzione per istruzione
        $statements = [];
        $buf        = '';
        $len        = strlen($rawSql);
        $i          = 0;
        $inString   = null;

        while ($i < $len) {
            $ch = $rawSql[$i];

            // Commento a riga singola (-- o #)
            if ($inString === null) {
                if (($ch === '-' && isset($rawSql[$i + 1]) && $rawSql[$i + 1] === '-') || $ch === '#') {
                    while ($i < $len && $rawSql[$i] !== "\n" && $rawSql[$i] !== "\r") {
                        $i++;
                    }
                    continue;
                }
            }

            // Inizio/fine stringa
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                if ($inString === null) {
                    $inString = $ch;
                } elseif ($inString === $ch) {
                    // Controllo escape
                    if (isset($rawSql[$i + 1]) && $rawSql[$i + 1] === $ch) {
                        $buf .= $ch . $rawSql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $inString = null;
                }
            }

            // Separatore di statement
            if ($inString === null && $ch === ';') {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buf = '';
                $i++;
                continue;
            }

            $buf .= $ch;
            $i++;
        }

        $stmt = trim($buf);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        // Avvia una transazione per l'importazione
        $pdo->beginTransaction();
        foreach ($statements as $s) {
            $pdo->exec($s);
        }
        $pdo->commit();

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
    <title>Inizializzazione Database MySQL - Quack ERP</title>
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
    <h1>🦆 Quack ERP — Setup MySQL</h1>
    
    <?php if ($error !== null): ?>
        <div class="alert alert-error">
            <strong>Errore di installazione:</strong><br>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>✅ Database creato con successo!</strong><br>
            Il database <strong><?= htmlspecialchars($dbname) ?></strong> è stato configurato e popolato.<br><br>
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
            Questo script crea il database MySQL specificato ed esegue le query presenti in <code>db/init_mysql.sql</code>.
            Se il database esiste già con le tabelle già presenti, le query <code>CREATE TABLE IF NOT EXISTS</code>
            non le modificano: per applicare uno schema aggiornato a un database esistente serve una migrazione
            dedicata, non questo script.
        </p>
        <form method="post">
            <div class="form-group">
                <label for="host">MySQL Host</label>
                <input type="text" id="host" name="host" value="<?= htmlspecialchars($host) ?>" required>
            </div>
            <div class="form-group">
                <label for="dbname">Nome Database</label>
                <input type="text" id="dbname" name="dbname" value="<?= htmlspecialchars($dbname) ?>" required>
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" value="<?= htmlspecialchars($password) ?>">
            </div>
            <button type="submit">Crea e Popola Database</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
