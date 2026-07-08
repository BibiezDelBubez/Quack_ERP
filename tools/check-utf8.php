<?php
/**
 * tools/check-utf8.php — Verifica che i file passati come argomento siano UTF-8 valido.
 *
 * PERCHÉ ESISTE:
 * In db/init_sqlite.sql (zona ricezioni_righe) è stato trovato un blocco di
 * commenti con caratteri accentati corrotti ("comodit\xFFFD" invece di
 * "comodità", ecc.) — quasi certamente testo incollato da una fonte salvata in
 * Windows-1252/Latin-1 dentro un file che deve restare UTF-8. L'errore non dà
 * segnali evidenti in molti editor, e in SQLite passa silenzioso perché era
 * dentro un commento "--", quindi può restare inosservato per mesi.
 *
 * COSA FA:
 * Controlla che ogni file passato come argomento sia UTF-8 valido, e segnala
 * anche i file che sono UTF-8 valido ma contengono già il carattere di
 * sostituzione U+FFFD (segno che una perdita di encoding è già avvenuta in
 * passato, anche se il file risulta "tecnicamente" valido ora).
 *
 * USO MANUALE (in qualunque momento, non solo da un hook):
 *   php tools/check-utf8.php db/init_sqlite.sql db/init_mysql.sql
 *   php tools/check-utf8.php $(git ls-files '*.php' '*.sql' '*.js')   -- scansione intera repo
 *
 * USO AUTOMATICO:
 * Richiamato dal git hook pre-commit (.githooks/pre-commit) sui soli file
 * "staged" per il commit, così una corruzione viene bloccata PRIMA di entrare
 * nella cronologia invece di essere scoperta mesi dopo. Vedi README del
 * repository per come attivare l'hook (git config core.hooksPath .githooks).
 *
 * USCITA: 0 se tutti i file sono UTF-8 valido e senza U+FFFD, 1 altrimenti
 * (con elenco dei file/righe incriminati stampato su stdout).
 */

$files = array_slice($argv, 1);

if (empty($files)) {
    fwrite(STDERR, "Uso: php tools/check-utf8.php <file1> [file2] ...\n");
    exit(0); // nessun file da controllare non è un errore
}

$hasErrors = false;

foreach ($files as $file) {
    if (!is_file($file) || !is_readable($file)) {
        continue;
    }

    // Limitati ai file di testo che il progetto tratta come sorgente:
    // evita falsi positivi su immagini, PDF, font, ecc. se qualcuno passa
    // per errore un glob troppo ampio.
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $textExtensions = ['php', 'sql', 'js', 'css', 'html', 'md', 'json', 'txt', 'ini', 'yml', 'yaml'];
    if (!in_array($ext, $textExtensions, true)) {
        continue;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        continue;
    }

    if (!mb_check_encoding($content, 'UTF-8')) {
        $hasErrors = true;
        echo "✗ Encoding non valido (non UTF-8): {$file}\n";

        $lines = preg_split("/\r\n|\n|\r/", $content);
        foreach ($lines as $i => $line) {
            if (!mb_check_encoding($line, 'UTF-8')) {
                echo "    riga " . ($i + 1) . "\n";
            }
        }
        continue;
    }

    if (strpos($content, "\xEF\xBF\xBD") !== false) {
        $hasErrors = true;
        echo "✗ Trovato carattere di sostituzione U+FFFD (probabile corruzione di encoding già avvenuta): {$file}\n";

        $lines = preg_split("/\r\n|\n|\r/", $content);
        foreach ($lines as $i => $line) {
            if (strpos($line, "\xEF\xBF\xBD") !== false) {
                echo "    riga " . ($i + 1) . "\n";
            }
        }
    }
}

if ($hasErrors) {
    echo "\nCommit bloccato: correggi l'encoding dei file sopra (devono essere UTF-8, senza BOM) prima di continuare.\n";
    echo "Suggerimento: nel tuo editor, verifica/imposta esplicitamente 'UTF-8' come encoding di salvataggio\n";
    echo "per questi file (non 'ANSI'/'Windows-1252'), poi ri-salva e ricontrolla.\n";
    exit(1);
}

exit(0);
