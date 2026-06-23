<?php
/**
 * Migration 006 — indirizzi_contatti_snapshot
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Crea la tabella indirizzi_documenti (snapshot indirizzo consegna).
 * 2. Crea la tabella contatti_documenti (snapshot contatto referente).
 * 3. Aggiunge le colonne necessarie alle testate:
 *    - offrete_testata: aggiunge indirizzo_consegna_id se non presente.
 * 4. Migra storicamente le righe di rfq_testata, offerte_testata, ordini_testata.
 *    Per ogni riga, sposta i dati denormalizzati di indirizzo e contatto nelle
 *    nuove tabelle di snapshot e aggiorna le relative chiavi esterne.
 * 5. Pulisce/aggiorna i vincoli di integrità.
 *
 * Eseguire da riga di comando:
 *   php db/migrations/006_indirizzi_contatti_snapshot.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';

$dsn = 'sqlite:' . APP_ROOT_DIR . '/db/database.sqlite';
$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('PRAGMA journal_mode = WAL');

$log = [];

function tableExists(PDO $pdo, string $name): bool {
    return (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$name}'")->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $col): bool {
    $cols = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    return in_array($col, $cols, true);
}

// ── 1. CREAZIONE TABELLA INDIRIZZI_DOCUMENTI ────────────────────────────────
if (!tableExists($pdo, 'indirizzi_documenti')) {
    $pdo->exec('CREATE TABLE indirizzi_documenti (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        indirizzo_master_id INTEGER REFERENCES indirizzi(id) ON DELETE SET NULL,
        nome_sede VARCHAR(100),
        presso VARCHAR(100),
        via VARCHAR(150) NOT NULL,
        civico VARCHAR(10),
        cap VARCHAR(10) NOT NULL,
        citta VARCHAR(100) NOT NULL,
        provincia CHAR(2),
        nazione CHAR(2) NOT NULL DEFAULT "IT",
        referente_consegna VARCHAR(80),
        telefono_consegna VARCHAR(20),
        note_consegna VARCHAR(255),
        personalizzato BOOLEAN NOT NULL DEFAULT 0,
        hash_originale VARCHAR(64)
    )');
    $log[] = '✓ Tabella indirizzi_documenti creata.';
} else {
    $log[] = '— Tabella indirizzi_documenti già esistente.';
}

// ── 2. CREAZIONE TABELLA CONTATTI_DOCUMENTI ─────────────────────────────────
if (!tableExists($pdo, 'contatti_documenti')) {
    $pdo->exec('CREATE TABLE contatti_documenti (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contatto_master_id INTEGER REFERENCES clienti_fornitori(id) ON DELETE SET NULL,
        nome VARCHAR(80),
        cognome VARCHAR(80),
        email VARCHAR(150),
        telefono VARCHAR(20),
        cellulare VARCHAR(20),
        personalizzato BOOLEAN NOT NULL DEFAULT 0,
        hash_originale VARCHAR(64)
    )');
    $log[] = '✓ Tabella contatti_documenti creata.';
} else {
    $log[] = '— Tabella contatti_documenti già esistente.';
}

// ── 3. COLONNE NELLA TESTATA DELLE OFFERTE ─────────────────────────────────
if (!columnExists($pdo, 'offerte_testata', 'indirizzo_consegna_id')) {
    $pdo->exec('ALTER TABLE offerte_testata ADD COLUMN indirizzo_consegna_id INTEGER REFERENCES indirizzi_documenti(id) ON DELETE SET NULL');
    $log[] = '✓ Colonna offerte_testata.indirizzo_consegna_id aggiunta.';
} else {
    $log[] = '— Colonna offerte_testata.indirizzo_consegna_id già esistente.';
}

// ── 4. MIGRAZIONE STORICA DATI DENORMALIZZATI ────────────────────────────────
function generateAddressHash(?array $addr): ?string {
    if (!$addr) return null;
    $parts = [
        trim((string)($addr['nome_sede'] ?? '')),
        trim((string)($addr['via'] ?? '')),
        trim((string)($addr['civico'] ?? '')),
        trim((string)($addr['cap'] ?? '')),
        trim((string)($addr['citta'] ?? '')),
        trim((string)($addr['provincia'] ?? '')),
        trim((string)($addr['nazione'] ?? 'IT'))
    ];
    $raw = strtolower(implode('|', $parts));
    return hash('sha256', $raw);
}

function generateContactHash(?array $cont): ?string {
    if (!$cont) return null;
    $parts = [
        trim((string)($cont['nome'] ?? '')),
        trim((string)($cont['cognome'] ?? '')),
        trim((string)($cont['email'] ?? '')),
        trim((string)($cont['telefono'] ?? ''))
    ];
    $raw = strtolower(implode('|', $parts));
    return hash('sha256', $raw);
}

// Migrazione RFQ, Offerte ed Ordini
$tabelle = ['rfq_testata', 'offerte_testata', 'ordini_testata'];

foreach ($tabelle as $tabella) {
    $rows = $pdo->query("SELECT * FROM {$tabella}")->fetchAll();
    $migratedCount = 0;
    
    foreach ($rows as $row) {
        $indirizzoDocId = null;
        $contattoDocId = null;
        
        // 1. Migra Indirizzo se presente via
        if (!empty($row['consegna_via'])) {
            $masterAddrId = isset($row['indirizzo_consegna_id']) ? (int)$row['indirizzo_consegna_id'] : null;
            $masterHash = null;
            $personalizzato = 0;
            
            if ($masterAddrId > 0) {
                $mAddr = $pdo->query("SELECT * FROM indirizzi WHERE id = {$masterAddrId}")->fetch();
                if ($mAddr) {
                    $masterHash = generateAddressHash($mAddr);
                    $currentHash = generateAddressHash([
                        'nome_sede' => $row['consegna_presso'] ?? '',
                        'via' => $row['consegna_via'] ?? '',
                        'civico' => $row['consegna_civico'] ?? '',
                        'cap' => $row['consegna_cap'] ?? '',
                        'citta' => $row['consegna_citta'] ?? '',
                        'provincia' => $row['consegna_provincia'] ?? '',
                        'nazione' => $row['consegna_nazione'] ?? 'IT'
                    ]);
                    if ($masterHash !== $currentHash) {
                        $personalizzato = 1;
                    }
                }
            }
            
            $stmt = $pdo->prepare('INSERT INTO indirizzi_documenti 
                (indirizzo_master_id, nome_sede, presso, via, civico, cap, citta, provincia, nazione, referente_consegna, telefono_consegna, note_consegna, personalizzato, hash_originale)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $masterAddrId > 0 ? $masterAddrId : null,
                $row['consegna_presso'] ?? null,
                $row['consegna_presso'] ?? null,
                $row['consegna_via'],
                $row['consegna_civico'] ?? null,
                $row['consegna_cap'],
                $row['consegna_citta'],
                $row['consegna_provincia'] ?? null,
                $row['consegna_nazione'] ?? 'IT',
                $row['consegna_referente'] ?? null,
                $row['consegna_telefono'] ?? null,
                $row['consegna_note'] ?? null,
                $personalizzato,
                $masterHash
            ]);
            $indirizzoDocId = (int)$pdo->lastInsertId();
        }
        
        // 2. Migra Contatto
        if (!empty($row['contatto_nome']) || !empty($row['contatto_email'])) {
            $masterContId = isset($row['contatto_id']) ? (int)$row['contatto_id'] : null;
            $masterHash = null;
            $personalizzato = 0;
            
            if ($masterContId > 0) {
                $mCont = $pdo->query("SELECT * FROM clienti_fornitori WHERE id = {$masterContId}")->fetch();
                if ($mCont) {
                    $masterHash = generateContactHash($mCont);
                    // Split del nome del contatto se presente
                    $nomeParts = explode(' ', trim((string)($row['contatto_nome'] ?? '')), 2);
                    $cNome = $nomeParts[0] ?? '';
                    $cCognome = $nomeParts[1] ?? '';
                    
                    $currentHash = generateContactHash([
                        'nome' => $cNome,
                        'cognome' => $cCognome,
                        'email' => $row['contatto_email'] ?? '',
                        'telefono' => $row['contatto_telefono'] ?? ''
                    ]);
                    if ($masterHash !== $currentHash) {
                        $personalizzato = 1;
                    }
                }
            }
            
            $nomeParts = explode(' ', trim((string)($row['contatto_nome'] ?? '')), 2);
            $cNome = $nomeParts[0] ?? '';
            $cCognome = $nomeParts[1] ?? '';
            
            $stmt = $pdo->prepare('INSERT INTO contatti_documenti 
                (contatto_master_id, nome, cognome, email, telefono, cellulare, personalizzato, hash_originale)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $masterContId > 0 ? $masterContId : null,
                $cNome,
                $cCognome,
                $row['contatto_email'] ?? null,
                $row['contatto_telefono'] ?? null,
                null,
                $personalizzato,
                $masterHash
            ]);
            $contattoDocId = (int)$pdo->lastInsertId();
        }
        
        // Aggiorna la riga della testata
        $updateStmt = $pdo->prepare("UPDATE {$tabella} SET indirizzo_consegna_id = ?, contatto_id = ? WHERE id = ?");
        $updateStmt->execute([$indirizzoDocId, $contattoDocId, $row['id']]);
        $migratedCount++;
    }
    $log[] = "✓ Migrati {$migratedCount} record per la tabella {$tabella}.";
}

$pdo->exec('PRAGMA foreign_keys = ON');

echo implode("\n", $log) . "\nMigration completata con successo!\n";
