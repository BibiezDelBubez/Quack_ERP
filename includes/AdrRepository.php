<?php

declare(strict_types=1);

final class AdrRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function loadCodiciOnu(string $search, int $page = 1, int $perPage = 100): array
    {
        $where  = '1=1';
        $params = [];
        if ($search !== '') {
            $where  = '(CAST(a.codice_onu AS TEXT) LIKE :q OR a.nome_descrizione LIKE :q OR cl.denominazione LIKE :q)';
            $params = [':q' => '%' . $search . '%'];
        }

        $stmtCount = $this->pdo->prepare(
            "SELECT COUNT(*) FROM adr_codici_onu a
             LEFT JOIN adr_classi_pericolo cl ON cl.codice = a.classe_pericolo
             WHERE $where"
        );
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();
        $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page  = max(1, min($page, $pages));

        $offset = ($page - 1) * $perPage;
        $params[':limit']  = $perPage;
        $params[':offset'] = $offset;

        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.codice_onu, a.nome_descrizione, a.classe_pericolo, a.codice_classificazione,
                    a.gruppo_imballaggio, a.categoria_trasporto,
                    a.codice_restrizione_gallerie, a.quantita_limitata,
                    a.codice_quantita_esente,
                    a.e_merce_alto_rischio, a.e_inquinante_marino,
                    a.inizio_validita, a.fine_validita,
                    cl.denominazione AS classe_label,
                    (SELECT GROUP_CONCAT(etichetta_codice) 
                     FROM adr_codici_onu_etichette 
                     WHERE codice_onu_id = a.id) AS etichette_str
               FROM adr_codici_onu a
          LEFT JOIN adr_classi_pericolo cl ON cl.codice = a.classe_pericolo
              WHERE $where
           ORDER BY a.codice_onu, a.classe_pericolo
           LIMIT :limit OFFSET :offset"
        );
        $stmt->execute($params);
        return [$stmt->fetchAll(), $total, $pages];
    }

    public function loadLookups(): array
    {
        return [
            'classi'       => $this->pdo->query(
                'SELECT codice, denominazione FROM adr_classi_pericolo ORDER BY CAST(codice AS REAL)'
            )->fetchAll(),
            'classificazioni' => $this->pdo->query(
                'SELECT codice, descrizione, codice_classe FROM adr_codici_classificazione ORDER BY codice'
            )->fetchAll(),
            'imballaggi'   => $this->pdo->query(
                'SELECT codice, descrizione FROM adr_gruppi_imballaggio ORDER BY codice'
            )->fetchAll(),
            'categorie'    => $this->pdo->query(
                'SELECT categoria, descrizione, soglia_esenzione_kg_l FROM adr_categorie_trasporto ORDER BY categoria'
            )->fetchAll(),
            'gallerie'     => $this->pdo->query(
                'SELECT codice, descrizione FROM adr_codici_restrizione_gallerie ORDER BY codice'
            )->fetchAll(),
            'quantita_esenti' => $this->pdo->query(
                'SELECT codice, descrizione FROM adr_quantita_esenti ORDER BY codice'
            )->fetchAll(),
            'unita_misura' => $this->pdo->query(
                'SELECT id, codice, descrizione FROM unita_misura ORDER BY descrizione, codice'
            )->fetchAll(),
        ];
    }

    public function getCodiceOnu(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM adr_codici_onu WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch();
        if (!$record) return null;

        $stmtLabels = $this->pdo->prepare('SELECT etichetta_codice FROM adr_codici_onu_etichette WHERE codice_onu_id = ? ORDER BY ordine ASC');
        $stmtLabels->execute([$id]);
        $record['etichette_str'] = implode(', ', $stmtLabels->fetchAll(PDO::FETCH_COLUMN));

        return $record;
    }

    public function insertCodice(array $v): int
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO adr_codici_onu
                    (codice_onu, nome_descrizione, classe_pericolo, codice_classificazione,
                     gruppo_imballaggio, categoria_trasporto, codice_restrizione_gallerie,
                     quantita_limitata, codice_quantita_esente,
                     e_merce_alto_rischio, e_inquinante_marino,
                     inizio_validita, fine_validita)
                 VALUES
                    (:codice_onu, :nome_descrizione, :classe_pericolo, :codice_classificazione,
                     :gruppo_imballaggio, :categoria_trasporto, :codice_restrizione_gallerie,
                     :quantita_limitata, :codice_quantita_esente,
                     :e_merce_alto_rischio, :e_inquinante_marino,
                     :inizio_validita, :fine_validita)'
            )->execute($this->codiceParams($v));
            $id = (int) $this->pdo->lastInsertId();
            $this->saveEtichette($id, $v['etichette_str'] ?? '');
            if ($ownTransaction) {
                $this->pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateCodice(int $id, array $v): void
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $p        = $this->codiceParams($v);
            $p[':id'] = $id;
            $this->pdo->prepare(
                'UPDATE adr_codici_onu SET
                    codice_onu=:codice_onu,
                    nome_descrizione=:nome_descrizione,
                    classe_pericolo=:classe_pericolo,
                    codice_classificazione=:codice_classificazione,
                    gruppo_imballaggio=:gruppo_imballaggio,
                    categoria_trasporto=:categoria_trasporto,
                    codice_restrizione_gallerie=:codice_restrizione_gallerie,
                    quantita_limitata=:quantita_limitata,
                    codice_quantita_esente=:codice_quantita_esente,
                    e_merce_alto_rischio=:e_merce_alto_rischio,
                    e_inquinante_marino=:e_inquinante_marino,
                    inizio_validita=:inizio_validita,
                    fine_validita=:fine_validita
                 WHERE id=:id'
            )->execute($p);
            $this->saveEtichette($id, $v['etichette_str'] ?? '');
            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteCodice(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM adr_codici_onu_etichette WHERE codice_onu_id = :id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM adr_codici_onu WHERE id=:id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function saveEtichette(int $id, string $etichetteStr): void
    {
        $this->pdo->prepare('DELETE FROM adr_codici_onu_etichette WHERE codice_onu_id = ?')->execute([$id]);

        $raw = array_values(array_filter(
            array_map('trim', explode(',', $etichetteStr)),
            static fn(string $v): bool => $v !== ''
        ));
        $etichette = [];
        $visti = [];
        foreach ($raw as $v) {
            $chiave = mb_strtolower($v);
            if (isset($visti[$chiave])) {
                continue;
            }
            $visti[$chiave] = true;
            $etichette[] = $v;
        }

        if (empty($etichette)) return;

        $stFind = $this->pdo->prepare('SELECT codice FROM adr_etichette WHERE LOWER(codice) = LOWER(?)');
        $stIns = $this->pdo->prepare(
            'INSERT INTO adr_codici_onu_etichette (codice_onu_id, etichetta_codice, tipo, ordine) VALUES (?, ?, ?, ?)'
        );
        foreach ($etichette as $idx => $et) {
            $stFind->execute([$et]);
            $resolved = $stFind->fetchColumn();
            $etReale = $resolved !== false ? $resolved : $et;
            $stIns->execute([$id, $etReale, $idx === 0 ? 'primaria' : 'sussidiaria', $idx + 1]);
        }
    }

    private function codiceParams(array $v): array
    {
        $n = fn($val): mixed => (trim((string) $val) === '') ? null : $val;
        return [
            ':codice_onu'                  => (int) $v['codice_onu'],
            ':nome_descrizione'            => $n($v['nome_descrizione']),
            ':classe_pericolo'             => $n($v['classe_pericolo']),
            ':codice_classificazione'      => $n($v['codice_classificazione']),
            ':gruppo_imballaggio'          => $n($v['gruppo_imballaggio']),
            ':categoria_trasporto'         => $n($v['categoria_trasporto']) !== null ? (int) $n($v['categoria_trasporto']) : null,
            ':codice_restrizione_gallerie' => $n($v['codice_restrizione_gallerie']),
            ':quantita_limitata'           => $n($v['quantita_limitata']) !== null ? (int) $n($v['quantita_limitata']) : null,
            ':codice_quantita_esente'      => $n($v['codice_quantita_esente']),
            ':e_merce_alto_rischio'        => (isset($v['e_merce_alto_rischio']) && $v['e_merce_alto_rischio'] === 1) ? 1 : null,
            ':e_inquinante_marino'         => (isset($v['e_inquinante_marino']) && $v['e_inquinante_marino'] === 1) ? 1 : null,
            ':inizio_validita'             => $n($v['inizio_validita']),
            ':fine_validita'               => $n($v['fine_validita']),
        ];
    }

    public function saveClasse(string $codice, string $denom, int $div): void
    {
        $sql = (DB_DRIVER === 'mysql')
            ? "INSERT INTO adr_classi_pericolo (codice, denominazione, ha_divisioni) VALUES (:c, :d, :hd) ON DUPLICATE KEY UPDATE denominazione=VALUES(denominazione), ha_divisioni=VALUES(ha_divisioni)"
            : "INSERT INTO adr_classi_pericolo (codice, denominazione, ha_divisioni) VALUES (:c, :d, :hd) ON CONFLICT(codice) DO UPDATE SET denominazione=excluded.denominazione, ha_divisioni=excluded.ha_divisioni";
        $this->pdo->prepare($sql)->execute([':c' => $codice, ':d' => $denom, ':hd' => $div]);
    }

    public function deleteClasse(string $codice): void
    {
        $this->pdo->prepare('DELETE FROM adr_classi_pericolo WHERE codice=:c')->execute([':c' => $codice]);
    }

    public function saveImballaggio(string $codice, string $desc): void
    {
        $sql = (DB_DRIVER === 'mysql')
            ? "INSERT INTO adr_gruppi_imballaggio (codice, descrizione) VALUES (:c, :d) ON DUPLICATE KEY UPDATE descrizione=VALUES(descrizione)"
            : "INSERT INTO adr_gruppi_imballaggio (codice, descrizione) VALUES (:c, :d) ON CONFLICT(codice) DO UPDATE SET descrizione=excluded.descrizione";
        $this->pdo->prepare($sql)->execute([':c' => $codice, ':d' => $desc]);
    }

    public function deleteImballaggio(string $codice): void
    {
        $this->pdo->prepare('DELETE FROM adr_gruppi_imballaggio WHERE codice=:c')->execute([':c' => $codice]);
    }

    public function saveGalleria(string $codice, ?string $cisterna, string $desc): void
    {
        $sql = (DB_DRIVER === 'mysql')
            ? "INSERT INTO adr_codici_restrizione_gallerie (codice, si_applica_a_cisterna, descrizione) VALUES (:c, :cs, :d) ON DUPLICATE KEY UPDATE si_applica_a_cisterna=VALUES(si_applica_a_cisterna), descrizione=VALUES(descrizione)"
            : "INSERT INTO adr_codici_restrizione_gallerie (codice, si_applica_a_cisterna, descrizione) VALUES (:c, :cs, :d) ON CONFLICT(codice) DO UPDATE SET si_applica_a_cisterna=excluded.si_applica_a_cisterna, descrizione=excluded.descrizione";
        $this->pdo->prepare($sql)->execute([':c' => $codice, ':cs' => $cisterna, ':d' => $desc]);
    }

    public function deleteGalleria(string $codice): void
    {
        $this->pdo->prepare('DELETE FROM adr_codici_restrizione_gallerie WHERE codice=:c')->execute([':c' => $codice]);
    }

    public function saveCategoria(int $cat, int $molt, ?int $soglia, ?string $desc): void
    {
        $sql = (DB_DRIVER === 'mysql')
            ? "INSERT INTO adr_categorie_trasporto (categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione) VALUES (:c, :m, :s, :d) ON DUPLICATE KEY UPDATE moltiplicatore_punti=VALUES(moltiplicatore_punti), soglia_esenzione_kg_l=VALUES(soglia_esenzione_kg_l), descrizione=VALUES(descrizione)"
            : "INSERT INTO adr_categorie_trasporto (categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione) VALUES (:c, :m, :s, :d) ON CONFLICT(categoria) DO UPDATE SET moltiplicatore_punti=excluded.moltiplicatore_punti, soglia_esenzione_kg_l=excluded.soglia_esenzione_kg_l, descrizione=excluded.descrizione";
        $this->pdo->prepare($sql)->execute([
            ':c' => $cat,
            ':m' => $molt,
            ':s' => $soglia,
            ':d' => $desc,
        ]);
    }

    public function deleteCategoria(int $cat): void
    {
        $this->pdo->prepare('DELETE FROM adr_categorie_trasporto WHERE categoria=:c')->execute([':c' => $cat]);
    }

    public function saveClassificazione(string $codice, string $classe, ?string $desc, ?string $prim, ?string $sec): void
    {
        $ex = $this->pdo->prepare('SELECT 1 FROM adr_codici_classificazione WHERE codice=:c AND codice_classe=:cl');
        $ex->execute([':c' => $codice, ':cl' => $classe]);
        if ($ex->fetchColumn()) {
            $this->pdo->prepare(
                'UPDATE adr_codici_classificazione
                    SET descrizione=:d, pericolo_primario=:pp, pericolo_secondario=:ps
                  WHERE codice=:c AND codice_classe=:cl'
            )->execute([
                ':c'  => $codice,
                ':cl' => $classe,
                ':d'  => $desc,
                ':pp' => $prim,
                ':ps' => $sec,
            ]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO adr_codici_classificazione (codice, codice_classe, descrizione, pericolo_primario, pericolo_secondario)
                 VALUES (:c, :cl, :d, :pp, :ps)'
            )->execute([
                ':c'  => $codice,
                ':cl' => $classe,
                ':d'  => $desc,
                ':pp' => $prim,
                ':ps' => $sec,
            ]);
        }
    }

    public function deleteClassificazione(string $codice, string $classe): void
    {
        $this->pdo->prepare('DELETE FROM adr_codici_classificazione WHERE codice=:c AND codice_classe=:cl')
            ->execute([':c' => $codice, ':cl' => $classe]);
    }
    
    public function loadAllClassiList(): array
    {
        return $this->pdo->query(
            'SELECT codice, denominazione, ha_divisioni
               FROM adr_classi_pericolo
              ORDER BY CAST(codice AS REAL)'
        )->fetchAll();
    }

    public function loadAllImballaggiList(): array
    {
        return $this->pdo->query(
            'SELECT codice, descrizione FROM adr_gruppi_imballaggio ORDER BY codice'
        )->fetchAll();
    }

    public function loadAllGallerieList(): array
    {
        $categorieConcat = db_group_concat_sql('c.categoria_galleria', ', ');
        return $this->pdo->query(
            "SELECT g.codice, g.si_applica_a_cisterna, g.descrizione,
                    {$categorieConcat} AS categorie
               FROM adr_codici_restrizione_gallerie g
          LEFT JOIN adr_restrizione_gallerie_categorie c ON c.restrizione_codice = g.codice
           GROUP BY g.codice
           ORDER BY g.codice"
        )->fetchAll();
    }

    public function loadAllCategorieTrasportoList(): array
    {
        return $this->pdo->query(
            'SELECT categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione
               FROM adr_categorie_trasporto
              ORDER BY categoria'
        )->fetchAll();
    }

    public function loadAllCodiciClassificazioneList(): array
    {
        return $this->pdo->query(
            'SELECT c.codice, c.codice_classe, c.descrizione, c.pericolo_primario, c.pericolo_secondario,
                    cl.denominazione AS classe_label
               FROM adr_codici_classificazione c
          LEFT JOIN adr_classi_pericolo cl ON cl.codice = c.codice_classe
              ORDER BY c.codice_classe, c.codice'
        )->fetchAll();
    }
    
    public function loadAllEsenzioneData(): array
    {
        $categorie = $this->pdo->query(
            'SELECT categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione
               FROM adr_categorie_trasporto
              ORDER BY categoria'
        )->fetchAll(PDO::FETCH_ASSOC);

        $sostanze = $this->pdo->query(
            "SELECT s.id, s.nome, s.codice_cas, s.gruppo_imballaggio,
                    c.codice_onu, c.classe_pericolo, c.categoria_trasporto,
                    c.quantita_limitata, c.codice_quantita_esente,
                    ct.moltiplicatore_punti, ct.soglia_esenzione_kg_l
               FROM sostanze_chimiche s
          LEFT JOIN adr_codici_onu c  ON c.id = s.codice_onu_id
          LEFT JOIN adr_categorie_trasporto ct ON ct.categoria = c.categoria_trasporto
              ORDER BY s.nome COLLATE NOCASE"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        return ['categorie' => $categorie, 'sostanze' => $sostanze];
    }
    
    public function searchApiProdotti(string $q, int $limit): array
    {
        $sql = "SELECT p.id, p.nome, p.sku_interno,
                       s.id AS sostanza_id, s.nome AS sostanza_nome, s.codice_cas,
                       s.gruppo_imballaggio,
                       c.codice_onu, c.classe_pericolo, c.categoria_trasporto,
                       ct.moltiplicatore_punti, ct.soglia_esenzione_kg_l
                  FROM prodotti p
                  JOIN sostanze_chimiche s        ON s.id = p.sostanza_chimica_id
             LEFT JOIN adr_codici_onu c          ON c.id = s.codice_onu_id
             LEFT JOIN adr_categorie_trasporto ct ON ct.categoria = c.categoria_trasporto
                 WHERE p.attivo = 1
                   AND COALESCE(p.is_adr, 0) = 1";
        $params = [];

        if ($q !== '') {
            $sql .= " AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR s.nome LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $sql .= " ORDER BY p.nome COLLATE NOCASE LIMIT :lim";
        $params[':lim'] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function lookup(string $table, string $idCol, string $labelCol, ?string $subCol, string $order, string $q): array
    {
        $where  = '';
        $params = [];
        if ($q !== '') {
            if (mb_strlen($q) === 1) {
                $where  = "WHERE CAST($idCol AS TEXT) = :q OR $labelCol LIKE :q_start"
                        . ($subCol ? " OR $subCol LIKE :q_start" : '');
                $params = [':q' => $q, ':q_start' => $q . '%'];
            } else {
                $where    = "WHERE CAST($idCol AS TEXT) LIKE :q OR $labelCol LIKE :q"
                          . ($subCol ? " OR $subCol LIKE :q" : '');
                $params   = [':q' => '%' . $q . '%'];
            }
        }

        $sql  = "SELECT $idCol AS id, $labelCol AS label"
              . ($subCol ? ", $subCol AS sub" : '')
              . " FROM $table $where ORDER BY $order LIMIT 200";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function validateClassi(): array
    {
        $classi = array_column($this->pdo->query('SELECT codice FROM adr_classi_pericolo')->fetchAll(PDO::FETCH_ASSOC), 'codice');
        $gruppi = array_column($this->pdo->query('SELECT codice FROM adr_gruppi_imballaggio')->fetchAll(PDO::FETCH_ASSOC), 'codice');
        $catTrasporto = array_column($this->pdo->query('SELECT categoria FROM adr_categorie_trasporto')->fetchAll(PDO::FETCH_ASSOC), 'categoria');
        $gallerie = array_column($this->pdo->query('SELECT codice FROM adr_codici_restrizione_gallerie')->fetchAll(PDO::FETCH_ASSOC), 'codice');
        $qtaEsente = array_column($this->pdo->query('SELECT codice FROM adr_quantita_esenti')->fetchAll(PDO::FETCH_ASSOC), 'codice');
        
        $classificazioni = [];
        foreach ($this->pdo->query('SELECT codice, codice_classe FROM adr_codici_classificazione')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $classificazioni[strtolower($r['codice']) . '|' . strtolower($r['codice_classe'])] = true;
        }
        
        return [
            'classi' => $classi,
            'gruppi' => $gruppi,
            'catTrasporto' => $catTrasporto,
            'gallerie' => $gallerie,
            'qtaEsente' => $qtaEsente,
            'classificazioni' => $classificazioni,
        ];
    }
}
