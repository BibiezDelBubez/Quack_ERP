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
    $masterHash = null;
    $personalizzato = 0;

    if ($masterId !== null) {
        $st = $pdo->prepare('SELECT * FROM indirizzi WHERE id = ?');
        $st->execute([$masterId]);
        $masterAddr = $st->fetch(PDO::FETCH_ASSOC);
        if ($masterAddr) {
            $masterHash = qk_generate_address_hash($masterAddr);
            $payloadHash = qk_generate_address_hash($payload);
            $personalizzato = ($masterHash !== $payloadHash) ? 1 : 0;
        }
    } else {
        $personalizzato = 1;
    }

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
    $masterHash = null;
    $personalizzato = 0;

    if ($masterId !== null) {
        $st = $pdo->prepare('SELECT * FROM clienti_fornitori WHERE id = ?');
        $st->execute([$masterId]);
        $masterCont = $st->fetch(PDO::FETCH_ASSOC);
        if ($masterCont) {
            $masterHash = qk_generate_contact_hash($masterCont);
            $payloadHash = qk_generate_contact_hash($payload);
            $personalizzato = ($masterHash !== $payloadHash) ? 1 : 0;
        }
    } else {
        $personalizzato = 1;
    }

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
 * Recupera e restituisce i dati denormalizzati di azienda, cliente e consegna per l'integrità storica.
 */
function qk_denormalize_document_details(PDO $pdo, array $values): array
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
        $azienda = $stAz->fetch(PDO::FETCH_ASSOC);
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
        $cliente = $stCli->fetch(PDO::FETCH_ASSOC);
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
        $consegna = $stInd->fetch(PDO::FETCH_ASSOC);
    }

    $azienda_ragione_sociale = $azienda['ragione_sociale'] ?? null;
    $azienda_partita_iva     = $azienda['partita_iva'] ?? null;
    $azienda_codice_fiscale  = $azienda['codice_fiscale'] ?? null;
    $azienda_indirizzo       = $azienda ? trim(($azienda['via'] ?? '') . ' ' . ($azienda['civico'] ?? '') . ', ' . ($azienda['cap'] ?? '') . ' ' . ($azienda['citta'] ?? '') . ' (' . ($azienda['provincia'] ?? '') . ')') : null;
    $azienda_telefono        = $azienda['telefono'] ?? null;
    $azienda_email           = $azienda['email'] ?? null;
    $azienda_iban            = $azienda['iban_default'] ?? null;

    $cliente_label = '';
    if ($cliente) {
        $cliente_label = $cliente['ragione_sociale'] ?: trim(($cliente['nome'] ?? '') . ' ' . ($cliente['cognome'] ?? ''));
    }
    $cliente_ragione_sociale = $cliente_label ?: null;
    $cliente_partita_iva     = $cliente['partita_iva'] ?? null;
    $cliente_codice_fiscale  = $cliente['codice_fiscale'] ?? null;
    $cliente_indirizzo       = $cliente ? trim(($cliente['via'] ?? '') . ' ' . ($cliente['civico'] ?? '') . ', ' . ($cliente['cap'] ?? '') . ' ' . ($cliente['citta'] ?? '') . ' (' . ($cliente['provincia'] ?? '') . ')') : null;

    return [
        'azienda_ragione_sociale' => $azienda_ragione_sociale,
        'azienda_partita_iva'     => $azienda_partita_iva,
        'azienda_codice_fiscale'  => $azienda_codice_fiscale,
        'azienda_indirizzo'       => $azienda_indirizzo,
        'azienda_telefono'        => $azienda_telefono,
        'azienda_email'           => $azienda_email,
        'azienda_iban'            => $azienda_iban,
        'cliente_ragione_sociale' => $cliente_ragione_sociale,
        'cliente_partita_iva'     => $cliente_partita_iva,
        'cliente_codice_fiscale'  => $cliente_codice_fiscale,
        'cliente_indirizzo'       => $cliente_indirizzo,
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

