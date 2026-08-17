<?php
/**
 * tools/check-utf8.php — Verifica che i file passati come argomento siano UTF-8 valido
 * E che non contengano mojibake da doppia codifica (vedi sotto).
 *
 * PERCHÉ ESISTE:
 * In db/init_sqlite.sql (zona ricezioni_righe) è stato trovato un blocco di
 * commenti con caratteri accentati corrotti ("comodit\xFFFD" invece di
 * "comodità", ecc.) — quasi certamente testo incollato da una fonte salvata in
 * Windows-1252/Latin-1 dentro un file che deve restare UTF-8. L'errore non dà
 * segnali evidenti in molti editor, e in SQLite passa silenzioso perché era
 * dentro un commento "--", quindi può restare inosservato per mesi.
 *
 * ESTENSIONE DEL 23/07/2026 — RILEVAMENTO MOJIBAKE (CLAUDE.md regola 10 /
 * AGENTS.md "Regola sulla Codifica dei File"): un audit a tappeto del
 * progetto ha trovato 306+ istanze di un problema DIVERSO e più subdolo, in
 * 4 file (assets/js/products-form.js, includes/prodotti.php e due file in
 * audit/): testo che era UTF-8 corretto, riletto una volta come CP1252 (o
 * Latin-1) e poi ri-salvato come UTF-8. Il file risultante è UTF-8
 * PERFETTAMENTE VALIDO (quindi il controllo mb_check_encoding() sopra non lo
 * vede) — solo il testo è sbagliato ("è" diventa "è", "—" diventa "—",
 * ecc.). Il controllo isVerifiedMojibake() sotto individua candidati (una
 * lettera 'Ã'/'Â' o il prefisso 'â€' seguiti da un carattere qualunque) e li
 * accetta SOLO se il round-trip CP1252<->UTF-8 è byte-per-byte reversibile:
 * questo esclude sistematicamente i falsi positivi (una vera lettera 'Ã'
 * isolata nel testo non supererebbe il round-trip).
 *
 * ESTENSIONE DEL 17/08/2026 — AMPLIAMENTO DEL PATTERN CANDIDATO: il pattern
 * precedente cercava solo il prefisso letterale 'â€' (cioè assumeva che il
 * SECONDO carattere mojibake fosse sempre '€', vero solo per l'em-dash
 * U+2014 e pochi altri casi con byte centrale originale 0x80). Qualunque
 * carattere Unicode nel blocco U+2000-U+2FFF (punteggiatura tipografica,
 * frecce, caratteri per disegnare box/tabelle, operatori matematici, ecc.)
 * produce comunque una corruzione che INIZIA con 'â' (tutti condividono lo
 * stesso primo byte UTF-8 0xE2, che CP1252 rimappa a 'â'), ma il secondo
 * carattere mojibake varia col byte centrale originale — es. i caratteri
 * per disegnare box (U+2550 ecc., byte centrale 0x95) producono 'â•' non
 * 'â€'. Il pattern stretto lasciava passare questi casi senza nemmeno
 * proporli a isVerifiedMojibake() per la verifica: scoperto perché 560
 * occorrenze di questo tipo in assets/js/products-form.js non venivano
 * segnalate da questo stesso script. Il pattern ora è 'â' + due caratteri
 * qualunque; la verifica di round-trip (invariata) scarta comunque i
 * candidati che non sono mojibake reale.
 *
 * COSA FA:
 * 1. Controlla che ogni file passato come argomento sia UTF-8 valido.
 * 2. Segnala i file che sono UTF-8 valido ma contengono già il carattere di
 *    sostituzione U+FFFD (segno che una perdita di encoding è già avvenuta
 *    in passato, anche se il file risulta "tecnicamente" valido ora).
 * 3. Segnala i file con mojibake da doppia codifica verificato (vedi sopra).
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
 * USCITA: 0 se tutti i file sono UTF-8 valido, senza U+FFFD e senza mojibake
 * verificato; 1 altrimenti (con elenco dei file/righe incriminati stampato
 * su stdout).
 */

/**
 * Ritorna il carattere/sequenza corretta se $candidate è mojibake verificato
 * (round-trip CP1252<->UTF-8 byte-per-byte reversibile), altrimenti null.
 *
 * Stessa identica logica (e stessa soglia di sicurezza) di
 * scripts/check_utf8_encoding.py::is_verified_mojibake() usato per l'audit
 * del 23/07/2026 — le due implementazioni NON devono divergere silenziosamente
 * su cosa conta come mojibake, quindi se una viene toccata va allineata anche
 * l'altra.
 */
function isVerifiedMojibake(string $candidate): ?string
{
    $asCp1252Bytes = @mb_convert_encoding($candidate, 'Windows-1252', 'UTF-8');
    if ($asCp1252Bytes === false || $asCp1252Bytes === '') {
        return null;
    }
    if (!mb_check_encoding($asCp1252Bytes, 'UTF-8')) {
        return null;
    }
    $correctedUtf8 = $asCp1252Bytes;
    if ($correctedUtf8 === $candidate) {
        return null; // nessuna differenza reale: non è un candidato utile
    }
    $reinterpreted = @mb_convert_encoding($correctedUtf8, 'UTF-8', 'Windows-1252');
    if ($reinterpreted !== $candidate) {
        return null; // round-trip non reversibile: troppo rischioso, si scarta
    }
    return $correctedUtf8;
}

/**
 * Individua i candidati mojibake in un blocco di testo UTF-8 valido e
 * ritorna solo quelli verificati da isVerifiedMojibake(), con il numero di
 * riga (1-based) della prima occorrenza di ciascun candidato distinto.
 *
 * @return array<string, int> candidato => numero di riga della prima occorrenza
 */
function findVerifiedMojibake(string $content): array
{
    // Candidati: 'Ã'/'Â' (primo byte UTF-8 di un carattere Latin-1 Supplement
    // doppio-codificato, un solo carattere mojibake successivo) oppure 'â'
    // seguita da DUE caratteri qualunque (primo byte UTF-8 comune a tutta la
    // punteggiatura tipografica/frecce/box-drawing U+2000-U+2FFF doppio-
    // codificata — vedi ESTENSIONE DEL 17/08/2026 in cima al file: non basta
    // assumere che il carattere successivo sia sempre '€', va lasciato
    // libero). Non basta elencare a mano le combinazioni note: il secondo
    // byte originale può cadere nel range CP1252 0x80-0x9F, che CP1252
    // rimappa a caratteri FUORI dal blocco Latin-1 (es. em-dash reale) — la
    // verifica vera è delegata a isVerifiedMojibake(), qui si individuano
    // solo i candidati da testare.
    if (!preg_match_all('/Ã.|Â.|â../us', $content, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $found = [];
    foreach ($matches[0] as [$candidate, $byteOffset]) {
        if (isset($found[$candidate])) {
            continue;
        }
        if (isVerifiedMojibake($candidate) === null) {
            continue;
        }
        $lineNo = substr_count($content, "\n", 0, $byteOffset) + 1;
        $found[$candidate] = $lineNo;
    }
    return $found;
}

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

    // Librerie di terze parti (vendor/, bundle minificati): non è compito né
    // sicurezza di questo tool "correggere" codice esterno, e possono
    // legittimamente contenere byte insoliti (es. una libreria di generazione
    // DOCX che usa U+FFFD di proposito come fallback per caratteri XML non
    // validi — scoperto il 23/07/2026 in assets/js/docx.umd.js, falso
    // positivo, non una corruzione reale). Si esclude per path/nome, non per
    // "sembra minificato", per restare un criterio esplicito e verificabile.
    $normalizedPath = str_replace('\\', '/', $file);
    $isVendored = str_contains($normalizedPath, '/vendor/')
        || str_starts_with($normalizedPath, 'vendor/')
        || (bool) preg_match('/\.min\.js$/i', $normalizedPath)
        || in_array(basename($normalizedPath), ['docx.umd.js', 'bootstrap.bundle.js'], true);
    if ($isVendored) {
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

    $mojibake = findVerifiedMojibake($content);
    if (!empty($mojibake)) {
        $hasErrors = true;
        echo "✗ Mojibake da doppia codifica (UTF-8 riletto come Windows-1252/Latin-1 e ri-salvato come UTF-8): {$file}\n";
        foreach ($mojibake as $candidate => $lineNo) {
            $corrected = isVerifiedMojibake($candidate);
            echo "    riga {$lineNo}: \"{$candidate}\" dovrebbe essere \"{$corrected}\"\n";
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
