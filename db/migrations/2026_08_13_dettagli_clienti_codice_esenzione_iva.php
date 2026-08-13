<?php
/**
 * Migration: dettagli_clienti.codice_esenzione_iva da testo libero a insieme
 * fisso di valori ('export_extra_ue', 'intra_ue', 'dichiarazione_intento',
 * 'altro'), NULL = nessuna esenzione / motivo non specificato.
 *
 * Contesto: PIANO - Aliquota IVA su Prodotti e Cliente Esente (Livello
 * Intermedio), §4 e §8 Fase 2. Serve solo a distinguere in modo affidabile,
 * lato applicazione, il motivo di un'esenzione già gestita tramite il
 * booleano dettagli_clienti.iva_esente (invariato) — NON per conformità di
 * fatturazione elettronica (esclusa per sempre, Backlog Sviluppi Futuri
 * voce 12): il calcolo dell'aliquota su una riga dipende solo da iva_esente,
 * mai da questo campo.
 *
 * Decisione presa qui, non nel piano originale (motivata sotto): il vincolo
 * CHECK viene applicato SOLO lato MySQL con questa migrazione. Su SQLite
 * NON viene aggiunto un CHECK constraint a una colonna già esistente su un
 * database live, perché SQLite non supporta "ALTER TABLE ... ADD CONSTRAINT"
 * — l'unico modo sarebbe ricreare la tabella (rinominare in _old, creare la
 * nuova, copiare i dati, droppare la vecchia). Questo stesso progetto ha già
 * documentato un caso reale di corruzione dello schema causato esattamente
 * da questo pattern su installazioni SQLite (vedi
 * "Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md",
 * riga 589: rinominare tabelle in `_old` prima di ricrearle rompe i
 * riferimenti REFERENCES di altre tabelle da SQLite 3.25 in poi). Il
 * guadagno di un CHECK a livello DB su un campo già usato pochissimo (per
 * costruzione: oggi non letto da nessun punto del codice) non giustifica
 * quel rischio. Su SQLite il vincolo resta quindi solo applicativo
 * (ProductsController/CustomersController — vedi validate() del controller
 * cliente), esattamente come già oggi per altri campi enum-simili di questo
 * stesso schema (es. regime_fiscale, mai vincolato da un CHECK). db/init_sqlite.sql
 * (usato solo per creare database NUOVI, non per alterarne uno esistente) ha
 * comunque il CHECK fin dalla creazione — nessuna incoerenza: i database
 * creati da zero dopo questa modifica hanno il vincolo, quelli esistenti
 * migrati con questo script no.
 *
 * Idempotente: su MySQL controlla se il constraint esiste già prima di
 * aggiungerlo. Su SQLite non fa nulla (vedi sopra) — echo esplicito, non un
 * silenzio che potrebbe sembrare un errore di esecuzione.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php'; // adatta al percorso reale del PDO

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    echo "  [--] SQLite: nessuna modifica di schema applicata volutamente (vedi commento in testa a questo file).\n";
    echo "       Il vincolo sui valori ammessi per codice_esenzione_iva resta solo applicativo su questo database.\n";
    exit(0);
}

// MySQL / MariaDB
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dettagli_clienti'
       AND CONSTRAINT_NAME = 'chk_dettagli_clienti_codice_esenzione_iva'"
);
$stmt->execute();
$exists = (int) $stmt->fetchColumn() > 0;

if ($exists) {
    echo "  [--] Vincolo chk_dettagli_clienti_codice_esenzione_iva già presente, skip.\n";
    exit(0);
}

// Righe esistenti con un valore fuori dall'insieme ammesso bloccherebbero
// l'ALTER TABLE (MySQL valida i dati esistenti quando aggiunge un CHECK) —
// verificato PRIMA di alterare, mai un fallimento a metà silenzioso.
$stmtBad = $pdo->query(
    "SELECT id, codice_esenzione_iva FROM dettagli_clienti
      WHERE codice_esenzione_iva IS NOT NULL
        AND codice_esenzione_iva NOT IN ('export_extra_ue', 'intra_ue', 'dichiarazione_intento', 'altro')"
);
$badRows = $stmtBad->fetchAll(PDO::FETCH_ASSOC);

if (!empty($badRows)) {
    echo "  [STOP] " . count($badRows) . " riga/e con un codice_esenzione_iva fuori dall'insieme ammesso:\n";
    foreach ($badRows as $r) {
        echo "         dettagli_clienti.id={$r['id']}: \"{$r['codice_esenzione_iva']}\"\n";
    }
    echo "  Correggi manualmente questi valori (o portali a NULL) prima di rilanciare la migrazione.\n";
    echo "  Nessuna modifica di schema applicata.\n";
    exit(1);
}

$pdo->exec(
    "ALTER TABLE dettagli_clienti ADD CONSTRAINT chk_dettagli_clienti_codice_esenzione_iva
     CHECK (codice_esenzione_iva IS NULL OR codice_esenzione_iva IN (
         'export_extra_ue', 'intra_ue', 'dichiarazione_intento', 'altro'
     ))"
);
echo "  [OK] Vincolo chk_dettagli_clienti_codice_esenzione_iva aggiunto.\n";
echo "\nMigrazione completata.\n";
