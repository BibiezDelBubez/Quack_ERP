<?php
declare(strict_types=1);
/**
 * includes/document_common.php
 * Utility condivise da tutti i cicli (Acquisti, Vendite) per la gestione
 * di indirizzi e contatti denormalizzati (architettura Snapshot & Override).
 */

/**
 * Genera un hash SHA-256 pulito concatenando i campi essenziali dell'indirizzo.
 * Rende la stringa minuscola e rimuove gli spazi extra per evitare falsi positivi.
 *
 * @param array|null $addr Dati dell'indirizzo
 * @return string Hash SHA-256
 */
function qk_generate_address_hash(?array $addr): string
{
    if (!$addr) return '';
    $components = [
        trim((string)($addr['nome_sede'] ?? '')),
        trim((string)($addr['via'] ?? '')),
        trim((string)($addr['civico'] ?? '')),
        trim((string)($addr['cap'] ?? '')),
        trim((string)($addr['citta'] ?? '')),
        trim((string)($addr['provincia'] ?? '')),
        trim((string)($addr['nazione'] ?? 'IT'))
    ];
    $raw_string = implode('|', array_map('mb_strtolower', $components));
    return hash('sha256', $raw_string);
}

/**
 * Genera un hash SHA-256 pulito concatenando i campi essenziali del contatto.
 * Rende la stringa minuscola e rimuove gli spazi extra per evitare falsi positivi.
 *
 * @param array|null $cont Dati del contatto
 * @return string Hash SHA-256
 */
function qk_generate_contact_hash(?array $cont): string
{
    if (!$cont) return '';
    $components = [
        trim((string)($cont['nome'] ?? '')),
        trim((string)($cont['cognome'] ?? '')),
        trim((string)($cont['email'] ?? '')),
        trim((string)($cont['telefono'] ?? ''))
    ];
    $raw_string = implode('|', array_map('mb_strtolower', $components));
    return hash('sha256', $raw_string);
}

/**
 * Calcola se uno snapshot documento (indirizzo o contatto) e' "personalizzato"
 * rispetto al record master, confrontando gli hash generati da $hashFn.
 * Logica prima duplicata identica in qk_upsert_document_address() e
 * qk_upsert_document_contact() (Revisione Incrociata Sez.5.6.3/7.2.2).
 *
 * @param array|null $masterRecord Il record master (indirizzo o clienti_fornitori),
 *        null se non e' stato passato un master_id o se il master non e' stato trovato.
 * @param array      $payload      I dati ricevuti dal client per lo snapshot.
 * @param bool       $masterIdGiven true se e' stato passato un master_id esplicito
 *        (anche se poi $masterRecord risulta null perche' il record non esiste piu').
 * @param callable   $hashFn       qk_generate_address_hash() o qk_generate_contact_hash()
 * @return array{personalizzato:int, hash_originale:?string}
 */
function qk_snapshot_stato_personalizzazione(?array $masterRecord, array $payload, bool $masterIdGiven, callable $hashFn): array
{
    if (!$masterIdGiven) {
        return ['personalizzato' => 1, 'hash_originale' => null];
    }
    if (!$masterRecord) {
        // master_id fornito ma record non trovato: comportamento invariato
        // rispetto al codice originale (personalizzato=0, hash=null).
        return ['personalizzato' => 0, 'hash_originale' => null];
    }
    $masterHash = $hashFn($masterRecord);
    $payloadHash = $hashFn($payload);
    return [
        'personalizzato' => ($masterHash !== $payloadHash) ? 1 : 0,
        'hash_originale' => $masterHash,
    ];
}

/**
 * Salva o aggiorna un record di snapshot per l'indirizzo di consegna di un documento.
 *
 * @param PDO      $pdo Database PDO connection
 * @param array    $payload Dati dell'indirizzo inviati dal client
 * @param int|null $existingId ID dell'indirizzo di snapshot esistente (se in modifica)
 * @return int ID del record inserito o aggiornato in indirizzi_documenti
 */
function qk_upsert_document_address(PDO $pdo, array $payload, ?int $existingId = null): int
{
    $masterId = ($payload['indirizzo_master_id'] ?? '') !== '' ? (int)$payload['indirizzo_master_id'] : null;
    $masterAddr = null;
    if ($masterId !== null) {
        $st = $pdo->prepare('SELECT * FROM indirizzi WHERE id = ?');
        $st->execute([$masterId]);
        $masterAddr = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $stato = qk_snapshot_stato_personalizzazione($masterAddr, $payload, $masterId !== null, 'qk_generate_address_hash');
    $personalizzato = $stato['personalizzato'];
    $masterHash = $stato['hash_originale'];

    $params = [
        $masterId,
        $payload['nome_sede'] ?? null,
        $payload['presso'] ?? null,
        $payload['via'] ?? '',
        $payload['civico'] ?? null,
        $payload['cap'] ?? '',
        $payload['citta'] ?? '',
        $payload['provincia'] ?? null,
        $payload['nazione'] ?? 'IT',
        $payload['referente_consegna'] ?? null,
        $payload['telefono_consegna'] ?? null,
        $payload['note_consegna'] ?? null,
        $personalizzato,
        $masterHash
    ];

    if ($existingId && $existingId > 0) {
        $st = $pdo->prepare('UPDATE indirizzi_documenti SET
            indirizzo_master_id = ?, nome_sede = ?, presso = ?, via = ?, civico = ?,
            cap = ?, citta = ?, provincia = ?, nazione = ?, referente_consegna = ?,
            telefono_consegna = ?, note_consegna = ?, personalizzato = ?, hash_originale = ?
            WHERE id = ?');
        $params[] = $existingId;
        $st->execute($params);
        return $existingId;
    } else {
        $st = $pdo->prepare('INSERT INTO indirizzi_documenti (
            indirizzo_master_id, nome_sede, presso, via, civico, cap, citta, provincia,
            nazione, referente_consegna, telefono_consegna, note_consegna, personalizzato, hash_originale
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

/**
 * Salva o aggiorna un record di snapshot per il contatto referente di un documento.
 *
 * @param PDO      $pdo Database PDO connection
 * @param array    $payload Dati del contatto inviati dal client
 * @param int|null $existingId ID del contatto di snapshot esistente (se in modifica)
 * @return int ID del record inserito o aggiornato in contatti_documenti
 */
function qk_upsert_document_contact(PDO $pdo, array $payload, ?int $existingId = null): int
{
    $masterId = ($payload['contatto_master_id'] ?? '') !== '' ? (int)$payload['contatto_master_id'] : null;
    $masterCont = null;
    if ($masterId !== null) {
        $st = $pdo->prepare('SELECT * FROM clienti_fornitori WHERE id = ?');
        $st->execute([$masterId]);
        $masterCont = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $stato = qk_snapshot_stato_personalizzazione($masterCont, $payload, $masterId !== null, 'qk_generate_contact_hash');
    $personalizzato = $stato['personalizzato'];
    $masterHash = $stato['hash_originale'];

    $params = [
        $masterId,
        $payload['nome'] ?? '',
        $payload['cognome'] ?? '',
        $payload['email'] ?? null,
        $payload['telefono'] ?? null,
        $payload['cellulare'] ?? null,
        $personalizzato,
        $masterHash
    ];

    if ($existingId && $existingId > 0) {
        $st = $pdo->prepare('UPDATE contatti_documenti SET
            contatto_master_id = ?, nome = ?, cognome = ?, email = ?,
            telefono = ?, cellulare = ?, personalizzato = ?, hash_originale = ?
            WHERE id = ?');
        $params[] = $existingId;
        $st->execute($params);
        return $existingId;
    } else {
        $st = $pdo->prepare('INSERT INTO contatti_documenti (
            contatto_master_id, nome, cognome, email, telefono, cellulare, personalizzato, hash_originale
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

/**
 * REPOSITORY: recupera dal DB i 3 record grezzi (azienda, cliente, consegna)
 * necessari per costruire lo snapshot denormalizzato di un documento.
 * Nessuna formattazione qui: solo accesso dati (Revisione Incrociata Sez.5.6.2/7.2.2).
 *
 * @return array{azienda: ?array, cliente: ?array, consegna: ?array}
 */
function qk_fetch_document_entities(PDO $pdo, array $values): array
{
    $azienda = null;
    if (!empty($values['azienda_interna_id'])) {
        $stAz = $pdo->prepare("
            SELECT cf.ragione_sociale, cf.partita_iva, cf.codice_fiscale, cf.email, cf.telefono,
                   ind.via, ind.civico, ind.cap, ind.citta, ind.provincia, ind.nazione,
                   az.iban_default
            FROM aziende_interne az
            JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
            LEFT JOIN indirizzi ind ON ind.soggetto_id = cf.id AND ind.tipo = 'sede_legale'
            WHERE az.id = ?
            LIMIT 1
        ");
        $stAz->execute([(int)$values['azienda_interna_id']]);
        $azienda = $stAz->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $cliente = null;
    if (!empty($values['cliente_id'])) {
        $stCli = $pdo->prepare("
            SELECT cf.ragione_sociale, cf.nome, cf.cognome, cf.partita_iva, cf.codice_fiscale,
                   ind.via, ind.civico, ind.cap, ind.citta, ind.provincia, ind.nazione
            FROM clienti_fornitori cf
            LEFT JOIN indirizzi ind ON ind.soggetto_id = cf.id AND ind.tipo = 'sede_legale'
            WHERE cf.id = ?
            LIMIT 1
        ");
        $stCli->execute([(int)$values['cliente_id']]);
        $cliente = $stCli->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $consegna = null;
    if (!empty($values['consegna_via'])) {
        $consegna = [
            'via'                => $values['consegna_via'] ?? null,
            'civico'             => $values['consegna_civico'] ?? null,
            'cap'                => $values['consegna_cap'] ?? null,
            'citta'              => $values['consegna_citta'] ?? null,
            'provincia'          => $values['consegna_provincia'] ?? null,
            'nazione'            => $values['consegna_nazione'] ?? null,
            'presso'             => $values['consegna_presso'] ?? null,
            'referente_consegna' => $values['consegna_referente'] ?? null,
            'telefono_consegna'  => $values['consegna_telefono'] ?? null,
            'note_consegna'      => $values['consegna_note'] ?? null,
        ];
    } elseif (!empty($values['indirizzo_spedizione_id']) && is_numeric($values['indirizzo_spedizione_id'])) {
        $stInd = $pdo->prepare("SELECT * FROM indirizzi WHERE id = ?");
        $stInd->execute([(int)$values['indirizzo_spedizione_id']]);
        $consegna = $stInd->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return ['azienda' => $azienda, 'cliente' => $cliente, 'consegna' => $consegna];
}

/**
 * Formatta una riga indirizzo (via/civico/cap/citta/provincia) in una singola
 * stringa leggibile, omettendo i campi vuoti (Revisione Incrociata Sez.5.2.4/5.6.2):
 * PRIMA di questa correzione, indirizzi con campi mancanti producevano
 * stringhe tipo ",  ()" (virgola/spazi/parentesi vuote) perche' la concatenazione
 * non filtrava le parti vuote. Restituisce null se non c'e' nulla da mostrare.
 */
function qk_format_indirizzo_stringa(?array $addr): ?string
{
    if (!$addr) {
        return null;
    }

    $viaCivico = trim(($addr['via'] ?? '') . ' ' . ($addr['civico'] ?? ''));

    $cittaParti = array_filter([
        trim((string)($addr['cap'] ?? '')),
        trim((string)($addr['citta'] ?? '')),
    ], static fn($s) => $s !== '');
    $cittaLinea = implode(' ', $cittaParti);

    $provincia = trim((string)($addr['provincia'] ?? ''));
    if ($provincia !== '' && $cittaLinea !== '') {
        $cittaLinea .= ' (' . $provincia . ')';
    } elseif ($provincia !== '') {
        $cittaLinea = '(' . $provincia . ')';
    }

    $parti = array_filter([$viaCivico, $cittaLinea], static fn($s) => $s !== '');
    $risultato = implode(', ', $parti);

    return $risultato !== '' ? $risultato : null;
}

/**
 * FORMATTER: trasforma le 3 entita' grezze (azienda/cliente/consegna) nel
 * formato piatto atteso dai chiamanti di qk_denormalize_document_details().
 * Funzione pura: nessun accesso a PDO/DB (Revisione Incrociata Sez.5.6.2/7.2.2),
 * facilmente testabile in isolamento.
 *
 * @param array{azienda: ?array, cliente: ?array, consegna: ?array} $entities
 */
function qk_format_document_snapshot(array $entities): array
{
    $azienda  = $entities['azienda']  ?? null;
    $cliente  = $entities['cliente']  ?? null;
    $consegna = $entities['consegna'] ?? null;

    $cliente_label = '';
    if ($cliente) {
        $cliente_label = $cliente['ragione_sociale'] ?: trim(($cliente['nome'] ?? '') . ' ' . ($cliente['cognome'] ?? ''));
    }

    return [
        'azienda_ragione_sociale' => $azienda['ragione_sociale'] ?? null,
        'azienda_partita_iva'     => $azienda['partita_iva'] ?? null,
        'azienda_codice_fiscale'  => $azienda['codice_fiscale'] ?? null,
        'azienda_indirizzo'       => qk_format_indirizzo_stringa($azienda),
        'azienda_telefono'        => $azienda['telefono'] ?? null,
        'azienda_email'           => $azienda['email'] ?? null,
        'azienda_iban'            => $azienda['iban_default'] ?? null,
        'cliente_ragione_sociale' => $cliente_label ?: null,
        'cliente_partita_iva'     => $cliente['partita_iva'] ?? null,
        'cliente_codice_fiscale'  => $cliente['codice_fiscale'] ?? null,
        'cliente_indirizzo'       => qk_format_indirizzo_stringa($cliente),
        'consegna_via'            => $consegna['via'] ?? null,
        'consegna_civico'         => $consegna['civico'] ?? null,
        'consegna_cap'            => $consegna['cap'] ?? null,
        'consegna_citta'          => $consegna['citta'] ?? null,
        'consegna_provincia'      => $consegna['provincia'] ?? null,
        'consegna_nazione'        => $consegna['nazione'] ?? null,
        'consegna_presso'         => $consegna['presso'] ?? null,
        'consegna_referente'      => $consegna['referente_consegna'] ?? null,
        'consegna_telefono'       => $consegna['telefono_consegna'] ?? null,
        'consegna_note'           => $consegna['note_consegna'] ?? null,
    ];
}

/**
 * Recupera e restituisce i dati denormalizzati di azienda, cliente e consegna
 * per l'integrita' storica. Ora e' un semplice orchestratore Repository+Formatter
 * (Revisione Incrociata Sez.5.6.2/7.2.2): qk_fetch_document_entities() legge dal
 * DB, qk_format_document_snapshot() formatta (funzione pura, testabile senza DB).
 *
 * NOTA COMPORTAMENTALE: questa correzione risolve anche il bug delle stringhe
 * indirizzo con campi vuoti (es. ",  ()") descritto in Revisione Sez.5.2.4 -
 * i documenti gia' salvati con quel bug NON vengono retroattivamente corretti
 * (sono snapshot storici), ma i nuovi salvataggi useranno il formato pulito.
 */
function qk_denormalize_document_details(PDO $pdo, array $values): array
{
    $entities = qk_fetch_document_entities($pdo, $values);
    return qk_format_document_snapshot($entities);
}


/**
 * Risolve i dati dell'azienda interna emittente da mostrare in stampa
 * (PDF/Word) su un documento di vendita (preventivo o ordine cliente),
 * rispettando l'integrità storica (Revisione Incrociata §5.3.3, doc
 * "00 - Anagrafica Unica" §3.3, fix 14/07/2026).
 *
 * PRIMA: la stampa rifaceva sempre una query dal vivo su aziende_interne,
 * ignorando le colonne denormalizzate azienda_* già scritte correttamente
 * sulla riga del documento alla creazione — un preventivo vecchio cambiava
 * intestazione fiscale (ragione sociale, P.IVA...) a ogni ristampa se
 * l'anagrafica dell'azienda cambiava nel frattempo, o se cambiava quale
 * azienda fosse "predefinita". Duplicato identico in due punti di
 * SalesQuotesController.php (generazione PDF e Word).
 *
 * ORA: se il documento ha le colonne azienda_* valorizzate (scritte da
 * qk_denormalize_document_details() alla creazione/modifica), sono quelle a
 * fare fede, senza query — il documento resta storicamente accurato. Il
 * fallback dal vivo scatta SOLO per documenti legacy creati prima che queste
 * colonne esistessero (azienda_ragione_sociale vuota), con lo stesso
 * fallback a due livelli di prima (azienda_interna_id esplicito, poi
 * predefinita = 1) MA con l'aggiunta del filtro attivo = 1 (Revisione
 * §5.3.1/§5.3.2: un'azienda disattivata non deve mai essere scelta come
 * emittente di default).
 *
 * header_path/logo_path (branding grafico) sono sempre risolti dal vivo,
 * deliberatamente: rappresentano l'aspetto attuale dei documenti stampati,
 * non un dato storico-fiscale da congelare — comportamento invariato.
 *
 * @param array $documento Riga di preventivi/ordini_clienti (deve contenere
 *        almeno azienda_interna_id e, se presenti, le colonne azienda_*).
 * @return array{ragione_sociale:?string, partita_iva:?string,
 *         codice_fiscale:?string, indirizzo:?string, telefono:?string,
 *         email:?string, iban_default:?string, header_path:?string,
 *         logo_path:?string}
 */
function qk_resolve_azienda_emittente_stampa(PDO $pdo, array $documento): array
{
    // Branding: sempre dal vivo (vedi motivazione sopra).
    $aziendaLive = null;
    if (!empty($documento['azienda_interna_id'])) {
        $st = $pdo->prepare(
            "SELECT cf.*, az.*
               FROM aziende_interne az
               JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
              WHERE az.id = ? LIMIT 1"
        );
        $st->execute([(int) $documento['azienda_interna_id']]);
        $aziendaLive = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$aziendaLive) {
        $st = $pdo->query(
            "SELECT cf.*, az.*
               FROM aziende_interne az
               JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
              WHERE az.predefinita = 1 AND az.attivo = 1 LIMIT 1"
        );
        $aziendaLive = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $frozen = !empty($documento['azienda_ragione_sociale']);

    return [
        'ragione_sociale' => $frozen ? $documento['azienda_ragione_sociale'] : ($aziendaLive['ragione_sociale'] ?? null),
        'partita_iva'     => $frozen ? ($documento['azienda_partita_iva'] ?? null)    : ($aziendaLive['partita_iva'] ?? null),
        'codice_fiscale'  => $frozen ? ($documento['azienda_codice_fiscale'] ?? null) : ($aziendaLive['codice_fiscale'] ?? null),
        'indirizzo'       => $frozen ? ($documento['azienda_indirizzo'] ?? null)      : qk_format_indirizzo_stringa($aziendaLive ?: null),
        'telefono'        => $frozen ? ($documento['azienda_telefono'] ?? null)       : ($aziendaLive['telefono'] ?? null),
        'email'           => $frozen ? ($documento['azienda_email'] ?? null)          : ($aziendaLive['email'] ?? null),
        'iban_default'    => $frozen ? ($documento['azienda_iban'] ?? null)           : ($aziendaLive['iban_default'] ?? null),
        'header_path'     => $aziendaLive['header_path'] ?? null,
        'logo_path'       => $aziendaLive['logo_path'] ?? null,
    ];
}

/**
 * Calcola il mittente (azienda interna + indirizzo) a partire dal magazzino di
 * spedizione, da congelare su documenti_trasporto.mittente_* al momento della
 * creazione del DDT — mai da ricalcolare in stampa (Revisione Gestione Magazzino
 * e Lotti §3, stesso principio di qk_resolve_azienda_emittente_stampa() sopra).
 *
 * Se il magazzino non ha un'azienda interna assegnata (magazzini.azienda_interna_id
 * è nullable) o non è indicato, ricade su qk_azienda_predefinita() — stesso fallback
 * già usato per preventivi/ordini/RFQ. Se il magazzino non ha un indirizzo assegnato,
 * ricade sulla sede legale dell'azienda risolta.
 */
function qk_calcola_mittente_ddt(PDO $pdo, ?int $magazzinoId): array
{
    $azienda = null;
    $indirizzoRow = null;

    if ($magazzinoId) {
        $st = $pdo->prepare('SELECT azienda_interna_id, indirizzo_id FROM magazzini WHERE id = ?');
        $st->execute([$magazzinoId]);
        $mg = $st->fetch(PDO::FETCH_ASSOC);

        if ($mg && $mg['azienda_interna_id']) {
            // Riusa qk_azienda_interna_by_id() (includes/anagrafiche.php) invece di
            // duplicare la query inline (Fase 3, microtappa 3.4) — stesso filtro
            // (nessun attivo=1: un id esplicito resta valido anche per documenti
            // storici, vedi il docblock di qk_azienda_interna_by_id()), quindi
            // nessun cambio di comportamento sul risultato.
            $azienda = qk_azienda_interna_by_id($pdo, (int) $mg['azienda_interna_id']);
        }
        if ($mg && $mg['indirizzo_id']) {
            $st3 = $pdo->prepare('SELECT * FROM indirizzi WHERE id = ?');
            $st3->execute([(int) $mg['indirizzo_id']]);
            $indirizzoRow = $st3->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    // qk_azienda_interna_by_id() e qk_azienda_predefinita() uniscono entrambe
    // cf.*+az.* (colonna 'id' duplicata: vince az.id) — il soggetto_id da usare
    // nel fallback indirizzo è quindi SEMPRE 'soggetto_id' (unica a quella chiave,
    // presente solo su az.*), mai 'id'.
    if (!$azienda) {
        $azienda = qk_azienda_predefinita($pdo) ?: [];
    }
    $soggettoIdPerIndirizzo = $azienda['soggetto_id'] ?? null;

    if (!$indirizzoRow && $soggettoIdPerIndirizzo) {
        $st4 = $pdo->prepare(
            "SELECT * FROM indirizzi WHERE soggetto_id = ? AND tipo = 'sede_legale'
              ORDER BY predefinito DESC, id ASC LIMIT 1"
        );
        $st4->execute([(int) $soggettoIdPerIndirizzo]);
        $indirizzoRow = $st4->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'ragione_sociale' => $azienda['ragione_sociale'] ?? null,
        'partita_iva'     => $azienda['partita_iva'] ?? null,
        'codice_fiscale'  => $azienda['codice_fiscale'] ?? null,
        'indirizzo'       => qk_format_indirizzo_stringa($indirizzoRow),
        'telefono'        => $azienda['telefono'] ?? null,
        'email'           => $azienda['email'] ?? null,
    ];
}

/**
 * Risolve il mittente da mostrare in stampa sul DDT, rispettando la storicità: legge
 * prima le colonne congelate `mittente_*` su documenti_trasporto, con fallback dal
 * vivo (via qk_calcola_mittente_ddt()) solo per DDT legacy privi di quelle colonne.
 *
 * @param array $ddt         riga di documenti_trasporto (deve includere le colonne mittente_*)
 * @param int|null $magazzinoId  spedizioni.magazzino_id collegato al DDT, usato solo nel
 *                                ramo legacy (documenti già congelati non lo consultano)
 */
function qk_resolve_mittente_ddt_stampa(PDO $pdo, array $ddt, ?int $magazzinoId): array
{
    if (!empty($ddt['mittente_ragione_sociale'])) {
        return [
            'ragione_sociale' => $ddt['mittente_ragione_sociale'],
            'partita_iva'     => $ddt['mittente_partita_iva'] ?? null,
            'codice_fiscale'  => $ddt['mittente_codice_fiscale'] ?? null,
            'indirizzo'       => $ddt['mittente_indirizzo'] ?? null,
            'telefono'        => $ddt['mittente_telefono'] ?? null,
            'email'           => $ddt['mittente_email'] ?? null,
        ];
    }
    return qk_calcola_mittente_ddt($pdo, $magazzinoId);
}

/**
 * Risolve dal vivo i dati del destinatario (cliente + indirizzo di consegna)
 * per un DDT — usata SOLO come fallback legacy da qk_resolve_destinatario_ddt_stampa()
 * per documenti privi delle colonne congelate destinatario_*. Estratta dalla
 * query che prima viveva solo dentro SpedizioniController::ddtView() (Revisione
 * Ciclo Vendite §1.5, PIANO - Congelare Destinatario sul DDT.md, 20/07/2026).
 *
 * A differenza di qk_calcola_mittente_ddt() (un solo campo indirizzo pre-formattato),
 * restituisce i campi indirizzo strutturati: è così che il template di stampa
 * (_ddt_view.php) li consuma.
 *
 * @param int      $destinatarioId          documenti_trasporto.destinatario_id
 * @param int|null $indirizzoDestinazioneId documenti_trasporto.indirizzo_destinazione_id
 */
function qk_calcola_destinatario_ddt(PDO $pdo, int $destinatarioId, ?int $indirizzoDestinazioneId): array
{
    $st = $pdo->prepare(
        "SELECT COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS ragione_sociale,
                cf.partita_iva, cf.codice_fiscale
           FROM clienti_fornitori cf
          WHERE cf.id = ?"
    );
    $st->execute([$destinatarioId]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $indirizzo = [];
    if ($indirizzoDestinazioneId) {
        $st2 = $pdo->prepare("SELECT via, civico, cap, citta, provincia, nazione, nome_sede FROM indirizzi WHERE id = ?");
        $st2->execute([$indirizzoDestinazioneId]);
        $indirizzo = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    return [
        'ragione_sociale' => $cliente['ragione_sociale'] ?? null,
        'partita_iva'     => $cliente['partita_iva'] ?? null,
        'codice_fiscale'  => $cliente['codice_fiscale'] ?? null,
        'via'             => $indirizzo['via'] ?? null,
        'civico'          => $indirizzo['civico'] ?? null,
        'cap'             => $indirizzo['cap'] ?? null,
        'citta'           => $indirizzo['citta'] ?? null,
        'provincia'       => $indirizzo['provincia'] ?? null,
        'nazione'         => $indirizzo['nazione'] ?? null,
        'nome_sede'       => $indirizzo['nome_sede'] ?? null,
    ];
}

/**
 * Risolve i dati del destinatario da mostrare in stampa sul DDT, rispettando
 * la storicità: legge prima le colonne congelate `destinatario_*` su
 * documenti_trasporto, con fallback dal vivo (qk_calcola_destinatario_ddt())
 * solo per DDT legacy privi di quelle colonne. Mirror esatto di
 * qk_resolve_mittente_ddt_stampa() sopra.
 *
 * @param array $ddt riga di documenti_trasporto (deve includere le colonne
 *        destinatario_*, destinatario_id, indirizzo_destinazione_id)
 */
function qk_resolve_destinatario_ddt_stampa(PDO $pdo, array $ddt): array
{
    if (!empty($ddt['destinatario_ragione_sociale'])) {
        return [
            'ragione_sociale' => $ddt['destinatario_ragione_sociale'],
            'partita_iva'     => $ddt['destinatario_partita_iva'] ?? null,
            'codice_fiscale'  => $ddt['destinatario_codice_fiscale'] ?? null,
            'via'             => $ddt['destinatario_via'] ?? null,
            'civico'          => $ddt['destinatario_civico'] ?? null,
            'cap'             => $ddt['destinatario_cap'] ?? null,
            'citta'           => $ddt['destinatario_citta'] ?? null,
            'provincia'       => $ddt['destinatario_provincia'] ?? null,
            'nazione'         => $ddt['destinatario_nazione'] ?? null,
            'nome_sede'       => $ddt['destinatario_nome_sede'] ?? null,
        ];
    }
    return qk_calcola_destinatario_ddt($pdo, (int)$ddt['destinatario_id'], !empty($ddt['indirizzo_destinazione_id']) ? (int)$ddt['indirizzo_destinazione_id'] : null);
}

/**
 * Calcola in modo centralizzato e uniforme l'imponibile, l'IVA di riga e il totale riga.
 *
 * @param float $qty       Quantità
 * @param float $price     Prezzo unitario
 * @param float $discount  Percentuale sconto (0-100)
 * @param float $vatPerc   Percentuale IVA (es. 22.0)
 * @return array{imponibile:float, iva_riga:float, totale_riga:float}
 */
function qk_calcola_totali_riga(float $qty, float $price, float $discount = 0.0, float $vatPerc = 22.0): array
{
    $imponibile = round($qty * $price * (1.0 - ($discount / 100.0)), 2);
    $iva_riga   = round($imponibile * ($vatPerc / 100.0), 2);
    $totale_riga = round($imponibile + $iva_riga, 2);

    return [
        'imponibile'  => $imponibile,
        'iva_riga'    => $iva_riga,
        'totale_riga' => $totale_riga,
    ];
}

/**
 * Valida che le righe di un documento abbiano quantità > 0 e prezzo >= 0 (o > 0 se $requirePrice è true).
 *
 * @param array $righe        Array delle righe dal form POST
 * @param array &$errors      Riferimento all'array degli errori
 * @param bool  $requirePrice Se true, il prezzo unitario deve essere strettamente > 0
 * @return bool True se valide, false in presenza di errori
 */
function qk_validate_document_rows(array $righe, array &$errors, bool $requirePrice = false): bool
{
    $valid = true;
    if (empty($righe)) {
        $errors['righe'] = 'Inserisci almeno una riga.';
        return false;
    }

    foreach ($righe as $i => $r) {
        $qta    = (float)($r['quantita'] ?? $r['quantita_richiesta'] ?? $r['quantita_offerta'] ?? $r['quantita_ordinata'] ?? 0);
        $prezzo = (float)($r['prezzo_unitario'] ?? $r['prezzo_indicativo'] ?? 0);

        if ($qta <= 0) {
            $errors["riga_{$i}_quantita"] = 'La quantità deve essere maggiore di zero.';
            $valid = false;
        }
        if ($requirePrice ? ($prezzo <= 0) : ($prezzo < 0)) {
            $errors["riga_{$i}_prezzo"] = $requirePrice ? 'Il prezzo unitario deve essere maggiore di zero.' : 'Il prezzo non può essere negativo.';
            $valid = false;
        }
    }

    return $valid;
}
