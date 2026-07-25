<?php
declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';
require_once __DIR__ . '/prodotti.php';
require_once __DIR__ . '/document_common.php';
require_once __DIR__ . '/magazzino.php';
require_once __DIR__ . '/transizioni_stati.php';
require_once __DIR__ . '/../app/Services/TransitionGuard.php';
// qk_ordine_cliente_crea_revisione() (sotto) chiama qk_compliance_check_sale()
// — dipendenza dichiarata qui (CLAUDE.md punto 4: require_once sempre in
// testa al file), non lasciata al caso che il chiamante (oggi
// SalesOrdersController.php) la includa lui per un altro motivo.
require_once __DIR__ . '/compliance.php';

/**
 * Genera il prossimo numero progressivo per l'ordine cliente diretto.
 */
function qk_next_numero_ordine_diretto(PDO $pdo): string
{
    return qk_next_sequence_number($pdo, 'ordine_cliente');
}

/**
 * Valida i dati dell'ordine diretto.
 */
function qk_ordine_diretto_validate(array $values, array $righe): array
{
    $errors = [];

    if (empty($values['cliente_id'])) {
        $errors['cliente_id'] = 'Selezionare un cliente.';
    }
    if (empty($values['data_ordine'])) {
        $errors['data_ordine'] = 'La data ordine è obbligatoria.';
    }

    if (empty($righe)) {
        $errors['_global'] = 'Inserire almeno una riga prodotto.';
    } else {
        foreach ($righe as $idx => $r) {
            if (empty(trim($r['descrizione'] ?? ''))) {
                $errors["righe_{$idx}_descrizione"] = 'La descrizione è obbligatoria.';
            }
            if ((float)($r['quantita'] ?? 0) <= 0) {
                $errors["righe_{$idx}_quantita"] = 'La quantità deve essere maggiore di zero.';
            }
            if ((float)($r['prezzo_unitario'] ?? 0) < 0) {
                $errors["righe_{$idx}_prezzo"] = 'Il prezzo unitario non può essere negativo.';
            }
        }
    }

    return $errors;
}

/**
 * Converte le righe "grezze" di un ordine diretto (form) nei dati di
 * conversione UoM necessari sia per l'inserimento vero e proprio sia per il
 * controllo preventivo di disponibilità (qk_verifica_disponibilita_ordine()).
 *
 * Estratta dal loop di conversione che viveva solo dentro
 * OrderCalculatorService::insertDirectOrder() (PIANO - Vendita Allo Scoperto
 * Controllata, P4, Tappa 4): il controllo di disponibilità deve girare PRIMA
 * di inserire qualunque cosa (avviso con conferma esplicita, mai un blocco —
 * ma comunque prima di scrivere), quindi il controller ha bisogno delle
 * stesse quantità in UoM di stock che userebbe l'inserimento vero. Senza
 * questa estrazione il controller avrebbe dovuto ricopiare lo stesso loop
 * "risolvi UoM + converti", violando la Regola Aurea DRY di AGENTS.md.
 * OrderCalculatorService::insertDirectOrder() è stata aggiornata per usare
 * questa stessa funzione invece del suo loop inline originale — stesso
 * identico comportamento, unica fonte di verità.
 *
 * @return array<int, array{prodotto_id:?int, unita_misura_id:?int, uom:array}>
 *         Stesso ordine di $righe in input. 'uom' è il valore di ritorno
 *         completo di qk_uom_converti_riga().
 */
function qk_calcola_righe_stock_ordine(PDO $pdo, array $righe): array
{
    require_once __DIR__ . '/../app/Repositories/SalesOrderRepository.php';
    $repository = new \App\Repositories\SalesOrderRepository($pdo);

    $umCache = [];
    $out = [];
    foreach ($righe as $r) {
        $prodottoId = !empty($r['prodotto_id']) ? (int)$r['prodotto_id'] : null;
        $qta = (float) ($r['quantita'] ?? 0);
        $pu  = (float) ($r['prezzo_unitario'] ?? 0);

        $umCodice = $r['unita_misura'] ?? 'pz';
        if (!array_key_exists($umCodice, $umCache)) {
            $umCache[$umCodice] = $repository->findUomIdByCode($umCodice);
        }
        $umId = $umCache[$umCodice];

        $uomResult = qk_uom_converti_riga($pdo, $prodottoId, $umId ? (int)$umId : null, $qta, $pu);

        $out[] = [
            'prodotto_id'     => $prodottoId,
            'unita_misura_id' => $umId,
            'uom'             => $uomResult,
        ];
    }
    return $out;
}

/**
 * Inserisce un ordine cliente diretto (senza preventivo di origine).
 * Restituisce l'ID del nuovo ordine.
 *
 * @param array $venditaScopertoDettaglio Righe scoperte già rilevate e
 *        confermate esplicitamente dall'utente (PIANO - Vendita Allo
 *        Scoperto Controllata, P4, §3.3/§3.5) — stesso shape del valore di
 *        ritorno di qk_verifica_disponibilita_ordine(). Vuoto (default) se
 *        l'ordine non ha righe scoperte: in quel caso nessuna colonna di
 *        audit trail viene scritta (restano NULL).
 */
function qk_insert_ordine_diretto(PDO $pdo, array $values, array $righe, int $user_id, int $magazzinoId, array $venditaScopertoDettaglio = []): int
{
    require_once __DIR__ . '/../app/Repositories/SalesOrderRepository.php';
    require_once __DIR__ . '/../app/Services/OrderCalculatorService.php';

    $repository = new \App\Repositories\SalesOrderRepository($pdo);
    $service = new \App\Services\OrderCalculatorService($pdo, $repository);

    return $service->insertDirectOrder($values, $righe, $user_id, $magazzinoId, $venditaScopertoDettaglio);
}

/**
 * Carica la riga di ordini_clienti per id, o lancia se non trovato.
 * Stesso pattern di _ordine_carica() in includes/purchases_orders.php.
 */
function _ordine_cliente_carica(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM ordini_clienti WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new \RuntimeException('Ordine non trovato.');
    }
    return $row;
}

/**
 * Verifica che l'ordine cliente NON sia in uno stato terminale (completato/
 * annullato). Precondizione condivisa da due punti del controller
 * (SalesOrdersController::edit() ed elaboraCreaSpedizione()) che PRIMA la
 * applicavano in modo incoerente: edit() la controllava (ma nel controller,
 * non nel model — violazione MVC), elaboraCreaSpedizione() non la applicava
 * affatto (poteva creare una spedizione, e quindi far "ripartire" lo stato,
 * su un ordine già completato o annullato).
 *
 * Tappa 7 (PIANO - Motore Unificato di Transizioni di Stato): riusa la stessa
 * mappa/istanza di qk_transizioni_ordini_clienti() invece di un secondo
 * in_array() scollegato — uno stato è "ancora attivo" se da lì è raggiungibile
 * 'annullato', esattamente {in_lavorazione, parzialmente_spedito, spedito}
 * nella mappa attuale, mai {completato, annullato}. Corrisponde esattamente
 * alla condizione già mostrata (ma mai imposta lato server) in
 * app/views/sales/orders/_detail.php righe 22 e 343: !$isAnnullato && !$isCompletato.
 */
function qk_ordine_cliente_assicura_modificabile(string $statoAttuale, string $messaggioErrore): void
{
    $guard = new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente');
    if (!$guard->puoTransire($statoAttuale, 'annullato')) {
        throw new \RuntimeException($messaggioErrore);
    }
}

/**
 * Aggiorna note e data di consegna prevista di un ordine cliente.
 * Tappa 7: il controllo "non è già completato/annullato" viveva SOLO dentro
 * SalesOrdersController::edit() (violazione MVC) — spostato qui.
 */
function qk_ordine_cliente_aggiorna_note(PDO $pdo, int $id, ?string $note, ?string $dataConsegnaPrevista): void
{
    $ordine = _ordine_cliente_carica($pdo, $id);
    qk_ordine_cliente_assicura_modificabile(
        (string) $ordine['stato'],
        'Accesso negato: impossibile modificare un ordine già completato o annullato.'
    );

    $pdo->prepare("
        UPDATE ordini_clienti
        SET note = ?, data_consegna_prevista = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$note, $dataConsegnaPrevista, $id]);
}

/**
 * Segna l'ordine cliente come completato manualmente.
 * Tappa 7: prima un UPDATE grezzo in SalesOrdersController::action(), senza
 * nessun controllo di stato.
 */
function qk_ordine_cliente_completa(PDO $pdo, int $id): void
{
    $ordine = _ordine_cliente_carica($pdo, $id);
    (new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente'))
        ->assicuraConMessaggio(
            (string) $ordine['stato'],
            'completato',
            "Impossibile completare: l'ordine non è in uno stato valido per il completamento (stato attuale: {$ordine['stato']})."
        );
    $pdo->prepare("UPDATE ordini_clienti SET stato = 'completato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$id]);
}

/**
 * Riporta l'ordine cliente in lavorazione. Ammesso solo da 'completato' —
 * coerente con app/views/sales/orders/_detail.php:86 (bottone "Riapri"
 * mostrato solo quando isCompletato && !isAnnullato).
 */
function qk_ordine_cliente_riapri(PDO $pdo, int $id): void
{
    $ordine = _ordine_cliente_carica($pdo, $id);
    (new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente'))
        ->assicuraConMessaggio(
            (string) $ordine['stato'],
            'in_lavorazione',
            "Impossibile riaprire: l'ordine può essere riaperto solo se è 'completato' (stato attuale: {$ordine['stato']})."
        );
    $pdo->prepare("UPDATE ordini_clienti SET stato = 'in_lavorazione', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$id]);
}

/**
 * Annulla l'ordine cliente.
 * NB: bug O2 preesistente e già noto (l'annullamento non rilascia mai la
 * riserva stock su giacenze.quantita_riservata) resta FUORI SCOPO per questo
 * piano — qui si aggiunge solo la guardia di transizione mancante, non si
 * tocca (né si finge di correggere) la logica di rilascio riserva.
 */
/**
 * Annulla un ordine cliente e rilascia la parte di giacenza riservata non
 * ancora spedita (REVISIONE Ciclo Vendite §1.7/O2: prima di questa
 * correzione l'annullamento cambiava solo lo stato, senza mai toccare
 * giacenze.quantita_riservata — la riserva restava appesa per sempre).
 *
 * Il rilascio usa SOLO il residuo non spedito di ogni riga
 * (quantita_ordinata - quantita_spedita), mai la quantità totale originaria
 * — stesso principio già corretto per il rilascio di quantita_in_arrivo
 * lato acquisti (REVISIONE Ciclo Acquisti §1.3): la parte già spedita è
 * giacenza già uscita per davvero, non va toccata.
 *
 * Il magazzino usato è quello congelato su ordini_clienti.magazzino_id alla
 * creazione/conferma (mai il magazzino attivo di sessione al momento
 * dell'annullamento, vedi P4b/O2 della revisione). Ordini storici creati
 * prima dell'introduzione di questa colonna hanno magazzino_id NULL: per
 * quelli il rilascio viene saltato riga per riga (non si può indovinare da
 * quale magazzino rilasciare) e l'evento viene loggato, non fallito in
 * silenzio né bloccato — l'annullamento in sé resta sempre permesso.
 */
function qk_ordine_cliente_annulla(PDO $pdo, int $id): void
{
    $ordine = _ordine_cliente_carica($pdo, $id);
    (new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente'))
        ->assicuraConMessaggio(
            (string) $ordine['stato'],
            'annullato',
            "Impossibile annullare: l'ordine è già in stato '{$ordine['stato']}'."
        );

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE ordini_clienti SET stato = 'annullato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$id]);

        $magazzinoId = $ordine['magazzino_id'] ?? null;
        if ($magazzinoId) {
            $stRighe = $pdo->prepare("
                SELECT id, prodotto_id, quantita_ordinata, quantita_spedita, quantita_stock
                  FROM ordini_clienti_righe
                 WHERE ordine_id = ? AND prodotto_id IS NOT NULL
            ");
            $stRighe->execute([$id]);
            foreach ($stRighe->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $qtaOrdinata = (float)$r['quantita_ordinata'];
                $qtaSpedita  = (float)$r['quantita_spedita'];
                $qtaStock    = (float)($r['quantita_stock'] ?? 0);
                if ($qtaOrdinata <= 0 || $qtaStock <= 0) {
                    continue;
                }
                $residuoStock = $qtaStock * max(0.0, $qtaOrdinata - $qtaSpedita) / $qtaOrdinata;
                if ($residuoStock > 0) {
                    // (int)$r['id'] passato per usare lo snapshot Kit se presente (AUDIT 3 §3.3).
                    qk_rilascia_stock_ordine($pdo, (int)$r['prodotto_id'], $residuoStock, (int)$magazzinoId, (int)$r['id']);
                }
            }
        } else {
            error_log("[qk_ordine_cliente_annulla] Ordine $id senza magazzino_id: riserva NON rilasciata (ordine storico pre-fix, magazzino di origine sconosciuto).");
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Clona una riga di indirizzi_documenti/contatti_documenti come nuova riga
 * fisica indipendente (stesso id-copy dell'originale, mai un riferimento
 * condiviso), restituendo il nuovo id o null se $id era già null/non trovato.
 *
 * Estratto come helper invece di ripetere due volte lo stesso blocco dentro
 * qk_ordine_cliente_crea_revisione() (sotto) — la versione Acquisti
 * (qk_ordine_crea_revisione(), includes/purchases_orders.php:654-684) lo
 * scrive due volte inline; qui, per non introdurre la stessa duplicazione
 * anche nel nuovo codice, è isolato una sola volta. $tabella è sempre una
 * stringa letterale passata dal chiamante (mai da input utente), quindi
 * l'interpolazione diretta nel nome tabella è sicura.
 */
function _clona_riga_snapshot_documento(PDO $pdo, string $tabella, ?int $id): ?int
{
    if (!$id) {
        return null;
    }
    $st = $pdo->prepare("SELECT * FROM {$tabella} WHERE id = ?");
    $st->execute([$id]);
    $riga = $st->fetch(PDO::FETCH_ASSOC);
    if (!$riga) {
        return null;
    }
    unset($riga['id']);
    $colonne = implode(', ', array_keys($riga));
    $placeholders = implode(', ', array_fill(0, count($riga), '?'));
    $pdo->prepare("INSERT INTO {$tabella} ({$colonne}) VALUES ({$placeholders})")
        ->execute(array_values($riga));
    return (int) $pdo->lastInsertId();
}

/**
 * Crea una nuova versione di un ordine cliente "in_lavorazione", marcando
 * l'originale come 'superato'. Mirror esatto di qk_ordine_crea_revisione()
 * lato Acquisti (includes/purchases_orders.php:625-802) — stessa struttura,
 * stessa transazione, stesso principio "duplica come righe fisiche nuove,
 * mai riferimenti condivisi" — adattato ai guard specifici delle vendite
 * decisi in docs/Ciclo Vendite/PIANO - Revisione Ordini Clienti (Nuova
 * Versione).md §4 (letto e verificato riga per riga in questa sessione):
 *
 *  - §4.2: permesso SOLO da stato esatto 'in_lavorazione', mai da
 *    'parzialmente_spedito'/'spedito'/'completato'/'annullato'/'superato';
 *    più un guard aggiuntivo (difesa in profondità, stesso principio già
 *    applicato altrove in questo file) che blocca comunque se esiste già una
 *    riga in `spedizioni` collegata, anche nel caso teorico in cui lo stato
 *    fosse disallineato.
 *  - §4.3: bloccata SEMPRE, senza eccezioni, se `stato_fatturazione =
 *    'fatturato'` — testo del messaggio deciso esplicitamente nella
 *    conversazione, riusato identico qui.
 *  - §4.4: il controllo compliance viene sempre ri-eseguito sulla nuova
 *    versione, mai ereditato "per fiducia" da quella vecchia.
 *
 * A differenza della versione Acquisti, `ordini_clienti.contatto_id`
 * referenzia `clienti_fornitori(id)` (un riferimento vivo, non uno snapshot
 * — verificato in db/init_sqlite.sql:1355) e va quindi copiato as-is, non
 * clonato: la colonna analoga a `ordini_testata.contatto_id` (che invece
 * referenzia `contatti_documenti`) qui si chiama `contatto_snapshot_id`
 * (db/init_sqlite.sql:1360) — nomi diversi per lo stesso concetto, da non
 * confondere copiando ciecamente il pattern Acquisti.
 *
 * @return int id del nuovo ordine (in stato 'in_lavorazione', versione+1)
 */
function qk_ordine_cliente_crea_revisione(PDO $pdo, int $ordine_id): int
{
    $pdo->beginTransaction();
    try {
        $ordine = _ordine_cliente_carica($pdo, $ordine_id);

        (new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente'))
            ->assicuraConMessaggio(
                (string) $ordine['stato'],
                'superato',
                "È possibile creare una nuova versione solo di un ordine in stato 'in lavorazione' (stato attuale: {$ordine['stato']})."
            );

        // Difesa in profondità (§3 punto 2 / §4.2): nessuna spedizione deve
        // già esistere, anche se lo stato fosse manomesso/disallineato.
        $stSped = $pdo->prepare("SELECT COUNT(*) FROM spedizioni WHERE ordine_id = ?");
        $stSped->execute([$ordine_id]);
        if ((int) $stSped->fetchColumn() > 0) {
            throw new \RuntimeException('Impossibile creare una nuova versione: esiste già una spedizione collegata a questo ordine.');
        }

        // §4.3: blocco assoluto, testo del messaggio deciso esplicitamente.
        if ((string) $ordine['stato_fatturazione'] === 'fatturato') {
            throw new \RuntimeException("Ordine già fatturato: per correggerlo serve una nota di credito, non una revisione.");
        }

        // 1. Duplica snapshot indirizzo di consegna, se presente.
        $nuovoIndirizzoId = _clona_riga_snapshot_documento($pdo, 'indirizzi_documenti', $ordine['indirizzo_consegna_id'] ? (int) $ordine['indirizzo_consegna_id'] : null);

        // 2. Duplica snapshot contatto referente, se presente (NB: colonna
        // contatto_snapshot_id, non contatto_id — vedi commento sopra).
        $nuovoContattoSnapshotId = _clona_riga_snapshot_documento($pdo, 'contatti_documenti', $ordine['contatto_snapshot_id'] ? (int) $ordine['contatto_snapshot_id'] : null);

        // 3. Nuovo numero con suffisso di versione, stesso schema Acquisti.
        $nuovaVersione = (int) ($ordine['versione'] ?? 1) + 1;
        $baseNumero = preg_replace('/ v\d+$/', '', (string) $ordine['numero_ordine']);
        $nuovoNumero = $baseNumero . ' v' . $nuovaVersione;

        // 4. Inserisce la nuova testata copiando la vecchia riga per intero
        // (SELECT * già caricata in $ordine) e sovrascrivendo solo i campi che
        // devono cambiare — evita di elencare a mano le ~50 colonne di
        // ordini_clienti (rischio concreto di dimenticarne una NOT NULL,
        // CLAUDE.md punto 2), stesso principio già usato per gli snapshot sopra.
        $nuova = $ordine;
        unset($nuova['id'], $nuova['created_at'], $nuova['updated_at'], $nuova['pdf_path'], $nuova['pdf_hash']);
        $nuova['numero_ordine']       = $nuovoNumero;
        // Mirror del comportamento Acquisti (qk_ordine_crea_revisione() usa
        // CURRENT_DATE per la nuova testata, non la data dell'ordine
        // originale) — la nuova versione è un documento creato oggi.
        $nuova['data_ordine']         = date('Y-m-d');
        $nuova['stato']               = 'in_lavorazione';
        $nuova['versione']            = $nuovaVersione;
        $nuova['ordine_padre_id']     = $ordine_id;
        $nuova['stato_fatturazione']  = 'da_fatturare';
        $nuova['indirizzo_consegna_id']   = $nuovoIndirizzoId;
        $nuova['contatto_snapshot_id']    = $nuovoContattoSnapshotId;
        // Audit vendita allo scoperto (P4): riguarda la conferma esplicita
        // fatta sull'ordine ORIGINALE al momento della SUA riserva — la nuova
        // versione riserva di nuovo da zero (punto 6 sotto), quindi non eredita
        // una conferma che si riferiva a un'altra riserva.
        $nuova['vendita_scoperto_confermata_da'] = null;
        $nuova['vendita_scoperto_confermata_at'] = null;
        $nuova['vendita_scoperto_dettaglio']     = null;

        $colonne = implode(', ', array_keys($nuova));
        $placeholders = implode(', ', array_fill(0, count($nuova), '?'));
        $pdo->prepare("INSERT INTO ordini_clienti ({$colonne}) VALUES ({$placeholders})")
            ->execute(array_values($nuova));
        $nuovoOrdineId = (int) $pdo->lastInsertId();

        // 5. Duplica le righe (stesso principio: copia intera + override dei
        // soli campi che cambiano, invece di elencarle a mano).
        $stRighe = $pdo->prepare('SELECT * FROM ordini_clienti_righe WHERE ordine_id = ?');
        $stRighe->execute([$ordine_id]);
        $righeVecchie = $stRighe->fetchAll(PDO::FETCH_ASSOC);

        $righeNuove = []; // righe complete della NUOVA versione, per riserva/compliance sotto
        foreach ($righeVecchie as $rigaVecchia) {
            $rigaNuova = $rigaVecchia;
            unset($rigaNuova['id'], $rigaNuova['created_at']);
            $rigaNuova['ordine_id']              = $nuovoOrdineId;
            $rigaNuova['quantita_spedita']        = 0;
            // Lo snapshot Kit (AUDIT 3 §3.3) va ricongelato dalla riserva vera
            // e propria della nuova riga (punto 6 sotto), non ereditato.
            $rigaNuova['kit_componenti_snapshot'] = null;

            $colonneR = implode(', ', array_keys($rigaNuova));
            $placeholdersR = implode(', ', array_fill(0, count($rigaNuova), '?'));
            $pdo->prepare("INSERT INTO ordini_clienti_righe ({$colonneR}) VALUES ({$placeholdersR})")
                ->execute(array_values($rigaNuova));
            $rigaNuova['id'] = (int) $pdo->lastInsertId();
            $rigaNuova['_vecchia_riga_id'] = (int) $rigaVecchia['id'];
            $righeNuove[] = $rigaNuova;
        }

        // 6. Sposta la riserva di giacenza dal vecchio ordine al nuovo, riga
        // per riga (§3 punto 3): rilascia sul vecchio, riserva sul nuovo,
        // stesso magazzino congelato su ordini_clienti.magazzino_id. Se
        // l'ordine è storico (magazzino_id NULL, pre-fix O2) si salta il
        // movimento e si logga, stesso fallback già usato in
        // qk_ordine_cliente_annulla() — la creazione della revisione resta
        // comunque permessa.
        $magazzinoId = $ordine['magazzino_id'] ?? null;
        if ($magazzinoId) {
            foreach ($righeNuove as $r) {
                if (empty($r['prodotto_id'])) {
                    continue;
                }
                $qtaOrdinata = (float) $r['quantita_ordinata'];
                $qtaStock    = (float) ($r['quantita_stock'] ?? 0);
                if ($qtaOrdinata <= 0 || $qtaStock <= 0) {
                    continue;
                }
                // quantita_spedita è garantita 0 dal guard del punto precedente
                // (nessuna spedizione esiste); si usa comunque la stessa
                // formula proporzionale di qk_ordine_cliente_annulla() per
                // difesa in profondità, non un presupposto non verificato.
                qk_rilascia_stock_ordine($pdo, (int) $r['prodotto_id'], $qtaStock, (int) $magazzinoId, (int) $r['_vecchia_riga_id']);
                qk_riserva_stock_ordine($pdo, (int) $r['prodotto_id'], $qtaStock, (int) $magazzinoId, (int) $r['id']);
            }
        } else {
            error_log("[qk_ordine_cliente_crea_revisione] Ordine $ordine_id senza magazzino_id: riserva NON spostata (ordine storico pre-fix, magazzino di origine sconosciuto).");
        }

        // 7. Ri-esegue il controllo compliance sulla nuova versione (§4.4):
        // mai ereditato "per fiducia" da quella vecchia, perché righe/quantità
        // possono essere diverse.
        $itemsCompliance = array_map(static fn (array $r) => ['prodotto_id' => $r['prodotto_id']], $righeNuove);
        $violazioni = qk_compliance_check_sale($pdo, (int) $ordine['cliente_id'], $itemsCompliance);
        if (!empty($violazioni)) {
            $messaggi = array_map(static fn (array $v) => $v['message'], $violazioni);
            throw new \RuntimeException('Impossibile creare la nuova versione per motivi di compliance: ' . implode(' | ', $messaggi));
        }

        // 8. Marca il vecchio ordine come 'superato'.
        $pdo->prepare("UPDATE ordini_clienti SET stato = 'superato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$ordine_id]);

        $pdo->commit();
        return $nuovoOrdineId;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Recupera id e numero_ordine di un ordine cliente per un link di
 * riferimento (padre/figlio di una revisione) — non l'intero dettaglio.
 * Riusata sia per "versione precedente" sia per "versione successiva"
 * (Tappa 6, badge/link nel dettaglio ordine).
 */
function qk_get_ordine_cliente_riferimento(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, numero_ordine FROM ordini_clienti WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Recupera l'eventuale "figlio" di un ordine (la nuova versione creata da
 * qk_ordine_cliente_crea_revisione() a partire da questo ordine, se esiste).
 */
function qk_get_ordine_cliente_figlio(PDO $pdo, int $ordinePadreId): ?array
{
    $st = $pdo->prepare('SELECT id, numero_ordine FROM ordini_clienti WHERE ordine_padre_id = ? LIMIT 1');
    $st->execute([$ordinePadreId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Ricerca prodotti con dettagli arricchiti per ordini di vendita.
 * Riusa la stessa query dei preventivi.
 */
function qk_search_prodotti_orders(PDO $pdo, string $q): array
{
    $stmt = $pdo->prepare("
        SELECT p.id,
               p.nome AS label,
               p.sku_interno AS sku,
               um.codice AS unita_misura,
               p.unita_misura_stock_id,
               p.descrizione_confezione AS quantita_confezione,
               p.unita_contenuto_id,
               um_comm.codice AS unita_misura_commerciale,
               p.quantita_contenuto,
               pf.rrp_min,
               pf.rrp_max,
               pf.fornitore_id AS rrp_fornitore_id,
               -- Costo base: da v_costo_acquisto_prodotti (fix 'Listino Fantasma',
               -- audit/MASTER_AUDIT_REPORT.md) — vedi commento esteso in db/init_sqlite.sql par.14b.
               CASE WHEN p.tipo = 'kit' THEN (
                   SELECT SUM(ck.quantita * vc.costo_acquisto)
                   FROM componenti_kit ck
                   LEFT JOIN v_costo_acquisto_prodotti vc ON vc.prodotto_id = ck.componente_id
                   WHERE ck.kit_id = p.id
               ) ELSE (
                   SELECT vc.costo_acquisto FROM v_costo_acquisto_prodotti vc WHERE vc.prodotto_id = p.id
               ) END AS costo_base
          FROM prodotti p
          LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
          LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
          LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.is_principale = 1
         WHERE p.attivo = 1
           AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR pf.sku_fornitore LIKE :q)
         ORDER BY p.nome
         LIMIT 60
    ");
    $stmt->execute([':q' => '%' . $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalizza campi
    foreach ($rows as &$r) {
        $r['rrp_min']   = isset($r['rrp_min'])   ? (float)$r['rrp_min']   : null;
        $r['rrp_max']   = isset($r['rrp_max'])    ? (float)$r['rrp_max']   : null;
        $r['costo_base'] = isset($r['costo_base']) ? (float)$r['costo_base'] : 0.0;
        $r['quantita_contenuto'] = isset($r['quantita_contenuto']) ? (float)$r['quantita_contenuto'] : 1.0;
    }
    unset($r);
    return $rows;
}

/**
 * Segna un ordine cliente come fatturato.
 */
function qk_ordine_cliente_segna_fatturato(PDO $pdo, int $id): void
{
    $st = $pdo->prepare("UPDATE ordini_clienti SET stato_fatturazione = 'fatturato', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $st->execute([$id]);
}

/**
 * Ripristina lo stato di fatturazione di un ordine cliente a 'da_fatturare'.
 */
function qk_ordine_cliente_segna_da_fatturare(PDO $pdo, int $id): void
{
    $st = $pdo->prepare("UPDATE ordini_clienti SET stato_fatturazione = 'da_fatturare', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $st->execute([$id]);
}

/**
 * Conteggio totale spedizioni per paginazione
 */
function qk_spedizioni_count(PDO $pdo, string $whereStr, array $params): int
{
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM spedizioni s
           JOIN ordini_clienti o ON o.id = s.ordine_id
           JOIN clienti_fornitori cf ON cf.id = o.cliente_id
          WHERE $whereStr"
    );
    $countStmt->execute($params);
    return (int) $countStmt->fetchColumn();
}

/**
 * Lista impaginata spedizioni
 */
function qk_spedizioni_list(PDO $pdo, string $whereStr, array $params, string $orderCol, string $dir, int $limit, int $offset): array
{
    $nomeClienteSql = qk_nome_cliente_sql_fragment('cf');
    $listStmt = $pdo->prepare(
        "SELECT s.id, s.numero_spedizione, s.data_spedizione, s.stato, s.vettore, s.tracking,
                s.ordine_id, o.numero_ordine,
                {$nomeClienteSql} AS cliente_nome,
                d.id AS ddt_id, d.numero_documento_trasporto
           FROM spedizioni s
           JOIN ordini_clienti o ON o.id = s.ordine_id
           JOIN clienti_fornitori cf ON cf.id = o.cliente_id
      LEFT JOIN documenti_trasporto d ON d.spedizione_id = s.id
          WHERE {$whereStr}
          ORDER BY {$orderCol} {$dir}
          LIMIT {$limit} OFFSET {$offset}"
    );
    $listStmt->execute($params);
    return $listStmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Carica dettagli ordine per nuova spedizione (pick & pack)
 */
function qk_get_ordine_per_spedizione(PDO $pdo, int $orderId): ?array
{
    $nomeClienteSql = qk_nome_cliente_sql_fragment('cf');
    $stOrd = $pdo->prepare("
        SELECT o.*,
               {$nomeClienteSql} AS cliente_nome,
               cf.email AS cliente_email,
               cf.telefono AS cliente_telefono
        FROM ordini_clienti o
        JOIN clienti_fornitori cf ON cf.id = o.cliente_id
        WHERE o.id = ?
    ");
    $stOrd->execute([$orderId]);
    $row = $stOrd->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Carica righe ordine con residuo da spedire > 0.0005
 */
function qk_get_righe_ordine_da_spedire(PDO $pdo, int $orderId): array
{
    // AUDIT 3 §1.1: qta_residua ora è espressa in Stock UoM (stessa unità di
    // lotti_giacenze.quantita_disponibile, con cui viene confrontata dalla
    // schermata "Nuova Spedizione") invece che nell'unità nativa/commerciale
    // della riga — prima le due venivano confrontate direttamente senza
    // convertirle, mostrando sistematicamente stati sbagliati per i prodotti
    // in unità commerciale (es. FUSTO vs KG). Stessa formula proporzionale
    // già usata per lo stesso scopo in qk_ordine_cliente_annulla():
    // quantita_stock * residuo_ordinato / quantita_ordinata.
    $stLines = $pdo->prepare("
        SELECT r.*,
               p.sku_interno AS prodotto_codice,
               p.nome AS prodotto_nome,
               p.traccia_lotti,
               um.codice AS uom_simbolo,
               um_stock.codice AS uom_stock_simbolo,
               (r.quantita_ordinata - r.quantita_spedita) AS qta_residua_nativa,
               CASE WHEN r.quantita_ordinata > 0
                    THEN r.quantita_stock * (r.quantita_ordinata - r.quantita_spedita) / r.quantita_ordinata
                    ELSE 0 END AS qta_residua
        FROM ordini_clienti_righe r
        LEFT JOIN prodotti p ON p.id = r.prodotto_id
        LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
        LEFT JOIN unita_misura um_stock ON um_stock.id = r.unita_misura_stock_id
        WHERE r.ordine_id = ?
          AND (r.quantita_ordinata - r.quantita_spedita) > 0.0005
        ORDER BY r.id ASC
    ");
    $stLines->execute([$orderId]);
    return $stLines->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Carica intestazione documento di trasporto (DDT)
 */
function qk_get_ddt_dettaglio(PDO $pdo, int $ddtId): ?array
{
    $st = $pdo->prepare("
        SELECT d.*,
               s.numero_spedizione, s.vettore AS s_vettore, s.tracking, s.magazzino_id AS spedizione_magazzino_id,
               o.numero_ordine
        FROM documenti_trasporto d
        JOIN ordini_clienti o ON o.id = d.ordine_id
        LEFT JOIN spedizioni s ON s.id = d.spedizione_id
        WHERE d.id = ?
    ");
    $st->execute([$ddtId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Carica righe del documento di trasporto (DDT)
 */
function qk_get_ddt_righe(PDO $pdo, int $ddtId): array
{
    $stRighe = $pdo->prepare("
        SELECT r.*,
               p.nome AS prodotto_nome, p.sku_interno AS prodotto_codice,
               um.codice AS uom_codice
        FROM documenti_trasporto_righe r
        LEFT JOIN prodotti p ON p.id = r.prodotto_id
        LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
        WHERE r.documento_trasporto_id = ?
        ORDER BY r.posizione_riga ASC
    ");
    $stRighe->execute([$ddtId]);
    return $stRighe->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Conteggio totale ordini clienti per paginazione
 */
function qk_ordini_clienti_count(PDO $pdo, string $whereStr, array $params): int
{
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ordini_clienti o
           JOIN clienti_fornitori cf ON cf.id = o.cliente_id
          WHERE $whereStr"
    );
    $countStmt->execute($params);
    return (int) $countStmt->fetchColumn();
}

/**
 * Lista impaginata ordini clienti
 */
function qk_ordini_clienti_list(PDO $pdo, string $whereStr, array $params, string $orderCol, string $dir, int $limit, int $offset): array
{
    $nomeClienteSql = qk_nome_cliente_sql_fragment('cf');
    $listStmt = $pdo->prepare(
        "SELECT o.id, o.numero_ordine, o.data_ordine, o.data_consegna_prevista, o.stato,
                (SELECT COALESCE(SUM(r.quantita_ordinata * r.prezzo_unitario * (1 - r.sconto_perc/100.0)),0) FROM ordini_clienti_righe r WHERE r.ordine_id = o.id) AS totale,
                {$nomeClienteSql} AS cliente_nome
           FROM ordini_clienti o
           JOIN clienti_fornitori cf ON cf.id = o.cliente_id
          WHERE {$whereStr}
          ORDER BY {$orderCol} {$dir}
          LIMIT {$limit} OFFSET {$offset}"
    );
    $listStmt->execute($params);
    return $listStmt->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Recupera l'intestazione completa di un ordine cliente con nome cliente
 */
function qk_get_ordine_cliente_dettaglio(PDO $pdo, int $id): ?array
{
    $nomeClienteSql = qk_nome_cliente_sql_fragment('cf');
    $st = $pdo->prepare("
        SELECT o.*,
               {$nomeClienteSql} AS cliente_nome,
               cf.codice AS cliente_codice,
               cf.telefono AS cliente_telefono, cf.email AS cliente_email,
               p.numero_preventivo AS preventivo_numero,
               u.username AS creato_da_username,
               lst.nome AS listino_nome
          FROM ordini_clienti o
          JOIN clienti_fornitori cf ON cf.id = o.cliente_id
          LEFT JOIN preventivi p ON p.id = o.preventivo_id
          LEFT JOIN utenti u ON u.id = o.creato_da
          LEFT JOIN listini lst ON lst.id = o.listino_id
         WHERE o.id = ?
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Recupera le righe di un ordine cliente con dati prodotto e UoM
 */
function qk_get_ordine_cliente_righe(PDO $pdo, int $id): array
{
    // AUDIT 3 V9 (già corretto e verificato in precedenza in questo stesso
    // ciclo di lavoro): imponibile_riga/margine_unit/margine_perc DEVONO
    // applicare sconto_perc, altrimenti la pagina di dettaglio mostra totali
    // e margini gonfiati (o, se le colonne mancano del tutto, sempre zero).
    $stLines = $pdo->prepare("
        SELECT r.*,
               p.nome AS prodotto_nome, p.sku_interno AS prodotto_codice, p.traccia_lotti,
               um.codice AS uom_simbolo,
               ((r.prezzo_unitario * (1 - r.sconto_perc/100.0)) - COALESCE(r.costo_unitario, 0)) AS margine_unit,
               CASE WHEN (r.prezzo_unitario * (1 - r.sconto_perc/100.0)) > 0
                    THEN (((r.prezzo_unitario * (1 - r.sconto_perc/100.0)) - COALESCE(r.costo_unitario, 0)) / (r.prezzo_unitario * (1 - r.sconto_perc/100.0))) * 100
                    ELSE 0 END AS margine_perc,
               (r.quantita_ordinata * r.prezzo_unitario * (1 - r.sconto_perc/100.0)) AS imponibile_riga
          FROM ordini_clienti_righe r
          LEFT JOIN prodotti p ON p.id = r.prodotto_id
          LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
         WHERE r.ordine_id = ?
         ORDER BY r.id ASC
    ");
    $stLines->execute([$id]);
    return $stLines->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recupera le spedizioni effettuate per un ordine cliente
 */
function qk_get_ordine_cliente_spedizioni(PDO $pdo, int $id): array
{
    $stShip = $pdo->prepare("
        SELECT s.*, m.nome AS magazzino_nome
          FROM spedizioni s
          LEFT JOIN magazzini m ON m.id = s.magazzino_id
         WHERE s.ordine_id = ?
         ORDER BY s.data_spedizione DESC, s.id DESC
    ");
    $stShip->execute([$id]);
    return $stShip->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recupera le foto allegate alle spedizioni di un ordine
 */
function qk_get_ordine_cliente_foto_spedizioni(PDO $pdo, int $id): array
{
    $stPhotos = $pdo->prepare("
        SELECT f.*, s.numero_spedizione
          FROM spedizioni_foto f
          JOIN spedizioni s ON s.id = f.spedizione_id
         WHERE s.ordine_id = ?
         ORDER BY f.created_at DESC
    ");
    $stPhotos->execute([$id]);
    return $stPhotos->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recupera i documenti di trasporto (DDT) emessi per un ordine
 */
function qk_get_ordine_cliente_ddt(PDO $pdo, int $id): array
{
    $stDdt = $pdo->prepare("SELECT d.* FROM documenti_trasporto d WHERE d.ordine_id = ? ORDER BY d.created_at DESC");
    $stDdt->execute([$id]);
    return $stDdt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Crea una nuova spedizione ed il relativo DDT in un'unica transazione.
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 6
 * (23/07/2026, problema 4.C.1): wrapper sottile — la logica di dominio è
 * stata estratta in App\Services\SpedizioneCalculatorService (+
 * App\Repositories\SpedizioneRepository per l'accesso dati), stesso schema
 * già usato da qk_insert_ordine_diretto() per l'ordine diretto. Firma
 * pubblica invariata: nessun chiamante esterno impattato.
 */
function qk_elabora_crea_spedizione(PDO $pdo, int $orderId, array $postData, array $filesData, int $userId): array
{
    require_once __DIR__ . '/../app/Repositories/SpedizioneRepository.php';
    require_once __DIR__ . '/../app/Services/SpedizioneCalculatorService.php';

    $repository = new \App\Repositories\SpedizioneRepository($pdo);
    $service = new \App\Services\SpedizioneCalculatorService($pdo, $repository);

    return $service->creaSpedizione($orderId, $postData, $filesData, $userId);
}

