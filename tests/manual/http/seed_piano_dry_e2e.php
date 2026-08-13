<?php
declare(strict_types=1);

/**
 * tests/manual/http/seed_piano_dry_e2e.php
 *
 * PIANO - Correzione DRY-MVC Viste Documenti Vendita e Acquisto.md, §5.2.
 *
 * Seme minimo per il confronto "prima/dopo" delle 3 pagine di CREAZIONE
 * documento (Ordine Cliente diretto, RFQ, Preventivo Vendita): a differenza
 * di seed_piano11_e2e.php (che semina ordini interi per testare la vista di
 * DETTAGLIO), qui non serve nessun cliente/fornitore/prodotto/documento
 * esistente — le pagine di creazione sono form vuoti, i controller
 * (SalesOrdersController::create(), RfqController::create(),
 * SalesQuotesController::create(), verificati in questa sessione) tollerano
 * tabelle anagrafiche vuote (foreach su array vuoto, nessun crash). Serve
 * solo un utente con permesso 'manage_orders' per superare il login e il
 * controllo di rotta (app/routes.php:38,54,180).
 *
 * Uso: DB_DIR deve essere già definita (dallo script chiamante, PRIMA di
 * questo require) — stesso identico pattern di seed_piano11_e2e.php.
 *
 * Scrive $SCRATCH/fixtures.env con USERNAME/PASSWORD.
 */

if (!defined('DB_DIR')) {
    fwrite(STDERR, "ERRORE: DB_DIR non definita prima di questo script. Vedi il commento in cima al file.\n");
    exit(1);
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/db.php';

/** @var PDO $pdo definita da includes/db.php */

$pdo->beginTransaction();

// ── Ruolo con permesso 'manage_orders' (includes/functions.php:can(), mappa
//    hardcoded su ruoli.codice = 'admin' — stesso pattern di
//    seed_piano11_e2e.php, verificato ancora valido in questa sessione). ────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli WHERE codice = 'admin'")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (codice, nome) VALUES ('admin', 'Amministratore Test PIANO DRY')");
    $ruoloId = (int) $pdo->lastInsertId();
}

$username = 'piano_dry_tester';
$password = 'Test1234!PianoDry';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$pdo->exec("DELETE FROM utenti WHERE username = " . $pdo->quote($username));
$pdo->prepare("INSERT INTO utenti (username, nome, cognome, email, password_hash, ruolo_id, attivo)
               VALUES (?, 'Test', 'PianoDry', 'piano_dry_tester@test.invalid', ?, ?, 1)")
    ->execute([$username, $passwordHash, $ruoloId]);

// ── Azienda interna minima, cosi' i select "Azienda venditrice/acquirente"
//    non sono vuoti nell'HTML (piu' fedele a un uso reale, non indispensabile
//    per evitare crash — qk_aziende_list_con_default() tollera comunque zero
//    righe, verificato in includes/anagrafiche.php:1554-1572 in questa
//    sessione). aziende_interne NON ha una colonna ragione_sociale propria:
//    punta con soggetto_id (NOT NULL UNIQUE) a un record clienti_fornitori
//    (db/init_sqlite.sql:340-362,463-485, riletto per intero in questa
//    sessione) — stesso pattern a due INSERT già usato da
//    tests/manual/suite_mittente_ddt.php e suite_sales_quote_pdf_docx_service.php. ──
$pdo->exec("INSERT INTO clienti_fornitori (codice, tipo, tipo_soggetto, ragione_sociale)
            VALUES ('AZ-DRY', 'entrambi', 'azienda', 'Azienda Test PIANO DRY')");
$soggettoAziendaId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO aziende_interne (soggetto_id, predefinita) VALUES (?, 1)")
    ->execute([$soggettoAziendaId]);

$pdo->commit();

$scratch = rtrim(dirname(DB_DIR), '/');
$lines = [];
$lines[] = "USERNAME={$username}";
$lines[] = "PASSWORD={$password}";
file_put_contents($scratch . '/fixtures.env', implode("\n", $lines) . "\n");

echo "Seed completato: utente '{$username}' pronto, 1 azienda interna creata.\n";
