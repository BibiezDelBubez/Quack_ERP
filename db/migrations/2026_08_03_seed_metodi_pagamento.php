<?php
/**
 * Migration: aggiunge il seed dei metodi di pagamento comuni per ERP italiani
 * alla tabella `metodi_pagamento`.
 *
 * Idempotente: inserisce solo se la tabella è vuota.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$st = $pdo->query("SELECT COUNT(*) FROM metodi_pagamento");
$count = (int) $st->fetchColumn();

if ($count === 0) {
    $metodi = [
        ['BB_VISTA', 'Bonifico Bancario Vista Fattura', 1],
        ['BB_30', 'Bonifico Bancario 30gg', 1],
        ['BB_60', 'Bonifico Bancario 60gg', 1],
        ['BB_90', 'Bonifico Bancario 90gg', 1],
        ['RB_30', 'Ricevuta Bancaria (RiBa) 30gg', 1],
        ['RB_60', 'Ricevuta Bancaria (RiBa) 60gg', 1],
        ['RB_90', 'Ricevuta Bancaria (RiBa) 90gg', 1],
        ['RB_3060', 'Ricevuta Bancaria (RiBa) 30/60gg', 1],
        ['RB_306090', 'Ricevuta Bancaria (RiBa) 30/60/90gg', 1],
        ['RD_VISTA', 'Rimessa Diretta Vista Fattura', 1],
        ['RD_30', 'Rimessa Diretta 30gg', 1],
        ['RD_60', 'Rimessa Diretta 60gg', 1],
        ['CON', 'Contanti', 1],
        ['CC', 'Carta di Credito / PayPal', 1],
        ['SDD', 'Addebito Diretto SEPA (SDD)', 1],
        ['ASG', 'Assegno Bancario/Circolare', 1],
        ['CONTR', 'Contrassegno', 1],
    ];

    $stmt = $pdo->prepare("INSERT INTO metodi_pagamento (codice, descrizione, attivo) VALUES (?, ?, ?)");
    foreach ($metodi as $m) {
        $stmt->execute($m);
    }
    
    echo "  [OK] metodi_pagamento: inseriti " . count($metodi) . " metodi di pagamento di default\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] metodi_pagamento: la tabella contiene già dei dati ($count record), skip seed\n";
    echo "\nNessuna modifica necessaria. Schema già popolato.\n";
}
