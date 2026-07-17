<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** @return PDO */
function qk_db(): PDO
{
    global $pdo;
    return $pdo;
}

/**
 * Recupera l'indirizzo principale (sede legale/operativa) di un soggetto.
 * Ritorna array associativo se trovato, altrimenti null.
 */
function qk_soggetto_get_indirizzo_principale(PDO $pdo, int $soggetto_id): ?array
{
    $st = $pdo->prepare("
        SELECT * FROM indirizzi 
        WHERE soggetto_id = ? 
        ORDER BY (CASE WHEN tipo='sede_legale' THEN 1 WHEN tipo='sede_operativa' THEN 2 ELSE 3 END), predefinito DESC 
        LIMIT 1
    ");
    $st->execute([$soggetto_id]);
    $ind = $st->fetch(PDO::FETCH_ASSOC);
    
    return $ind ?: null;
}

function qk_null_if_empty(?string $s): ?string
{
    $s = trim((string) $s);
    return $s === '' ? null : $s;
}

/** Normalizza un URL: aggiunge http:// se manca lo schema. Restituisce null se vuoto. */
function qk_normalize_url(?string $s): ?string
{
    $s = trim((string) $s);
    if ($s === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $s)) {
        $s = 'http://' . $s;
    }
    return $s;
}

function qk_next_codice(PDO $pdo, string $prefix): string
{
    $stmt = $pdo->prepare(
        'SELECT codice FROM clienti_fornitori WHERE codice LIKE :pat ORDER BY codice DESC LIMIT 5'
    );
    $stmt->execute([':pat' => $prefix . '-%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cod) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', (string) $cod, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return sprintf('%s-%05d', $prefix, $max + 1);
}

/** UI category → dettagli_clienti.tipo_cliente */
function qk_map_category_to_tipo_cliente(?string $category): ?string
{
    $category = trim((string) $category);
    $map = [
        ''          => null,
        'standard'  => 'end_user',
        'premium'   => 'dropshipper',
        'reseller'  => 'rivenditore',
        'wholesale' => 'grossista',
    ];
    return $map[$category] ?? null;
}

/** dettagli_clienti.tipo_cliente → UI category */
function qk_map_tipo_cliente_to_category(?string $tc): string
{
    $map = [
        'end_user'       => 'standard',
        'dropshipper'    => 'premium',
        'rivenditore'    => 'reseller',
        'grossista'      => 'wholesale',
        'ente_pubblico'  => 'standard',
    ];
    return $map[$tc ?? ''] ?? 'standard';
}

/** Cerca ID di tipo_cliente in base al codice stringa */
function qk_get_tipo_cliente_id_by_code(PDO $pdo, ?string $code): ?int
{
    if (!$code) return null;
    $st = $pdo->prepare('SELECT id FROM tipi_cliente WHERE codice = ? LIMIT 1');
    $st->execute([$code]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}


function qk_form_tipo_soggetto(string $type): string
{
    return match ($type) {
        'azienda' => 'azienda',
        'libpro'  => 'libero_professionista',
        default   => 'privato',
    };
}

function qk_reverse_tipo_soggetto(string $db): string
{
    return match ($db) {
        'azienda'                  => 'azienda',
        'libero_professionista'    => 'libpro',
        default                    => 'privato',
    };
}

function qk_form_attivo(string $status): int
{
    return $status === 'active' ? 1 : 0;
}

function qk_reverse_status(int $attivo): string
{
    return $attivo ? 'active' : 'inactive';
}

function qk_display_name_cliente(array $row): string
{
    if (($row['tipo_soggetto'] ?? '') === 'privato') {
        return trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
    }
    return trim((string) ($row['ragione_sociale'] ?? ''));
}

/**
 * Nome completo di un soggetto con RAGIONE SOCIALE prioritaria — replica esatta
 * (spostata dalla query SQL al PHP) di `COALESCE(ragione_sociale, nome || ' ' ||
 * COALESCE(cognome,''))`. Come in SQLite COALESCE, il fallback scatta solo se
 * $ragioneSociale è NULL, non se è stringa vuota (comportamento preservato
 * intenzionalmente identico all'originale). Vedi Revisione Ciclo Acquisti §3bis A.5.
 *
 * NON la stessa cosa di qk_display_name_cliente(), che decide in base a
 * tipo_soggetto invece che a NULL/non-NULL — non unificarle senza verificare
 * i casi limite (es. ragione_sociale = '' su un soggetto non 'privato').
 */
function qk_nome_completo_soggetto(?string $ragioneSociale, ?string $nome, ?string $cognome): string
{
    if ($ragioneSociale !== null) {
        return $ragioneSociale;
    }
    return trim(($nome ?? '') . ' ' . ($cognome ?? ''));
}

/**
 * Nome completo con NOME/COGNOME prioritari (precedenza opposta a
 * qk_nome_completo_soggetto()) — usata per i sotto-contatti (persone), dove il
 * nome della persona ha senso di venire prima della ragione sociale del
 * soggetto padre. Replica esatta di `COALESCE(nome || ' ' || COALESCE(cognome,
 * ''), ragione_sociale)`. Vedi Revisione Ciclo Acquisti §3bis A.5.
 */
function qk_nome_completo_persona(?string $nome, ?string $cognome, ?string $ragioneSociale): ?string
{
    if ($nome !== null) {
        return trim($nome . ' ' . ($cognome ?? ''));
    }
    return $ragioneSociale;
}

// qk_default_tipo_fornitore_id e qk_list_tipi_fornitore rimossi (tabella tipi_fornitore eliminata nello schema v4.2)

/** Preserva altre righe in note_commerciali aggiornando solo la riga Referente. */
function qk_merge_note_commerciali_referente(?string $existing, ?string $referent): ?string
{
    $linesOut = [];
    foreach (preg_split("/\r\n|\r|\n/", (string) $existing) as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/^Referente:\s*/i', $line)) {
            continue;
        }
        $linesOut[] = $line;
    }
    $ref = trim((string) $referent);
    if ($ref !== '') {
        array_unshift($linesOut, 'Referente: ' . $ref);
    }
    $merged = trim(implode("\n", $linesOut));
    return $merged === '' ? null : $merged;
}

/**
 * @param array<string, string> $values Form values (customers keys)
 * @return array{soggetto: array, dettagli: array, indirizzo: ?array}
 */
/**
 * Converte una stringa numerica in formato italiano (virgola decimale) in
 * float, con un default per stringa vuota. Prima duplicata identica (stesso
 * pattern str_replace(',', '.', ...)) in qk_cliente_prepare_row() (campo
 * sconto) e qk_fornitore_prepare_row() (campo minimo_ordine_euro) - vedi
 * Revisione Incrociata Sez.7.2.1. Esiste lo stesso identico pattern anche in
 * altri ~18 punti del codice fuori da questo dominio (Prodotti, Acquisti,
 * Vendite, Magazzino) - non toccati qui perche' fuori scope, ma segnalati.
 */
function qk_parse_float_it(?string $raw, ?float $default = null): ?float
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $default;
    }
    return (float) str_replace(',', '.', $raw);
}

function qk_cliente_prepare_row(array $values, ?string $existingNoteCommerciali = null): array
{
    $tipoSogg = qk_form_tipo_soggetto($values['type'] ?? 'privato');
    $ragione  = qk_null_if_empty($values['company'] ?? '');
    $nome     = qk_null_if_empty($values['first_name'] ?? '');
    $cognome  = qk_null_if_empty($values['last_name'] ?? '');
    $piva     = qk_null_if_empty($values['vat'] ?? '');
    if ($tipoSogg === 'privato') {
        $ragione = null;
    } else {
        $nome = null;
        $cognome = null;
    }

    $noteBase = qk_null_if_empty($values['notes'] ?? '');
    $news = !empty($values['newsletter']);
    $noteInterne = $noteBase;
    if ($news) {
        $noteInterne = trim(($noteInterne ?? '') . ($noteInterne ? "\n" : '') . 'Newsletter: iscritto');
    }

    $refRaw = $values['referent'] ?? '';
    $noteComm = qk_merge_note_commerciali_referente($existingNoteCommerciali, $refRaw);

    $soggetto = [
        'tipo'            => 'cliente',
        'tipo_soggetto'   => $tipoSogg,
        'ragione_sociale' => $ragione,
        'nome'            => $nome,
        'cognome'         => $cognome,
        'partita_iva'     => $piva,
        'codice_fiscale'  => qk_null_if_empty($values['tax_code'] ?? ''),
        'email'           => qk_null_if_empty($values['email'] ?? ''),
        'telefono'        => qk_null_if_empty($values['phone'] ?? ''),
        'cellulare'       => qk_null_if_empty($values['mobile'] ?? ''),
        'pec'               => qk_null_if_empty($values['pec'] ?? ''),
        'sito_web'          => qk_normalize_url($values['website'] ?? ''),
        'note'              => null,
        'attivo'            => qk_form_attivo($values['status'] ?? 'active'),
    ];

    $sconto = qk_parse_float_it($values['discount'] ?? '', 0.0);

    $dettagli = [
        'tipo_cliente'         => qk_map_category_to_tipo_cliente($values['category'] ?? ''),
        'sconto_extra_perc'    => $sconto,
        'condizioni_pagamento' => qk_null_if_empty($values['payment_terms'] ?? ''),
        'codice_sdi'           => qk_null_if_empty($values['sdi_code'] ?? ''),
        'note_commerciali'     => $noteComm,
        'note_interne'         => $noteInterne,
        'listino_id'           => !empty($values['listino_id']) ? (int)$values['listino_id'] : null,
    ];

    $via = trim((string) ($values['address'] ?? ''));
    $cap = trim((string) ($values['zip'] ?? ''));
    $citta = trim((string) ($values['city'] ?? ''));
    $prov = qk_null_if_empty($values['province'] ?? '');
    if ($prov) {
        $prov = strtoupper(substr($prov, 0, 2));
    }
    $nazione = trim((string) ($values['country'] ?? 'IT')) ?: 'IT';

    $indirizzo = null;
    if ($via !== '' || $cap !== '' || $citta !== '') {
        $indirizzo = [
            'tipo'        => 'sede_legale',
            'predefinito' => 1,
            'via'         => $via !== '' ? $via : '—',
            'civico'      => null,
            'cap'         => $cap !== '' ? $cap : '00000',
            'citta'       => $citta !== '' ? $citta : '—',
            'provincia'   => $prov,
            'nazione'     => substr($nazione, 0, 2),
        ];
    }

    return ['soggetto' => $soggetto, 'dettagli' => $dettagli, 'indirizzo' => $indirizzo];
}

function qk_upsert_indirizzo(PDO $pdo, int $soggettoId, ?array $indirizzo): void
{
    if ($indirizzo === null) {
        return;
    }
    $find = $pdo->prepare(
        "SELECT id FROM indirizzi WHERE soggetto_id = ? AND tipo = 'sede_legale' LIMIT 1"
    );
    $find->execute([$soggettoId]);
    $id = $find->fetchColumn();

    if ($id) {
        $u = $pdo->prepare(
            'UPDATE indirizzi SET via = ?, civico = ?, cap = ?, citta = ?, provincia = ?, nazione = ?, predefinito = 1, attivo = 1 WHERE id = ?'
        );
        $u->execute([
            $indirizzo['via'],
            $indirizzo['civico'],
            $indirizzo['cap'],
            $indirizzo['citta'],
            $indirizzo['provincia'],
            $indirizzo['nazione'],
            $id,
        ]);
        return;
    }

    $i = $pdo->prepare(
        'INSERT INTO indirizzi (soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia, nazione, attivo)
         VALUES (?,?,?,?,?,?,?,?,?,1)'
    );
    $i->execute([
        $soggettoId,
        $indirizzo['tipo'],
        $indirizzo['predefinito'],
        $indirizzo['via'],
        $indirizzo['civico'],
        $indirizzo['cap'],
        $indirizzo['citta'],
        $indirizzo['provincia'],
        $indirizzo['nazione'],
    ]);
}

/**
 * Inserisce o aggiorna la riga "soggetto" (record base) in clienti_fornitori.
 * Condivisa da cliente e fornitore (Revisione Incrociata Sez.7.2.1).
 *
 * MOTIVAZIONE (analisi del 2026-07-14): prima, insert e update per il
 * fornitore scrivevano due insiemi di colonne DIVERSI (l'update "dimenticava"
 * tipo_soggetto/nome/cognome/cellulare rispetto all'insert) perche' il codice
 * SQL era scritto due volte, in due punti diversi, ed era libero di divergere
 * nel tempo. Con questa funzione unica, insert e update leggono sempre lo
 * stesso identico elenco di colonne dallo stesso array $soggetto: non possono
 * piu' divergere per costruzione, ne' oggi ne' in futuro se si aggiungeranno
 * altri campi. Effetto collaterale positivo: corregge anche il bug latente
 * di qk_update_fornitore() (colonne mancanti nell'UPDATE).
 *
 * @param array $soggetto Deve contenere le chiavi: tipo, tipo_soggetto,
 *        ragione_sociale, nome, cognome, partita_iva, codice_fiscale, email,
 *        telefono, cellulare, pec, sito_web, note, attivo (esattamente le
 *        chiavi gia' prodotte da qk_cliente_prepare_row()/qk_fornitore_prepare_row()).
 * @param int|null $existingId null per creare un nuovo soggetto (INSERT),
 *        altrimenti l'id del soggetto esistente da aggiornare (UPDATE).
 *        'codice' e 'tipo' sono scritti solo in creazione: sono considerati
 *        immutabili una volta assegnati (comportamento invariato rispetto
 *        al codice originale, che gia' li escludeva dall'UPDATE).
 * @param string $codicePrefix Prefisso per qk_next_codice() (es. 'CLI', 'FOR').
 *        Usato solo in creazione, ignorato in aggiornamento.
 * @return int L'id del soggetto (nuovo o esistente)
 */
function qk_persist_soggetto_clienti_fornitori(PDO $pdo, array $soggetto, ?int $existingId, string $codicePrefix): int
{
    if ($existingId === null) {
        $codice = qk_next_codice($pdo, $codicePrefix);
        $stmt = $pdo->prepare(
            'INSERT INTO clienti_fornitori (
                codice, tipo, tipo_soggetto, ragione_sociale, nome, cognome,
                partita_iva, codice_fiscale, email, telefono, cellulare, pec, sito_web, note, attivo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $codice,
            $soggetto['tipo'],
            $soggetto['tipo_soggetto'],
            $soggetto['ragione_sociale'],
            $soggetto['nome'],
            $soggetto['cognome'],
            $soggetto['partita_iva'],
            $soggetto['codice_fiscale'],
            $soggetto['email'],
            $soggetto['telefono'],
            $soggetto['cellulare'],
            $soggetto['pec'],
            $soggetto['sito_web'],
            $soggetto['note'],
            $soggetto['attivo'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare(
        'UPDATE clienti_fornitori SET
            tipo_soggetto = ?, ragione_sociale = ?, nome = ?, cognome = ?,
            partita_iva = ?, codice_fiscale = ?, email = ?, telefono = ?, cellulare = ?,
            pec = ?, sito_web = ?, note = ?, attivo = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $soggetto['tipo_soggetto'],
        $soggetto['ragione_sociale'],
        $soggetto['nome'],
        $soggetto['cognome'],
        $soggetto['partita_iva'],
        $soggetto['codice_fiscale'],
        $soggetto['email'],
        $soggetto['telefono'],
        $soggetto['cellulare'],
        $soggetto['pec'],
        $soggetto['sito_web'],
        $soggetto['note'],
        $soggetto['attivo'],
        $existingId,
    ]);
    return $existingId;
}

function qk_insert_cliente(PDO $pdo, array $values): int
{
    $prep = qk_cliente_prepare_row($values, null);
    $pdo->beginTransaction();
    try {
        $s = $prep['soggetto'];
        $id = qk_persist_soggetto_clienti_fornitori($pdo, $s, null, 'CLI');

        $d = $prep['dettagli'];
        $tipo_cliente_id = qk_get_tipo_cliente_id_by_code($pdo, $d['tipo_cliente']);
        $stmtD = $pdo->prepare(
            'INSERT INTO dettagli_clienti (
                soggetto_id, tipo_cliente_id, sconto_extra_perc, condizioni_pagamento,
                codice_sdi, note_commerciali, note_interne, listino_id
            ) VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmtD->execute([
            $id,
            $tipo_cliente_id,
            $d['sconto_extra_perc'],
            $d['condizioni_pagamento'],
            $d['codice_sdi'],
            $d['note_commerciali'],
            $d['note_interne'],
            $d['listino_id'],
        ]);

        // Sync many-to-many listini from form values
        if (!empty($values['listini'])) {
            $insListino = $pdo->prepare("
                INSERT INTO clienti_listini (cliente_id, listino_id, priorita, data_inizio, data_fine)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($values['listini'] as $lst) {
                if (empty($lst['listino_id'])) continue;
                $insListino->execute([
                    $id,
                    (int)$lst['listino_id'],
                    isset($lst['priorita']) && $lst['priorita'] !== '' ? (int)$lst['priorita'] : 10,
                    !empty($lst['data_inizio']) ? $lst['data_inizio'] : null,
                    !empty($lst['data_fine']) ? $lst['data_fine'] : null
                ]);
            }
        }

        if ($prep['indirizzo']) {
            $prep['indirizzo']['soggetto_id'] = $id;
            $ins = $pdo->prepare(
                'INSERT INTO indirizzi (soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia, nazione, attivo)
                 VALUES (?,?,?,?,?,?,?,?,?,1)'
            );
            $ind = $prep['indirizzo'];
            $ins->execute([
                $id,
                $ind['tipo'],
                $ind['predefinito'],
                $ind['via'],
                $ind['civico'],
                $ind['cap'],
                $ind['citta'],
                $ind['provincia'],
                $ind['nazione'],
            ]);
        }

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function qk_update_cliente(PDO $pdo, int $id, array $values): void
{
    $pdo->beginTransaction();
    try {
        $chk = $pdo->prepare(
            "SELECT id FROM clienti_fornitori WHERE id = ? AND tipo IN ('cliente','entrambi') LIMIT 1"
        );
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) {
            $pdo->rollBack();
            throw new InvalidArgumentException('Cliente non trovato.');
        }

        $ncExisting = $pdo->prepare('SELECT note_commerciali FROM dettagli_clienti WHERE soggetto_id = ?');
        $ncExisting->execute([$id]);
        $existingNc = $ncExisting->fetchColumn();
        $prep = qk_cliente_prepare_row($values, $existingNc !== false ? (string) $existingNc : null);

        $s = $prep['soggetto'];
        qk_persist_soggetto_clienti_fornitori($pdo, $s, $id, 'CLI');

        $d = $prep['dettagli'];
        $tipo_cliente_id = qk_get_tipo_cliente_id_by_code($pdo, $d['tipo_cliente']);
        $chk = $pdo->prepare('SELECT 1 FROM dettagli_clienti WHERE soggetto_id = ?');
        $chk->execute([$id]);
        if ($chk->fetchColumn()) {
            $pdo->prepare(
                'UPDATE dettagli_clienti SET
                    tipo_cliente_id = ?, sconto_extra_perc = ?, condizioni_pagamento = ?,
                    codice_sdi = ?, note_commerciali = ?, note_interne = ?, listino_id = ?
                 WHERE soggetto_id = ?'
            )->execute([
                $tipo_cliente_id,
                $d['sconto_extra_perc'],
                $d['condizioni_pagamento'],
                $d['codice_sdi'],
                $d['note_commerciali'],
                $d['note_interne'],
                $d['listino_id'],
                $id,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO dettagli_clienti (
                    soggetto_id, tipo_cliente_id, sconto_extra_perc, condizioni_pagamento,
                    codice_sdi, note_commerciali, note_interne, listino_id
                ) VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $id,
                $tipo_cliente_id,
                $d['sconto_extra_perc'],
                $d['condizioni_pagamento'],
                $d['codice_sdi'],
                $d['note_commerciali'],
                $d['note_interne'],
                $d['listino_id'],
            ]);
        }

        // Sync many-to-many listini from form values
        $pdo->prepare("DELETE FROM clienti_listini WHERE cliente_id = ?")->execute([$id]);
        if (!empty($values['listini'])) {
            $insListino = $pdo->prepare("
                INSERT INTO clienti_listini (cliente_id, listino_id, priorita, data_inizio, data_fine)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($values['listini'] as $lst) {
                if (empty($lst['listino_id'])) continue;
                $insListino->execute([
                    $id,
                    (int)$lst['listino_id'],
                    isset($lst['priorita']) && $lst['priorita'] !== '' ? (int)$lst['priorita'] : 10,
                    !empty($lst['data_inizio']) ? $lst['data_inizio'] : null,
                    !empty($lst['data_fine']) ? $lst['data_fine'] : null
                ]);
            }
        }

        qk_upsert_indirizzo($pdo, $id, $prep['indirizzo']);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @return ?array<string, string> */
function qk_load_cliente_form(PDO $pdo, int $id): ?array
{
    $sql = 'SELECT cf.*, tc.codice AS tipo_cliente, dc.sconto_extra_perc, dc.condizioni_pagamento,
                   dc.codice_sdi, dc.note_commerciali, dc.note_interne, dc.listino_id,
                   i.via AS ind_via, i.civico AS ind_civico, i.cap AS ind_cap,
                   i.citta AS ind_citta, i.provincia AS ind_provincia, i.nazione AS ind_nazione
            FROM clienti_fornitori cf
            LEFT JOIN dettagli_clienti dc ON dc.soggetto_id = cf.id
            LEFT JOIN tipi_cliente tc ON tc.id = dc.tipo_cliente_id
            LEFT JOIN indirizzi i ON i.soggetto_id = cf.id AND i.tipo = \'sede_legale\' AND i.attivo = 1
            WHERE cf.id = ? AND cf.tipo IN (\'cliente\',\'entrambi\')
            LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $tipoUi = qk_reverse_tipo_soggetto((string) $row['tipo_soggetto']);
    $newsletter = str_contains((string) ($row['note_interne'] ?? ''), 'Newsletter: iscritto') ? '1' : '0';
    $notesClean = (string) preg_replace('/\n?Newsletter: iscritto\s*$/', '', (string) ($row['note_interne'] ?? ''));

    $referent = '';
    if (preg_match('/^Referente:\s*(.+)$/m', (string) ($row['note_commerciali'] ?? ''), $m)) {
        $referent = trim($m[1]);
    }

    return [
        'type'          => $tipoUi,
        'first_name'    => (string) ($row['nome'] ?? ''),
        'last_name'     => (string) ($row['cognome'] ?? ''),
        'company'       => (string) ($row['ragione_sociale'] ?? ''),
        'referent'      => $referent,
        'email'         => (string) ($row['email'] ?? ''),
        'phone'         => (string) ($row['telefono'] ?? ''),
        'mobile'        => (string) ($row['cellulare'] ?? ''),
        'website'       => (string) ($row['sito_web'] ?? ''),
        'address'       => (string) ($row['ind_via'] ?? ''),
        'zip'           => (string) ($row['ind_cap'] ?? ''),
        'city'          => (string) ($row['ind_citta'] ?? ''),
        'province'      => (string) ($row['ind_provincia'] ?? ''),
        'country'       => (string) ($row['ind_nazione'] ?: 'IT'),
        'vat'           => (string) ($row['partita_iva'] ?? ''),
        'tax_code'      => (string) ($row['codice_fiscale'] ?? ''),
        'sdi_code'      => (string) ($row['codice_sdi'] ?? ''),
        'pec'           => (string) ($row['pec'] ?? ''),
        'category'      => qk_map_tipo_cliente_to_category($row['tipo_cliente'] ?? null),
        'discount'      => isset($row['sconto_extra_perc']) ? (string) $row['sconto_extra_perc'] : '',
        'payment_terms' => (string) ($row['condizioni_pagamento'] ?? ''),
        'listino_id'    => isset($row['listino_id']) ? (string) $row['listino_id'] : '',
        'status'        => qk_reverse_status((int) $row['attivo']),
        'notes'         => $notesClean,
        'newsletter'    => $newsletter,
        '_created_at'   => (string) ($row['created_at'] ?? ''),
        '_updated_at'   => (string) ($row['updated_at'] ?? ''),
        '_codice'       => (string) ($row['codice'] ?? ''),
    ];
}

/**
 * @return array{rows: list<array<string, mixed>>, total: int}
 */
function qk_clienti_list(PDO $pdo, string $search, string $typeFilter, int $limit = 50, int $offset = 0, string $sort = 'nome', string $dir = 'asc'): array
{
    $where = ["cf.tipo IN ('cliente','entrambi')", "cf.id NOT IN (SELECT soggetto_id FROM aziende_interne)"];
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'asc';
    if (!in_array($sort, ['nome','email','citta','created'], true)) $sort = 'nome';

    $params = [];

    if ($search !== '') {
        $where[] = '(cf.ragione_sociale LIKE ? OR cf.nome LIKE ? OR cf.cognome LIKE ? OR cf.email LIKE ? OR cf.partita_iva LIKE ? OR i.citta LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    if ($typeFilter === 'privato') {
        $where[] = "cf.tipo_soggetto = 'privato'";
    } elseif ($typeFilter === 'azienda') {
        $where[] = "cf.tipo_soggetto IN ('azienda','libero_professionista')";
    }

    $joinAddr = 'LEFT JOIN indirizzi i ON i.soggetto_id = cf.id AND i.tipo = \'sede_legale\' AND i.attivo = 1';
    $sqlCount = 'SELECT COUNT(DISTINCT cf.id) FROM clienti_fornitori cf ' . $joinAddr . ' WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare($sqlCount);
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $sortColMap = [
        'nome'    => "COALESCE(NULLIF(TRIM(cf.ragione_sociale),''), cf.cognome, cf.nome)",
        'email'   => 'cf.email',
        'citta'   => 'MAX(i.citta)',
        'created' => 'cf.created_at',
    ];
    $sortCol = $sortColMap[$sort] ?? $sortColMap['nome'];

    $sql = 'SELECT cf.id, cf.tipo_soggetto, cf.ragione_sociale, cf.nome, cf.cognome,
                   cf.email, cf.telefono, cf.created_at,
                   MAX(i.citta) AS citta,
                   (SELECT COUNT(*) FROM ordini_clienti oc WHERE oc.cliente_id = cf.id AND oc.stato NOT IN (\'completato\', \'annullato\')) AS ordini_count
            FROM clienti_fornitori cf
            ' . $joinAddr . '
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY cf.id
            ORDER BY ' . $sortCol . ' COLLATE NOCASE ' . $dir . '
            LIMIT ? OFFSET ?';

    $st2 = $pdo->prepare($sql);
    $st2->execute([...$params, $limit, $offset]);
    $rows = $st2->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $display = qk_display_name_cliente($r);
        $tipoUi = (($r['tipo_soggetto'] ?? '') === 'privato') ? 'privato' : 'azienda';
        $out[] = [
            'id'         => (int) $r['id'],
            'name'       => $display !== '' ? $display : ('#' . $r['id']),
            'type'       => $tipoUi,
            'email'      => (string) ($r['email'] ?? ''),
            'phone'      => (string) ($r['telefono'] ?? ''),
            'city'       => (string) ($r['citta'] ?? ''),
            'orders'     => (int) ($r['ordini_count'] ?? 0),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ];
    }

    return ['rows' => $out, 'total' => $total];
}

/**
 * @param array<string, string> $values
 * @return array{soggetto: array, dettagli: array}
 *
 * Gli indirizzi del fornitore sono gestiti esclusivamente dalla tabella `indirizzi`
 * tramite le API dedicate (apiIndirizziSave / apiIndirizziDelete).
 * Questo metodo non tocca più gli indirizzi.
 */
function qk_fornitore_prepare_row(array $values): array
{
    $ragione = trim((string) ($values['company'] ?? ''));

    $soggetto = [
        'tipo'            => 'fornitore',
        'tipo_soggetto'   => 'azienda',
        'ragione_sociale' => $ragione !== '' ? $ragione : null,
        'nome'            => null,
        'cognome'         => null,
        'partita_iva'     => qk_null_if_empty($values['vat'] ?? ''),
        'codice_fiscale'  => qk_null_if_empty($values['tax_code'] ?? ''),
        'email'           => qk_null_if_empty($values['email'] ?? ''),
        'telefono'        => qk_null_if_empty($values['phone'] ?? ''),
        'cellulare'       => null,
        'pec'             => qk_null_if_empty($values['pec'] ?? ''),
        'sito_web'        => qk_normalize_url($values['website'] ?? ''),
        'note'            => qk_null_if_empty($values['notes'] ?? ''),
        'attivo'          => qk_form_attivo($values['status'] ?? 'active'),
    ];

    $lead = trim((string) ($values['lead_time'] ?? ''));
    $leadDays = $lead === '' ? null : (int) $lead;

    $minEuroVal = qk_parse_float_it($values['min_order'] ?? '', null);

    $dettagli = [
        'condizioni_pagamento'            => qk_null_if_empty($values['payment_terms'] ?? ''),
        'valuta'                          => qk_null_if_empty($values['currency'] ?? 'EUR') ?: 'EUR',
        'lead_time_giorni'                => $leadDays,
        'minimo_ordine_euro'              => $minEuroVal,
        'codice_cliente_presso_fornitore' => qk_null_if_empty($values['our_customer_code'] ?? ''),
        'portale_ordini_url'              => qk_normalize_url($values['orders_portal_url'] ?? ''),
        'note_commerciali'                => qk_null_if_empty($values['supply_categories'] ?? ''),
        'note_interne'                    => qk_null_if_empty($values['notes_intern'] ?? ''),
    ];

    return ['soggetto' => $soggetto, 'dettagli' => $dettagli];
}

function qk_insert_fornitore(PDO $pdo, array $values): int
{
    $prep = qk_fornitore_prepare_row($values);
    $pdo->beginTransaction();
    try {
        $s = $prep['soggetto'];
        $id = qk_persist_soggetto_clienti_fornitori($pdo, $s, null, 'FOR');

        $d = $prep['dettagli'];
        $pdo->prepare(
            'INSERT INTO dettagli_fornitori (
                soggetto_id, condizioni_pagamento, valuta, lead_time_giorni,
                minimo_ordine_euro, codice_cliente_presso_fornitore, portale_ordini_url,
                note_commerciali, note_interne
            ) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id,
            $d['condizioni_pagamento'],
            $d['valuta'],
            $d['lead_time_giorni'],
            $d['minimo_ordine_euro'],
            $d['codice_cliente_presso_fornitore'],
            $d['portale_ordini_url'],
            $d['note_commerciali'],
            $d['note_interne'],
        ]);

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function qk_update_fornitore(PDO $pdo, int $id, array $values): void
{
    $prep = qk_fornitore_prepare_row($values);
    $pdo->beginTransaction();
    try {
        $chk = $pdo->prepare(
            "SELECT id FROM clienti_fornitori WHERE id = ? AND tipo IN ('fornitore','entrambi') LIMIT 1"
        );
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) {
            $pdo->rollBack();
            throw new InvalidArgumentException('Fornitore non trovato.');
        }

        $s = $prep['soggetto'];
        // NOTA (correzione 2026-07-14, Revisione Incrociata Sez.7.2.1): prima
        // questo UPDATE scriveva solo 9 delle 13 colonne mutabili (mancavano
        // tipo_soggetto/nome/cognome/cellulare, presenti invece in
        // qk_insert_fornitore()). Usando la stessa funzione condivisa
        // dell'insert, l'update scrive ora lo stesso identico set di colonne:
        // struttralmente non puo' piu' divergere.
        qk_persist_soggetto_clienti_fornitori($pdo, $s, $id, 'FOR');

        $d = $prep['dettagli'];
        $chk = $pdo->prepare('SELECT 1 FROM dettagli_fornitori WHERE soggetto_id = ?');
        $chk->execute([$id]);
        if ($chk->fetchColumn()) {
            $pdo->prepare(
                'UPDATE dettagli_fornitori SET
                    condizioni_pagamento = ?, valuta = ?, lead_time_giorni = ?,
                    minimo_ordine_euro = ?, codice_cliente_presso_fornitore = ?,
                    portale_ordini_url = ?, note_commerciali = ?, note_interne = ?
                 WHERE soggetto_id = ?'
            )->execute([
                $d['condizioni_pagamento'],
                $d['valuta'],
                $d['lead_time_giorni'],
                $d['minimo_ordine_euro'],
                $d['codice_cliente_presso_fornitore'],
                $d['portale_ordini_url'],
                $d['note_commerciali'],
                $d['note_interne'],
                $id,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO dettagli_fornitori (
                    soggetto_id, condizioni_pagamento, valuta, lead_time_giorni,
                    minimo_ordine_euro, codice_cliente_presso_fornitore, portale_ordini_url,
                    note_commerciali, note_interne
                ) VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id,
                $d['condizioni_pagamento'],
                $d['valuta'],
                $d['lead_time_giorni'],
                $d['minimo_ordine_euro'],
                $d['codice_cliente_presso_fornitore'],
                $d['portale_ordini_url'],
                $d['note_commerciali'],
                $d['note_interne'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @return ?array<string, string> */
function qk_load_fornitore_form(PDO $pdo, int $id): ?array
{
    // Gli indirizzi non fanno parte del form principale: sono gestiti
    // esclusivamente dalla tab "Sedi e indirizzi" tramite la tabella `indirizzi`.
    $sql = 'SELECT cf.*, df.valuta, df.codice_cliente_presso_fornitore, df.portale_ordini_url,
                   df.condizioni_pagamento, df.lead_time_giorni, df.minimo_ordine_euro,
                   df.note_commerciali, df.note_interne
            FROM clienti_fornitori cf
            LEFT JOIN dettagli_fornitori df ON df.soggetto_id = cf.id
            WHERE cf.id = ? AND cf.tipo IN (\'fornitore\',\'entrambi\')
            LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'company'           => (string) ($row['ragione_sociale'] ?? ''),
        'email'             => (string) ($row['email'] ?? ''),
        'phone'             => (string) ($row['telefono'] ?? ''),
        'website'           => (string) ($row['sito_web'] ?? ''),
        'vat'               => (string) ($row['partita_iva'] ?? ''),
        'tax_code'          => (string) ($row['codice_fiscale'] ?? ''),
        'pec'               => (string) ($row['pec'] ?? ''),
        'lead_time'         => isset($row['lead_time_giorni']) && $row['lead_time_giorni'] !== null
            ? (string) (int) $row['lead_time_giorni'] : '',
        'min_order'         => isset($row['minimo_ordine_euro']) && $row['minimo_ordine_euro'] !== null
            ? (string) $row['minimo_ordine_euro'] : '',
        'payment_terms'     => (string) ($row['condizioni_pagamento'] ?? ''),
        'supply_categories' => (string) ($row['note_commerciali'] ?? ''),
        'currency'          => (string) ($row['valuta'] ?: 'EUR'),
        'our_customer_code' => (string) ($row['codice_cliente_presso_fornitore'] ?? ''),
        'orders_portal_url' => (string) ($row['portale_ordini_url'] ?? ''),
        'status'            => qk_reverse_status((int) $row['attivo']),
        'notes'             => (string) ($row['note'] ?? ''),
        'notes_intern'      => (string) ($row['note_interne'] ?? ''),
        '_created_at'       => (string) ($row['created_at'] ?? ''),
        '_updated_at'       => (string) ($row['updated_at'] ?? ''),
        '_codice'           => (string) ($row['codice'] ?? ''),
    ];
}

/**
 * @return array{rows: list<array<string, mixed>>, total: int}
 */
function qk_fornitori_list(PDO $pdo, string $search, bool $adrOnly = false, int $limit = 50, int $offset = 0, string $sort = 'nome', string $dir = 'asc'): array
{
    $where = ["cf.tipo IN ('fornitore','entrambi')", "cf.id NOT IN (SELECT soggetto_id FROM aziende_interne)"];
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'asc';
    if (!in_array($sort, ['nome','email','status'], true)) $sort = 'nome';

    $params = [];

    if ($search !== '') {
        $where[] = '(cf.ragione_sociale LIKE ? OR cf.partita_iva LIKE ? OR cf.email LIKE ? OR df.note_commerciali LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $join = 'INNER JOIN dettagli_fornitori df ON df.soggetto_id = cf.id';
    $sqlCount = 'SELECT COUNT(*) FROM clienti_fornitori cf ' . $join . ' WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare($sqlCount);
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $forniSortMap = [
        'nome'   => 'cf.ragione_sociale',
        'email'  => 'cf.email',
        'status' => 'CAST(cf.attivo AS TEXT)',
    ];
    $forniSortCol = $forniSortMap[$sort] ?? 'cf.ragione_sociale';

    $sql = 'SELECT cf.id, cf.ragione_sociale, cf.partita_iva, cf.email, cf.attivo,
                    df.lead_time_giorni, df.condizioni_pagamento, df.note_commerciali
             FROM clienti_fornitori cf
             ' . $join . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $forniSortCol . ' COLLATE NOCASE ' . $dir . '
             LIMIT ? OFFSET ?';

    $st2 = $pdo->prepare($sql);
    $st2->execute([...$params, $limit, $offset]);
    $rows = $st2->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        // Extract referent from note_commerciali if present
        $referent = '';
        if (isset($r['note_commerciali'])) {
            if (preg_match('/^Referente:\s*(.+)$/mi', $r['note_commerciali'], $m)) {
                $referent = trim($m[1]);
            }
        }
        $out[] = [
            'id' => (int) $r['id'],
            'company' => (string) ($r['ragione_sociale'] ?? ''),
            'vat' => (string) ($r['partita_iva'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
            'lead_time' => $r['lead_time_giorni'],
            'payment_terms' => (string) ($r['condizioni_pagamento'] ?? ''),
            'status' => qk_reverse_status((int) $r['attivo']),
            'referent' => $referent,
        ];
    }

    return ['rows' => $out, 'total' => $total];
}

function qk_db_user_message(Throwable $e): string
{
    $msg = $e->getMessage();
    if (str_contains($msg, 'UNIQUE') || str_contains($msg, 'unique')) {
        return 'Esiste già un soggetto con lo stesso codice anagrafica o partita IVA.';
    }
    return 'Errore durante il salvataggio. Verifica i dati e riprova.';
}

// =============================================================================
// CONTATTI FORNITORE
// Sono record di clienti_fornitori con parent_id = id del fornitore padre.
// =============================================================================

/**
 * Restituisce tutti i contatti collegati a un cliente o fornitore, ordinati
 * per cognome/nome. Funzione generica condivisa (Revisione Incrociata
 * Sez.7.2.1): unifica qk_list_contatti_cliente()/qk_list_contatti_fornitore(),
 * prima identiche a parte il filtro sul campo tipo.
 *
 * NOTA COMPORTAMENTALE (comportamento pre-esistente, non introdotto qui):
 * la versione "fornitore" originale non filtrava affatto per tipo (solo
 * parent_id), mentre la versione "cliente" filtrava tipo IN ('cliente','entrambi').
 * Per non alterare il comportamento esistente, $tipoFiltro è opzionale: null
 * per il fornitore (nessun filtro, come prima), 'cliente' per il cliente.
 *
 * @return list<array{id:int, nome:string, cognome:string, email:string,
 *                    telefono:string, cellulare:string, ruolo:string}>
 */
function qk_list_contatti_generico(PDO $pdo, int $parent_id, ?string $tipoFiltro): array
{
    $sql = "SELECT id, nome, cognome, email, telefono, cellulare, note AS ruolo
              FROM clienti_fornitori
             WHERE parent_id = ?";
    if ($tipoFiltro !== null) {
        $sql .= " AND tipo IN ('" . $tipoFiltro . "', 'entrambi')";
    }
    $sql .= " ORDER BY cognome COLLATE NOCASE, nome COLLATE NOCASE";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$parent_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $r): array {
        return [
            'id'        => (int) $r['id'],
            'nome'      => (string) ($r['nome']      ?? ''),
            'cognome'   => (string) ($r['cognome']   ?? ''),
            'email'     => (string) ($r['email']     ?? ''),
            'telefono'  => (string) ($r['telefono']  ?? ''),
            'cellulare' => (string) ($r['cellulare'] ?? ''),
            'ruolo'     => (string) ($r['ruolo']     ?? ''),
        ];
    }, $rows);
}

/** Restituisce tutti i contatti di un fornitore, ordinati per cognome/nome. */
function qk_list_contatti_fornitore(PDO $pdo, int $parent_id): array
{
    return qk_list_contatti_generico($pdo, $parent_id, null);
}

/** Ritorna la lista dei contatti collegati a un cliente. */
function qk_list_contatti_cliente(PDO $pdo, int $parent_id): array
{
    return qk_list_contatti_generico($pdo, $parent_id, 'cliente');
}

/**
 * Crea un nuovo contatto collegato a un cliente o fornitore. Funzione
 * generica condivisa (Revisione Incrociata Sez.7.2.1): unifica
 * qk_insert_contatto_cliente()/qk_insert_contatto_fornitore(), prima
 * identiche a parte la stringa 'tipo' e i messaggi d'errore.
 *
 * @param string $tipo 'cliente' o 'fornitore'
 * @return int l'id del nuovo record
 */
function qk_insert_contatto_generico(PDO $pdo, int $parent_id, array $v, string $tipo): int
{
    // Verifica che il parent esista ed è del tipo atteso
    $check = $pdo->prepare(
        "SELECT id FROM clienti_fornitori WHERE id = ? AND tipo IN (?, 'entrambi') LIMIT 1"
    );
    $check->execute([$parent_id, $tipo]);
    if (!$check->fetchColumn()) {
        throw new \RuntimeException(ucfirst($tipo) . ' non trovato.');
    }

    $codice = qk_next_codice($pdo, 'CONT');

    $stmt = $pdo->prepare(
        "INSERT INTO clienti_fornitori
            (parent_id, codice, tipo, tipo_soggetto, nome, cognome, email, telefono, cellulare, note, attivo, updated_at)
         VALUES
            (?, ?, ?, 'privato', ?, ?, ?, ?, ?, ?, 1, datetime('now'))"
    );
    $stmt->execute([
        $parent_id,
        $codice,
        $tipo,
        qk_null_if_empty($v['nome']      ?? ''),
        qk_null_if_empty($v['cognome']   ?? ''),
        qk_null_if_empty($v['email']     ?? ''),
        qk_null_if_empty($v['telefono']  ?? ''),
        qk_null_if_empty($v['cellulare'] ?? ''),
        qk_null_if_empty($v['ruolo']     ?? ''),
    ]);

    return (int) $pdo->lastInsertId();
}

/** Crea un nuovo contatto collegato a un fornitore. @return int l'id del nuovo record */
function qk_insert_contatto_fornitore(PDO $pdo, int $parent_id, array $v): int
{
    return qk_insert_contatto_generico($pdo, $parent_id, $v, 'fornitore');
}

/** Crea un nuovo contatto collegato a un cliente. @return int l'id del nuovo record */
function qk_insert_contatto_cliente(PDO $pdo, int $parent_id, array $v): int
{
    return qk_insert_contatto_generico($pdo, $parent_id, $v, 'cliente');
}

/**
 * Aggiorna un contatto esistente, verificando che appartenga al
 * cliente/fornitore corretto. Funzione generica condivisa (Revisione
 * Incrociata Sez.7.2.1).
 *
 * @param string $tipoLabel 'cliente' o 'fornitore', solo per il messaggio d'errore
 */
function qk_update_contatto_generico(PDO $pdo, int $id, int $parent_id, array $v, string $tipoLabel): void
{
    $stmt = $pdo->prepare(
        "UPDATE clienti_fornitori
            SET nome      = ?,
                cognome   = ?,
                email     = ?,
                telefono  = ?,
                cellulare = ?,
                note      = ?,
                updated_at = datetime('now')
          WHERE id = ? AND parent_id = ?"
    );
    $stmt->execute([
        qk_null_if_empty($v['nome']      ?? ''),
        qk_null_if_empty($v['cognome']   ?? ''),
        qk_null_if_empty($v['email']     ?? ''),
        qk_null_if_empty($v['telefono']  ?? ''),
        qk_null_if_empty($v['cellulare'] ?? ''),
        qk_null_if_empty($v['ruolo']     ?? ''),
        $id,
        $parent_id,
    ]);

    if ($stmt->rowCount() === 0) {
        throw new \RuntimeException('Contatto non trovato o non appartiene a questo ' . $tipoLabel . '.');
    }
}

/** Aggiorna un contatto esistente, verificando che appartenga al fornitore corretto. */
function qk_update_contatto_fornitore(PDO $pdo, int $id, int $parent_id, array $v): void
{
    qk_update_contatto_generico($pdo, $id, $parent_id, $v, 'fornitore');
}

/** Aggiorna un contatto esistente, verificando che appartenga al cliente corretto. */
function qk_update_contatto_cliente(PDO $pdo, int $id, int $parent_id, array $v): void
{
    qk_update_contatto_generico($pdo, $id, $parent_id, $v, 'cliente');
}

/**
 * Elimina un contatto, verificando che appartenga al cliente/fornitore
 * corretto. Funzione generica condivisa (Revisione Incrociata Sez.7.2.1).
 *
 * @param string $tipoLabel 'cliente' o 'fornitore', solo per il messaggio d'errore
 */
function qk_delete_contatto_generico(PDO $pdo, int $id, int $parent_id, string $tipoLabel): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM clienti_fornitori WHERE id = ? AND parent_id = ?'
    );
    $stmt->execute([$id, $parent_id]);

    if ($stmt->rowCount() === 0) {
        throw new \RuntimeException('Contatto non trovato o non appartiene a questo ' . $tipoLabel . '.');
    }
}

/** Elimina un contatto, verificando che appartenga al fornitore corretto. */
function qk_delete_contatto_fornitore(PDO $pdo, int $id, int $parent_id): void
{
    qk_delete_contatto_generico($pdo, $id, $parent_id, 'fornitore');
}

/** Elimina un contatto, verificando che appartenga al cliente corretto. */
function qk_delete_contatto_cliente(PDO $pdo, int $id, int $parent_id): void
{
    qk_delete_contatto_generico($pdo, $id, $parent_id, 'cliente');
}

// =============================================================================
// INDIRIZZI / SEDI FORNITORE
// Sono record della tabella `indirizzi` con soggetto_id = un qualunque record di
// `clienti_fornitori` — cliente, fornitore o azienda interna (le aziende interne
// SONO record di clienti_fornitori, vedi Piano Multi-Magazzino §2.6). Funzioni
// neutre: operano solo su soggetto_id, senza assumere chi sia il soggetto.
// Sostituiscono le vecchie coppie qk_*_indirizzo_cliente/_fornitore, che erano
// duplicati identici (stesso SQL, nomi diversi) già usati in modo fuorviante
// anche per le aziende interne — vedi Piano Multi-Magazzino §3.2.
// Tipi: sede_legale | sede_operativa | spedizione | fatturazione | magazzino
// =============================================================================

/**
 * Restituisce tutti gli indirizzi di un soggetto (cliente, fornitore o azienda
 * interna), ordinati per predefinito desc, tipo.
 *
 * @return list<array<string, mixed>>
 */
function qk_list_indirizzi(PDO $pdo, int $soggetto_id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, soggetto_id, tipo, nome_sede, predefinito,
                referente_consegna, telefono_consegna,
                presso, via, civico, cap, citta, provincia, nazione,
                note_consegna, attivo
           FROM indirizzi
          WHERE soggetto_id = ? AND attivo = 1
          ORDER BY predefinito DESC, tipo COLLATE NOCASE, citta COLLATE NOCASE'
    );
    $stmt->execute([$soggetto_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $r): array {
        return [
            'id'                 => (int)    $r['id'],
            'soggetto_id'        => (int)    $r['soggetto_id'],
            'tipo'               => (string) ($r['tipo']               ?? ''),
            'nome_sede'          => (string) ($r['nome_sede']          ?? ''),
            'predefinito'        => (bool)   $r['predefinito'],
            'referente_consegna' => (string) ($r['referente_consegna'] ?? ''),
            'telefono_consegna'  => (string) ($r['telefono_consegna']  ?? ''),
            'presso'             => (string) ($r['presso']             ?? ''),
            'via'                => (string) ($r['via']                ?? ''),
            'civico'             => (string) ($r['civico']             ?? ''),
            'cap'                => (string) ($r['cap']                ?? ''),
            'citta'              => (string) ($r['citta']              ?? ''),
            'provincia'          => (string) ($r['provincia']          ?? ''),
            'nazione'            => (string) ($r['nazione']            ?: 'IT'),
            'note_consegna'      => (string) ($r['note_consegna']      ?? ''),
            'attivo'             => (bool)   $r['attivo'],
        ];
    }, $rows);
}

/**
 * Inserisce un nuovo indirizzo per un soggetto.
 * Se predefinito=1, azzera gli altri predefiniti dello stesso tipo.
 *
 * @return int  l'id del nuovo record
 */
function qk_insert_indirizzo(PDO $pdo, int $soggetto_id, array $v): int
{
    $isPredefinito = (bool) ($v['predefinito'] ?? false);

    // Revisione Incrociata §5.2.2: reset del predefinito precedente + scrittura del
    // nuovo vanno avvolti in una transazione, altrimenti due richieste quasi
    // contemporanee sullo stesso soggetto potrebbero lasciare due predefiniti (o
    // zero) per lo stesso tipo. La transazione si apre solo quando serve davvero
    // (predefinito=1), per non appesantire il caso comune.
    $ownTransaction = $isPredefinito && !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if ($isPredefinito) {
            $pdo->prepare(
                "UPDATE indirizzi SET predefinito = 0 WHERE soggetto_id = ? AND tipo = ?"
            )->execute([$soggetto_id, $v['tipo']]);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO indirizzi
                (soggetto_id, tipo, nome_sede, predefinito,
                 referente_consegna, telefono_consegna,
                 presso, via, civico, cap, citta, provincia, nazione,
                 note_consegna, attivo)
             VALUES
                (?, ?, ?, ?,
                 ?, ?,
                 ?, ?, ?, ?, ?, ?, ?,
                 ?, 1)"
        );
        $stmt->execute([
            $soggetto_id,
            $v['tipo'],
            qk_null_if_empty($v['nome_sede']          ?? ''),
            (int) $isPredefinito,
            qk_null_if_empty($v['referente_consegna']  ?? ''),
            qk_null_if_empty($v['telefono_consegna']   ?? ''),
            qk_null_if_empty($v['presso']              ?? ''),
            $v['via'],
            qk_null_if_empty($v['civico']              ?? ''),
            $v['cap'],
            $v['citta'],
            qk_null_if_empty($v['provincia']           ?? ''),
            $v['nazione'] ?: 'IT',
            qk_null_if_empty($v['note_consegna']       ?? ''),
        ]);

        $newId = (int) $pdo->lastInsertId();

        if ($ownTransaction) {
            $pdo->commit();
        }

        return $newId;
    } catch (\Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Aggiorna un indirizzo esistente, verificando che appartenga al soggetto corretto.
 * Se predefinito=1, azzera gli altri predefiniti dello stesso tipo (escluso l'id corrente).
 */
function qk_update_indirizzo(PDO $pdo, int $id, int $soggetto_id, array $v): void
{
    $isPredefinito = (bool) ($v['predefinito'] ?? false);

    // Stessa protezione transazionale di qk_insert_indirizzo() — Revisione
    // Incrociata §5.2.2. Vedi commento lì per il motivo.
    $ownTransaction = $isPredefinito && !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if ($isPredefinito) {
            $pdo->prepare(
                "UPDATE indirizzi SET predefinito = 0 WHERE soggetto_id = ? AND tipo = ? AND id != ?"
            )->execute([$soggetto_id, $v['tipo'], $id]);
        }

        $stmt = $pdo->prepare(
            "UPDATE indirizzi
                SET tipo                = ?,
                    nome_sede           = ?,
                    predefinito         = ?,
                    referente_consegna  = ?,
                    telefono_consegna   = ?,
                    presso              = ?,
                    via                 = ?,
                    civico              = ?,
                    cap                 = ?,
                    citta               = ?,
                    provincia           = ?,
                    nazione             = ?,
                    note_consegna       = ?
              WHERE id = ? AND soggetto_id = ?"
        );
        $stmt->execute([
            $v['tipo'],
            qk_null_if_empty($v['nome_sede']          ?? ''),
            (int) $isPredefinito,
            qk_null_if_empty($v['referente_consegna']  ?? ''),
            qk_null_if_empty($v['telefono_consegna']   ?? ''),
            qk_null_if_empty($v['presso']              ?? ''),
            $v['via'],
            qk_null_if_empty($v['civico']              ?? ''),
            $v['cap'],
            $v['citta'],
            qk_null_if_empty($v['provincia']           ?? ''),
            $v['nazione'] ?: 'IT',
            qk_null_if_empty($v['note_consegna']       ?? ''),
            $id,
            $soggetto_id,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Indirizzo non trovato o non appartiene a questo soggetto.');
        }

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Elimina (disattiva) un indirizzo verificando che appartenga al soggetto corretto.
 * Usa soft-delete (attivo = 0) per preservare la storia nei documenti.
 */
function qk_delete_indirizzo(PDO $pdo, int $id, int $soggetto_id): void
{
    $stmt = $pdo->prepare(
        'UPDATE indirizzi SET attivo = 0 WHERE id = ? AND soggetto_id = ?'
    );
    $stmt->execute([$id, $soggetto_id]);

    if ($stmt->rowCount() === 0) {
        throw new \RuntimeException('Indirizzo non trovato o non appartiene a questo soggetto.');
    }
}

// =============================================================================
// AZIENDE INTERNE (CRUD)
// =============================================================================

function qk_aziende_list(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT az.id, az.predefinita, cf.ragione_sociale, cf.partita_iva, cf.email, cf.telefono,
                i.citta, i.via
           FROM aziende_interne az
           JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
           LEFT JOIN indirizzi i ON i.soggetto_id = cf.id AND i.tipo = 'sede_legale' AND i.attivo = 1
          WHERE az.attivo = 1
          ORDER BY az.predefinita DESC, cf.ragione_sociale COLLATE NOCASE"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Carica un'azienda interna completa (tutti i campi di clienti_fornitori e
 * aziende_interne, utile per intestazioni di stampa PDF/Word) dato il suo id.
 *
 * NOTA: nessun filtro attivo=1 qui, di proposito. Questa funzione viene usata
 * per risolvere l'azienda GIA' COLLEGATA a un documento esistente (RFQ,
 * ordine...): un documento storico deve continuare a mostrare l'azienda che
 * lo ha effettivamente emesso anche se quell'azienda e' stata disattivata nel
 * frattempo (integrita' storica). Comportamento identico ai 4 punti duplicati
 * originali in RfqController.php/PurchaseOrdersController.php, che non
 * filtravano attivo. Per un elenco di aziende SELEZIONABILI (form, dropdown),
 * usare invece qk_aziende_list(), che filtra attivo=1.
 */
function qk_azienda_interna_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT cf.*, az.*
           FROM aziende_interne az
           JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
          WHERE az.id = ?
          LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Carica l'azienda interna marcata come predefinita (stesso formato di
 * qk_azienda_interna_by_id), filtrando attivo=1.
 *
 * CORRETTO il 14/07/2026 (era: nessun filtro attivo=1, stesso comportamento
 * dei 4 punti duplicati originali in RfqController.php/PurchaseOrdersController.php).
 * Questa funzione è usata solo come *fallback* quando un documento non ha
 * ancora un'azienda_interna_id esplicita: in quel caso proporre un'azienda
 * disattivata come emittente di un documento nuovo/da stampare oggi è un
 * bug vero, non una scelta di integrità storica (a differenza del ramo con
 * id esplicito in qk_resolve_azienda_emittente(), che resta intenzionalmente
 * senza filtro attivo — vedi il docblock di qk_azienda_interna_by_id()).
 * Vedi `00` §3.1 e `01`/`02` per il dettaglio della distinzione.
 */
function qk_azienda_predefinita(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT cf.*, az.*
           FROM aziende_interne az
           JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
          WHERE az.predefinita = 1 AND az.attivo = 1
          LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Risolve l'azienda interna "emittente" per un documento (RFQ, offerta,
 * ordine d'acquisto, preventivo/ordine di vendita): usa l'id esplicito se
 * presente e trovato, altrimenti l'azienda marcata come predefinita.
 *
 * CORRETTO il 14/07/2026: due comportamenti distinti, non più identici:
 * - id esplicito ($aziendaInternaId, sempre il valore già salvato sul
 *   documento) → nessun filtro attivo=1, di proposito (integrità storica,
 *   vedi qk_azienda_interna_by_id()): un documento già emesso deve
 *   continuare a mostrare l'azienda che lo ha realmente emesso anche se
 *   nel frattempo è stata disattivata.
 * - fallback su predefinita (nessun id esplicito sul documento) → filtro
 *   attivo=1 ora applicato in qk_azienda_predefinita(): proporre
 *   un'azienda disattivata come emittente per un documento senza azienda
 *   ancora assegnata era il bug reale (vedi `00` §3.1).
 *
 * Unifica la logica che prima era duplicata identica in RfqController.php
 * (x2) e PurchaseOrdersController.php (x2) — vedi Revisione Incrociata,
 * nota metodologica MVC/DRY in cima al documento, e §5.3.1/§7.1.1.
 *
 * @return array Array associativo dei dati azienda, o [] se nessuna azienda
 *               è risolvibile (comportamento invariato per i chiamanti
 *               esistenti, che già gestiscono l'array vuoto con `?:`/`??`).
 */
function qk_resolve_azienda_emittente(PDO $pdo, ?int $aziendaInternaId = null): array
{
    if ($aziendaInternaId) {
        $az = qk_azienda_interna_by_id($pdo, $aziendaInternaId);
        if ($az) {
            return $az;
        }
    }
    return qk_azienda_predefinita($pdo) ?? [];
}

/**
 * Risolve e sincronizza in $_SESSION l'azienda interna "attiva" mostrata
 * nella topbar: sessione → predefinita → prima disponibile. Restituisce
 * null se non esiste alcuna azienda interna attiva.
 *
 * Estratta da includes/header.php (Revisione Incrociata §7.1.1): prima di
 * questa correzione la stessa logica viveva inline dentro la View, con
 * query dirette e scrittura di sessione mescolate alla presentazione.
 * Resta un side-effect (scrive $_SESSION) perché header.php è incluso
 * direttamente da diversi controller senza un hook comune "prima del
 * render" — spostarlo in un vero passaggio Controller→View richiederebbe
 * toccare ogni controller che include header.php; qui almeno la logica
 * duplicata e le query dirette sono centralizzate in un solo posto.
 *
 * @param array|null $aziendeGiaCaricate Se il chiamante ha già una lista
 *        aziende (es. header.php la usa anche per il dropdown), passarla qui
 *        evita una seconda query identica a qk_aziende_list().
 */
function qk_resolve_azienda_attiva_sessione(PDO $pdo, ?array $aziendeGiaCaricate = null): ?array
{
    $aziende = $aziendeGiaCaricate ?? qk_aziende_list($pdo);
    if (empty($aziende)) {
        return null;
    }

    $sessId = $_SESSION['azienda_attiva_id'] ?? null;
    $attiva = null;
    if ($sessId) {
        foreach ($aziende as $az) {
            if ((int) $az['id'] === (int) $sessId) {
                $attiva = $az;
                break;
            }
        }
    }
    if (!$attiva) {
        foreach ($aziende as $az) {
            if ($az['predefinita'] == 1) {
                $attiva = $az;
                break;
            }
        }
    }
    if (!$attiva) {
        $attiva = $aziende[0];
    }

    $_SESSION['azienda_attiva_id'] = (int) $attiva['id'];
    return $attiva;
}

function qk_load_azienda_form(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT az.*, cf.ragione_sociale, cf.partita_iva, cf.codice_fiscale, cf.email, cf.telefono, cf.cellulare, cf.pec, cf.sito_web,
                i.via, i.civico, i.cap, i.citta, i.provincia, i.nazione
           FROM aziende_interne az
           JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
           LEFT JOIN indirizzi i ON i.soggetto_id = cf.id AND i.tipo = 'sede_legale' AND i.attivo = 1
          WHERE az.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    return [
        'id'                 => (int) $row['id'],
        'soggetto_id'        => (int) $row['soggetto_id'],
        'ragione_sociale'    => (string) ($row['ragione_sociale'] ?? ''),
        'partita_iva'        => (string) ($row['partita_iva'] ?? ''),
        'codice_fiscale'     => (string) ($row['codice_fiscale'] ?? ''),
        'email'              => (string) ($row['email'] ?? ''),
        'telefono'           => (string) ($row['telefono'] ?? ''),
        'cellulare'          => (string) ($row['cellulare'] ?? ''),
        'pec'                => (string) ($row['pec'] ?? ''),
        'sito_web'           => (string) ($row['sito_web'] ?? ''),
        'address'            => (string) ($row['via'] ?? ''),
        'civico'             => (string) ($row['civico'] ?? ''),
        'zip'                => (string) ($row['cap'] ?? ''),
        'city'               => (string) ($row['citta'] ?? ''),
        'province'           => (string) ($row['provincia'] ?? ''),
        'country'            => (string) ($row['nazione'] ?: 'IT'),
        'logo_path'          => (string) ($row['logo_path'] ?? ''),
        'header_path'        => (string) ($row['header_path'] ?? ''),
        'capitale_sociale'   => $row['capitale_sociale'] !== null ? (float) $row['capitale_sociale'] : '',
        'registro_imprese'   => (string) ($row['registro_imprese'] ?? ''),
        'socio_unico'        => (bool) $row['socio_unico'],
        'in_liquidazione'    => (bool) $row['in_liquidazione'],
        'banca_default'      => (string) ($row['banca_default'] ?? ''),
        'iban_default'       => (string) ($row['iban_default'] ?? ''),
        'bic_swift_default'  => (string) ($row['bic_swift_default'] ?? ''),
        'predefinita'        => (bool) $row['predefinita'],
    ];
}

function qk_insert_azienda(PDO $pdo, array $v): int
{
    $pdo->beginTransaction();
    try {
        $codice = qk_next_codice($pdo, 'AZI');
        
        // 1. Inserisci soggetto in clienti_fornitori
        $stmtCf = $pdo->prepare(
            "INSERT INTO clienti_fornitori (
                codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, codice_fiscale,
                email, telefono, cellulare, pec, sito_web, note, attivo, created_at, updated_at
            ) VALUES (?, 'entrambi', 'azienda', ?, ?, ?, ?, ?, ?, ?, ?, '', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmtCf->execute([
            $codice,
            $v['ragione_sociale'],
            qk_null_if_empty($v['partita_iva']),
            qk_null_if_empty($v['codice_fiscale']),
            qk_null_if_empty($v['email']),
            qk_null_if_empty($v['telefono']),
            qk_null_if_empty($v['cellulare']),
            qk_null_if_empty($v['pec']),
            qk_normalize_url($v['sito_web']),
        ]);
        $soggetto_id = (int) $pdo->lastInsertId();

        // 2. Se specificato, inserisci indirizzo sede_legale
        $via = trim((string) ($v['address'] ?? ''));
        $cap = trim((string) ($v['zip'] ?? ''));
        $citta = trim((string) ($v['city'] ?? ''));
        if ($via !== '' || $cap !== '' || $citta !== '') {
            $stmtInd = $pdo->prepare(
                "INSERT INTO indirizzi (soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia, nazione, attivo)
                 VALUES (?, 'sede_legale', 1, ?, ?, ?, ?, ?, ?, 1)"
            );
            $stmtInd->execute([
                $soggetto_id,
                $via !== '' ? $via : '—',
                qk_null_if_empty($v['civico']),
                $cap !== '' ? $cap : '00000',
                $citta !== '' ? $citta : '—',
                qk_null_if_empty($v['province']),
                substr(trim($v['country'] ?: 'IT'), 0, 2),
            ]);
        }

        // 3. Se questa è predefinita, azzera le altre predefinite
        if (!empty($v['predefinita'])) {
            $pdo->query("UPDATE aziende_interne SET predefinita = 0");
        }

        // 4. Inserisci azienda_interna
        $stmtAz = $pdo->prepare(
            "INSERT INTO aziende_interne (
                soggetto_id, logo_path, header_path, capitale_sociale, registro_imprese,
                socio_unico, in_liquidazione, banca_default, iban_default, bic_swift_default,
                predefinita, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmtAz->execute([
            $soggetto_id,
            qk_null_if_empty($v['logo_path']),
            qk_null_if_empty($v['header_path']),
            $v['capitale_sociale'] !== '' ? (float) $v['capitale_sociale'] : null,
            qk_null_if_empty($v['registro_imprese']),
            (int) !empty($v['socio_unico']),
            (int) !empty($v['in_liquidazione']),
            qk_null_if_empty($v['banca_default']),
            qk_null_if_empty($v['iban_default']),
            qk_null_if_empty($v['bic_swift_default']),
            (int) !empty($v['predefinita']),
        ]);
        $azienda_id = (int) $pdo->lastInsertId();

        $pdo->commit();
        return $azienda_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function qk_update_azienda(PDO $pdo, int $id, array $v): void
{
    $pdo->beginTransaction();
    try {
        // Carica dati attuali per trovare soggetto_id
        $stmtLoad = $pdo->prepare("SELECT soggetto_id FROM aziende_interne WHERE id = ?");
        $stmtLoad->execute([$id]);
        $soggetto_id = $stmtLoad->fetchColumn();
        if (!$soggetto_id) {
            $pdo->rollBack();
            throw new RuntimeException("Azienda non trovata.");
        }

        // 1. Aggiorna soggetto
        $stmtCf = $pdo->prepare(
            "UPDATE clienti_fornitori SET
                ragione_sociale = ?, partita_iva = ?, codice_fiscale = ?, email = ?,
                telefono = ?, cellulare = ?, pec = ?, sito_web = ?, updated_at = datetime('now')
             WHERE id = ?"
        );
        $stmtCf->execute([
            $v['ragione_sociale'],
            qk_null_if_empty($v['partita_iva']),
            qk_null_if_empty($v['codice_fiscale']),
            qk_null_if_empty($v['email']),
            qk_null_if_empty($v['telefono']),
            qk_null_if_empty($v['cellulare']),
            qk_null_if_empty($v['pec']),
            qk_normalize_url($v['sito_web']),
            $soggetto_id
        ]);

        // 2. Aggiorna o inserisci indirizzo sede_legale
        $via = trim((string) ($v['address'] ?? ''));
        $cap = trim((string) ($v['zip'] ?? ''));
        $citta = trim((string) ($v['city'] ?? ''));
        
        $chkInd = $pdo->prepare("SELECT id FROM indirizzi WHERE soggetto_id = ? AND tipo = 'sede_legale' AND attivo = 1 LIMIT 1");
        $chkInd->execute([$soggetto_id]);
        $ind_id = $chkInd->fetchColumn();

        if ($via !== '' || $cap !== '' || $citta !== '') {
            if ($ind_id) {
                $stmtInd = $pdo->prepare(
                    "UPDATE indirizzi SET via = ?, civico = ?, cap = ?, citta = ?, provincia = ?, nazione = ? WHERE id = ?"
                );
                $stmtInd->execute([
                    $via !== '' ? $via : '—',
                    qk_null_if_empty($v['civico']),
                    $cap !== '' ? $cap : '00000',
                    $citta !== '' ? $citta : '—',
                    qk_null_if_empty($v['province']),
                    substr(trim($v['country'] ?: 'IT'), 0, 2),
                    $ind_id
                ]);
            } else {
                $stmtInd = $pdo->prepare(
                    "INSERT INTO indirizzi (soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia, nazione, attivo)
                     VALUES (?, 'sede_legale', 1, ?, ?, ?, ?, ?, ?, 1)"
                );
                $stmtInd->execute([
                    $soggetto_id,
                    $via !== '' ? $via : '—',
                    qk_null_if_empty($v['civico']),
                    $cap !== '' ? $cap : '00000',
                    $citta !== '' ? $citta : '—',
                    qk_null_if_empty($v['province']),
                    substr(trim($v['country'] ?: 'IT'), 0, 2),
                ]);
            }
        } elseif ($ind_id) {
            // Disattiva indirizzo se svuotato
            $pdo->prepare("UPDATE indirizzi SET attivo = 0 WHERE id = ?")->execute([$ind_id]);
        }

        // 3. Se predefinita, azzera le altre
        if (!empty($v['predefinita'])) {
            $pdo->prepare("UPDATE aziende_interne SET predefinita = 0 WHERE id != ?")->execute([$id]);
        }

        // 4. Aggiorna azienda_interna
        $stmtAz = $pdo->prepare(
            "UPDATE aziende_interne SET
                logo_path = ?, header_path = ?, capitale_sociale = ?, registro_imprese = ?,
                socio_unico = ?, in_liquidazione = ?, banca_default = ?, iban_default = ?, bic_swift_default = ?,
                predefinita = ?, updated_at = datetime('now')
             WHERE id = ?"
        );
        $stmtAz->execute([
            qk_null_if_empty($v['logo_path']),
            qk_null_if_empty($v['header_path']),
            $v['capitale_sociale'] !== '' ? (float) $v['capitale_sociale'] : null,
            qk_null_if_empty($v['registro_imprese']),
            (int) !empty($v['socio_unico']),
            (int) !empty($v['in_liquidazione']),
            qk_null_if_empty($v['banca_default']),
            qk_null_if_empty($v['iban_default']),
            qk_null_if_empty($v['bic_swift_default']),
            (int) !empty($v['predefinita']),
            $id
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Disattiva un'azienda interna (SOFT DELETE sempre: mai cancellazione fisica,
 * perché aziende_interne è collegata a documenti storici tramite FK). Ritorna
 * l'esito ('disattivato' o 'non_trovato') da passare a
 * qk_esito_cancellazione_messaggio() — prima il controller mostrava sempre
 * "rimossa con successo" anche quando in realtà non era stata trovata alcuna
 * azienda con quell'id (return silenzioso), o quando si trattava solo di una
 * disattivazione e non di un'eliminazione reale (Revisione §5.3.2/§7.2.2).
 */
function qk_delete_azienda(PDO $pdo, int $id): string
{
    $stmtLoad = $pdo->prepare("SELECT soggetto_id, predefinita FROM aziende_interne WHERE id = ?");
    $stmtLoad->execute([$id]);
    $azienda = $stmtLoad->fetch(PDO::FETCH_ASSOC);
    if (!$azienda) {
        return 'non_trovato';
    }
    $soggetto_id     = (int) $azienda['soggetto_id'];
    $eraPredefinita  = (bool) $azienda['predefinita'];

    $pdo->beginTransaction();
    try {
        // Disattiva la riga specifica di aziende_interne (SOFT DELETE, mai
        // fisica: collegata a documenti storici tramite FK RESTRICT). Azzera
        // anche 'predefinita' qui stesso: un'azienda disattivata non deve mai
        // restare selezionabile come emittente di default (Revisione §5.3.1).
        $pdo->prepare("UPDATE aziende_interne SET attivo = 0, predefinita = 0 WHERE id = ?")->execute([$id]);

        // Se era la predefinita, promuove un'altra azienda attiva al suo posto
        // (la prima per id), invece di lasciare il fallback "predefinita = 1"
        // senza alcuna azienda che lo soddisfi (Revisione §5.3.1/§5.3.2).
        if ($eraPredefinita) {
            $pdo->prepare(
                "UPDATE aziende_interne SET predefinita = 1
                  WHERE id = (SELECT id FROM aziende_interne WHERE attivo = 1 AND id != ? ORDER BY id LIMIT 1)"
            )->execute([$id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Riusa la logica generica di cancellazione/soft-delete del soggetto
    // clienti_fornitori sottostante (stessa funzione usata per clienti e
    // fornitori puri) invece di duplicarla qui. La DELETE fisica tentata da
    // qk_delete_soggetto() fallirà sempre in questo caso — aziende_interne.
    // soggetto_id ha 'ON DELETE RESTRICT' e la riga aziende_interne non viene
    // mai eliminata, solo disattivata sopra — quindi il risultato è
    // deterministicamente lo stesso soft-delete del comportamento precedente,
    // senza più duplicare la query UPDATE clienti_fornitori SET attivo = 0.
    qk_delete_soggetto($pdo, $soggetto_id);

    return 'disattivato';
}

/**
 * Elimina fisicamente un cliente/fornitore se non ci sono vincoli di integrità
 * referenziale; altrimenti esegue un soft-delete (attivo = 0). Ritorna
 * l'esito ('eliminato' o 'disattivato') da passare a
 * qk_esito_cancellazione_messaggio() — prima il chiamante non aveva modo di
 * sapere quale dei due rami si era verificato, e mostrava sempre lo stesso
 * messaggio "rimosso con successo" anche quando il record era in realtà
 * ancora presente, solo disattivato (Revisione §7.2.2).
 */
function qk_delete_soggetto(PDO $pdo, int $id): string
{
    // Proviamo la cancellazione fisica abilitando i vincoli di FK
    $pdo->beginTransaction();
    try {
        // Forza vincoli in SQLite per essere sicuri (in MySQL/InnoDB sono sempre attivi)
        if (DB_DRIVER === 'sqlite') {
            $pdo->exec("PRAGMA foreign_keys = ON;");
        }

        $stmt = $pdo->prepare("DELETE FROM clienti_fornitori WHERE id = ?");
        $stmt->execute([$id]);
        $pdo->commit();
        return 'eliminato';
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Fallback sul soft delete (disattivazione)
        $pdo->prepare("UPDATE clienti_fornitori SET attivo = 0 WHERE id = ?")->execute([$id]);
        return 'disattivato';
    }
}

/**
 * Costruisce il messaggio flash da mostrare dopo un'eliminazione di
 * cliente/fornitore/azienda, in base all'esito effettivo restituito da
 * qk_delete_soggetto()/qk_delete_azienda(). Unifica la logica di messaggio
 * prima duplicata (in modo errato: sempre "successo" generico) in
 * CustomersController::delete(), SuppliersController::delete() e
 * AziendeController::delete() — vedi Revisione Incrociata §7.2.2.
 *
 * @param string $esito           'eliminato' | 'disattivato' | 'non_trovato'
 * @param string $entitaSingolare Es. 'cliente', 'fornitore', 'azienda'
 * @param string $entitaArticolo  Articolo indeterminativo concordato, es. 'il', 'la'
 * @return array{type: string, text: string} type è 'success' o 'warning',
 *         pronto per redirect_with($path, $type, $text)
 */
function qk_esito_cancellazione_messaggio(string $esito, string $entitaSingolare, string $entitaArticolo = 'il'): array
{
    switch ($esito) {
        case 'eliminato':
            return [
                'type' => 'success',
                'text' => ucfirst($entitaSingolare) . ' eliminat' . ($entitaArticolo === 'la' ? 'a' : 'o') . ' definitivamente.',
            ];
        case 'disattivato':
            return [
                'type' => 'warning',
                'text' => ucfirst($entitaSingolare) . ' non eliminat' . ($entitaArticolo === 'la' ? 'a' : 'o')
                    . ' (è referenziat' . ($entitaArticolo === 'la' ? 'a' : 'o') . ' da altri documenti/dati): '
                    . 'è stat' . ($entitaArticolo === 'la' ? 'a' : 'o') . ' disattivat' . ($entitaArticolo === 'la' ? 'a' : 'o') . ' invece.',
            ];
        case 'non_trovato':
            return [
                'type' => 'danger',
                'text' => ucfirst($entitaSingolare) . ' non trovat' . ($entitaArticolo === 'la' ? 'a' : 'o') . '.',
            ];
        default:
            return [
                'type' => 'success',
                'text' => ucfirst($entitaSingolare) . ' rimosso con successo.',
            ];
    }
}

/**
 * Legge da $_POST, costruisce e valida i campi di un indirizzo (usato dai 3
 * endpoint AJAX "salva indirizzo" quasi identici in CustomersController.php,
 * SuppliersController.php, AziendeController.php — vedi Revisione Incrociata
 * §7.2.1/§7.2.2). Unifica: whitelist tipi sede, costruzione array valori,
 * validazione campi obbligatori (via/cap/citta).
 *
 * NOTA: non è (ancora) validazione CAP/provincia differenziata per nazione
 * (vedi Backlog Sviluppi Futuri, voce 11) — qui si unifica solo la
 * validazione ESISTENTE (presenza dei campi), senza cambiarne le regole.
 *
 * @return array{ok: true, v: array}|array{ok: false, error: string}
 */
function qk_build_e_valida_indirizzo(): array
{
    $tipiValidi = ['sede_legale', 'sede_operativa', 'spedizione', 'fatturazione', 'magazzino'];
    $tipo = post('tipo', '');
    if (!in_array($tipo, $tipiValidi, true)) {
        return ['ok' => false, 'error' => 'Tipo indirizzo non valido.'];
    }

    $v = [
        'tipo'               => $tipo,
        'nome_sede'          => post('nome_sede', ''),
        'predefinito'        => post('predefinito', '0') === '1',
        'referente_consegna' => post('referente_consegna', ''),
        'telefono_consegna'  => post('telefono_consegna', ''),
        'presso'             => post('presso', ''),
        'via'                => post('via', ''),
        'civico'             => post('civico', ''),
        'cap'                => post('cap', ''),
        'citta'              => post('citta', ''),
        'provincia'          => post('provincia', ''),
        'nazione'            => post('nazione', 'IT') ?: 'IT',
        'note_consegna'      => post('note_consegna', ''),
    ];

    if (trim($v['via']) === '') {
        return ['ok' => false, 'error' => 'Il campo indirizzo (via) è obbligatorio.'];
    }
    if (trim($v['cap']) === '') {
        return ['ok' => false, 'error' => 'Il CAP è obbligatorio.'];
    }
    if (trim($v['citta']) === '') {
        return ['ok' => false, 'error' => 'La città è obbligatoria.'];
    }

    return ['ok' => true, 'v' => $v];
}
