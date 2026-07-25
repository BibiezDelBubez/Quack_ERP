<?php
/**
 * transizioni_stati.php — mappe dichiarative delle transizioni di stato ammesse,
 * un'unica fonte di verità per entità. Consumate da App\Services\TransitionGuard.
 *
 * Vedi docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md.
 *
 * REGOLE per questo file:
 * - Solo dati, nessuna logica. Il motore che le usa vive in
 *   app/Services/TransitionGuard.php.
 * - Ogni funzione è indipendente dalle altre: non esiste nessun vocabolario
 *   di stati condiviso fra entità (vedi commento in TransitionGuard.php).
 * - Gli stati elencati come chiave in ogni mappa provengono dal relativo
 *   CHECK (stato IN (...)) in db/init_sqlite.sql — quella è la fonte di
 *   verità sugli stati validi, non questo file.
 * - Le mappe marcate "TAPPA N — non ancora popolata" sono placeholder creati
 *   in Tappa 0 solo per avere la struttura completa fin da subito; il loro
 *   contenuto reale verrà scritto (con rilettura fresca del codice sorgente,
 *   non a memoria) quando si eseguirà quella tappa specifica del piano, e
 *   solo allora un controller/model inizierà davvero a consultarle.
 */

/**
 * Preventivi — fonte stati: db/init_sqlite.sql, CHECK preventivi.stato (~riga 1173).
 * Tappa 6 del piano. Transizioni ricostruite dal comportamento attuale del
 * codice (includes/sales_quotes.php, SalesQuotesController::action()) +
 * decisioni ancora aperte segnalate esplicitamente (vedi PIANO §5).
 */
function qk_transizioni_preventivi(): array
{
    return [
        // Self-loop bozza->bozza: "ancora modificabile mentre in bozza"
        // (qk_update_offerta), stesso principio già visto altrove.
        'bozza'      => ['bozza', 'inviato'],
        'inviato'    => ['accettato', 'rifiutato', 'annullato'],
        // Tappa 6 (PIANO §5, "DA DECIDERE" risolto con l'indagine sul codice
        // reale, non a tavolino): NON esiste oggi nessun'azione diretta di
        // "annulla preventivo" — l'UNICO punto che scrive stato='annullato' è
        // qk_preventivo_nuova_versione() (annulla la vecchia versione quando ne
        // crea una nuova). La vista app/views/sales/quotes/_form.php:69 mostra
        // già il bottone "Crea nuova versione" SOLO per
        // ['inviato','rifiutato','scaduto','annullato'] — MAI per 'bozza' o
        // 'accettato'. Questa mappa formalizza esattamente quella regola già
        // implicita nella UI (mai applicata lato server finora): un preventivo
        // 'accettato' (ha già generato un ordine) resta terminale, non
        // annullabile/rivedibile da qui — coerente con l'esclusione già fatta
        // dalla vista.
        'accettato'  => [],
        'rifiutato'  => ['annullato'],
        'scaduto'    => ['annullato'], // stato oggi MAI impostato da nessun codice (collegato a P1, non ancora implementato)
        'annullato'  => ['annullato'], // self-loop: si può creare un'ulteriore nuova versione anche da uno già annullato
    ];
}

/**
 * Ordini cliente — fonte stati: db/init_sqlite.sql, CHECK ordini_clienti.stato (~riga 1261).
 * Tappa 7 del piano. Ricostruita da SalesOrdersController::action()/
 * elaboraCreaSpedizione() + dalle condizioni di visibilità già presenti (ma
 * mai imposte lato server) in app/views/sales/orders/_detail.php:
 *  - riga 75:  bottone "Completa"  quando !isCompletato && !isAnnullato
 *  - riga 86:  bottone "Riapri"    quando  isCompletato && !isAnnullato
 *  - riga 119: bottone "Annulla"   quando !isCompletato && !isAnnullato
 *  - riga 343: bottone "Nuova Spedizione" quando !isCompletato && !isAnnullato
 * 'in_lavorazione'/'parzialmente_spedito'/'spedito' sono quindi tutti stati
 * "attivi" equivalenti per queste 4 azioni; solo 'completato' e 'annullato'
 * sono terminali. stato_fatturazione è una colonna separata (segna_fatturato/
 * segna_da_fatturare) e resta FUORI da questa mappa, stessa decisione già
 * presa per ordini_testata/offerte_fornitore.
 */
function qk_transizioni_ordini_clienti(): array
{
    return [
        // 'parzialmente_spedito' e 'spedito' sono raggiunti/ricalcolati da
        // elaboraCreaSpedizione() (SalesOrdersController.php) in base alle
        // quantità aggregate spedite/ordinate — non è un'azione validata a sé,
        // stesso principio di qk_ordine_aggiorna_stato_ricezione() per gli
        // ordini fornitore. Qui si modellano solo gli STATI raggiungibili, non
        // il ricalcolo in sé (che resta un'UPDATE derivata, non guardata).
        // 'superato' (PIANO - Revisione Ordini Clienti, §2/§4.2): raggiungibile
        // SOLO da 'in_lavorazione', mai dagli altri stati attivi — creare una
        // nuova versione è permesso solo quando nulla è ancora stato spedito
        // (mirror esatto della restrizione "solo da 'inviato'" già usata lato
        // Acquisti in qk_transizioni_ordini_fornitore()).
        'in_lavorazione'       => ['parzialmente_spedito', 'spedito', 'completato', 'annullato', 'superato'],
        'parzialmente_spedito' => ['parzialmente_spedito', 'spedito', 'completato', 'annullato'],
        // Self-loop spedito->spedito: una spedizione aggiuntiva (es. sostituzione
        // merce danneggiata) su un ordine già interamente spedito ricalcola lo
        // stato e lo trova ancora 'spedito' — non è un errore, resta lì.
        'spedito'              => ['spedito', 'completato', 'annullato'],
        // 'riapri' (SalesOrdersController::action, azione='riapri') è ammesso
        // SOLO da 'completato', coerente con _detail.php:86 (bottone "Riapri"
        // mostrato solo quando isCompletato && !isAnnullato).
        'completato'           => ['in_lavorazione'],
        'annullato'            => [],
        // Stato terminale: un ordine superato da una revisione non transisce
        // più altrove (mirror di 'annullato' e dello stesso stato lato Acquisti).
        'superato'             => [],
    ];
}

/**
 * RFQ (richieste d'offerta a fornitore) — fonte stati: db/init_sqlite.sql,
 * CHECK rfq_testata.stato (~riga 2012).
 * TAPPA 5 — non ancora popolata. Da definire allineando anche i 2 controlli
 * oggi non coperti da _rfq_check_stato() (includes/purchases_rfq.php).
 */
function qk_transizioni_rfq(): array
{
    return [
        // Self-loop bozza->bozza: "ancora modificabile mentre in bozza"
        // (includes/purchases_rfq.php, salvataggio), stesso principio già
        // spiegato per inventari/offerte fornitore.
        'bozza'        => ['bozza', 'inviata', 'chiusa'],
        // 'chiusa' da 'inviata' copre sia la chiusura manuale (qk_rfq_chiudi,
        // esito 'annullata') sia quella automatica quando un'offerta collegata
        // viene accettata (qk_offerta_accetta in includes/purchases_quotes.php,
        // esito 'offerta_accettata') — stessa transizione, due esiti diversi
        // registrati in rfq_testata.esito_chiusura, non due regole diverse.
        'inviata'      => ['chiusa', 'revisionata'],
        'revisionata'  => [],
        'chiusa'       => [],
    ];
}

/**
 * Offerte fornitore — fonte stati: db/init_sqlite.sql, CHECK offerte_testata.stato (~riga 2111).
 * TAPPA 3 — non ancora popolata.
 */
function qk_transizioni_offerte_fornitore(): array
{
    return [
        // Self-loop 'in_valutazione'->'in_valutazione': stesso significato già
        // spiegato per gli inventari — "è ancora modificabile restando in
        // valutazione" (include/purchases_quotes.php, salvataggio bozza),
        // distinto dalle vere transizioni verso accettata/rifiutata.
        'in_valutazione' => ['in_valutazione', 'accettata', 'rifiutata'],
        'accettata'      => [],
        'rifiutata'      => [],
    ];
}

/**
 * Ordini fornitore — fonte stati: db/init_sqlite.sql, CHECK ordini_testata.stato (~riga 2174).
 * TAPPA 4 — non ancora popolata. Il caso più maturo oggi (già ben commentato
 * in includes/purchases_orders.php, REVISIONE Ciclo Acquisti §1.10): da
 * formalizzare fedelmente con rilettura completa del file, non a memoria.
 */
function qk_transizioni_ordini_fornitore(): array
{
    return [
        'bozza'                 => ['approvato', 'annullato'],
        'in_approvazione'       => ['approvato', 'annullato'],
        'approvato'             => ['inviato', 'annullato'],
        // 'completato' qui è raggiungibile ANCHE direttamente da 'inviato' (non
        // solo passando da 'parzialmente_ricevuto'): qk_ordine_aggiorna_stato_ricezione()
        // in includes/purchases_orders.php ricalcola lo stato da zero ad ogni
        // ricezione confermata, e se una singola ricezione copre l'intero
        // ordine si salta direttamente a 'completato'.
        'inviato'               => ['parzialmente_ricevuto', 'completato', 'superato', 'annullato'],
        'parzialmente_ricevuto' => ['completato', 'annullato'],
        'completato'            => [],
        'annullato'             => [],
        'superato'              => [],
    ];
}

/**
 * Ricezioni merce — fonte stati: db/init_sqlite.sql, CHECK ricezioni_testata.stato (~riga 2287).
 * TAPPA 3 — non ancora popolata.
 */
function qk_transizioni_ricezioni(): array
{
    return [
        'bozza'      => ['completata', 'annullata'],
        'completata' => [],
        'annullata'  => [],
    ];
}

/**
 * Trasferimenti magazzino — fonte stati: db/init_sqlite.sql,
 * CHECK trasferimenti_magazzino.stato (~riga 1042).
 * TAPPA 2 — popolata, a specchio del comportamento già corretto in
 * includes/trasferimenti.php (qk_trasferimento_registra_partenza,
 * qk_conferma_arrivo_trasferimento, qk_annulla_trasferimento).
 *
 * NB: qk_annulla_trasferimento() tratta annullato->annullato in modo
 * IDEMPOTENTE (no-op silenzioso, non un errore) — quel caso resta gestito con
 * un controllo esplicito PRIMA di interrogare questa mappa, non con un
 * self-loop qui: un self-loop farebbe apparire "annullato->annullato" come
 * un'azione validamente eseguita quando in realtà non deve succedere nulla,
 * un significato diverso che confonderebbe le due cose.
 */
function qk_transizioni_trasferimenti(): array
{
    return [
        'bozza'        => ['in_transito', 'annullato'],
        'in_transito'  => ['completato', 'annullato'],
        'completato'   => [],
        'annullato'    => [],
    ];
}

/**
 * Inventari — fonte stati: db/init_sqlite.sql, CHECK implicito inventari.stato
 * (colonna con solo 2 valori: 'bozza' | 'convalidato').
 * TAPPA 1 (pilota del piano) — popolata.
 *
 * 'bozza' => ['bozza', ...]: l'auto-transizione (self-loop) bozza->bozza NON è
 * un errore di battitura — rappresenta "è ancora possibile modificare/salvare
 * restando in bozza" (qk_convalida_inventario() con azione='salva' non cambia
 * stato). Senza il self-loop, TransitionGuard::assicura($statoAttuale, 'bozza')
 * non avrebbe modo di esprimere "posso ancora scrivere qui restando allo
 * stesso stato" — è lo stesso trucco usato da Symfony Workflow/Spatie per le
 * transizioni "resta qui". 'convalidato' => [] resta bloccato per qualunque
 * scrittura (nessuna via d'uscita, coerente con qk_convalida_inventario()).
 *
 * NB: l'azione "annulla" (cancellazione della bozza, WarehouseController::
 * editInventory()) NON è modellata qui — non è una transizione verso un
 * altro stato valido, è una precondizione per un DELETE (la riga smette di
 * esistere, non "diventa" un altro stato). Resta un controllo a parte,
 * volutamente non forzato dentro questa mappa.
 */
function qk_transizioni_inventari(): array
{
    return [
        'bozza'       => ['bozza', 'convalidato'],
        'convalidato' => [],
    ];
}
