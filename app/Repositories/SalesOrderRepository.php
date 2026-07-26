<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SalesOrderRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // getNextSequenceNumber(): RIMOSSA il 26/07/2026 (PIANO - Unificazione
    // Creazione Ordine Cliente, Regola sul Refactoring di AGENTS.md — non
    // lasciare codice morto). Verificato con grep sull'intero progetto in
    // questa sessione: zero chiamanti residui (qk_ordine_cliente_salva(),
    // includes/sales_orders.php, chiama qk_next_sequence_number() —
    // definita in includes/functions.php — direttamente, stesso identico
    // pattern già usato da qk_next_numero_ordine_diretto() due righe sopra
    // nello stesso file, per non introdurre una dipendenza da
    // includes/sales_quotes.php dove viveva l'altro wrapper equivalente).

    // insertOrderHeader()/insertOrderLine(): RIMOSSE il 26/07/2026 (PIANO -
    // Unificazione Creazione Ordine Cliente, eseguito su autorizzazione
    // esplicita "esegui" — Regola sul Refactoring di AGENTS.md: quando
    // OrderCalculatorService::insertDirectOrder() ha smesso di chiamare
    // questi due metodi, vanno rimossi per intero, non lasciati come codice
    // morto parallelo). La scrittura di ordini_clienti/ordini_clienti_righe
    // in fase di creazione ora passa da UN SOLO punto,
    // qk_ordine_cliente_salva() (includes/sales_orders.php), condiviso col
    // percorso "da preventivo" (che prima duplicava la stessa logica con due
    // INSERT scritti a mano dentro qk_preventivo_conferma_ordine()).
    // Verificato con grep sull'intero progetto in questa sessione: zero
    // chiamanti residui di insertOrderHeader()/insertOrderLine() al di fuori
    // di questo stesso file e della documentazione storica.
    //
    // findUomIdByCode() (sotto) resta: ancora usata da
    // qk_calcola_righe_stock_ordine() (includes/sales_orders.php), non
    // toccata da questo piano.

    public function findUomIdByCode(string $code): ?int
    {
        $st = $this->pdo->prepare("SELECT id FROM unita_misura WHERE codice = ? LIMIT 1");
        $st->execute([$code]);
        $val = $st->fetchColumn();
        return $val !== false ? (int)$val : null;
    }
}
