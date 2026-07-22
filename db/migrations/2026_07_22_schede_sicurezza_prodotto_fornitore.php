<?php
/**
 * Migration: crea le tabelle `iso_lingue` e `prodotti_fornitori_schede_sicurezza`.
 *
 * Contesto (PIANO - Refactoring Schede di Sicurezza (SDS) su Prodotto-Fornitore.md,
 * 22/07/2026): sposta la gestione delle Schede Dati di Sicurezza (SDS) dalla
 * sostanza chimica condivisa (sostanze_chimiche_schede) al rapporto specifico
 * prodotto↔fornitore (prodotti_fornitori), con versioning, periodo di validità
 * e gestione multi-lingua vincolata a un elenco ufficiale ISO 639-1.
 *
 * NON tocca in alcun modo `sostanze_chimiche_schede` (verrà rimossa solo in una
 * migrazione futura, M4 del piano, DOPO che il codice legacy che la usa è stato
 * rimosso — vedi §3.6-bis del piano: droppare la tabella prima romperebbe
 * /adr/sostanze/edit per tutto il tempo che intercorre tra le due modifiche).
 *
 * iso_lingue: tabella di riferimento statica ISO 639-1 (codice a 2 lettere,
 * sempre minuscolo, + nome leggibile). Popolata con l'elenco ufficiale completo
 * (184 codici). Scelta esplicita su questa migrazione, non decisa nel piano
 * originale: i nomi sono in ITALIANO ("Tedesco", non "Deutsch"/"German") — il
 * piano dà solo un esempio misto ("Italiano", "English") senza deliberare la
 * lingua dei nomi. Motivazione: l'operatore che classifica una SDS in questo
 * ERP è un utente italofono che deve riconoscere il nome della lingua, non
 * necessariamente leggerne lo script nativo (es. "日本語", "العربية"); l'intera
 * applicazione è già in italiano (vedi CLAUDE.md). Scelta facilmente reversibile
 * in futuro: è un semplice UPDATE sui 184 valori di `nome`, non un cambio di
 * schema. Vedi §3.9 del piano per il ragionamento completo sul perché una
 * tabella di riferimento con FK invece di un campo di testo libero.
 *
 * prodotti_fornitori_schede_sicurezza: la tabella SDS vera e propria, con:
 *  - valido_dal_key, colonna generata (GENERATED ALWAYS AS ... STORED) che
 *    sostituisce i NULL di valido_dal con una sentinella fissa, necessaria
 *    perché altrimenti il vincolo UNIQUE sotto non catturerebbe due righe
 *    entrambe con valido_dal NULL (NULL non è mai uguale a un altro NULL in
 *    SQL) — vedi §3.15 del piano. Richiede SQLite >= 3.31 e MySQL >= 5.7.6/8.0;
 *    verificato sull'ambiente di sviluppo attuale (SQLite 3.37.2, 22/07/2026)
 *    PRIMA di scrivere questa migrazione, come richiesto dal piano — non
 *    assunto. Il controllo qui sotto lo riverifica anche a runtime, per non
 *    fallire silenziosamente su un ambiente diverso in futuro.
 *  - file_path nullable + file_rimosso_il: distinzione tra "eliminare il
 *    record" e "rimuovere solo il file fisico", §3.13 del piano.
 *
 * Idempotente: CREATE TABLE IF NOT EXISTS + INSERT OR IGNORE/INSERT IGNORE,
 * sicura da rieseguire.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_mig_sds_table_exists(PDO $pdo, string $table): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

// --- Verifica supporto colonne generate (§3.15 / §6 M1 del piano) — non assunto, controllato a runtime ---
if ($driver === 'sqlite') {
    $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    if (version_compare($version, '3.31.0', '<')) {
        fwrite(STDERR, "ERRORE: SQLite $version non supporta le colonne generate (GENERATED ALWAYS AS ... STORED), richiesta >= 3.31.0. Migrazione interrotta, nessuna modifica applicata. Vedi §3.15 del piano SDS per discutere un'alternativa prima di procedere.\n");
        exit(1);
    }
    echo "  [OK] SQLite $version supporta le colonne generate (>= 3.31.0 richiesta)\n";
} else {
    // Nessuna connessione MySQL disponibile in questo ambiente di sviluppo per una
    // verifica automatica. RICORDA: verificare manualmente (SELECT VERSION()) che il
    // server di destinazione sia >= 5.7.6 (o MariaDB con supporto equivalente) PRIMA
    // di eseguire questa migrazione in un ambiente MySQL - §3.15/§6 M1 del piano.
    echo "  [!!] Driver MySQL: verificare manualmente SELECT VERSION() >= 5.7.6 prima di procedere (non verificato automaticamente qui)\n";
}

// --- 1. Tabella di riferimento iso_lingue (creata PRIMA, referenziata dalla tabella SDS) ---
if (!qk_mig_sds_table_exists($pdo, 'iso_lingue')) {
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE iso_lingue (
            codice  CHAR(2)      PRIMARY KEY,
            nome    VARCHAR(100) NOT NULL
        )");
    } else {
        $pdo->exec("CREATE TABLE iso_lingue (
            codice  CHAR(2)      PRIMARY KEY,
            nome    VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "  [OK] tabella iso_lingue creata\n";
} else {
    echo "  [--] tabella iso_lingue già presente, skip creazione\n";
}

// Elenco ufficiale ISO 639-1 (184 codici), nomi in italiano — vedi commento in testa al file.
$isoLingue = [
    ['aa', 'Afar'], ['ab', 'Abcaso'], ['ae', 'Avestico'], ['af', 'Afrikaans'],
    ['ak', 'Akan'], ['am', 'Amarico'], ['an', 'Aragonese'], ['ar', 'Arabo'],
    ['as', 'Assamese'], ['av', 'Avaro'], ['ay', 'Aymara'], ['az', 'Azero'],
    ['ba', 'Baschiro'], ['be', 'Bielorusso'], ['bg', 'Bulgaro'], ['bh', 'Bihari'],
    ['bi', 'Bislama'], ['bm', 'Bambara'], ['bn', 'Bengalese'], ['bo', 'Tibetano'],
    ['br', 'Bretone'], ['bs', 'Bosniaco'], ['ca', 'Catalano'], ['ce', 'Ceceno'],
    ['ch', 'Chamorro'], ['co', 'Corso'], ['cr', 'Cree'], ['cs', 'Ceco'],
    ['cu', 'Slavo ecclesiastico'], ['cv', 'Ciuvascio'], ['cy', 'Gallese'], ['da', 'Danese'],
    ['de', 'Tedesco'], ['dv', 'Divehi'], ['dz', 'Dzongkha'], ['ee', 'Ewe'],
    ['el', 'Greco'], ['en', 'Inglese'], ['eo', 'Esperanto'], ['es', 'Spagnolo'],
    ['et', 'Estone'], ['eu', 'Basco'], ['fa', 'Persiano'], ['ff', 'Fulah'],
    ['fi', 'Finlandese'], ['fj', 'Figiano'], ['fo', 'Faroese'], ['fr', 'Francese'],
    ['fy', 'Frisone occidentale'], ['ga', 'Irlandese'], ['gd', 'Gaelico scozzese'], ['gl', 'Galiziano'],
    ['gn', 'Guarani'], ['gu', 'Gujarati'], ['gv', 'Mannese'], ['ha', 'Hausa'],
    ['he', 'Ebraico'], ['hi', 'Hindi'], ['ho', 'Hiri Motu'], ['hr', 'Croato'],
    ['ht', 'Creolo haitiano'], ['hu', 'Ungherese'], ['hy', 'Armeno'], ['hz', 'Herero'],
    ['ia', 'Interlingua'], ['id', 'Indonesiano'], ['ie', 'Interlingue'], ['ig', 'Igbo'],
    ['ii', 'Yi del Sichuan'], ['ik', 'Inupiaq'], ['io', 'Ido'], ['is', 'Islandese'],
    ['it', 'Italiano'], ['iu', 'Inuktitut'], ['ja', 'Giapponese'], ['jv', 'Giavanese'],
    ['ka', 'Georgiano'], ['kg', 'Kongo'], ['ki', 'Kikuyu'], ['kj', 'Kuanyama'],
    ['kk', 'Kazako'], ['kl', 'Groenlandese'], ['km', 'Khmer'], ['kn', 'Kannada'],
    ['ko', 'Coreano'], ['kr', 'Kanuri'], ['ks', 'Kashmiri'], ['ku', 'Curdo'],
    ['kv', 'Komi'], ['kw', 'Cornico'], ['ky', 'Kirghiso'], ['la', 'Latino'],
    ['lb', 'Lussemburghese'], ['lg', 'Ganda'], ['li', 'Limburghese'], ['ln', 'Lingala'],
    ['lo', 'Lao'], ['lt', 'Lituano'], ['lu', 'Luba-Katanga'], ['lv', 'Lettone'],
    ['mg', 'Malgascio'], ['mh', 'Marshallese'], ['mi', 'Maori'], ['mk', 'Macedone'],
    ['ml', 'Malayalam'], ['mn', 'Mongolo'], ['mr', 'Marathi'], ['ms', 'Malese'],
    ['mt', 'Maltese'], ['my', 'Birmano'], ['na', 'Nauruano'], ['nb', 'Norvegese Bokmal'],
    ['nd', 'Ndebele del nord'], ['ne', 'Nepalese'], ['ng', 'Ndonga'], ['nl', 'Olandese'],
    ['nn', 'Norvegese Nynorsk'], ['no', 'Norvegese'], ['nr', 'Ndebele del sud'], ['nv', 'Navajo'],
    ['ny', 'Chichewa'], ['oc', 'Occitano'], ['oj', 'Ojibwe'], ['om', 'Oromo'],
    ['or', 'Oriya'], ['os', 'Osseto'], ['pa', 'Panjabi'], ['pi', 'Pali'],
    ['pl', 'Polacco'], ['ps', 'Pashto'], ['pt', 'Portoghese'], ['qu', 'Quechua'],
    ['rm', 'Romancio'], ['rn', 'Rundi'], ['ro', 'Rumeno'], ['ru', 'Russo'],
    ['rw', 'Kinyarwanda'], ['sa', 'Sanscrito'], ['sc', 'Sardo'], ['sd', 'Sindhi'],
    ['se', 'Sami del nord'], ['sg', 'Sango'], ['si', 'Singalese'], ['sk', 'Slovacco'],
    ['sl', 'Sloveno'], ['sm', 'Samoano'], ['sn', 'Shona'], ['so', 'Somalo'],
    ['sq', 'Albanese'], ['sr', 'Serbo'], ['ss', 'Swati'], ['st', 'Sotho del sud'],
    ['su', 'Sundanese'], ['sv', 'Svedese'], ['sw', 'Swahili'], ['ta', 'Tamil'],
    ['te', 'Telugu'], ['tg', 'Tagiko'], ['th', 'Thailandese'], ['ti', 'Tigrino'],
    ['tk', 'Turkmeno'], ['tl', 'Tagalog'], ['tn', 'Tswana'], ['to', 'Tongano'],
    ['tr', 'Turco'], ['ts', 'Tsonga'], ['tt', 'Tataro'], ['tw', 'Twi'],
    ['ty', 'Tahitiano'], ['ug', 'Uiguro'], ['uk', 'Ucraino'], ['ur', 'Urdu'],
    ['uz', 'Uzbeco'], ['ve', 'Venda'], ['vi', 'Vietnamita'], ['vo', 'Volapuk'],
    ['wa', 'Vallone'], ['wo', 'Wolof'], ['xh', 'Xhosa'], ['yi', 'Yiddish'],
    ['yo', 'Yoruba'], ['za', 'Zhuang'], ['zh', 'Cinese'], ['zu', 'Zulu'],
];

$insertSql = $driver === 'sqlite'
    ? "INSERT OR IGNORE INTO iso_lingue (codice, nome) VALUES (?, ?)"
    : "INSERT IGNORE INTO iso_lingue (codice, nome) VALUES (?, ?)";
$stIns = $pdo->prepare($insertSql);
$inseriti = 0;
foreach ($isoLingue as [$codice, $nome]) {
    $stIns->execute([$codice, $nome]);
    if ($stIns->rowCount() > 0) $inseriti++;
}
echo "  [OK] iso_lingue: $inseriti righe inserite (" . count($isoLingue) . " totali nell'elenco ufficiale, le altre già presenti)\n";

// --- 2. Tabella prodotti_fornitori_schede_sicurezza ---
if (!qk_mig_sds_table_exists($pdo, 'prodotti_fornitori_schede_sicurezza')) {
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE prodotti_fornitori_schede_sicurezza (
            id                     INTEGER   PRIMARY KEY AUTOINCREMENT,
            prodotto_fornitore_id  INTEGER   NOT NULL REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
            lingua                 CHAR(2)   NOT NULL REFERENCES iso_lingue(codice),
            versione               VARCHAR(20),
            data_emissione         DATE,
            valido_dal             DATE,
            valido_al              DATE,
            valido_dal_key         DATE      GENERATED ALWAYS AS (COALESCE(valido_dal, '0001-01-01')) STORED,
            file_path              TEXT,
            file_nome_orig         VARCHAR(255),
            file_rimosso_il        DATETIME,
            e_corrente             BOOLEAN   NOT NULL DEFAULT 1,
            note                   TEXT,
            creato_da              INTEGER   REFERENCES utenti(id),
            created_at             DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at             DATETIME,
            UNIQUE (prodotto_fornitore_id, lingua, valido_dal_key)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pf_sds_pf_lingua
            ON prodotti_fornitori_schede_sicurezza(prodotto_fornitore_id, lingua)");
    } else {
        $pdo->exec("CREATE TABLE prodotti_fornitori_schede_sicurezza (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prodotto_fornitore_id INTEGER NOT NULL,
            lingua CHAR(2) NOT NULL,
            versione VARCHAR(20),
            data_emissione DATE,
            valido_dal DATE,
            valido_al DATE,
            valido_dal_key DATE GENERATED ALWAYS AS (COALESCE(valido_dal, '0001-01-01')) STORED,
            file_path TEXT,
            file_nome_orig VARCHAR(255),
            file_rimosso_il DATETIME,
            e_corrente TINYINT(1) NOT NULL DEFAULT 1,
            note TEXT,
            creato_da INTEGER,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            UNIQUE (prodotto_fornitore_id, lingua, valido_dal_key),
            CONSTRAINT fk_pf_sds_prodotto_fornitore_id FOREIGN KEY (prodotto_fornitore_id) REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
            CONSTRAINT fk_pf_sds_lingua FOREIGN KEY (lingua) REFERENCES iso_lingue(codice),
            CONSTRAINT fk_pf_sds_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE INDEX idx_pf_sds_pf_lingua
            ON prodotti_fornitori_schede_sicurezza(prodotto_fornitore_id, lingua)");
    }
    echo "  [OK] tabella prodotti_fornitori_schede_sicurezza creata (con indice idx_pf_sds_pf_lingua)\n";
} else {
    echo "  [--] tabella prodotti_fornitori_schede_sicurezza già presente, skip creazione\n";
}

echo "\nMigrazione completata. sostanze_chimiche_schede non è stata toccata (rimozione prevista in M4).\n";
