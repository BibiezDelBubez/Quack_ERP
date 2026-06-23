<?php
declare(strict_types=1);
/**
 * includes/purchases_common.php
 * Utility condivise dal ciclo acquisti: generazione numeri documento.
 * Incluso da purchases_rfq.php, purchases_quotes.php, ecc.
 */

/**
 * Genera e salva il prossimo numero documento per il tipo dato.
 * Usa la tabella `sequenze`; gestisce il reset annuale automaticamente.
 *
 * @param string $tipo  'rfq' | 'offerta' | 'ordine' | 'ricezione'
 * @return string  es. "RFQ/2026/0001"
 * @throws RuntimeException se la sequenza non esiste
 */
function qk_next_numero_acquisto(PDO $pdo, string $tipo): string
{
    return qk_next_sequence_number($pdo, $tipo);
}

/**
 * Anteprima del prossimo numero senza incrementare il contatore.
 * Usato solo per mostrare l'anteprima nel form prima del salvataggio.
 */
function qk_peek_numero_acquisto(PDO $pdo, string $tipo): string
{
    $anno = (int) date('Y');
    $st   = $pdo->prepare('SELECT prefisso, anno, numero_corrente, padding FROM sequenze WHERE tipo = ?');
    $st->execute([$tipo]);
    $seq  = $st->fetch(PDO::FETCH_ASSOC);
    if (!$seq) return '';

    $currentAnno = (int) $seq['anno'];
    $next        = ($currentAnno !== $anno) ? 1 : (int) $seq['numero_corrente'] + 1;
    $pad         = (int) $seq['padding'];

    return $seq['prefisso'] . $anno . '/' . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
}

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

