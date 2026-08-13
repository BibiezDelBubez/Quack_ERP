<?php
declare(strict_types=1);

/**
 * tests/manual/http/seed_piano11_e2e.php
 *
 * PIANO - Architettura UX Preventivo-Ordine-Spedizione.md, §12 (Layer 2).
 *
 * Semina, in un DB SQLite reale (mai quello di produzione — vedi
 * tools/dev-php-sandbox/README.md, sezione Sicurezza), un utente di test e 8
 * ordini cliente fixture, uno per ognuno dei 6 stati di ordini_clienti.stato
 * più 2 casi limite (spedizioni multiple, quantità frazionarie) — pensati
 * per essere poi richiesti via HTTP reale (login, sessione, CSRF) dallo
 * script orchestratore run_piano11_e2e.sh, che avvia il vero dev server PHP
 * sopra lo stesso DB.
 *
 * Uso: DB_DIR deve essere già definita (dallo script chiamante, PRIMA di
 * questo require) sulla stessa cartella scratch che userà il server HTTP —
 * altrimenti questo seed scriverebbe in un DB diverso da quello che il
 * server legge, e i test troverebbero pagine vuote/404 (stesso principio di
 * tools/dev-php-sandbox/README.md, sezione Sicurezza).
 *
 * Scrive un file $SCRATCH/fixtures.env con KEY=VALUE (username, password in
 * chiaro per il login di test, id di ogni ordine fixture) — leggibile da
 * bash con `source`, nessuno spazio o carattere speciale nei valori.
 */

if (!defined('DB_DIR')) {
    fwrite(STDERR, "ERRORE: DB_DIR non definita prima di questo script. Vedi il commento in cima al file.\n");
    exit(1);
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/sales_orders.php';

/** @var PDO $pdo definita da includes/db.php */

$pdo->beginTransaction();

// ── Ruolo con permesso 'manage_orders' (includes/functions.php:can(), mappa
//    hardcoded su ruoli.codice = 'admin' — deve essere ESATTAMENTE questa
//    stringa, non un codice di comodo come in altre suite del progetto che
//    non passano mai da un vero controllo HTTP di permesso). ────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli WHERE codice = 'admin'")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (codice, nome) VALUES ('admin', 'Amministratore Test PIANO11')");
    $ruoloId = (int) $pdo->lastInsertId();
}

// ── Utente di test, password nota in chiaro solo qui (mai committata altrove:
//    questo file scrive in un DB scratch temporaneo, cancellato a fine test). ──
$username = 'piano11_tester';
$password = 'Test1234!Piano11';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$pdo->exec("DELETE FROM utenti WHERE username = " . $pdo->quote($username));
$pdo->prepare("INSERT INTO utenti (username, nome, cognome, email, password_hash, ruolo_id, attivo)
               VALUES (?, 'Test', 'Piano11', 'piano11_tester@test.invalid', ?, ?, 1)")
    ->execute([$username, $passwordHash, $ruoloId]);
$utenteId = (int) $pdo->lastInsertId();

// ── Anagrafica minima condivisa da tutti gli ordini fixture ─────────────────
$pdo->exec("INSERT INTO clienti_fornitori (codice, tipo, tipo_soggetto, ragione_sociale)
            VALUES ('CLI-T11', 'cliente', 'azienda', 'Cliente Test PIANO11')");
$clienteId = (int) $pdo->lastInsertId();

// via/cap/citta NOT NULL senza default (CLAUDE.md punto 2) — mancavano nel
// primo tentativo di seed di questa sessione, corretto dopo aver riletto lo
// schema per intero invece di fidarsi della lettura parziale precedente.
$pdo->prepare("INSERT INTO indirizzi (soggetto_id, tipo, nome_sede, predefinito, via, cap, citta, provincia)
               VALUES (?, 'spedizione', 'Sede Test PIANO11', 1, 'Via di Test 11', '00100', 'Roma', 'RM')")
    ->execute([$clienteId]);
$indirizzoId = (int) $pdo->lastInsertId();

$umPzId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'pz'")->fetchColumn();
if (!$umPzId) {
    $pdo->exec("INSERT INTO unita_misura (codice, descrizione, e_riferimento) VALUES ('pz', 'Pezzi', 1)");
    $umPzId = (int) $pdo->lastInsertId();
}
$umKgId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'kg'")->fetchColumn();
if (!$umKgId) {
    $pdo->exec("INSERT INTO unita_misura (codice, descrizione, e_riferimento) VALUES ('kg', 'Chilogrammi', 1)");
    $umKgId = (int) $pdo->lastInsertId();
}

$pdo->exec("INSERT INTO magazzini (codice, nome, tipo) VALUES ('MAG-T11', 'Magazzino Test PIANO11', 'fisico')");
$magazzinoId = (int) $pdo->lastInsertId();

// prodotti: 'tipo' e 'unita_misura_stock_id' NOT NULL senza default (CLAUDE.md
// punto 2) — schema riverificato in db/init_sqlite.sql in questa sessione.
$pdo->prepare("INSERT INTO prodotti (sku_interno, nome, tipo, unita_misura_stock_id)
               VALUES ('SKU-T11-PZ', 'Prodotto Test PIANO11 (pezzi)', 'semplice', ?)")
    ->execute([$umPzId]);
$prodottoPzId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO prodotti (sku_interno, nome, tipo, unita_misura_stock_id)
               VALUES ('SKU-T11-KG', 'Prodotto Test PIANO11 (kg, chimico)', 'semplice', ?)")
    ->execute([$umKgId]);
$prodottoKgId = (int) $pdo->lastInsertId();

// ── Helper locali di seed, per non ripetere 8 volte lo stesso boilerplate ──
$oggi = date('Y-m-d');

function seedOrdine(PDO $pdo, array $p): int
{
    $st = $pdo->prepare("
        INSERT INTO ordini_clienti
            (numero_ordine, cliente_id, indirizzo_spedizione_id, magazzino_id,
             data_ordine, stato, stato_fatturazione, creato_da)
        VALUES (:numero, :cliente, :indirizzo, :magazzino, :data, :stato, :fatt, :creato_da)
    ");
    $st->execute([
        ':numero'    => $p['numero'],
        ':cliente'   => $p['cliente_id'],
        ':indirizzo' => $p['indirizzo_id'],
        ':magazzino' => $p['magazzino_id'],
        ':data'      => $p['data_ordine'],
        ':stato'     => $p['stato'],
        ':fatt'      => $p['stato_fatturazione'] ?? 'da_fatturare',
        ':creato_da' => $p['creato_da'],
    ]);
    return (int) $pdo->lastInsertId();
}

function seedRiga(PDO $pdo, int $ordineId, int $prodottoId, int $umId, float $ordinata, float $spedita): int
{
    $st = $pdo->prepare("
        INSERT INTO ordini_clienti_righe
            (ordine_id, prodotto_id, descrizione, quantita_ordinata, quantita_spedita,
             unita_misura_id, prezzo_unitario, costo_unitario)
        VALUES (?, ?, 'Riga di test PIANO11', ?, ?, ?, 10.0000, 5.0000)
    ");
    $st->execute([$ordineId, $prodottoId, $ordinata, $spedita, $umId]);
    return (int) $pdo->lastInsertId();
}

function seedSpedizioneEDdt(PDO $pdo, int $ordineId, int $rigaId, int $prodottoId, int $magazzinoId,
                              int $clienteId, int $indirizzoId, int $utenteId,
                              string $numeroSped, string $numeroDdt, string $data, float $quantita): void
{
    $st = $pdo->prepare("
        INSERT INTO spedizioni (numero_spedizione, ordine_id, data_spedizione, magazzino_id, stato, creato_da)
        VALUES (?, ?, ?, ?, 'spedita', ?)
    ");
    $st->execute([$numeroSped, $ordineId, $data, $magazzinoId, $utenteId]);
    $spedizioneId = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO spedizioni_righe (spedizione_id, ordine_riga_id, prodotto_id, quantita)
        VALUES (?, ?, ?, ?)
    ")->execute([$spedizioneId, $rigaId, $prodottoId, $quantita]);

    $pdo->prepare("
        INSERT INTO documenti_trasporto
            (numero_documento_trasporto, spedizione_id, ordine_id, data,
             destinatario_id, indirizzo_destinazione_id, porto, stato, creato_da)
        VALUES (?, ?, ?, ?, ?, ?, 'franco', 'emesso', ?)
    ")->execute([$numeroDdt, $spedizioneId, $ordineId, $data, $clienteId, $indirizzoId, $utenteId]);
}

$fixtures = [];

// 1. In lavorazione, zero spedito.
$id = seedOrdine($pdo, ['numero' => 'T11-INLAV', 'cliente_id' => $clienteId, 'indirizzo_id' => $indirizzoId,
    'magazzino_id' => $magazzinoId, 'data_ordine' => $oggi, 'stato' => 'in_lavorazione', 'creato_da' => $utenteId]);
seedRiga($pdo, $id, $prodottoPzId, $umPzId, 100, 0);
$fixtures['ORDER_IN_LAVORAZIONE'] = $id;

// 2. Parzialmente spedito, due righe, una completa una parziale.
$id = seedOrdine($pdo, ['numero' => 'T11-PARZ', 'cliente_id' => $clienteId, 'indirizzo_id' => $indirizzoId,
    'magazzino_id' => $magazzinoId, 'data_ordine' => $oggi, 'stato' => 'parzialmente_spedito', 'creato_da' => $utenteId]);
$rigaA = seedRiga($pdo, $id, $prodottoPzId, $umPzId, 100, 100);
$rigaB = seedRiga($pdo, $id, $prodottoPzId, $umPzId, 50, 30);
seedSpedizioneEDdt($pdo, $id, $rigaA, $prodottoPzId, $magazzinoId, $clienteId, $indirizzoId, $utenteId,
    'SP-T11-PARZ-1', 'DDT-T11-PARZ-1', $oggi, 130);
$fixtures['ORDER_PARZIALE'] = $id;

// 3. Tutto spedito, un solo colpo.
$id = seedOrdine($pdo, ['numero' => 'T11-SPED', 'cliente_id' => $clienteId, 'indirizzo_id' => $indirizzoId,
    'magazzino_id' => $magazzinoId, 'data_ordine' => $oggi, 'stato' => 'spedito', 'creato_da' => $utenteId]);
$riga = seedRiga($pdo, $id, $prodottoPzId, $umPzId, 80, 80);
seedSpedizioneEDdt($pdo, $id, $riga, $prodottoPzId, $magazzinoId, $clienteId, $indirizzoId, $utenteId,
    'SP-T11-SPED-1', 'DDT-T11-SPED-1', $oggi, 80);
$fixtures['ORDER_SPEDITO'] = $id;

// 4. Completato e fatturato.
$id = seedOrdine($pdo, ['numero' => 'T11-COMPL', 'cliente_id' => $clienteId, 'indirizzo_id' => $indirizzoId,
    'magazzino_id' => $magazzinoId, 'data_ordine' => $oggi, 'stato' => 'completato',
    'stato_fatturazione' => 'fatturato', 'creato_da' => $utenteId]);
$riga = seedRiga($pdo, $id, $prodottoPzId, $umPzId, 60, 60);
seedSpedizioneEDdt($pdo, $id, $riga, $prodottoPzId, $magazzinoId, $clienteId, $indirizzoId, $utenteId,
    'SP-T11-COMPL-1', 'DDT-T11-COMPL-1', $oggi, 60);
$fixtures['ORDER_COMPLETATO'] = $id;

// 5. Annullato, zero spedito.
$id = seedOrdine($pdo, ['numero' => 'T11-ANN', 'cliente_id' => $clienteId, 'indirizzo_id' => $indirizzoId,
    'magazzino_id' => $magazzinoId, 'data_ordine' => $oggi, 'stato' => 'annullato', 'creato_da' => $utenteId]);
seedRiga($pdo, $id, $prodottoPzId, $umPzId, 40, 0);
$fixtures['ORDER_ANNULLATO'] = $id;

// 6. Superato da una revisione (nessun ordine figlio reale necessario: la
//    vista gestisce $ordineFiglio assente senza errori — controller
//    verificato in questa sessione, SalesOrdersController.php righe 537-538).
$id = seedOrdine($pdo, ['numero' => 'T11-SUP', 'cliente_id' => $clienteId, 'indirizzo_id' => $indirizzoId,
    'magazzino_id' => $magazzinoId, 'data_ordine' => $oggi, 'stato' => 'superato', 'creato_da' => $utenteId]);
seedRiga($pdo, $id, $prodottoPzId, $umPzId, 30, 0);
$fixtures['ORDER_SUPERATO'] = $id;

// 7. Caso limite: due spedizioni in date diverse, badge deve contare 2 e la
//    guidance box (dopo il Diff 2) deve citare l'ULTIMA (quella più recente,
//    non la prima) — verifica diretta del sort DESC di
//    qk_get_ordine_cliente_spedizioni() (includes/sales_orders.php:1111-1122).
//    DEVE restare 'parzialmente_spedito' (residuo > 0), non 'spedito': solo
//    il ramo "parzialmente_spedito" della guidance box (Diff 2, PIANO §5
//    Passo 4) cita il numero dell'ultima spedizione — il ramo "spedito"
//    (Passo 5) mostra solo i conteggi, per design, mai un numero specifico
//    (bug del primo tentativo di questo seed, scoperto eseguendo davvero la
//    suite in modo "post" il 12/08/2026: con stato 'spedito' l'assert sulla
//    guidance box falliva non perché l'implementazione fosse sbagliata, ma
//    perché la fixture testava il ramo sbagliato della specifica).
$id = seedOrdine($pdo, ['numero' => 'T11-MULTI', 'cliente_id' => $clienteId, 'indirizzo_id' => $indirizzoId,
    'magazzino_id' => $magazzinoId, 'data_ordine' => $oggi, 'stato' => 'parzialmente_spedito', 'creato_da' => $utenteId]);
$riga = seedRiga($pdo, $id, $prodottoPzId, $umPzId, 100, 80);
$dataVecchia = date('Y-m-d', strtotime('-5 days'));
seedSpedizioneEDdt($pdo, $id, $riga, $prodottoPzId, $magazzinoId, $clienteId, $indirizzoId, $utenteId,
    'SP-T11-MULTI-1', 'DDT-T11-MULTI-1', $dataVecchia, 50);
seedSpedizioneEDdt($pdo, $id, $riga, $prodottoPzId, $magazzinoId, $clienteId, $indirizzoId, $utenteId,
    'SP-T11-MULTI-2', 'DDT-T11-MULTI-2', $oggi, 30);
$fixtures['ORDER_MULTISPED'] = $id;

// 8. Caso limite: quantità frazionarie (prodotto chimico venduto a peso).
$id = seedOrdine($pdo, ['numero' => 'T11-FRAZ', 'cliente_id' => $clienteId, 'indirizzo_id' => $indirizzoId,
    'magazzino_id' => $magazzinoId, 'data_ordine' => $oggi, 'stato' => 'parzialmente_spedito', 'creato_da' => $utenteId]);
$riga = seedRiga($pdo, $id, $prodottoKgId, $umKgId, 12.5, 5.25);
seedSpedizioneEDdt($pdo, $id, $riga, $prodottoKgId, $magazzinoId, $clienteId, $indirizzoId, $utenteId,
    'SP-T11-FRAZ-1', 'DDT-T11-FRAZ-1', $oggi, 5.25);
$fixtures['ORDER_FRAZIONARIO'] = $id;

$pdo->commit();

// ── Scrive fixtures.env per lo script bash chiamante ────────────────────────
$scratch = rtrim(dirname(DB_DIR), '/');
$lines = [];
$lines[] = "USERNAME={$username}";
$lines[] = "PASSWORD={$password}";
foreach ($fixtures as $key => $orderId) {
    $lines[] = "{$key}={$orderId}";
}
file_put_contents($scratch . '/fixtures.env', implode("\n", $lines) . "\n");

echo "Seed completato: " . count($fixtures) . " ordini fixture creati, utente '{$username}' pronto.\n";
foreach ($fixtures as $key => $orderId) {
    echo "  {$key} = ordine id {$orderId}\n";
}
