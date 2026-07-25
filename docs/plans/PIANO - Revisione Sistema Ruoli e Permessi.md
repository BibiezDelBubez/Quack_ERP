# PIANO — Revisione Sistema Ruoli e Permessi

Data: 25/07/2026
Origine: domanda esplicita dell'utente durante l'audit del piano "Revisione Ordini Clienti" — "Colgo l'occasione per chiederti se il sistema attuale di gestione dei ruoli permessi ti sembra abbastanza maturo o ha bisogno di un approccio più esaustivo" — seguita dal mandato:

> "1. Aggiorna il piano revisione ordini (o come si chiamava)
> 2. Sempre in docs/ crea un piano dettagliato di proposta della nuova gestione ruoli e permessi, comprendendo tutti gli 'stakeholder', cioè parti di codice che sono interessate, si potrebbero rompere o comunque sono (anche solo vagamente) influenzate da un eventuale cambiamento. sia direttamente che indirettamente"

Nessuna modifica al codice è stata fatta. Questo documento è solo analisi + proposta, in attesa di conferma (regola di progetto: non decidere al posto dell'utente, non implementare nulla senza richiesta esplicita).

Tutti i riferimenti file:riga in questo documento sono stati verificati con lo strumento di lettura file in questa sessione, il 25/07/2026 — non a memoria, non da documentazione precedente (regola CLAUDE.md §11).

---

## 1. Stato attuale — cosa fa davvero il codice oggi

### 1.1 Il pezzo buono, da NON buttare: il gate centralizzato in `index.php`/`app/routes.php`

Ogni richiesta passa da un unico punto. `app/routes.php` (284 righe) definisce ogni rotta come un array con chiavi `'auth'` (booleano) e, quando serve, `'permission'` (stringa). `index.php:44-61` è l'unico punto dove queste chiavi vengono lette e applicate, prima di istanziare qualunque controller:

```php
$route = $router->resolve($path);

$requiresAuth = $route['auth'] ?? true;
if ($requiresAuth && !is_authenticated()) {
    redirect('login');
}

if (!empty($route['permission']) && !can($route['permission'])) {
    http_response_code(403);
    // ... 403 JSON o HTML
    exit;
}
```

Contato riga per riga in questa sessione: 206 rotte hanno `'auth' => true`, di cui 126 portano anche una `'permission'`; le restanti 80 richiedono solo login, nessun permesso specifico. Le 126 con permesso si dividono così: `manage_orders` 37, `manage_catalog` 35, `manage_users` 25, `edit_prices` 18, `manage_warehouse` 11 (somma 126, verificata due volte con pattern diversi — regola CLAUDE.md §12).

**Questo è un design maturo e corretto**: dichiarativo, centralizzato, un solo punto di enforcement, nessun controller può "dimenticare" il controllo perché non passa mai dal controller — passa da `index.php` prima ancora che il controller esista. Va conservato esattamente com'è. Il problema non è qui.

### 1.2 Il pezzo rotto: da dove arriva il valore che `can()` controlla

`can(string $permission): bool` (`includes/functions.php:384-403`) non legge la tabella `ruoli`. Legge un array PHP hardcoded nel codice:

```php
function can(string $permission): bool
{
    $role = current_user()['role'] ?? 'readonly';

    $map = [
        'admin' => ['manage_users','view_costs','edit_prices','manage_catalog','manage_warehouse','manage_orders'],
        'commerciale' => ['view_costs','edit_prices','manage_orders'],
        'magazziniere' => ['manage_warehouse'],
        'readonly' => [],
    ];

    return in_array($permission, $map[$role] ?? [], true);
}
```

Intanto, la tabella `ruoli` (`db/init_sqlite.sql:251-263`) ha già, dal database, esattamente le colonne che servirebbero per fare la stessa cosa a runtime:

```sql
CREATE TABLE IF NOT EXISTS ruoli (
    id                   INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice               VARCHAR(20)  NOT NULL UNIQUE,
    nome                 VARCHAR(100) NOT NULL,
    can_manage_users     BOOLEAN      NOT NULL DEFAULT 0,
    can_view_costs       BOOLEAN      NOT NULL DEFAULT 0,
    can_edit_prices      BOOLEAN      NOT NULL DEFAULT 0,
    can_manage_catalog   BOOLEAN      NOT NULL DEFAULT 0,
    can_manage_warehouse BOOLEAN      NOT NULL DEFAULT 0,
    can_manage_orders    BOOLEAN      NOT NULL DEFAULT 0,
    can_view_only        BOOLEAN      NOT NULL DEFAULT 0,
    attivo               BOOLEAN      NOT NULL DEFAULT 1
);
```

Queste 7 colonne booleane sono popolate al seed (`db/init_sqlite.sql:1837-1838`) ma **nessuna query in tutto il progetto le legge mai** (verificato: `includes/utenti.php`, letto per intero, seleziona solo `r.codice AS ruolo`/`AS role` nelle sue 2 query di lettura — righe 18-20 e 50-52 — mai le colonne `can_*`). In pratica: il database ha una tabella di permessi per ruolo, completamente scollegata dal codice che decide i permessi. Sono due sistemi paralleli, uno vivo (l'array PHP) e uno morto (le colonne SQL), che oggi per puro caso raccontano la stessa storia — ma nulla garantisce che restino sincronizzati, perché non sono la stessa fonte.

Conseguenza pratica verificabile subito: se oggi un amministratore aprisse la UI utenti e "spuntasse" `can_view_costs` per il ruolo Magazziniere (se una UI così esistesse — non esiste, altro sintomo dello stesso problema), letteralmente non cambierebbe nulla nel comportamento dell'app, perché quella colonna non viene mai letta.

### 1.3 Il nome del ruolo è scritto a mano in 5 posti indipendenti (violazione DRY concreta, non solo estetica)

Ricerca corroborata con due metodi (grep esteso su `can(` con e senza apici, per evitare il falso negativo già sperimentato in questa stessa conversazione — regola CLAUDE.md §12): il set `{'admin','commerciale','magazziniere','readonly'}` è scritto letteralmente, in modo indipendente, in questi punti:

1. `includes/functions.php:388-399` — l'array `$map` dentro `can()`.
2. `app/Controllers/UsersController.php:227` — `if (!in_array($values['ruolo'] ?? '', ['admin', 'commerciale', 'magazziniere', 'readonly'], true))`, la validazione server-side del form utente.
3. `app/views/users/_form.php:10-21` — due array PHP `$ruoloLabels`/`$ruoloDesc` con le stesse 4 chiavi, per etichetta e descrizione visualizzate nel radio-button del form.
4. `app/views/users/_list.php:15` — `foreach (['admin','commerciale','magazziniere','readonly'] as $r)`, il filtro a tendina della lista utenti.
5. `app/Controllers/UsersController.php:42` — `'ruolo' => 'commerciale'` come default hardcoded per un nuovo utente in `create()`.

Aggiungere un quinto ruolo oggi (es. "supervisore acquisti") richiederebbe modificare a mano tutti e 4 questi file, nel giusto ordine, senza dimenticarne uno — è esattamente il tipo di duplicazione che CLAUDE.md (punto 1) chiede di eliminare quando trovata, non di ignorare perché "funziona comunque".

### 1.4 Un endpoint bypassa `can()` del tutto, con un controllo di ruolo scritto a mano diverso da tutti gli altri

`CustomersController::apiComplianceApprove()` (righe 789-918), l'endpoint che approva le autorizzazioni di compliance dei clienti, non usa `can()` — controlla direttamente la sessione:

```php
// riga 800-806
$user_role = $_SESSION['qk_user']['role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'commerciale') {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => 'Non hai i permessi per approvare autorizzazioni di compliance.']);
    exit;
}
```

Da notare: la rotta `/customers/api/compliance/approve` in `app/routes.php:22` porta già `'permission' => 'manage_orders'`, quindi il gate centralizzato (§1.1) **ha già fatto un controllo** prima di arrivare qui. Questo controllo interno è ridondante rispetto al gate E incoerente con esso (usa nomi di ruolo invece che permessi, quindi se domani `manage_orders` venisse tolto al ruolo `commerciale` e assegnato solo a un nuovo ruolo "supervisore", questo endpoint continuerebbe a rifiutare il supervisore perché controlla il nome del ruolo, non il permesso). È un secondo sistema di autorizzazione, parallelo e discorde, dentro lo stesso identico endpoint che il primo sistema già protegge.

### 1.5 Due permessi esistono nello schema ma non vengono applicati da nessuna parte

`view_costs` e `view_only`/`can_view_only`: cercati come stringa di permesso (`'permission' => 'view_costs'` ecc.) in tutte le 126 occorrenze di `'permission' =>` di `app/routes.php` — zero corrispondenze per entrambi. `view_costs` esiste nell'array hardcoded di `can()` (assegnato ad admin e commerciale) ma nessuna rotta lo richiede mai, quindi è un permesso "vivo nel codice ma senza effetto" — chiunque acceda a una qualunque rotta autenticata vede comunque i costi, perché nessun controllo li nasconde. `can_view_only` non esiste nemmeno nell'array di `can()` — esiste solo come colonna DB morta.

### 1.6 Giudizio di maturità, bilanciato

Non è vero (ed è stato un mio errore di percorso in questa stessa conversazione, corretto prima di scriverlo qui) che "manchi un sistema centralizzato di permessi" — il gate in `index.php`/`routes.php` è reale, buono, e va conservato. Il problema non è l'assenza di un meccanismo, è che **la fonte dei dati che quel meccanismo consulta è sbagliata**: un array hardcoded invece della tabella già pensata per questo, duplicato altre 4 volte per il solo nome del ruolo, con un punto che lo aggira del tutto, e due permessi dichiarati ma senza alcun effetto. È un sistema maturo nell'enforcement, immaturo nella sorgente di verità.

---

## 2. Principio di redesign

Un solo cambiamento concettuale, non una riscrittura: **la tabella `ruoli` diventa l'unica fonte di verità dei permessi, letta a runtime**; `can()` diventa un thin wrapper sul dato del ruolo corrente invece che un array hardcoded; il gate di `index.php`/`routes.php` resta identico, perché è già corretto (§1.1) — non tocca minimamente questo pezzo.

```php
// includes/functions.php — can() ridisegnata
function can(string $permission): bool
{
    $ruolo = current_user()['ruolo_permessi'] ?? null;   // riga già caricata da current_user()
    if ($ruolo === null) {
        return false;
    }
    return !empty($ruolo['can_' . $permission]);
}
```

Per rendere questo possibile, `current_user()` (`includes/functions.php:167-182`, oggi seleziona solo `r.codice AS role`) deve selezionare anche le 7 colonne booleane di `ruoli` nella stessa query — una join già esistente, si allarga solo la `SELECT`, non si aggiunge nessuna query nuova.

Effetto pratico: aggiungere un permesso nuovo (es. i due di §4) significa aggiungere una colonna a `ruoli` e assegnarla nel seed/UI — non toccare `includes/functions.php`. Aggiungere un ruolo nuovo significa una riga in più nella tabella `ruoli` — non toccare 4 file PHP diversi.

### 2.1 Cosa fanno i grandi ERP (per contesto, non per copiare pedissequamente una soluzione sproporzionata)

SAP (autorizzazioni via ruoli/profili in tabelle `AGR_1251` ecc.), Odoo (`res.groups` + `ir.model.access` in tabelle, non in codice PHP/Python hardcoded) e praticamente ogni ERP maturo tengono i permessi in tabella, mai in un array hardcoded nel codice applicativo — non perché serva la complessità di SAP in un progetto di queste dimensioni, ma perché è il principio minimo comune: i permessi sono dati di configurazione, non logica di programma. Quack ha già la tabella (§1.2) — il redesign qui proposto è l'implementazione minima di un principio già universalmente adottato, non un'invenzione.

### 2.2 Cosa NON cambia (per essere espliciti su cosa questo piano non tocca)

- Il meccanismo di route-level enforcement (`app/routes.php` + `index.php:44-61`) resta identico.
- I 5 permessi esistenti (`manage_users`, `view_costs`, `edit_prices`, `manage_catalog`, `manage_warehouse`, `manage_orders`) restano con lo stesso nome e lo stesso significato — nessuna rotta va toccata per questo piano da solo.
- Non si tocca la UI di login/sessione (`$_SESSION['qk_user']`) oltre ad aggiungere il pacchetto di permessi già caricato da `current_user()`.

---

## 3. Colonne "phantom" (`view_costs`, `view_only`) — decisione da prendere, non presa qui

`view_costs` è dichiarato ma mai applicato da nessuna rotta (§1.5): o si trova dove effettivamente dovrebbe nascondere un costo (es. colonne prezzo di acquisto/margine in viste ordini/prodotti) e si aggiunge il controllo lì, oppure si documenta esplicitamente come "riservato per un uso futuro non ancora implementato" e si toglie dall'array `can()` per non dare un falso senso di sicurezza a chi legge il codice pensando che sia già applicato. `can_view_only`/`view_only` è ancora più ambiguo: non è chiaro se dovesse significare "sola lettura su tutto" (un quinto ruolo implicito) o qualcos'altro — **questa è una decisione che spetta all'utente, non viene presa in questo documento** (regola CLAUDE.md §8).

---

## 4. Primo "cliente" del sistema ridisegnato: i due permessi del maker-checker ordini clienti

Il piano gemello `docs/Ciclo Vendite/PIANO - Revisione Ordini Clienti (Nuova Versione).md` (§7.7.3, Tappa 8) richiede due nuove colonne booleane su `ruoli`: `can_approvare_correzioni`, `can_bypass_maker_checker`. Con il redesign di questo documento, aggiungerle è meccanico: due `ALTER TABLE`/colonne nel seed, zero modifiche a `can()` (che le leggerà automaticamente perché generico), zero nuove righe hardcoded in nessun altro file. Senza questo redesign, andrebbero aggiunte anche dentro l'array hardcoded di `includes/functions.php:388-399` — funzionerebbe comunque, ma perpetuerebbe esattamente il problema descritto al §1.2 invece di risolverlo. Per questo il piano ordini clienti dipende dall'esito di questo documento (come già annotato nel suo §6/§7.7.8).

---

## 5. Mappa stakeholder — tutto il codice toccato direttamente o indirettamente

### 5.1 Stakeholder diretti — leggono/scrivono `ruoli`, o chiamano/definiscono `can()`

| File:riga | Cosa fa oggi | Impatto del redesign |
|---|---|---|
| `includes/functions.php:384-403` | Definisce `can()` con array hardcoded | Riscritta per leggere le colonne `can_*` dal ruolo corrente (§2) |
| `includes/functions.php:167-182` (`current_user()`) | `JOIN ruoli r ON r.id = u.ruolo_id`, seleziona solo `r.codice AS role` | La `SELECT` si allarga alle 7 colonne `can_*` (query già esistente, nessuna nuova query) |
| `index.php:51` | Chiama `can($route['permission'])` nel gate centrale | Nessuna modifica: consuma comunque un booleano da `can()`, non gli importa come viene calcolato |
| `includes/sidebar.php:264` | `can('manage_users')` per mostrare/nascondere la voce di menu "Utenti" | Nessuna modifica di comportamento, stesso identico booleano |
| `app/Controllers/UsersController.php:11,36,84,159` | 4 gate `can('manage_users')` (index/create/edit/delete) | Nessuna modifica di comportamento |
| `app/Controllers/UsersController.php:227` | Validazione hardcoded dei 4 codici ruolo | Da sostituire con una query `SELECT codice FROM ruoli WHERE attivo = 1` — unica fonte anche per l'elenco dei ruoli validi, non solo per i permessi |
| `app/Controllers/UsersController.php:42` | Default hardcoded `'ruolo' => 'commerciale'` per nuovo utente | Non strettamente parte del redesign permessi, ma stesso tipo di fragilità (rinominare/rimuovere il ruolo "commerciale" romperebbe questo default silenziosamente) — segnalato, decisione rimandata all'utente |
| `app/views/users/_form.php:10-21` | Due array hardcoded `$ruoloLabels`/`$ruoloDesc` | Da generare leggendo `ruoli.codice`/`ruoli.nome` dalla tabella (la colonna `nome` già esiste per questo, oggi inutilizzata anche lei — verificato in `db/init_sqlite.sql:254`); la "descrizione" andrebbe o rimossa o aggiunta come nuova colonna `ruoli.descrizione` |
| `app/views/users/_list.php:15` | Array hardcoded per il filtro ruolo | Da generare dalla stessa query di cui sopra |
| `includes/utenti.php:18,20,50,52` | 2 query che fanno `JOIN ruoli` selezionando solo `r.codice` | Non richiedono modifiche per il redesign dei permessi in sé (restano funzioni separate, a scopo di gestione anagrafica utente/ruolo, non di controllo permessi) — citate qui solo come stakeholder che tocca la stessa tabella |
| `app/Controllers/CustomersController.php:800-806` (`apiComplianceApprove`) | Controllo hardcoded `$_SESSION['qk_user']['role'] !== 'admin' && !== 'commerciale'`, bypassando `can()` | Da correggere nello stesso intervento: sostituire con `can('manage_orders')` (il permesso già richiesto dalla rotta in `app/routes.php:22`, rendendo il controllo interno coerente con quello del gate invece che un secondo sistema parallelo) |

### 5.2 Stakeholder indiretti — non toccano `ruoli`/`can()` ma il loro comportamento dipende dal risultato

- **Tutte le 126 rotte con `'permission' => ...` in `app/routes.php`** (elenco per permesso: 37 `manage_orders`, 35 `manage_catalog`, 25 `manage_users`, 18 `edit_prices`, 11 `manage_warehouse`): ognuna continuerà a funzionare identicamente SE il redesign preserva lo stesso set di permessi con lo stesso significato per lo stesso ruolo (cioè: il seed iniziale di `ruoli.can_*` deve riprodurre esattamente l'array hardcoded attuale, riga per riga — è un requisito di correttezza della migrazione, non solo di design). Un errore nel seed di migrazione (es. dimenticare `can_edit_prices=1` per `commerciale`) toglierebbe silenziosamente un permesso che oggi tutti i commerciali hanno, su 18 rotte in un colpo solo.
- **Le 80 rotte con solo `'auth' => true`** (nessun permesso specifico): non sono toccate in alcun modo da questo redesign, perché non passano mai da `can()`. Rimangono accessibili a chiunque sia autenticato, qualunque cosa cambi in `ruoli`.
- **`app/Controllers/BaseController.php`** (letto per intero, 92 righe): zero logica di autorizzazione — nessun impatto, citato solo per escluderlo esplicitamente dopo verifica.
- **`app/Core/Router.php`** (letto per intero, 23 righe): risolve solo il path verso la definizione di rotta, non legge mai `'permission'` — nessun impatto.
- **Le viste che condizionano pulsanti/sezioni su `can(...)`** (oltre a `includes/sidebar.php:264` già in §5.1): qualunque vista che oggi chiama `can('manage_users')`, `can('manage_orders')` ecc. per nascondere un bottone (es. i bottoni "Nuova versione"/azioni di stato discussi nel piano ordini clienti gemello) continuerà a ricevere lo stesso booleano — nessuna modifica di comportamento atteso, ma va incluso nel piano di test manuale (§7) perché sono i punti dove un errore di migrazione dei permessi si vedrebbe per primo (bottone sparito o comparso per il ruolo sbagliato).
- **`docs/Ciclo Vendite/PIANO - Revisione Ordini Clienti (Nuova Versione).md`, Tappa 8** (dipendenza esplicita, dichiarata in quel documento stesso §6/§7.7.8): le sue due nuove colonne (`can_approvare_correzioni`, `can_bypass_maker_checker`) sono il primo caso reale che beneficia di questo redesign (§4) — se questo piano non viene implementato prima, quella Tappa 8 andrebbe comunque implementata aggiungendo le due colonne SIA a `ruoli` SIA (temporaneamente) all'array hardcoded di `can()`, da disfare poi quando/se questo redesign verrà fatto. Va deciso l'ordine di implementazione tra i due piani (non deciso qui).
- **Sessione utente (`$_SESSION['qk_user']`)**: oggi contiene `role` (stringa codice). Con il redesign conterrà anche il pacchetto di permessi booleani (o li leggerà da `current_user()` ad ogni richiesta, da decidere in fase di implementazione se cache in sessione o lettura live — un utente il cui ruolo viene cambiato da un admin mentre ha una sessione attiva vedrebbe il cambiamento subito solo nel secondo caso). Punto architetturale da decidere esplicitamente, non deciso qui.
- **Qualunque futuro sviluppo che assegni un permesso a un ruolo** (non solo il maker-checker di §4): da questo redesign in poi, il pattern corretto da seguire è "aggiungi colonna a `ruoli` in entrambi gli schemi (`db/init_sqlite.sql` E `db/init_mysql.sql`, regola CLAUDE.md §7), popola il seed, referenzia in `routes.php`" — mai più "aggiungi una entry nell'array di `can()`".

### 5.3 Esplicitamente NON stakeholder (verificato, per escludere dubbi)

- `app/Controllers/ProfileController.php` (letto per intero, 109 righe): usa `current_user()` ma non chiama mai `can()` — nessun impatto.
- `app/Controllers/ProductsComplianceController.php` (letto per intero, 192 righe): gestisce `prodotti_restrizioni`, non l'approvazione compliance clienti (quella è in `CustomersController`, §5.1) — nessun impatto su questo piano.

---

## 6. Tappe proposte (non implementare finché non richiesto esplicitamente)

**Tappa 1 — Schema**: aggiungere `ruoli.nome` come sorgente delle etichette UI (già esiste, oggi inutilizzata — verificare se basta o serve `ruoli.descrizione` nuova); allineare `db/init_sqlite.sql` e `db/init_mysql.sql` (CLAUDE.md §7).

**Tappa 2 — `current_user()` e `can()`**: allargare la `SELECT` di `current_user()` alle colonne `can_*`; riscrivere `can()` come thin wrapper (§2); test manuale: per ognuno dei 4 ruoli esistenti, verificare che ognuno dei 5 permessi attuali dia lo stesso risultato prima/dopo (20 combinazioni, non un campione).

**Tappa 3 — Eliminare le 4 duplicazioni hardcoded** (§1.3): `UsersController::validate()`, `_form.php`, `_list.php` leggono da `ruoli` invece di un array letterale.

**Tappa 4 — Correggere `CustomersController::apiComplianceApprove()`** (§1.4): sostituire il controllo di sessione hardcoded con `can('manage_orders')`.

**Tappa 5 — Decisione utente su `view_costs`/`view_only`** (§3): non implementabile finché l'utente non decide il significato voluto.

**Tappa 6 — (dipendenza) Tappa 8 del piano ordini clienti**: aggiungere `can_approvare_correzioni`/`can_bypass_maker_checker` usando il pattern ormai corretto.

---

## 7. Piano di test (da eseguire quando/se implementato, non ora)

Suite manuale che, per ciascuno dei 4 ruoli attuali, verifica via chiamata reale (non mock) tutti i 5 permessi esistenti prima e dopo la migrazione — stesso stile delle suite già in uso nel progetto per `TransitionGuard` (`tests/manual/suite_transition_guard_*.php`, citate come precedente nel piano gemello §7.7.1), non uno script superficiale.

---

## 8. Stato

Analisi completa, nessuna riga di codice modificata. In attesa di decisione dell'utente su: ordine di implementazione rispetto al piano ordini clienti (§5.2), significato di `view_costs`/`view_only` (§3), se cache dei permessi in sessione o lettura live (§5.2).
