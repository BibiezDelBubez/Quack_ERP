<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** @return PDO */
function qk_db(): PDO
{
    global $pdo;
    return $pdo;
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

    $discount = trim((string) ($values['discount'] ?? ''));
    $sconto = $discount === '' ? 0.0 : (float) str_replace(',', '.', $discount);

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

function qk_insert_cliente(PDO $pdo, array $values): int
{
    $prep = qk_cliente_prepare_row($values, null);
    $pdo->beginTransaction();
    try {
        $codice = qk_next_codice($pdo, 'CLI');
        $s = $prep['soggetto'];
        $sql = 'INSERT INTO clienti_fornitori (
            codice, tipo, tipo_soggetto, ragione_sociale, nome, cognome,
            partita_iva, codice_fiscale, email, telefono, cellulare, pec, sito_web, note, attivo, updated_at
        ) VALUES (
            :codice, :tipo, :tipo_soggetto, :ragione_sociale, :nome, :cognome,
            :partita_iva, :codice_fiscale, :email, :telefono, :cellulare, :pec, :sito_web, :note, :attivo, datetime(\'now\')
        )';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':codice'          => $codice,
            ':tipo'            => $s['tipo'],
            ':tipo_soggetto'   => $s['tipo_soggetto'],
            ':ragione_sociale' => $s['ragione_sociale'],
            ':nome'            => $s['nome'],
            ':cognome'         => $s['cognome'],
            ':partita_iva'     => $s['partita_iva'],
            ':codice_fiscale'  => $s['codice_fiscale'],
            ':email'           => $s['email'],
            ':telefono'        => $s['telefono'],
            ':cellulare'       => $s['cellulare'],
            ':pec'             => $s['pec'],
            ':sito_web'        => $s['sito_web'],
            ':note'            => $s['note'],
            ':attivo'          => $s['attivo'],
        ]);
        $id = (int) $pdo->lastInsertId();

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
        $upd = $pdo->prepare(
            'UPDATE clienti_fornitori SET
                tipo_soggetto = ?, ragione_sociale = ?, nome = ?, cognome = ?,
                partita_iva = ?, codice_fiscale = ?, email = ?, telefono = ?, cellulare = ?,
                pec = ?, sito_web = ?, note = ?, attivo = ?, updated_at = datetime(\'now\')
             WHERE id = ?'
        );
        $upd->execute([
            $s['tipo_soggetto'],
            $s['ragione_sociale'],
            $s['nome'],
            $s['cognome'],
            $s['partita_iva'],
            $s['codice_fiscale'],
            $s['email'],
            $s['telefono'],
            $s['cellulare'],
            $s['pec'],
            $s['sito_web'],
            $s['note'],
            $s['attivo'],
            $id,
        ]);

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

    $minEuro = trim((string) ($values['min_order'] ?? ''));
    $minEuroVal = $minEuro === '' ? null : (float) str_replace(',', '.', $minEuro);

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
        $codice = qk_next_codice($pdo, 'FOR');
        $s = $prep['soggetto'];
        $stmt = $pdo->prepare(
            'INSERT INTO clienti_fornitori (
                codice, tipo, tipo_soggetto, ragione_sociale, nome, cognome,
                partita_iva, codice_fiscale, email, telefono, cellulare, pec, sito_web, note, attivo, updated_at
            ) VALUES (
                ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, datetime(\'now\')
            )'
        );
        $stmt->execute([
            $codice,
            $s['tipo'],
            $s['tipo_soggetto'],
            $s['ragione_sociale'],
            $s['nome'],
            $s['cognome'],
            $s['partita_iva'],
            $s['codice_fiscale'],
            $s['email'],
            $s['telefono'],
            $s['cellulare'],
            $s['pec'],
            $s['sito_web'],
            $s['note'],
            $s['attivo'],
        ]);
        $id = (int) $pdo->lastInsertId();

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
        $pdo->prepare(
            'UPDATE clienti_fornitori SET
                ragione_sociale = ?, partita_iva = ?, codice_fiscale = ?, email = ?, telefono = ?,
                pec = ?, sito_web = ?, note = ?, attivo = ?, updated_at = datetime(\'now\')
             WHERE id = ?'
        )->execute([
            $s['ragione_sociale'],
            $s['partita_iva'],
            $s['codice_fiscale'],
            $s['email'],
            $s['telefono'],
            $s['pec'],
            $s['sito_web'],
            $s['note'],
            $s['attivo'],
            $id,
        ]);

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
 * Restituisce tutti i contatti di un fornitore, ordinati per cognome/nome.
 *
 * @return list<array{id:int, nome:string, cognome:string, email:string,
 *                    telefono:string, cellulare:string, ruolo:string}>
 */
function qk_list_contatti_fornitore(PDO $pdo, int $parent_id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, nome, cognome, email, telefono, cellulare, note AS ruolo
           FROM clienti_fornitori
          WHERE parent_id = ?
          ORDER BY cognome COLLATE NOCASE, nome COLLATE NOCASE"
    );
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

/**
 * Crea un nuovo contatto collegato a un fornitore.
 *
 * @return int  l'id del nuovo record
 */
function qk_insert_contatto_fornitore(PDO $pdo, int $parent_id, array $v): int
{
    // Verifica che il parent esista ed è un fornitore
    $check = $pdo->prepare(
        "SELECT id FROM clienti_fornitori WHERE id = ? AND tipo IN ('fornitore','entrambi') LIMIT 1"
    );
    $check->execute([$parent_id]);
    if (!$check->fetchColumn()) {
        throw new \RuntimeException('Fornitore non trovato.');
    }

    $codice = qk_next_codice($pdo, 'CONT');

    $stmt = $pdo->prepare(
        "INSERT INTO clienti_fornitori
            (parent_id, codice, tipo, tipo_soggetto, nome, cognome, email, telefono, cellulare, note, attivo, updated_at)
         VALUES
            (?, ?, 'fornitore', 'privato', ?, ?, ?, ?, ?, ?, 1, datetime('now'))"
    );
    $stmt->execute([
        $parent_id,
        $codice,
        qk_null_if_empty($v['nome']      ?? ''),
        qk_null_if_empty($v['cognome']   ?? ''),
        qk_null_if_empty($v['email']     ?? ''),
        qk_null_if_empty($v['telefono']  ?? ''),
        qk_null_if_empty($v['cellulare'] ?? ''),
        qk_null_if_empty($v['ruolo']     ?? ''),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Aggiorna un contatto esistente, verificando che appartenga al fornitore corretto.
 */
function qk_update_contatto_fornitore(PDO $pdo, int $id, int $parent_id, array $v): void
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
        throw new \RuntimeException('Contatto non trovato o non appartiene a questo fornitore.');
    }
}

/**
 * Elimina un contatto, verificando che appartenga al fornitore corretto.
 */
function qk_delete_contatto_fornitore(PDO $pdo, int $id, int $parent_id): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM clienti_fornitori WHERE id = ? AND parent_id = ?'
    );
    $stmt->execute([$id, $parent_id]);

    if ($stmt->rowCount() === 0) {
        throw new \RuntimeException('Contatto non trovato o non appartiene a questo fornitore.');
    }
}

// =============================================================================
// INDIRIZZI / SEDI FORNITORE
// Sono record della tabella `indirizzi` con soggetto_id = id del fornitore.
// Tipi: sede_legale | sede_operativa | spedizione | fatturazione | magazzino
// =============================================================================

/**
 * Restituisce tutti gli indirizzi di un fornitore, ordinati per predefinito desc, tipo.
 *
 * @return list<array<string, mixed>>
 */
function qk_list_indirizzi_fornitore(PDO $pdo, int $soggetto_id): array
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
 * Inserisce un nuovo indirizzo per un fornitore.
 * Se predefinito=1, azzera gli altri predefiniti dello stesso tipo.
 *
 * @return int  l'id del nuovo record
 */
function qk_insert_indirizzo_fornitore(PDO $pdo, int $soggetto_id, array $v): int
{
    if ((bool) ($v['predefinito'] ?? false)) {
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
        (int) (bool) ($v['predefinito']            ?? false),
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

    return (int) $pdo->lastInsertId();
}

/**
 * Aggiorna un indirizzo esistente, verificando che appartenga al fornitore corretto.
 * Se predefinito=1, azzera gli altri predefiniti dello stesso tipo (escluso l'id corrente).
 */
function qk_update_indirizzo_fornitore(PDO $pdo, int $id, int $soggetto_id, array $v): void
{
    if ((bool) ($v['predefinito'] ?? false)) {
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
        (int) (bool) ($v['predefinito']            ?? false),
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
        throw new \RuntimeException('Indirizzo non trovato o non appartiene a questo fornitore.');
    }
}

/**
 * Elimina (disattiva) un indirizzo verificando che appartenga al fornitore corretto.
 * Usa soft-delete (attivo = 0) per preservare la storia nei documenti.
 */
function qk_delete_indirizzo_fornitore(PDO $pdo, int $id, int $soggetto_id): void
{
    $stmt = $pdo->prepare(
        'UPDATE indirizzi SET attivo = 0 WHERE id = ? AND soggetto_id = ?'
    );
    $stmt->execute([$id, $soggetto_id]);

    if ($stmt->rowCount() === 0) {
        throw new \RuntimeException('Indirizzo non trovato o non appartiene a questo fornitore.');
    }
}

// =============================================================================
// INDIRIZZI / SEDI CLIENTE
// Sono record della tabella `indirizzi` con soggetto_id = id del cliente.
// =============================================================================

/**
 * Restituisce tutti gli indirizzi di un cliente, ordinati per predefinito desc, tipo.
 *
 * @return list<array<string, mixed>>
 */
function qk_list_indirizzi_cliente(PDO $pdo, int $soggetto_id): array
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
 * Inserisce un nuovo indirizzo per un cliente.
 * Se predefinito=1, azzera gli altri predefiniti dello stesso tipo.
 *
 * @return int  l'id del nuovo record
 */
function qk_insert_indirizzo_cliente(PDO $pdo, int $soggetto_id, array $v): int
{
    if ((bool) ($v['predefinito'] ?? false)) {
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
        (int) (bool) ($v['predefinito']            ?? false),
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

    return (int) $pdo->lastInsertId();
}

/**
 * Aggiorna un indirizzo esistente, verificando che appartenga al cliente corretto.
 * Se predefinito=1, azzera gli altri predefiniti dello stesso tipo (escluso l'id corrente).
 */
function qk_update_indirizzo_cliente(PDO $pdo, int $id, int $soggetto_id, array $v): void
{
    if ((bool) ($v['predefinito'] ?? false)) {
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
        (int) (bool) ($v['predefinito']            ?? false),
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
        throw new \RuntimeException('Indirizzo non trovato o non appartiene a questo cliente.');
    }
}

/**
 * Elimina (disattiva) un indirizzo verificando che appartenga al cliente corretto.
 */
function qk_delete_indirizzo_cliente(PDO $pdo, int $id, int $soggetto_id): void
{
    $stmt = $pdo->prepare(
        'UPDATE indirizzi SET attivo = 0 WHERE id = ? AND soggetto_id = ?'
    );
    $stmt->execute([$id, $soggetto_id]);

    if ($stmt->rowCount() === 0) {
        throw new \RuntimeException('Indirizzo non trovato o non appartiene a questo cliente.');
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
          ORDER BY az.predefinita DESC, cf.ragione_sociale COLLATE NOCASE"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function qk_delete_azienda(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $stmtLoad = $pdo->prepare("SELECT soggetto_id FROM aziende_interne WHERE id = ?");
        $stmtLoad->execute([$id]);
        $soggetto_id = $stmtLoad->fetchColumn();
        if (!$soggetto_id) {
            $pdo->rollBack();
            return;
        }

        // Elimina record da aziende_interne
        $pdo->prepare("DELETE FROM aziende_interne WHERE id = ?")->execute([$id]);

        // Elimina/Disattiva soggetto in clienti_fornitori
        $pdo->prepare("UPDATE clienti_fornitori SET attivo = 0 WHERE id = ?")->execute([$soggetto_id]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
