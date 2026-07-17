<?php
declare(strict_types=1);

$jsonPath = __DIR__ . '/../audit/audit-journal.json';
$initDbPath = __DIR__ . '/../db/init_mysql.sql';

if (!file_exists($jsonPath)) {
    die("JSON non trovato.\n");
}

$json = json_decode(file_get_contents($jsonPath), true);
$openTasks = $json['open_tasks'] ?? [];

$newOpenTasks = [];
$nonProblems = []; // Tasks/Questions
$falsePositivesCount = 0;

$initDbContent = file_exists($initDbPath) ? file_get_contents($initDbPath) : '';

foreach ($openTasks as $entry) {
    if (preg_match('/^`([^`]+)`:\s*(.*)$/', $entry, $matches)) {
        $path = $matches[1];
        $text = $matches[2];

        // 1. E' un problema o una domanda di verifica?
        $isQuestion = preg_match('/Esiste in DB Live|Ogni campo.*?richiamato nel codice/i', $text);
        if ($isQuestion) {
            $nonProblems[] = $entry;
            $newOpenTasks[] = $entry; // Lo manteniamo nei task, o possiamo spostarlo. Il requisito dice "vedere e scriverlo" e "rimane".
            continue;
        }

        // 2. Controllo Esenzione Assoluta Vendor/Tools
        $isVendor = preg_match('/(assets\/fontawesome\/|\.min\.js$|\.min\.css$|^tools\/|exceljs)/i', $path);
        if ($isVendor) {
            $falsePositivesCount++;
            continue; // FALSE POSITIVE
        }

        // Determiniamo il percorso fisico se è un file
        $physicalPath = __DIR__ . '/../' . $path;
        $fileExists = file_exists($physicalPath) && is_file($physicalPath);
        $fileContent = $fileExists ? file_get_contents($physicalPath) : '';

        // 3. Bad Practices (@, isset)
        if (str_contains($text, 'Uso di @, isset')) {
            if ($fileExists) {
                // Rimuoviamo stringhe tra apici o virgolette e commenti
                $codeWithoutStrings = preg_replace('/\'[^\']*\'|"[^"]*"/', '', $fileContent);
                $codeWithoutStrings = preg_replace('#//.*|/\*.*?\*/#s', '', $codeWithoutStrings);
                if (!str_contains($codeWithoutStrings, '@')) {
                    $falsePositivesCount++;
                    continue; // FALSE POSITIVE
                }
            }
        }

        // 4. Try-catch vuoti
        if (str_contains($text, 'Try-catch vuoti') || str_contains($text, 'Try..catch vuoti')) {
            if ($fileExists) {
                if (!preg_match('/\bcatch\b/i', $fileContent)) {
                    $falsePositivesCount++;
                    continue; // FALSE POSITIVE
                }
            }
        }

        // 5. Float per Valuta
        if (str_contains($text, 'Float per valuta')) {
            if ($fileExists) {
                if (!preg_match('/\b(parseFloat|floatval|Math\.)\b/i', $fileContent)) {
                    $falsePositivesCount++;
                    continue; // FALSE POSITIVE
                }
            }
        }

        // 6. Coerenza Tipi DB
        if (str_contains($text, 'Verifica coerenza tipi DB')) {
            // $path is the table name
            $tableName = $path;
            if ($initDbContent !== '') {
                // Cerchiamo la definizione della tabella
                $pattern = '/CREATE TABLE\s+(IF NOT EXISTS\s+)?' . preg_quote($tableName, '/') . '\s*\((.*?)\)\s*(ENGINE|;)/is';
                if (preg_match($pattern, $initDbContent, $m)) {
                    $tableDDL = $m[2];
                    if (!preg_match('/\b(DECIMAL|FLOAT|DOUBLE|REAL|NUMERIC)\b/i', $tableDDL)) {
                        $falsePositivesCount++;
                        continue; // FALSE POSITIVE
                    }
                }
            }
        }

        // Nessun criterio ha smentito matematicamente la segnalazione
        $newOpenTasks[] = $entry;
    } else {
        // Formato sconosciuto, lo manteniamo
        $newOpenTasks[] = $entry;
    }
}

$json['open_tasks'] = $newOpenTasks;
$json['tasks_di_verifica_documentati'] = $nonProblems; // Aggiungiamo un array doc

file_put_contents($jsonPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Analisi Paranoica Completata.\n";
echo "Falsi positivi inconfutabili eliminati: $falsePositivesCount\n";
echo "Task rimanenti (inclusi quelli di verifica): " . count($newOpenTasks) . "\n";
echo "Di cui task esplorativi (non problemi): " . count($nonProblems) . "\n";
