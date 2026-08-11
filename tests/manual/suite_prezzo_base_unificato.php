<?php
declare(strict_types=1);

/**
 * suite_prezzo_base_unificato.php
 *
 * Bug 2, Parte B (audit/PIANO - Correzioni Enterprise Post-Test Manuale (10-08-2026).md, §2.3/§2.5):
 * qk_salva_prezzo_base_prodotto() (includes/prodotti.php) e' stata riscritta per essere un
 * wrapper sottile su qk_listino_prezzo_riga_insert()/_update() (includes/pricelists.php) —
 * la stessa funzione che usa apiSaveRiga() per la matrice prezzi/pagina Listini Vendita —
 * invece di un secondo INSERT/UPDATE scritto a mano. Questa suite verifica che il percorso
 * "prodotto" (creazione/aggiornamento/import CSV) e il percorso "matrice/listino" convergano
 * sempre sulla stessa identica riga in listini_prezzi, mai su due righe separate.
 *
 * Nota: la verifica di convergenza con apiSaveRiga() (PB1.4/PB1.5 del piano) e' stata eseguita
 * dal vivo in sessione (10/08/2026) con un test a processi PHP separati, non qui — apiSaveRiga()
 * termina il processo con exit() incondizionato a fine esecuzione (come ogni altro endpoint
 * JSON del controller), incompatibile con lo stile ad asserzioni multiple (chk()) di questo
 * file. Quel test ha confermato: prezzo scritto prima da qk_salva_prezzo_base_prodotto() (equivalente
 * al percorso CSV) poi sovrascritto da apiSaveRiga() (equivalente alla matrice) aggiornano la
 * STESSA riga (stesso id), mai una seconda.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/prodotti.php'; // trascina anche pricelists.php
require_once __DIR__ . '/../../includes/pricing.php';

function chk(string $desc, $expected, $actual) {
    if ($expected !== $actual) {
        echo "[FAIL] $desc\n";
        echo "  Expected: " . json_encode($expected) . "\n";
        echo "  Actual:   " . json_encode($actual) . "\n";
        exit(1);
    }
    echo "[OK] $desc\n";
}

try {
    $pdo->beginTransaction();

    // Setup
    $pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (999, 'PZ', 'Pezzi') ON CONFLICT(id) DO NOTHING");
    $pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo) VALUES (9998, 'TEST-PB-UNIF', 'Prodotto Test Prezzo Base Unificato', 'semplice', 999, 1)");
    $prodottoId = 9998;

    // PB1.1: creazione — prima scrittura del prezzo base
    qk_salva_prezzo_base_prodotto($pdo, $prodottoId, 10.50, 999);
    $righe = $pdo->query("SELECT id, listino_id, quantita_minima_stock, prezzo_unitario FROM listini_prezzi WHERE prodotto_id = $prodottoId")->fetchAll(PDO::FETCH_ASSOC);
    chk("PB1.1: esattamente 1 riga dopo la prima scrittura", 1, count($righe));
    chk("PB1.1: listino_id = 1", 1, (int)$righe[0]['listino_id']);
    chk("PB1.1: quantita_minima_stock = 0", 0.0, (float)$righe[0]['quantita_minima_stock']);
    chk("PB1.1: prezzo salvato correttamente", 10.5, (float)$righe[0]['prezzo_unitario']);
    $primoRigaId = (int)$righe[0]['id'];

    // PB1.2: stesso prodotto, prezzo diverso -> deve essere un UPDATE, non un secondo INSERT
    qk_salva_prezzo_base_prodotto($pdo, $prodottoId, 15.75, 999);
    $righe2 = $pdo->query("SELECT id, prezzo_unitario FROM listini_prezzi WHERE prodotto_id = $prodottoId")->fetchAll(PDO::FETCH_ASSOC);
    chk("PB1.2: ancora esattamente 1 riga dopo il secondo salvataggio", 1, count($righe2));
    chk("PB1.2: stesso id di riga (UPDATE, non nuovo INSERT)", $primoRigaId, (int)$righe2[0]['id']);
    chk("PB1.2: prezzo aggiornato all'ultimo valore", 15.75, (float)$righe2[0]['prezzo_unitario']);

    // PB1.6: round-trip — le 3 vie di lettura del prezzo base concordano tutte
    $formData = qk_load_prodotto_form($pdo, $prodottoId);
    chk("PB1.6: qk_load_prodotto_form() legge il prezzo aggiornato", 15.75, (float)$formData['prezzo_vendita_base']);

    $lista = qk_prodotti_list($pdo, null, null, false, null, 50, 0, 'nome', 'asc');
    $rigaLista = null;
    foreach ($lista['rows'] as $r) {
        if ((int)$r['id'] === $prodottoId) { $rigaLista = $r; break; }
    }
    chk("PB1.6: qk_prodotti_list() trova il prodotto", true, $rigaLista !== null);
    chk("PB1.6: qk_prodotti_list() mostra il prezzo aggiornato", 15.75, (float)$rigaLista['prezzo_vendita']);

    $prezzoVendita = qk_get_sale_price($pdo, $prodottoId, null, 1.0, date('Y-m-d'));
    chk("PB1.6: qk_get_sale_price() (motore preventivi) trova il prezzo", 15.75, (float)$prezzoVendita['prezzo_unitario']);
    chk("PB1.6: qk_get_sale_price() usa listino_id = 1", 1, (int)$prezzoVendita['listino_id']);

    echo "\nTutti i test di suite_prezzo_base_unificato.php superati con successo.\n";
    $pdo->rollBack();
    exit(0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "[ERRORE FATALE] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
