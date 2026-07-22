<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * SchedeSicurezzaService — logica di dominio per le Schede Dati di Sicurezza
 * (SDS) agganciate a prodotto-fornitore.
 *
 * Contesto: docs/appunti adr/PIANO - Refactoring Schede di Sicurezza (SDS) su
 * Prodotto-Fornitore.md (M2). Nessun accesso a $_FILES o all'HTTP qui dentro:
 * questa classe riceve percorsi/stringhe già estratti dalla request e
 * restituisce dati o solleva eccezioni con messaggi leggibili in italiano —
 * l'orchestrazione HTTP (multipart, move_uploaded_file, unlink, transazione,
 * risposta JSON) vive in ProductsSdsController (M3), che è l'unico a
 * conoscere UPLOAD_DIR e i dettagli della request.
 *
 * Ogni metodo che scrive (inserisciScheda/aggiornaScheda/eliminaSchedaCompleta/
 * rimuoviSoloFile) presume di essere chiamato DENTRO una transazione già
 * aperta dal controller quando fa parte del flusso apiSdsSave() (§3.12 del
 * piano) — questa classe non apre né chiude transazioni proprie, per lasciare
 * al chiamante il controllo dell'atomicità complessiva.
 */
class SchedeSicurezzaService
{
    private PDO $pdo;

    /**
     * Whitelist MIME reale (sniffata dal contenuto, non dall'estensione
     * dichiarata) → estensione fisica da usare. Stessa lista già in uso in
     * SostanzeChimicheController::uploadScheda() (SDS legacy) — vedi CLAUDE.md
     * punto 1, riusare invece di reinventare.
     */
    private const ALLOWED_MIME_TO_EXT = [
        'application/pdf'                                                         => 'pdf',
        'application/msword'                                                      => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/zip'                                                         => 'docx', // ambiguo, vedi validaMimeFile()
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ════════════════════════════════════════════════════════════════════
     *  VALIDAZIONE FILE (§4.8, §3.8 del piano)
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * Verifica il MIME reale del file (sniffato dal contenuto) e ritorna
     * l'estensione fisica da usare. Solleva RuntimeException con messaggio
     * leggibile se il formato non è ammesso.
     *
     * @param string $tmpFilePath Percorso del file (tipicamente $_FILES[...]['tmp_name'])
     * @return string Estensione senza punto, es. 'pdf', 'doc', 'docx'
     */
    public function validaMimeFile(string $tmpFilePath): string
    {
        if (!is_file($tmpFilePath)) {
            throw new RuntimeException('File non trovato o upload non riuscito.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? finfo_file($finfo, $tmpFilePath) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($realMime === false || !isset(self::ALLOWED_MIME_TO_EXT[$realMime])) {
            throw new RuntimeException('Formato non consentito. Usa PDF, DOC o DOCX.');
        }

        // application/zip è ambiguo: potrebbe essere un vero DOCX, ma anche un
        // XLSX/PPTX/JAR/zip generico rilevato genericamente allo stesso modo.
        // Ispezioniamo il contenuto per confermare che sia davvero un DOCX
        // prima di accettarlo con quell'estensione (qk_is_docx_zip, già
        // esistente in includes/functions.php, incluso globalmente).
        if ($realMime === 'application/zip' && !qk_is_docx_zip($tmpFilePath)) {
            throw new RuntimeException('Formato non consentito. Usa PDF, DOC o DOCX.');
        }

        return self::ALLOWED_MIME_TO_EXT[$realMime];
    }

    /**
     * Genera il nome fisico del file da salvare su disco: token casuale
     * crittografico, mai basato su data/ora o sul nome originale (§3.8 del
     * piano — un nome prevedibile rischia collisione silenziosa e
     * sovrascrittura di un file esistente).
     */
    public function generaNomeFisico(string $estensione): string
    {
        return bin2hex(random_bytes(16)) . '.' . ltrim($estensione, '.');
    }

    /* ════════════════════════════════════════════════════════════════════
     *  VALIDAZIONE LINGUA (§3.9 del piano)
     * ════════════════════════════════════════════════════════════════════ */

    /** Normalizza (minuscolo, trim) senza validare l'esistenza. */
    public function normalizzaLingua(string $lingua): string
    {
        return strtolower(trim($lingua));
    }

    /**
     * Normalizza e verifica che il codice esista in iso_lingue. Il controllo
     * applicativo esplicito qui restituisce un messaggio leggibile invece di
     * lasciare che sia l'eccezione grezza del vincolo FK a bloccare l'insert
     * (§3.9 del piano). Solleva RuntimeException se il codice non è valido.
     *
     * @return string Il codice normalizzato (minuscolo), pronto per l'uso.
     */
    public function validaLingua(string $lingua): string
    {
        $codice = $this->normalizzaLingua($lingua);
        if ($codice === '') {
            throw new RuntimeException('Lingua obbligatoria.');
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM iso_lingue WHERE codice = ?');
        $st->execute([$codice]);
        if ((int) $st->fetchColumn() === 0) {
            throw new RuntimeException("Codice lingua \"{$codice}\" non riconosciuto (deve essere un codice ISO 639-1 valido, es. 'it', 'en', 'de').");
        }
        return $codice;
    }

    /* ════════════════════════════════════════════════════════════════════
     *  OVERLAP E SCOPING "CORRENTE" (§3.2, §3.3 del piano)
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * Cerca righe ESISTENTI che confliggono con il nuovo intervallo
     * [$validoDal, $validoAl] per la stessa combinazione
     * (prodotto_fornitore_id, lingua). I NULL vanno trattati come "senza
     * limite" (query esatta da §3.3 del piano).
     *
     * @param int $selfId Riga da escludere dal controllo (0 = nessuna, caso INSERT).
     * @return array|null La prima riga in conflitto (id, valido_dal, valido_al), o null se nessun conflitto.
     */
    public function checkOverlap(
        int $prodottoFornitoreId,
        string $lingua,
        ?string $validoDal,
        ?string $validoAl,
        int $selfId = 0
    ): ?array {
        $st = $this->pdo->prepare(
            'SELECT id, valido_dal, valido_al
               FROM prodotti_fornitori_schede_sicurezza
              WHERE prodotto_fornitore_id = :pfid
                AND lingua = :lingua
                AND id != :self_id
                AND (:nuovo_dal IS NULL OR valido_al   IS NULL OR valido_al   >= :nuovo_dal)
                AND (:nuovo_al  IS NULL OR valido_dal  IS NULL OR valido_dal  <= :nuovo_al)
              LIMIT 1'
        );
        $st->execute([
            ':pfid'      => $prodottoFornitoreId,
            ':lingua'    => $lingua,
            ':self_id'   => $selfId,
            ':nuovo_dal' => $validoDal,
            ':nuovo_al'  => $validoAl,
        ]);
        $riga = $st->fetch();
        return $riga ?: null;
    }

    /**
     * Azzera e_corrente sulle altre righe della stessa combinazione
     * (prodotto_fornitore_id, lingua), lasciando intatta la riga
     * $idEsclusa (quella che deve restare/diventare corrente) — §3.2 del
     * piano: "corrente" è scoped per lingua, non globale.
     */
    public function ricalcolaCorrente(int $prodottoFornitoreId, string $lingua, int $idEsclusa): void
    {
        $this->pdo->prepare(
            'UPDATE prodotti_fornitori_schede_sicurezza
                SET e_corrente = 0
              WHERE prodotto_fornitore_id = ?
                AND lingua = ?
                AND id != ?'
        )->execute([$prodottoFornitoreId, $lingua, $idEsclusa]);
    }

    /* ════════════════════════════════════════════════════════════════════
     *  LETTURA
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * Riga SDS con verifica di ownership (la SDS deve appartenere a un
     * prodotto_fornitore del prodotto indicato) — stesso pattern di
     * ProductsPricingController::savePrezzo() (WHERE id = :id AND
     * prodotto_id = :pid, tramite join). Ritorna null se non trovata o non
     * appartenente a quel prodotto.
     */
    public function getSchedaOwned(int $id, int $prodottoId): ?array
    {
        // Stessa forma (join fornitore incluso) di getSchede(), non solo
        // id/lingua: così il risultato è direttamente utilizzabile sia per
        // il controllo ownership sia per formattare la risposta JSON dopo
        // save/rimuovi-file (ProductsSdsController::formatRiga()) senza una
        // seconda query.
        $st = $this->pdo->prepare(
            'SELECT s.*, pf.prodotto_id, pf.fornitore_id,
                    cf.ragione_sociale AS nome_fornitore, pf.attivo AS fornitore_attivo
               FROM prodotti_fornitori_schede_sicurezza s
               JOIN prodotti_fornitori pf ON pf.id = s.prodotto_fornitore_id
               JOIN clienti_fornitori cf  ON cf.id = pf.fornitore_id
              WHERE s.id = ? AND pf.prodotto_id = ?'
        );
        $st->execute([$id, $prodottoId]);
        $riga = $st->fetch();
        return $riga ?: null;
    }

    /**
     * Elenco SDS di un prodotto (tutte le lingue/fornitori), con nome e
     * stato attivo/disattivo del fornitore collegato — per apiSdsList()
     * (§5.2 del piano) e per il rendering della tab M5 (badge "Inattivo",
     * §4.23). Ordinata fornitore → lingua → valido_dal decrescente (§4.15).
     */
    public function getSchede(int $prodottoId): array
    {
        $st = $this->pdo->prepare(
            'SELECT s.id, s.prodotto_fornitore_id, pf.fornitore_id,
                    cf.ragione_sociale AS nome_fornitore, pf.attivo AS fornitore_attivo,
                    s.lingua, s.versione, s.data_emissione, s.valido_dal, s.valido_al,
                    s.file_path, s.file_nome_orig, s.file_rimosso_il, s.e_corrente,
                    s.created_at, s.updated_at, s.note
               FROM prodotti_fornitori_schede_sicurezza s
               JOIN prodotti_fornitori pf ON pf.id = s.prodotto_fornitore_id
               JOIN clienti_fornitori cf  ON cf.id = pf.fornitore_id
              WHERE pf.prodotto_id = ?
              ORDER BY cf.ragione_sociale, s.lingua, s.valido_dal DESC'
        );
        $st->execute([$prodottoId]);
        return $st->fetchAll();
    }

    /* ════════════════════════════════════════════════════════════════════
     *  SCRITTURA (§3.10, §3.11, §3.12, §3.13 del piano)
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * Inserisce una nuova scheda. Diventa sempre e_corrente=1 (stesso
     * comportamento della SDS legacy: un nuovo upload è sempre la versione
     * "in vigore" per quella lingua finché non arriva qualcos'altro) — il
     * chiamante deve poi invocare ricalcolaCorrente() per azzerare le
     * eventuali righe sorelle (non fatto qui dentro: il controller decide
     * quando, dentro la stessa transazione, §3.12).
     *
     * @param array $dati Chiavi: prodotto_fornitore_id, lingua (già
     *   normalizzata/validata dal chiamante), versione, data_emissione,
     *   valido_dal, valido_al, file_path, file_nome_orig, note, creato_da.
     * @return int Id della riga appena creata.
     */
    public function inserisciScheda(array $dati): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO prodotti_fornitori_schede_sicurezza
                (prodotto_fornitore_id, lingua, versione, data_emissione,
                 valido_dal, valido_al, file_path, file_nome_orig, note,
                 creato_da, e_corrente)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $st->execute([
            $dati['prodotto_fornitore_id'],
            $dati['lingua'],
            $dati['versione'] ?? null,
            $dati['data_emissione'] ?? null,
            $dati['valido_dal'] ?? null,
            $dati['valido_al'] ?? null,
            $dati['file_path'] ?? null,
            $dati['file_nome_orig'] ?? null,
            $dati['note'] ?? null,
            $dati['creato_da'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Aggiorna una scheda esistente, gestendo correttamente il caso "cambio
     * di lingua" (§3.11 del piano): se $dati['lingua'] differisce dalla
     * lingua già salvata, la riga si sposta di gruppo scoping. Il chiamante
     * deve aver già eseguito checkOverlap() contro il gruppo di destinazione
     * PRIMA di chiamare questo metodo (§3.12: overlap si controlla prima di
     * toccare qualunque riga) — qui ci si limita a scrivere.
     *
     * updated_at è impostato esplicitamente qui (§3.10) — nessun trigger DB
     * lo fa da solo.
     *
     * Non forza e_corrente: il contratto API (§5.3) non permette di
     * cambiarlo direttamente. Se la riga ERA (prima dell'update) e_corrente
     * = 1, il chiamante deve invocare ricalcolaCorrente() sul gruppo di
     * ARRIVO dopo questa chiamata (§3.11 punto 5) — nessuna azione sul
     * gruppo di provenienza (§3.11 punto 6, deliberato: nessuna promozione
     * automatica di un'altra riga).
     *
     * @param array $dati Stesse chiavi di inserisciScheda(), tranne
     *   creato_da (non si tocca in update). file_path/file_nome_orig
     *   opzionali: se assenti, il file esistente non viene modificato.
     */
    public function aggiornaScheda(int $id, array $dati): void
    {
        $vecchia = $this->pdo->prepare('SELECT * FROM prodotti_fornitori_schede_sicurezza WHERE id = ?');
        $vecchia->execute([$id]);
        $rigaVecchia = $vecchia->fetch();
        if (!$rigaVecchia) {
            throw new RuntimeException('Scheda non trovata.');
        }

        $aggiornaFile = array_key_exists('file_path', $dati);

        $sql = 'UPDATE prodotti_fornitori_schede_sicurezza
                   SET lingua = ?, versione = ?, data_emissione = ?,
                       valido_dal = ?, valido_al = ?, note = ?,
                       updated_at = CURRENT_TIMESTAMP';
        $params = [
            $dati['lingua'],
            $dati['versione'] ?? null,
            $dati['data_emissione'] ?? null,
            $dati['valido_dal'] ?? null,
            $dati['valido_al'] ?? null,
            $dati['note'] ?? null,
        ];
        if ($aggiornaFile) {
            $sql .= ', file_path = ?, file_nome_orig = ?, file_rimosso_il = NULL';
            $params[] = $dati['file_path'];
            $params[] = $dati['file_nome_orig'] ?? null;
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Verifica se una scheda può essere eliminata per intero (riga + file):
     * solo se non è mai entrata in vigore, cioè valido_dal è valorizzato ED è
     * una data futura rispetto a oggi (§3.13 del piano). Non esegue alcuna
     * scrittura: solo il controllo. Ritorna null se l'eliminazione è
     * permessa, altrimenti l'array di errore pronto per la risposta JSON
     * (§5.4: error "in_vigore" + messaggio esatto).
     */
    public function verificaEliminabile(array $riga): ?array
    {
        $validoDal = $riga['valido_dal'] ?? null;
        $oggi = date('Y-m-d');
        $mainEntrataInVigore = ($validoDal !== null && $validoDal > $oggi);
        if ($mainEntrataInVigore) {
            return null; // eliminabile
        }
        $quando = $validoDal !== null ? $validoDal : 'sempre';
        return [
            'ok' => false,
            'error' => 'in_vigore',
            'messaggio' => "Impossibile eliminare: questa scheda risulta (o è stata) in vigore dal {$quando}. "
                . "Usa 'Rimuovi solo il file' per liberare spazio mantenendo la tracciabilità.",
        ];
    }

    /**
     * Elimina la riga (SOLO il record DB — l'unlink del file fisico resta al
     * controller, che conosce UPLOAD_DIR). Il chiamante deve aver già
     * verificato verificaEliminabile() === null prima di chiamare questo
     * metodo.
     *
     * @return string|null Il file_path che il controller dovrà unlinkare (o null se già assente).
     */
    public function eliminaRiga(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT file_path FROM prodotti_fornitori_schede_sicurezza WHERE id = ?');
        $st->execute([$id]);
        $filePath = $st->fetchColumn();

        $this->pdo->prepare('DELETE FROM prodotti_fornitori_schede_sicurezza WHERE id = ?')->execute([$id]);

        return $filePath !== false ? $filePath : null;
    }

    /**
     * Rimuove solo il riferimento al file (metadati conservati) — sempre
     * permesso, indipendentemente da validità/correntezza (§3.13/§5.4bis).
     * L'unlink fisico resta al controller; questo metodo aggiorna solo il DB
     * e ritorna il vecchio file_path da cancellare su disco (null se il file
     * era già stato rimosso in precedenza — operazione non distruttiva se
     * ripetuta, test 26 del piano).
     */
    public function rimuoviSoloFile(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT file_path FROM prodotti_fornitori_schede_sicurezza WHERE id = ?');
        $st->execute([$id]);
        $filePath = $st->fetchColumn();

        $this->pdo->prepare(
            'UPDATE prodotti_fornitori_schede_sicurezza
                SET file_path = NULL, file_rimosso_il = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([$id]);

        return ($filePath !== false && $filePath !== null) ? $filePath : null;
    }
}
