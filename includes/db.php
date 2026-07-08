<?php
/**
 * db.php — Bootstrap connessione PDO (dispatcher sqlite/mysql) + auto-creazione schema
 *
 * Il motore attivo è deciso da DB_DRIVER in config.php:
 *   - 'sqlite' (oggi): file esterno in DB_DIR/database.sqlite. Se non esiste
 *     (o è vuoto), esegue db/init_sqlite.sql statement per statement.
 *   - 'mysql' (quando pronto): si connette al server MySQL/MariaDB configurato
 *     tramite le costanti DB_MYSQL_*. Il DB va creato/popolato una volta con
 *     db/crea_db.php (o una migrazione esplicita) — qui non c'è auto-setup.
 *
 * In entrambi i casi il resto dell'app continua a usare $pdo esattamente
 * come prima: stesso ERRMODE, stesso FETCH_MODE.
 */

switch (DB_DRIVER) {

    case 'mysql':
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_MYSQL_HOST,
            DB_MYSQL_PORT,
            DB_MYSQL_NAME,
            DB_MYSQL_CHARSET
        );

        try {
            $pdo = new PDO($dsn, DB_MYSQL_USER, DB_MYSQL_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $msg = APP_ENV === 'development'
                ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                : 'Errore interno. Contatta il supporto.';
            die('<b>Errore database:</b> ' . $msg);
        }
        break;

    case 'sqlite':
    default:
        $db_path = DB_DIR . '/database.sqlite';

        try {
            $pdo = new PDO('sqlite:' . $db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Foreign keys devono essere abilitati per ogni connessione (solo SQLite:
            // in MySQL/InnoDB sono sempre attive, questa riga si applica solo qui)
            $pdo->exec('PRAGMA foreign_keys = ON;');

            // ── Auto-setup: crea lo schema se la tabella utenti non esiste ──────────
            $exists = $pdo
                ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='utenti'")
                ->fetchColumn();

            if (!$exists) {
                $sql_file = APP_ROOT_DIR . '/db/init_sqlite.sql';
                if (!is_readable($sql_file)) {
                    die('<b>Errore setup DB:</b> impossibile leggere <code>db/init_sqlite.sql</code>.');
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
                $in_string  = null;
                $in_trigger = false;

                while ($i < $len) {
                    $ch = $raw[$i];

                    // Commento inline: -- fino a fine riga
                    if ($in_string === null && $ch === '-' && isset($raw[$i + 1]) && $raw[$i + 1] === '-') {
                        while ($i < $len && $raw[$i] !== "\n" && $raw[$i] !== "\r") {
                            $i++;
                        }
                        continue;
                    }

                    // Inizio stringa o delimitatore
                    if ($in_string === null && ($ch === "'" || $ch === '"' || $ch === '`')) {
                        $in_string = $ch;
                        $buf .= $ch;
                        $i++;
                        continue;
                    }

                    // Fine stringa
                    if ($in_string !== null && $ch === $in_string) {
                        $buf .= $ch;
                        if (isset($raw[$i + 1]) && $raw[$i + 1] === $in_string) {
                            $buf .= $raw[$i + 1];
                            $i += 2;
                            continue;
                        }
                        $in_string = null;
                        $i++;
                        continue;
                    }

                    // Separatore di statement (fuori da stringhe)
                    if ($in_string === null) {
                        $trimmed = trim(strtolower($buf));
                        if (substr($trimmed, -5) === 'begin') {
                            $in_trigger = true;
                        }

                        if ($ch === ';') {
                            if ($in_trigger) {
                                if (preg_match('/\bend\s*$/i', $trimmed)) {
                                    $in_trigger = false;
                                }
                            }

                            if (!$in_trigger) {
                                $stmt = trim($buf);
                                if ($stmt !== '') {
                                    $statements[] = $stmt;
                                }
                                $buf = '';
                                $i++;
                                continue;
                            }
                        }
                    }

                    $buf .= $ch;
                    $i++;
                }

                $stmt = trim($buf);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }

                foreach ($statements as $stmt) {
                    $pdo->exec($stmt);
                }
            }

        } catch (PDOException $e) {
            $msg = APP_ENV === 'development'
                ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                : 'Errore interno. Contatta il supporto.';
            die('<b>Errore database:</b> ' . $msg);
        }
        break;
}
