<?php
/**
 * Migration 005 — sostanze_chimiche
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Rinomina sostanze_adr          → sostanze_chimiche
 * 2. Rinomina sostanze_adr_schede   → sostanze_chimiche_schede
 * 3. Aggiunge colonne scalari a sostanze_chimiche:
 *      codice_ufi, codice_einecs, codice_reach, numero_kemler,
 *      stato_fisico, clp_avvertenza
 * 4. Aggiunge colonna prodotti.sostanza_chimica_id (alias/rinomina logica)
 *    NOTA: SQLite non supporta RENAME COLUMN prima della 3.25.0;
 *    se la versione è < 3.25 la colonna viene aggiunta come alias e
 *    la vecchia mantenuta. Su MySQL la colonna viene rinominata direttamente.
 * 5. Crea tabelle lookup CLP:
 *      clp_h_statements, clp_p_statements, clp_pittogrammi
 * 6. Crea tabelle pivot:
 *      sostanze_h_statements, sostanze_p_statements, sostanze_pittogrammi
 * 7. Inserisce seed H statements, P statements e pittogrammi GHS completi.
 *
 * Eseguire da riga di comando:
 *   php db/migrations/005_sostanze_chimiche.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';

$dsn = 'sqlite:' . APP_ROOT_DIR . '/db/database.sqlite';
$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('PRAGMA journal_mode = WAL');

$log = [];

/* ═══════════════════════════════════════════════════════════════════════════
 *  Helper
 * ═══════════════════════════════════════════════════════════════════════════ */
function tableExists(PDO $pdo, string $name): bool {
    return (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$name}'")->fetchColumn();
}
function columnExists(PDO $pdo, string $table, string $col): bool {
    $cols = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    return in_array($col, $cols, true);
}
function sqliteVersion(PDO $pdo): string {
    return $pdo->query('SELECT sqlite_version()')->fetchColumn();
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  1. Rinomina sostanze_adr → sostanze_chimiche
 * ═══════════════════════════════════════════════════════════════════════════ */
if (!tableExists($pdo, 'sostanze_chimiche') && tableExists($pdo, 'sostanze_adr')) {
    $pdo->exec('ALTER TABLE sostanze_adr RENAME TO sostanze_chimiche');
    $log[] = '✓ Tabella sostanze_adr rinominata in sostanze_chimiche.';
} elseif (tableExists($pdo, 'sostanze_chimiche')) {
    $log[] = '— sostanze_chimiche già presente, rinomina saltata.';
} else {
    $log[] = '⚠ Né sostanze_adr né sostanze_chimiche trovate — skip.';
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  2. Rinomina sostanze_adr_schede → sostanze_chimiche_schede
 * ═══════════════════════════════════════════════════════════════════════════ */
if (!tableExists($pdo, 'sostanze_chimiche_schede') && tableExists($pdo, 'sostanze_adr_schede')) {
    $pdo->exec('ALTER TABLE sostanze_adr_schede RENAME TO sostanze_chimiche_schede');
    $log[] = '✓ Tabella sostanze_adr_schede rinominata in sostanze_chimiche_schede.';
} elseif (tableExists($pdo, 'sostanze_chimiche_schede')) {
    $log[] = '— sostanze_chimiche_schede già presente, rinomina saltata.';
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  3. Nuove colonne scalari su sostanze_chimiche
 * ═══════════════════════════════════════════════════════════════════════════ */
$newCols = [
    'codice_ufi'     => 'VARCHAR(20)',
    'codice_einecs'  => 'VARCHAR(10)',
    'codice_reach'   => 'VARCHAR(30)',
    'numero_kemler'  => 'VARCHAR(4)',
    'stato_fisico'   => "TEXT CHECK(stato_fisico IN ('solido','liquido','gas','aerosol','pasta'))",
    'clp_avvertenza' => "TEXT CHECK(clp_avvertenza IN ('Pericolo','Attenzione'))",
];
foreach ($newCols as $col => $def) {
    if (!columnExists($pdo, 'sostanze_chimiche', $col)) {
        $pdo->exec("ALTER TABLE sostanze_chimiche ADD COLUMN {$col} {$def}");
        $log[] = "✓ Colonna sostanze_chimiche.{$col} aggiunta.";
    } else {
        $log[] = "— sostanze_chimiche.{$col} già presente, skip.";
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  4. prodotti: aggiungi sostanza_chimica_id
 *     SQLite >= 3.25.0 supporta RENAME COLUMN; altrimenti aggiungiamo alias.
 * ═══════════════════════════════════════════════════════════════════════════ */
$ver = sqliteVersion($pdo);
$supportsRenameCol = version_compare($ver, '3.25.0', '>=');

if (columnExists($pdo, 'prodotti', 'sostanza_adr_id') && !columnExists($pdo, 'prodotti', 'sostanza_chimica_id')) {
    if ($supportsRenameCol) {
        $pdo->exec('ALTER TABLE prodotti RENAME COLUMN sostanza_adr_id TO sostanza_chimica_id');
        $log[] = "✓ Colonna prodotti.sostanza_adr_id rinominata in sostanza_chimica_id (SQLite {$ver}).";
    } else {
        // Fallback: aggiungi nuova colonna e copia valori
        $pdo->exec('ALTER TABLE prodotti ADD COLUMN sostanza_chimica_id INTEGER REFERENCES sostanze_chimiche(id)');
        $pdo->exec('UPDATE prodotti SET sostanza_chimica_id = sostanza_adr_id WHERE sostanza_adr_id IS NOT NULL');
        $log[] = "✓ Colonna sostanza_chimica_id aggiunta e popolata da sostanza_adr_id (SQLite {$ver}, RENAME COLUMN non supportato).";
    }
} elseif (columnExists($pdo, 'prodotti', 'sostanza_chimica_id')) {
    $log[] = '— prodotti.sostanza_chimica_id già presente, skip.';
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  5. Tabelle lookup CLP
 * ═══════════════════════════════════════════════════════════════════════════ */
if (!tableExists($pdo, 'clp_h_statements')) {
    $pdo->exec("
        CREATE TABLE clp_h_statements (
            codice VARCHAR(6) PRIMARY KEY,  -- H200, H225, H302...
            testo  TEXT NOT NULL,
            tipo   TEXT NOT NULL CHECK(tipo IN ('fisico','salute','ambiente','supplementare'))
        )
    ");
    $log[] = '✓ Tabella clp_h_statements creata.';
}

if (!tableExists($pdo, 'clp_p_statements')) {
    $pdo->exec("
        CREATE TABLE clp_p_statements (
            codice VARCHAR(6) PRIMARY KEY,  -- P101, P210, P501...
            testo  TEXT NOT NULL,
            tipo   TEXT NOT NULL CHECK(tipo IN ('generale','prevenzione','risposta','conservazione','smaltimento'))
        )
    ");
    $log[] = '✓ Tabella clp_p_statements creata.';
}

if (!tableExists($pdo, 'clp_pittogrammi')) {
    $pdo->exec("
        CREATE TABLE clp_pittogrammi (
            codice      VARCHAR(6) PRIMARY KEY,  -- GHS01..GHS09
            nome        VARCHAR(100) NOT NULL,
            descrizione TEXT,
            file_path   TEXT  -- percorso icona SVG/PNG
        )
    ");
    $log[] = '✓ Tabella clp_pittogrammi creata.';
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  6. Tabelle pivot
 * ═══════════════════════════════════════════════════════════════════════════ */
if (!tableExists($pdo, 'sostanze_h_statements')) {
    $pdo->exec("
        CREATE TABLE sostanze_h_statements (
            sostanza_id INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
            h_codice    VARCHAR(6) NOT NULL REFERENCES clp_h_statements(codice) ON DELETE CASCADE,
            PRIMARY KEY (sostanza_id, h_codice)
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sost_h_sostanza ON sostanze_h_statements(sostanza_id)');
    $log[] = '✓ Tabella sostanze_h_statements creata.';
}

if (!tableExists($pdo, 'sostanze_p_statements')) {
    $pdo->exec("
        CREATE TABLE sostanze_p_statements (
            sostanza_id INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
            p_codice    VARCHAR(6) NOT NULL REFERENCES clp_p_statements(codice) ON DELETE CASCADE,
            PRIMARY KEY (sostanza_id, p_codice)
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sost_p_sostanza ON sostanze_p_statements(sostanza_id)');
    $log[] = '✓ Tabella sostanze_p_statements creata.';
}

if (!tableExists($pdo, 'sostanze_pittogrammi')) {
    $pdo->exec("
        CREATE TABLE sostanze_pittogrammi (
            sostanza_id     INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
            pittogramma_cod VARCHAR(6) NOT NULL REFERENCES clp_pittogrammi(codice) ON DELETE CASCADE,
            PRIMARY KEY (sostanza_id, pittogramma_cod)
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sost_pitt_sostanza ON sostanze_pittogrammi(sostanza_id)');
    $log[] = '✓ Tabella sostanze_pittogrammi creata.';
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  7. Seed pittogrammi GHS
 * ═══════════════════════════════════════════════════════════════════════════ */
$pittogrammi = [
    ['GHS01', 'Esplosivo',         'Esplosivi instabili, esplosivi div. 1.1–1.4, sostanze autoreattive tipo A/B, perossidi organici tipo A/B'],
    ['GHS02', 'Infiammabile',      'Gas, aerosol, liquidi e solidi infiammabili; sostanze piroforiche; autoriscaldanti; idroattive'],
    ['GHS03', 'Comburente',        'Gas, liquidi e solidi comburenti; perossidi organici'],
    ['GHS04', 'Gas compressi',     'Gas compressi, liquefatti, disciolti o refrigerati'],
    ['GHS05', 'Corrosivo',         'Corrosivi per metalli; corrosivi cutanei cat. 1; lesioni oculari gravi cat. 1'],
    ['GHS06', 'Tossico acuto',     'Tossicità acuta cat. 1-3 (orale, cutanea, inalazione)'],
    ['GHS07', 'Nocivo/Irritante',  'Tossicità acuta cat. 4; irritazione cutanea/oculare; sensibilizzazione cutanea; tossicità specifica organo bersaglio cat. 3'],
    ['GHS08', 'Pericolo salute',   'Sensibilizzazione respiratoria; mutagenesi; carcinogenesi; tossicità riproduttiva; STOT cat. 1-2; aspirazione'],
    ['GHS09', 'Ambiente',          'Pericolo acuto/cronico per l\'ambiente acquatico'],
];
$stPitt = $pdo->prepare("INSERT OR IGNORE INTO clp_pittogrammi (codice, nome, descrizione) VALUES (?,?,?)");
foreach ($pittogrammi as $p) {
    $stPitt->execute($p);
}
$log[] = '✓ Seed pittogrammi GHS inserito (9 voci).';

/* ═══════════════════════════════════════════════════════════════════════════
 *  7b. Seed H statements (GHS/CLP standard — lista completa)
 * ═══════════════════════════════════════════════════════════════════════════ */
$hStatements = [
    // Fisici
    ['H200','Esplosivo instabile.','fisico'],
    ['H201','Esplosivo; pericolo di esplosione di massa.','fisico'],
    ['H202','Esplosivo; grave pericolo di proiezione.','fisico'],
    ['H203','Esplosivo; pericolo di incendio, di spostamento d\'aria o di proiezione.','fisico'],
    ['H204','Pericolo di incendio o di proiezione.','fisico'],
    ['H205','Pericolo di esplosione di massa in caso di incendio.','fisico'],
    ['H206','Pericolo di incendio, di spostamento d\'aria o di proiezione; maggiore pericolo di esplosione se il desensibilizzante è ridotto o eliminato.','fisico'],
    ['H207','Pericolo di incendio o di proiezione; maggiore pericolo di esplosione se il desensibilizzante è ridotto o eliminato.','fisico'],
    ['H208','Pericolo di incendio; maggiore pericolo di esplosione se il desensibilizzante è ridotto o eliminato.','fisico'],
    ['H220','Gas facilmente infiammabile.','fisico'],
    ['H221','Gas infiammabile.','fisico'],
    ['H222','Aerosol facilmente infiammabile.','fisico'],
    ['H223','Aerosol infiammabile.','fisico'],
    ['H224','Liquido e vapori facilmente infiammabili.','fisico'],
    ['H225','Liquido e vapori molto facilmente infiammabili.','fisico'],
    ['H226','Liquido e vapori infiammabili.','fisico'],
    ['H227','Liquido combustibile.','fisico'],
    ['H228','Solido infiammabile.','fisico'],
    ['H229','Contenitore in pressione: può esplodere se riscaldato.','fisico'],
    ['H230','Può esplodere anche in assenza di aria.','fisico'],
    ['H231','Può esplodere anche in assenza di aria a pressione e/o temperatura elevate.','fisico'],
    ['H232','Può accendersi spontaneamente a contatto con l\'aria.','fisico'],
    ['H240','Rischio di esplosione per riscaldamento.','fisico'],
    ['H241','Rischio di incendio o esplosione per riscaldamento.','fisico'],
    ['H242','Rischio di incendio per riscaldamento.','fisico'],
    ['H250','Prende fuoco spontaneamente a contatto con l\'aria.','fisico'],
    ['H251','Autoriscaldante; può prendere fuoco.','fisico'],
    ['H252','Autoriscaldante in grandi quantità; può prendere fuoco.','fisico'],
    ['H260','A contatto con l\'acqua libera gas infiammabili che possono infiammarsi spontaneamente.','fisico'],
    ['H261','A contatto con l\'acqua libera gas infiammabili.','fisico'],
    ['H270','Può provocare o aggravare un incendio; comburente.','fisico'],
    ['H271','Rischio di esplosione o incendio; molto comburente.','fisico'],
    ['H272','Può aggravare un incendio; comburente.','fisico'],
    ['H280','Contiene gas sotto pressione; può esplodere se riscaldato.','fisico'],
    ['H281','Contiene gas refrigerato; può causare ustioni o lesioni criogeniche.','fisico'],
    ['H282','Gas estremamente infiammabile; contenitore in pressione: può esplodere se riscaldato.','fisico'],
    ['H283','Gas infiammabile; contenitore in pressione: può esplodere se riscaldato.','fisico'],
    ['H284','Gas; contenitore in pressione: può esplodere se riscaldato.','fisico'],
    ['H290','Può essere corrosivo per i metalli.','fisico'],
    // Salute
    ['H300','Letale se ingerito.','salute'],
    ['H301','Tossico se ingerito.','salute'],
    ['H302','Nocivo se ingerito.','salute'],
    ['H303','Può essere nocivo se ingerito.','salute'],
    ['H304','Può essere letale in caso di ingestione e di penetrazione nelle vie respiratorie.','salute'],
    ['H305','Può essere nocivo in caso di ingestione e di penetrazione nelle vie respiratorie.','salute'],
    ['H310','Letale per contatto con la pelle.','salute'],
    ['H311','Tossico per contatto con la pelle.','salute'],
    ['H312','Nocivo per contatto con la pelle.','salute'],
    ['H313','Può essere nocivo per contatto con la pelle.','salute'],
    ['H314','Provoca gravi ustioni cutanee e gravi lesioni oculari.','salute'],
    ['H315','Provoca irritazione cutanea.','salute'],
    ['H316','Provoca lieve irritazione cutanea.','salute'],
    ['H317','Può provocare una reazione allergica cutanea.','salute'],
    ['H318','Provoca gravi lesioni oculari.','salute'],
    ['H319','Provoca grave irritazione oculare.','salute'],
    ['H320','Provoca irritazione oculare.','salute'],
    ['H330','Letale per inalazione.','salute'],
    ['H331','Tossico per inalazione.','salute'],
    ['H332','Nocivo per inalazione.','salute'],
    ['H333','Può essere nocivo per inalazione.','salute'],
    ['H334','Può provocare sintomi allergici o asmatici o difficoltà respiratorie per inalazione.','salute'],
    ['H335','Può irritare le vie respiratorie.','salute'],
    ['H336','Può provocare sonnolenza o vertigini.','salute'],
    ['H340','Può provocare alterazioni genetiche.','salute'],
    ['H341','Sospettato di provocare alterazioni genetiche.','salute'],
    ['H350','Può provocare il cancro.','salute'],
    ['H351','Sospettato di provocare il cancro.','salute'],
    ['H360','Può nuocere alla fertilità o al feto.','salute'],
    ['H361','Sospettato di nuocere alla fertilità o al feto.','salute'],
    ['H362','Può essere nocivo per i lattanti allattati al seno.','salute'],
    ['H370','Provoca danni agli organi.','salute'],
    ['H371','Può provocare danni agli organi.','salute'],
    ['H372','Provoca danni agli organi in caso di esposizione prolungata o ripetuta.','salute'],
    ['H373','Può provocare danni agli organi in caso di esposizione prolungata o ripetuta.','salute'],
    // Ambiente
    ['H400','Molto tossico per gli organismi acquatici.','ambiente'],
    ['H401','Tossico per gli organismi acquatici.','ambiente'],
    ['H402','Nocivo per gli organismi acquatici.','ambiente'],
    ['H410','Molto tossico per gli organismi acquatici con effetti di lunga durata.','ambiente'],
    ['H411','Tossico per gli organismi acquatici con effetti di lunga durata.','ambiente'],
    ['H412','Nocivo per gli organismi acquatici con effetti di lunga durata.','ambiente'],
    ['H413','Può essere nocivo per gli organismi acquatici con effetti di lunga durata.','ambiente'],
    ['H420','Danneggia la salute pubblica e l\'ambiente distruggendo l\'ozono nello strato superiore dell\'atmosfera.','ambiente'],
    // Supplementari
    ['EUH001','Esplosivo allo stato secco.','supplementare'],
    ['EUH006','Esplosivo a contatto o senza contatto con l\'aria.','supplementare'],
    ['EUH014','Reagisce violentemente con l\'acqua.','supplementare'],
    ['EUH018','Durante l\'uso può formarsi una miscela vapore-aria esplosiva/infiammabile.','supplementare'],
    ['EUH019','Può formare perossidi esplosivi.','supplementare'],
    ['EUH044','Rischio di esplosione per riscaldamento in ambiente confinato.','supplementare'],
    ['EUH029','A contatto con l\'acqua libera gas tossici.','supplementare'],
    ['EUH031','A contatto con acidi libera gas tossici.','supplementare'],
    ['EUH032','A contatto con acidi libera gas molto tossici.','supplementare'],
    ['EUH059','Pericoloso per lo strato di ozono.','supplementare'],
    ['EUH066','L\'esposizione ripetuta può provocare secchezza o screpolature della pelle.','supplementare'],
    ['EUH070','Tossico per contatto oculare.','supplementare'],
    ['EUH071','Corrosivo per le vie respiratorie.','supplementare'],
    ['EUH201','Contiene piombo. Non utilizzare su superfici che possono essere masticate o succhiate dai bambini.','supplementare'],
    ['EUH208','Contiene [nome della sostanza sensibilizzante]. Può provocare una reazione allergica.','supplementare'],
    ['EUH210','Su richiesta è disponibile una scheda di dati di sicurezza.','supplementare'],
    ['EUH401','Per evitare rischi per la salute umana e l\'ambiente, seguire le istruzioni per l\'uso.','supplementare'],
];
$stH = $pdo->prepare("INSERT OR IGNORE INTO clp_h_statements (codice, testo, tipo) VALUES (?,?,?)");
foreach ($hStatements as $h) {
    $stH->execute($h);
}
$log[] = '✓ Seed H statements inserito (' . count($hStatements) . ' voci).';

/* ═══════════════════════════════════════════════════════════════════════════
 *  7c. Seed P statements (principali)
 * ═══════════════════════════════════════════════════════════════════════════ */
$pStatements = [
    ['P101','In caso di consultazione di un medico, tenere a disposizione il contenitore o l\'etichetta del prodotto.','generale'],
    ['P102','Tenere fuori dalla portata dei bambini.','generale'],
    ['P103','Leggere l\'etichetta prima dell\'uso.','generale'],
    ['P201','Procurarsi istruzioni specifiche prima dell\'uso.','prevenzione'],
    ['P202','Non manipolare prima di avere letto e compreso tutte le avvertenze.','prevenzione'],
    ['P210','Tenere lontano da fonti di calore, superfici surriscaldate, scintille, fiamme libere o altre fonti di accensione. Non fumare.','prevenzione'],
    ['P211','Non vaporizzare su una fiamma libera o altra fonte di accensione.','prevenzione'],
    ['P220','Tenere lontano da indumenti e altri materiali combustibili.','prevenzione'],
    ['P221','Prendere precauzioni adeguate contro la formazione di miscele con aria infiammabili.','prevenzione'],
    ['P222','Non lasciare che il prodotto venga a contatto con l\'aria.','prevenzione'],
    ['P223','Evitare qualsiasi contatto con l\'acqua.','prevenzione'],
    ['P230','Mantenere umido con...','prevenzione'],
    ['P231','Manipolare e conservare in atmosfera di gas inerte.','prevenzione'],
    ['P232','Proteggere dall\'umidità.','prevenzione'],
    ['P233','Tenere il recipiente ben chiuso.','prevenzione'],
    ['P234','Conservare soltanto nel contenitore originale.','prevenzione'],
    ['P235','Conservare in luogo fresco.','prevenzione'],
    ['P240','Mettere a terra e collegare equipotenzialmente il contenitore e il materiale ricevente.','prevenzione'],
    ['P241','Utilizzare impianti elettrici, di ventilazione, di illuminazione a prova di esplosione.','prevenzione'],
    ['P242','Utilizzare solo attrezzatura antiscintilla.','prevenzione'],
    ['P243','Adottare misure precauzionali contro scariche elettrostatiche.','prevenzione'],
    ['P244','Mantenere le valvole di riduzione libere da grasso e olio.','prevenzione'],
    ['P250','Evitare abrasioni/urti/attrito.','prevenzione'],
    ['P251','Non perforare, incenerire il contenitore anche dopo l\'uso.','prevenzione'],
    ['P260','Non respirare polveri/fumi/gas/nebbie/vapori/aerosol.','prevenzione'],
    ['P261','Evitare di respirare polveri/fumi/gas/nebbie/vapori/aerosol.','prevenzione'],
    ['P262','Evitare il contatto con gli occhi, la pelle o gli indumenti.','prevenzione'],
    ['P263','Evitare il contatto durante la gravidanza e l\'allattamento.','prevenzione'],
    ['P264','Lavare accuratamente dopo l\'uso.','prevenzione'],
    ['P265','Non toccarsi gli occhi dopo la manipolazione.','prevenzione'],
    ['P270','Non mangiare, né bere, né fumare durante l\'uso.','prevenzione'],
    ['P271','Utilizzare soltanto all\'aperto o in luogo ben ventilato.','prevenzione'],
    ['P272','Gli indumenti da lavoro contaminati non devono essere portati fuori dal luogo di lavoro.','prevenzione'],
    ['P273','Non disperdere nell\'ambiente.','prevenzione'],
    ['P280','Indossare dispositivi di protezione individuale: guanti/indumenti protettivi/Protezione per gli occhi/il viso.','prevenzione'],
    ['P281','Utilizzare il dispositivo di protezione individuale richiesto.','prevenzione'],
    ['P282','Indossare guanti termici/schermo facciale/Protezione per gli occhi.','prevenzione'],
    ['P283','Indossare indumenti ignifughi, resistenti alle fiamme o ritardanti di fiamma.','prevenzione'],
    ['P284','In caso di ventilazione insufficiente, indossare un apparecchio respiratorio adatto.','prevenzione'],
    ['P301','IN CASO DI INGESTIONE:','risposta'],
    ['P302','IN CASO DI CONTATTO CON LA PELLE:','risposta'],
    ['P303','IN CASO DI CONTATTO CON LA PELLE (o con i capelli):','risposta'],
    ['P304','IN CASO DI INALAZIONE:','risposta'],
    ['P305','IN CASO DI CONTATTO CON GLI OCCHI:','risposta'],
    ['P306','IN CASO DI CONTATTO CON GLI INDUMENTI:','risposta'],
    ['P308','IN CASO DI esposizione o di possibile esposizione:','risposta'],
    ['P310','Contattare immediatamente un CENTRO ANTIVELENI/un medico.','risposta'],
    ['P311','Contattare un CENTRO ANTIVELENI/un medico.','risposta'],
    ['P312','Contattare un CENTRO ANTIVELENI/un medico in caso di malessere.','risposta'],
    ['P313','Consultare un medico.','risposta'],
    ['P314','In caso di malessere, consultare un medico.','risposta'],
    ['P315','Consultare immediatamente un medico.','risposta'],
    ['P320','Trattamento specifico urgente (vedere supplemento in questa etichetta).','risposta'],
    ['P321','Trattamento specifico (vedere supplemento in questa etichetta).','risposta'],
    ['P330','Sciacquare la bocca.','risposta'],
    ['P331','NON provocare il vomito.','risposta'],
    ['P332','In caso di irritazione della pelle:','risposta'],
    ['P333','In caso di irritazione o eruzione della pelle:','risposta'],
    ['P334','Immergere in acqua fredda/avvolgere con un bendaggio umido.','risposta'],
    ['P335','Rimuovere le particelle sciolte dalla pelle.','risposta'],
    ['P336','Sgelare le parti assiderate con acqua tiepida. Non sfregare le parti interessate.','risposta'],
    ['P337','Se l\'irritazione degli occhi persiste:','risposta'],
    ['P338','Togliere le eventuali lenti a contatto se è agevole farlo. Continuare a sciacquare.','risposta'],
    ['P340','Trasportare l\'infortunato all\'aria aperta e mantenerlo a riposo in posizione che favorisca la respirazione.','risposta'],
    ['P342','In caso di sintomi respiratori:','risposta'],
    ['P351','Sciacquare accuratamente per parecchi minuti.','risposta'],
    ['P352','Lavare abbondantemente con acqua e sapone.','risposta'],
    ['P353','Sciacquare la pelle/fare una doccia.','risposta'],
    ['P360','Sciacquare immediatamente e abbondantemente gli indumenti contaminati e la pelle prima di togliersi gli indumenti.','risposta'],
    ['P361','Togliersi di dosso immediatamente tutti gli indumenti contaminati.','risposta'],
    ['P362','Togliersi di dosso gli indumenti contaminati e lavarli prima di indossarli nuovamente.','risposta'],
    ['P363','Lavare gli indumenti contaminati prima di indossarli nuovamente.','risposta'],
    ['P370','In caso di incendio:','risposta'],
    ['P371','In caso di incendio grave e di grandi quantità:','risposta'],
    ['P372','Rischio di esplosione in caso di incendio.','risposta'],
    ['P373','NON combattere l\'incendio quando le fiamme raggiungono gli esplosivi.','risposta'],
    ['P374','Combattere l\'incendio a distanza ragionevole prendendo le precauzioni normali.','risposta'],
    ['P375','Combattere l\'incendio a distanza perché c\'è rischio di esplosione.','risposta'],
    ['P376','Bloccare la perdita se non c\'è pericolo.','risposta'],
    ['P377','In caso di perdita di gas con innesco: non tentare di bloccarla a meno che non sia possibile farlo senza pericolo.','risposta'],
    ['P378','Usare ... per spegnere.','risposta'],
    ['P380','Evacuare la zona.','risposta'],
    ['P381','In caso di perdita: eliminare tutte le sorgenti di accensione.','risposta'],
    ['P390','Assorbire la fuoriuscita di prodotto per evitare danni materiali.','risposta'],
    ['P391','Raccogliere il materiale fuoriuscito.','risposta'],
    ['P401','Conservare in conformità alle norme locali.','conservazione'],
    ['P402','Conservare in luogo asciutto.','conservazione'],
    ['P403','Conservare in luogo ben ventilato.','conservazione'],
    ['P404','Conservare in un contenitore chiuso.','conservazione'],
    ['P405','Conservare sotto chiave.','conservazione'],
    ['P406','Conservare in contenitore resistente alla corrosione.','conservazione'],
    ['P407','Mantenere un\'intercapedine d\'aria tra le pile/i pallet.','conservazione'],
    ['P410','Proteggere dai raggi solari.','conservazione'],
    ['P411','Conservare a temperature non superiori a ... °C.','conservazione'],
    ['P412','Non esporre a temperature superiori a 50 °C.','conservazione'],
    ['P413','Conservare le rinfuse di peso superiore a ... kg a temperature non superiori a ... °C.','conservazione'],
    ['P420','Tenere lontano da altri materiali.','conservazione'],
    ['P422','Conservare sotto...','conservazione'],
    ['P501','Smaltire il prodotto/recipiente in conformità alle norme locali.','smaltimento'],
    ['P502','Informarsi presso il produttore per il recupero e il riciclo.','smaltimento'],
    ['P503','Smaltire il prodotto/contenitore in conformità alle norme locali/regionali/nazionali/internazionali.','smaltimento'],
];
$stP = $pdo->prepare("INSERT OR IGNORE INTO clp_p_statements (codice, testo, tipo) VALUES (?,?,?)");
foreach ($pStatements as $p) {
    $stP->execute($p);
}
$log[] = '✓ Seed P statements inserito (' . count($pStatements) . ' voci).';

/* ═══════════════════════════════════════════════════════════════════════════
 *  Output
 * ═══════════════════════════════════════════════════════════════════════════ */
$pdo->exec('PRAGMA foreign_keys = ON');

$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    echo implode("\n", $log) . "\n\nMigrazione completata.\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $log) . "\n\nMigrazione completata.\n";
}
