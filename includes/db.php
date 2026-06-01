<?php
/**
 * db.php — Bootstrap connessione PDO + auto-creazione schema
 *
 * Se database.sqlite non esiste (o è vuoto), esegue db/init.sql
 * statement per statement in modo sicuro e affidabile.
 *
 * Non serve eseguire crea_db.php manualmente: questo file
 * fa tutto in automatico alla prima richiesta dell'app.
 */

$db_path = APP_ROOT_DIR . '/db/database.sqlite';

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Foreign keys devono essere abilitati per ogni connessione
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // ── Auto-setup: crea lo schema se la tabella utenti non esiste ──────────
    $exists = $pdo
        ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='utenti'")
        ->fetchColumn();

    if (!$exists) {
        $sql_file = APP_ROOT_DIR . '/db/init.sql';
        if (!is_readable($sql_file)) {
            die('<b>Errore setup DB:</b> impossibile leggere <code>db/init.sql</code>.');
        }

        $raw = file_get_contents($sql_file);

        /*
         * Suddivide il testo SQL in singoli statement eseguibili.
         *
         * Gestisce correttamente:
         *  - stringhe delimitate da ' o " (incluso l'escape '' e "")
         *  - commenti a riga intera (--) e inline (-- dopo codice)
         *  - ';' dentro stringhe (non trattato come separatore)
         */
        $statements = [];
        $buf        = '';
        $len        = strlen($raw);
        $i          = 0;

        while ($i < $len) {
            $ch = $raw[$i];

            // Commento inline: -- fino a fine riga
            if (!isset($in_string) && $ch === '-' && isset($raw[$i + 1]) && $raw[$i + 1] === '-') {
                while ($i < $len && $raw[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Inizio stringa
            if (!isset($in_string) && ($ch === "'" || $ch === '"')) {
                $in_string = $ch;
                $buf .= $ch;
                $i++;
                continue;
            }

            // Fine stringa (con gestione escape '' e "")
            if (isset($in_string) && $ch === $in_string) {
                $buf .= $ch;
                // Controlla se è un escape (apostrofo raddoppiato)
                if (isset($raw[$i + 1]) && $raw[$i + 1] === $in_string) {
                    $buf .= $raw[$i + 1];
                    $i += 2;
                    continue;
                }
                unset($in_string);
                $i++;
                continue;
            }

            // Separatore di statement (fuori da stringhe)
            if (!isset($in_string) && $ch === ';') {
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

        // Eventuale residuo senza punto e virgola finale
        $stmt = trim($buf);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        foreach ($statements as $stmt) {
            $pdo->exec($stmt . ';');
        }
    }

} catch (PDOException $e) {
    $msg = APP_ENV === 'development'
        ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        : 'Errore interno. Contatta il supporto.';
    die('<b>Errore database:</b> ' . $msg);
}
