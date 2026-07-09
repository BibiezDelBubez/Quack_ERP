# "Magazzino Attivo": Serve Davvero?

Nota di studio scritta per essere capita anche da chi non programma. Risponde a una domanda semplice: se i grandi gestionali (SAP, Oracle NetSuite, Microsoft Dynamics, Odoo) non usano il concetto di "magazzino attivo" che Quack usa oggi, è un errore averlo fatto? Va tolto? Si può tenere in parte?

---

## 1. Cos'è, in pratica, il "magazzino attivo"

In alto in ogni pagina di Quack c'è un piccolo menu a tendina dove scegli su quale sede vuoi "essere seduto": Roma, Milano, eccetera. Da quel momento, finché non lo cambi tu stesso, tutta l'applicazione si comporta come se tu stessi lavorando fisicamente in quella sede — i numeri che vedi, le azioni che fai, sono quasi sempre riferiti a quella sede lì, anche se la pagina che stai guardando non lo dice da nessuna parte.

È comodo: non devi specificare la sede ogni due minuti. Ma è anche un interruttore che, una volta girato, **resta girato in silenzio** — e se te ne dimentichi, ogni pagina che apri dopo continua a darti per scontato che tu sia ancora lì.

## 2. Cosa fanno diversamente i grandi gestionali

Il confronto con SAP, NetSuite, Dynamics e Odoo (fatto in un altro documento di studio) porta a una conclusione precisa, che vale la pena riportare senza semplificarla troppo: **anche loro hanno un modo di "dare per scontata" una sede di lavoro** — non è vero che ti chiedono sempre tutto da zero. La differenza è *dove* questo scontato si vede.

- In Odoo, se crei un ordine cliente, la sede di spedizione è già scritta nel campo giusto del modulo — **ma la vedi, è scritta lì, e la puoi cambiare prima di salvare**.
- In SAP, ogni operazione ti chiede la sede come parte del comando stesso — non c'è "sto lavorando su Roma" invisibile in giro, il campo sede è sempre davanti ai tuoi occhi.

Il punto debole che nessuno dei quattro accetta è un altro: **un numero o un elenco che ti viene mostrato come se fosse "tutto", mentre in realtà è filtrato su una sola sede, senza dirtelo**. Quello sì che nessuno dei quattro grandi gestionali lo fa mai come unico modo di mostrare i dati.

## 3. Dove Quack rischia davvero, spiegato con esempi concreti

Ho controllato punto per punto ogni schermata di Quack che usa il magazzino attivo, e il quadro è a due velocità: **in alcuni punti va benissimo così com'è, in altri è un rischio reale.**

### 3.1 Dove va bene così

Quando crei un nuovo ordine d'acquisto, il campo "magazzino di destinazione" è già precompilato con la sede su cui sei seduto — ma **è un menu a tendina visibile sul modulo stesso**, e puoi cambiarlo prima di confermare. Stessa cosa quando crei un nuovo prodotto: serve comunque assegnargli una sede di partenza, e usare quella su cui stai lavorando è la scelta più sensata. Qui Quack fa esattamente quello che fanno Odoo e SAP: un default comodo, ma visibile e modificabile. Nessun problema, niente da cambiare.

### 3.2 Dove il rischio è reale — un esempio concreto

Immagina Maria, magazziniere. Ieri pomeriggio ha lavorato sulla sede di Roma, e ha lasciato "Roma" selezionato nel menu in alto. Stamattina arriva, apre Quack per registrare che ha scaricato 50 taniche di un prodotto dal magazzino di **Milano** (dove lavora oggi), e va sulla schermata "Nuovo movimento".

Il modulo le chiede: che tipo di movimento (carico/scarico/rettifica)? Quale prodotto? Quale lotto? Quanta quantità? **Non le chiede da nessuna parte su quale magazzino.** Maria compila tutto, conferma — e il sistema scarica silenziosamente 50 taniche dal magazzino di **Roma**, perché è quello rimasto selezionato dal giorno prima, senza che nulla sullo schermo glielo segnalasse. Il giorno dopo, chi controlla le giacenze trova Roma con 50 pezzi in meno che nessuno ha davvero tolto, e Milano con 50 pezzi in più che il sistema pensa siano ancora lì.

Questo non è un'ipotesi teorica: è esattamente come funziona oggi quel modulo. È il punto più delicato di tutti, perché è l'unico caso in cui l'errore **scrive un dato sbagliato nel sistema**, in modo silenzioso, senza che nessuno se ne accorga finché non fa un inventario fisico.

### 3.3 Dove il rischio è più sottile — numeri che sembrano completi ma non lo sono

Due schermate di Quack mostrano elenchi o numeri **senza mai scrivere da nessuna parte a quale sede si riferiscono**:

- **"Giacenze Magazzino"** — la lista di quanto c'è disponibile di ogni prodotto. Oggi mostra solo i numeri della sede attiva, ma il titolo della pagina è semplicemente "Situazione disponibilità e giacenze" — niente segnala che è filtrata.
- **"Proposte di Riordino"** — la lista di cosa andrebbe riordinato perché sotto scorta minima. Stesso problema: chi la guarda potrebbe pensare di vedere il fabbisogno di tutta l'azienda, mentre vede solo quello di una sede, e genera un ordine d'acquisto pensando di coprire tutto mentre copre solo una parte.
- **Il numero "Prodotti Sottoscorta" nella dashboard principale** — stesso meccanismo, rischio più basso perché è solo un'informazione, non porta a un'azione diretta come le proposte di riordino.

Qui l'errore non è "il sistema scrive un dato sbagliato" (come per Maria), ma "una persona prende una decisione — cosa comprare, quanto tenere — basandosi su un numero che crede completo e non lo è".

## 4. Perché è stato fatto così, se sappiamo che non è l'ideale

Non è stata una scelta deliberata di progettazione, copiata (bene o male) da un sistema esistente. È una scorciatoia nata per un motivo molto più banale: **Quack, all'inizio, aveva un solo magazzino**. Non esisteva alcun concetto di "sede" — c'era un solo numero di giacenza per ogni prodotto, punto.

Quando è stato introdotto il multi-magazzino, quasi ogni schermata dell'applicazione (la dashboard, il modulo movimenti, il riordino) presupponeva già "il" magazzino, senza mai doverlo specificare. Riscrivere tutte queste schermate nello stesso momento — aggiungendo un selettore esplicito ovunque serviva — avrebbe richiesto molto più tempo. La scelta pragmatica è stata: introdurre un interruttore di sessione che si comportasse *come se* fosse ancora il vecchio magazzino unico, così tutto il codice esistente continuava a funzionare senza essere riscritto subito.

Non è una scoperta fatta ora: **il codice stesso lo dice**, con la stessa frase ripetuta quasi identica in quattro punti diversi (nel modulo movimenti, nella dashboard, nel riordino, nelle spedizioni): *"finché non esiste un selettore magazzino dedicato, si usa il magazzino attivo come approssimazione."* Era pensato come temporaneo fin dal primo giorno — solo che nessuno è più tornato a completarlo.

## 5. Quindi: si tiene, si toglie, o si cambia?

Non va tolto — il selettore in alto resta uno strumento comodo e legittimo, esattamente come lo è in Odoo o in SAP. Il problema non è "avere un default", è **dove quel default viene usato senza essere mai mostrato**.

La correzione non è complicata concettualmente: ogni schermata dove oggi il magazzino attivo agisce "di nascosto" deve invece **mostrare esplicitamente la sede**, con la possibilità di cambiarla lì, sulla pagina stessa — non tornando su, al menu in alto. Il magazzino attivo resta come valore di partenza già selezionato (comodo, un click in meno), ma diventa sempre visibile e modificabile dove conta.

In concreto, in ordine di urgenza:

1. **Il modulo "Nuovo movimento"** deve avere un campo magazzino visibile, come già ce l'hanno gli ordini d'acquisto. È la correzione più urgente, perché è l'unica che oggi può scrivere un dato sbagliato senza che nessuno se ne accorga (il caso di Maria).
2. **"Proposte di Riordino"** deve mostrare/permettere di scegliere la sede sulla pagina stessa, non solo tramite il menu in alto — influenza decisioni di acquisto reali.
3. **"Giacenze Magazzino"** deve avere lo stesso trattamento, con in più un'opzione vera "tutti i magazzini insieme" che sommi correttamente i numeri di tutte le sedi (oggi, tecnicamente, se si aggiungesse un "tutti" rischierebbe di mostrare un numero preso a caso da una sola riga invece di una somma — un dettaglio tecnico, ma che andrebbe evitato).
4. **Il numero "Prodotti Sottoscorta" in dashboard** dovrebbe almeno scrivere il nome della sede a cui si riferisce, così non viene letto per errore come dato aziendale.
5. Spedizioni e prenotazione di stock sugli ordini cliente hanno lo stesso problema, ma la correzione vera richiede prima che gli ordini di vendita abbiano un proprio concetto di "sede di spedizione" — un lavoro più ampio, da affrontare a parte.

Nessuno di questi interventi richiede toccare il database: l'informazione "a quale sede appartiene questo dato" è già registrata correttamente dappertutto. È un lavoro di interfaccia — rendere visibile quello che oggi il sistema già sa, ma tiene nascosto.

---

## Appendice tecnica (per chi implementerà la correzione)

Riferimenti puntuali al codice, per chi dovrà tradurre questa analisi in modifiche concrete.

Il meccanismo vive in due punti: `app/bootstrap.php` (righe 61-67) scrive la scelta del menu in alto in `$_SESSION['magazzino_attivo_id']`; `includes/magazzino.php`, funzione `qk_magazzino_attivo_id()` (righe 38-71), la legge con fallback a cascata (sessione → magazzino `predefinito` → primo magazzino attivo).

| Punto del codice | Categoria | Cosa fare |
|---|---|---|
| `includes/purchases_orders.php:251` — campo `magazzino_destinazione_id` sul nuovo ordine d'acquisto | Corretto così com'è | Nessun intervento |
| `includes/prodotti.php:189`, `ProductsController.php:359,1663` — riga giacenza iniziale su nuovo prodotto | Corretto così com'è | Nessun intervento |
| `includes/purchases_receipts.php:159-162,268-271`, `includes/purchases_orders.php:508-512` — fallback solo se il documento non ha già un proprio `magazzino_id` | Rischio basso | Nessun intervento urgente |
| `app/views/warehouse/_movement_form.php` + `WarehouseController.php:639` (azione `createMovement`) | **Rischio alto — scrittura senza campo esplicito** | Aggiungere un `<select>` magazzino esplicito al form, sul modello di `magazzino_destinazione_id` negli ordini d'acquisto |
| `WarehouseController.php:477-480` (azione `reorder`), view `reorder.php` | Rischio alto — lettura senza indicazione | Aggiungere filtro/selettore magazzino visibile in pagina |
| `WarehouseController.php:74-78` circa (azione `stock`), view `stock.php` | Rischio alto — lettura senza indicazione | Aggiungere filtro magazzino visibile, con opzione "Tutti i magazzini" implementata come `SUM(...) GROUP BY prodotto_id`, non come singola riga presa a caso |
| `DashboardController.php:31-32` (KPI sottoscorta) | Rischio medio — lettura senza indicazione | Aggiungere il nome della sede nel testo del widget |
| `SpedizioniController.php:137,667`, `includes/sales_quotes.php:1056`, `includes/sales_orders.php:239` | Rischio alto, ma dipendente da lavoro più ampio | Richiede prima un campo "sede di spedizione" esplicito sugli ordini di vendita |

## 8. Piano di Implementazione Dettagliato

Da qui in poi il documento cambia registro: non spiega più "perché", ma "come", nel dettaglio necessario a implementare senza ambiguità. È scritto per essere revisionato **prima** di toccare il codice — ogni blocco di codice qui sotto è una *proposta*, non una modifica già applicata. Dove c'è una scelta di comportamento non ovvia (es. cosa fare col pulsante "Genera RFQ" quando si guardano tutti i magazzini insieme), è segnalata esplicitamente come **decisione da prendere**, non risolta a mia discrezione.

### 8.0 Convenzione comune a tutto il piano

Tutte le funzioni toccate da questo piano condividono la stessa firma per il parametro magazzino: `?int $magazzino_id`. Il significato è già in parte quello attuale, esteso di un caso:

| Valore | Significato | Usato da |
|---|---|---|
| `null` | Non specificato dal chiamante → risolvi a `qk_magazzino_attivo_id()` (comportamento storico, retrocompatibile: nessun chiamante esistente si accorge di nulla) | Tutte le funzioni di magazzino.php, invariato |
| `0` | **Nuovo.** "Tutti i magazzini", aggregato con `SUM`/`GROUP BY` | Solo `qk_stock_list()`, `qk_stock_count()`, la query di `reorder()` — le uniche due schermate dove "tutti insieme" ha senso come *lettura* |
| intero positivo | Quel magazzino specifico | Tutte |

**Perché `0` e non un parametro separato tipo `bool $aggregato`.** Zero non è mai un id di magazzino valido (`id` è `AUTOINCREMENT`, parte da 1), quindi non c'è ambiguità possibile. Usarlo come sentinella evita di aggiungere un secondo parametro a funzioni già con firma lunga, e mappa 1:1 su un valore che arriva già come stringa `"0"` da un `<select>` HTML — nessuna conversione speciale lato controller.

**Dove *non* si applica.** `qk_prodotto_stock_info()`, `qk_lotti_by_prodotto()`, `qk_insert_movimento_manuale()` — le funzioni dietro al form "Nuovo movimento" — **non** ricevono mai `0`: un movimento (carico/scarico/rettifica) è per natura un'operazione su **un** magazzino preciso, "tutti insieme" non è un'azione compiibile. Per queste tre funzioni il piano si limita a passare un intero positivo esplicito invece di lasciarle risolvere internamente su `qk_magazzino_attivo_id()` — le firme non cambiano.

---

### 8.1 Fondamenta condivise — `includes/magazzino.php`

Prerequisito per il Fix 2 e il Fix 3: un helper condiviso per la condizione "sottoscorta" in versione aggregata, gemello di `qk_sottoscorta_where()` che già esiste per la versione a riga singola.

```php
/**
 * Frammento HAVING equivalente a qk_sottoscorta_where(), ma per la modalità
 * aggregata "tutti i magazzini": stessa condizione, riscritta su SUM(...)
 * invece che sulla singola riga giacenze — perché in aggregato ogni prodotto
 * ha più righe (una per sede) raggruppate in una sola.
 * Condiviso tra qk_stock_list() (Fix 2) e la query di reorder() (Fix 3),
 * per non duplicare la stessa formula in due punti.
 */
function qk_sottoscorta_having(string $aliasGiacenza = 'g'): string
{
    return sprintf(
        'SUM(COALESCE(%1$s.scorta_minima,0)) > 0
         AND (SUM(COALESCE(%1$s.quantita_disponibile,0)) - SUM(COALESCE(%1$s.quantita_riservata,0))) < SUM(COALESCE(%1$s.scorta_minima,0))',
        $aliasGiacenza
    );
}

/** Variante di _qk_stock_where() per gli altri filtri (in_stock/esauriti/in_arrivo) in modalità aggregata. */
function _qk_stock_having(string $filter): string
{
    return match ($filter) {
        'in_stock'    => 'SUM(COALESCE(g.quantita_disponibile,0)) > 0',
        'sottoscorta' => qk_sottoscorta_having('g'),
        'esauriti'    => 'SUM(COALESCE(g.quantita_disponibile,0)) <= 0',
        'in_arrivo'   => 'SUM(COALESCE(g.quantita_in_arrivo,0)) > 0',
        default       => '1=1',
    };
}
```

**Nota tecnica sul `LEFT JOIN` + `SUM`.** Un prodotto senza nessuna riga in `giacenze` (mai movimentato in nessuna sede) produce comunque una riga nel `LEFT JOIN` (con `g.*` a `NULL`); `SUM(COALESCE(g.quantita_disponibile,0))` su quella singola riga dà `0`, non `NULL` — il calcolo resta corretto anche per prodotti mai stoccati da nessuna parte. Verificato leggendo la semantica SQLite di `SUM` su una singola riga con `COALESCE`: non serve un `COALESCE` esterno sul risultato di `SUM`.

---

### 8.2 Fix 1 (priorità 1) — Nuovo movimento: campo magazzino esplicito

Questo è l'unico Fix che tocca una **scrittura**, quindi il più delicato. `qk_insert_movimento_manuale()` già rifiuta un `magazzino_id` mancante (`InvalidArgumentException`) — il backend è già sicuro, il buco è tutto tra controller e form.

**`app/Controllers/WarehouseController.php`, metodo `createMovement()`:**

```php
// Nei $values di default, aggiungere:
'magazzino_id' => '',

// All'inizio della gestione GET/POST (prima di leggere gli altri campi POST),
// pre-compilare il default SOLO se non è un resubmit di form con errori:
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $values['magazzino_id'] = (string) qk_magazzino_attivo_id($this->pdo);
}

// Nel blocco POST, insieme agli altri post():
$values['magazzino_id'] = post('magazzino_id', $values['magazzino_id']);

// Validazione, insieme alle altre:
$magazzinoIdInt = (int) $values['magazzino_id'];
if ($magazzinoIdInt <= 0) {
    $errors['magazzino_id'] = 'Seleziona il magazzino.';
}
// (non serve verificare che sia "attivo": qk_insert_movimento_manuale scriverebbe
// comunque su un id valido; un controllo extra sull'esistenza/attivo è opzionale
// difensivo, non strettamente necessario perché il <select> lo popola già solo
// con magazzini attivi — vedi vista sotto)

// Nel payload verso qk_insert_movimento_manuale, sostituire la riga:
//   'magazzino_id' => qk_magazzino_attivo_id($this->pdo),
// con:
'magazzino_id' => $magazzinoIdInt,

// Prima di richiamare $this->page(...), aggiungere:
$magazzini = qk_magazzini_list($this->pdo, true);
// ... e aggiungerlo al compact() passato alla vista.
```

**`app/views/warehouse/_movement_form.php`:** nuovo campo, posizionato **prima** della card "Prodotto" (è il campo che determina tutto il resto, va scelto per primo, sullo stesso principio con cui i form di trasferimento mettono origine/destinazione in cima):

```php
<!-- Magazzino -->
<div class="qk-card mb-3 mt-3">
  <div class="qk-card-header"><span class="qk-card-title">Magazzino</span></div>
  <div class="qk-card-body">
    <div class="qk-form-group">
      <label class="qk-form-label">Magazzino <span class="required">*</span></label>
      <select name="magazzino_id" id="selectMagazzino"
              class="qk-form-control <?= isset($errors['magazzino_id']) ? 'is-invalid' : '' ?>">
        <option value="">— seleziona —</option>
        <?php foreach ($magazzini as $mg): ?>
        <option value="<?= $mg['id'] ?>" <?= (string)$values['magazzino_id'] === (string)$mg['id'] ? 'selected' : '' ?>>
          <?= e($mg['nome']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['magazzino_id'])): ?>
      <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['magazzino_id']) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>
```

**`app/Controllers/WarehouseController.php`, metodo `apiProdottoInfo()`:** deve rispettare il magazzino scelto nel form, non sempre quello del topbar — altrimenti il campo nuovo esisterebbe ma la "Giacenza attuale" mostrata dal riquadro informativo continuerebbe a mentire quando si sceglie una sede diversa da quella attiva.

```php
public function apiProdottoInfo(): void
{
    ob_start();
    header('Content-Type: application/json');
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $magazzinoId = filter_input(INPUT_GET, 'magazzino_id', FILTER_VALIDATE_INT) ?: null; // null → fallback interno a qk_magazzino_attivo_id
    if (!$id) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'id mancante']);
        exit;
    }
    $info  = qk_prodotto_stock_info($this->pdo, $id, $magazzinoId);
    $lotti = $info && $info['traccia_lotti']
        ? qk_lotti_by_prodotto($this->pdo, $id, false, $magazzinoId)
        : [];
    ob_end_clean();
    echo json_encode(['ok' => true, 'prodotto' => $info, 'lotti' => $lotti]);
    exit;
}
```

**`assets/js/warehouse-movement.js`:** due modifiche.

1. `loadProdottoInfo(id)` deve includere il magazzino selezionato nella chiamata fetch:
```js
async function loadProdottoInfo(id) {
  const magazzinoId = selectMagazzino.value; // nuovo ref DOM, vedi sotto
  const url = cfg.apiProdottoInfo + '?id=' + id + (magazzinoId ? '&magazzino_id=' + magazzinoId : '');
  const res = await fetch(url, { credentials: 'same-origin' });
  // ... resto invariato
}
```
2. Cambiare magazzino, a prodotto già scelto, deve ricaricare giacenza e lotti — stesso pattern già usato in `warehouse-transfer.js` per `selectOrigine` che ricarica i lotti al cambio origine:
```js
const selectMagazzino = document.getElementById('selectMagazzino'); // aggiungere ai DOM refs in testa al file
selectMagazzino.addEventListener('change', function () {
  if (prodIdInput.value) loadProdottoInfo(prodIdInput.value);
});
```

**Cosa NON cambia in questo Fix:** lo schema DB (`giacenze`, `movimenti_magazzino` hanno già `magazzino_id`); la firma di `qk_insert_movimento_manuale()` (già richiede `magazzino_id` nel payload); gli altri form (trasferimenti, ordini) — nessuno di questi passa da `_movement_form.php`.

---

### 8.3 Fix 2 (priorità 2) — Giacenze Magazzino: filtro esplicito + aggregato

**`includes/magazzino.php`:** estendere `qk_stock_count()` e `qk_stock_list()` col ramo `magazzino_id === 0`.

```php
function qk_stock_count(PDO $pdo, string $search = '', string $filter = 'all', ?int $magazzino_id = null): int
{
    $magazzino_id ??= qk_magazzino_attivo_id($pdo);

    if ($magazzino_id === 0) {
        $where = ['p.attivo = 1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(p.nome LIKE ? OR p.sku_interno LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM (
                SELECT p.id
                  FROM prodotti p
                  LEFT JOIN giacenze g ON g.prodotto_id = p.id
                 WHERE ' . implode(' AND ', $where) . '
                 GROUP BY p.id
                HAVING ' . _qk_stock_having($filter) . '
             ) t'
        );
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    // ... ramo esistente per magazzino singolo, invariato
}
```

Per `qk_stock_list()`, stesso principio: un ramo `if ($magazzino_id === 0) { return _qk_stock_list_aggregato(...); }` all'inizio della funzione, e una nuova funzione interna che rispecchia la query esistente ma con `SUM`/`GROUP BY`/`HAVING` al posto del filtro sulla singola riga. Punti da tradurre con attenzione, uno per uno:

- `LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?` → `LEFT JOIN giacenze g ON g.prodotto_id = p.id` (nessun filtro sede, si vuole *ogni* riga per poi sommarle) + `GROUP BY p.id` in fondo.
- `COALESCE(g.quantita_disponibile, 0) AS qta_disponibile` → `SUM(COALESCE(g.quantita_disponibile, 0)) AS qta_disponibile`. Stessa cosa per `qta_riservata`, `qta_in_arrivo`, `scorta_minima`.
- **Il calcolo dei kit è il punto più delicato.** Oggi: `SELECT MIN(...) FROM componenti_kit ck LEFT JOIN giacenze g2 ON g2.prodotto_id = ck.componente_id AND g2.magazzino_id = ? WHERE ck.kit_id = p.id` — la disponibilità di un componente in **una** sede. In aggregato serve la disponibilità del componente su **tutte** le sedi, quindi il join va sostituito con una sub-subquery che somma:
  ```sql
  SELECT MIN(CAST(IFNULL(
      (SELECT SUM(g3.quantita_disponibile) FROM giacenze g3 WHERE g3.prodotto_id = ck.componente_id)
  , 0) / ck.quantita AS INT))
  FROM componenti_kit ck
  WHERE ck.kit_id = p.id
  ```
- `g.updated_at AS giacenza_updated_at` → `MAX(g.updated_at) AS giacenza_updated_at` (l'aggiornamento più recente tra tutte le sedi; nessuna sede singola da cui prenderlo).
- `WHERE ... ` resta la stessa (ricerca testuale + `p.attivo=1`), ma la condizione di filtro (`in_stock`/`sottoscorta`/`esauriti`/`in_arrivo`) si sposta da `WHERE` a `HAVING ` + `_qk_stock_having($filter)`, perché ora valuta un aggregato.
- `ORDER BY qta_disponibile/qta_libera/...` — restano alias validi anche con `GROUP BY` in SQLite, nessuna modifica al `$sortMap`.

**`app/Controllers/WarehouseController.php`, metodo `stock()`:**

```php
$magazzinoParam = get_param('magazzino_id', '');
if ($magazzinoParam === '') {
    $magazzinoId = qk_magazzino_attivo_id($this->pdo); // default iniziale, comodo
} elseif ($magazzinoParam === '0') {
    $magazzinoId = 0; // "Tutti i magazzini", scelta esplicita dell'utente
} else {
    $candidato = (int) $magazzinoParam;
    // valida che esista/sia attivo, altrimenti ricadi sul default
    $st = $this->pdo->prepare('SELECT id FROM magazzini WHERE id = ? AND attivo = 1');
    $st->execute([$candidato]);
    $magazzinoId = $st->fetchColumn() ? $candidato : qk_magazzino_attivo_id($this->pdo);
}

$magazzini = qk_magazzini_list($this->pdo, true);

// ... sostituire le chiamate esistenti:
$total = qk_stock_count($this->pdo, $search, $filter, $magazzinoId);
$stock = qk_stock_list($this->pdo, $search, $filter, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset'], $magazzinoId);

// aggiungere 'magazzini', 'magazzinoId' al compact() finale
```

**Decisione da prendere — il ramo "in arrivo".** Il filtro `filter=in_arrivo` di `stock()` oggi interroga `ordini_testata` (ordini d'acquisto pendenti) **senza alcun filtro di sede** — mostra sempre tutti gli ordini in arrivo dell'azienda, indipendentemente dal magazzino attivo. Aggiungendo il selettore esplicito, questo comportamento diventerebbe inconsistente con gli altri filtri (che invece rispettano la sede scelta): scegliere "Roma" nel nuovo selettore e poi cliccare il filtro "In arrivo" mostrerebbe comunque gli ordini di tutte le sedi. Due opzioni, entrambe legittime, da decidere prima di implementare: **(a)** lasciarlo così com'è ma segnalarlo in UI ("gli ordini in arrivo non sono filtrati per sede"), oppure **(b)** filtrare anche questa query su `ot.magazzino_destinazione_id = ?` quando non è "tutti". Non la risolvo qui: è un cambio di comportamento visibile, va deciso.

**`app/views/warehouse/stock.php`:** aggiungere il selettore nella toolbar, accanto alla ricerca testuale (non nel topbar):

```php
<form method="get" action="<?= $base ?? url('warehouse/stock') ?>" class="qk-table-toolbar-left">
  ...
  <select name="magazzino_id" class="qk-form-control qk-form-control-sm" onchange="this.form.submit()">
    <option value="0" <?= $magazzinoId === 0 ? 'selected' : '' ?>>Tutti i magazzini</option>
    <?php foreach ($magazzini as $mg): ?>
    <option value="<?= $mg['id'] ?>" <?= $magazzinoId === (int)$mg['id'] ? 'selected' : '' ?>><?= e($mg['nome']) ?></option>
    <?php endforeach; ?>
  </select>
  <!-- hidden input per preservare $search/$filter/$sort/$dir già esistenti, stesso pattern del resto della pagina -->
</form>
```

Il `page_subtitle` andrebbe reso dinamico per rinforzare visivamente la scelta (non solo il `<select>`): `"Situazione disponibilità e giacenze — " . ($magazzinoId === 0 ? 'tutti i magazzini' : $nomeMagazzinoScelto)`.

---

### 8.4 Fix 3 (priorità 3) — Proposte di Riordino: filtro esplicito + aggregato

Stesso identico pattern di lettura/risoluzione del parametro `magazzino_id` del Fix 2 (stesso blocco di codice, riusabile). La differenza è che `reorder()` non passa da `qk_stock_list()` — ha una query scritta a mano nel controller — quindi serve un secondo ramo `if ($magazzinoId === 0) { ... } else { ... }` **dentro `reorder()` stesso**, non dentro `magazzino.php`.

**Ramo aggregato (nuovo), da affiancare a quello esistente (invariato) in `reorder()`:**

```php
if ($magazzinoId === 0) {
    $baseSql =
        "FROM prodotti p
         LEFT JOIN giacenze g ON g.prodotto_id = p.id
         LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
         LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.ordine_preferenza_fornitore = 1 AND pf.attivo = 1
         LEFT JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
         WHERE p.attivo = 1 $whereExtra
         GROUP BY p.id
         HAVING " . qk_sottoscorta_having('g');

    $stmtC = $this->pdo->prepare("SELECT COUNT(*) FROM (SELECT p.id " . $baseSql . ") t");
    $stmtC->execute($paramsSenzaMagazzino); // niente più il primo parametro magazzino_id
    $total = (int) $stmtC->fetchColumn();

    $stmtQ = $this->pdo->prepare(
        "SELECT p.id, p.nome, p.sku_interno, u.codice AS uom,
                SUM(COALESCE(g.quantita_disponibile, 0)) AS qta_disponibile,
                SUM(COALESCE(g.quantita_riservata, 0)) AS qta_riservata,
                SUM(COALESCE(g.quantita_in_arrivo, 0)) AS qta_in_arrivo,
                (SUM(COALESCE(g.quantita_disponibile, 0)) - SUM(COALESCE(g.quantita_riservata, 0))) AS qta_libera,
                SUM(COALESCE(g.scorta_minima, 0)) AS scorta_minima,
                MAX(pf.fornitore_id) AS fornitore_id,
                MAX(COALESCE(cf.ragione_sociale, cf.nome || ' ' || COALESCE(cf.cognome, ''))) AS fornitore_nome,
                MAX((SELECT pfp.prezzo FROM prodotti_fornitori_prezzi pfp
                     WHERE pfp.prodotto_fornitore_id = pf.id AND pfp.quantita_minima = 0
                     ORDER BY pfp.id DESC LIMIT 1)) AS ultimo_prezzo
         " . $baseSql . "
         ORDER BY " . $sortColAggregato . " COLLATE NOCASE " . $dir . "
         LIMIT $limit OFFSET $offset"
    );
    $stmtQ->execute($paramsSenzaMagazzino);
    $proposals = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
}
```

Nota: `$sortColMap` deve avere una seconda versione per l'aggregato dove le colonne che oggi sono espressioni dirette (es. `'libera' => '(COALESCE(g.quantita_disponibile,0) - COALESCE(g.quantita_riservata,0))'`) diventano alias (`'libera' => 'qta_libera'`), perché in `GROUP BY` non si possono ripetere le stesse espressioni non aggregate nell'`ORDER BY` come se fossero colonne singole.

**Decisione da prendere — il pulsante "Genera RFQ".** Oggi ogni riga propone una quantità consigliata (`scorta_minima - qta_libera`) e un link diretto a "Genera RFQ" precompilato. In modalità aggregata, quella quantità è la somma del fabbisogno di *tutte* le sedi insieme — generare una RFQ da lì presuppone una singola sede di destinazione che in aggregato non esiste. Opzioni, da scegliere prima di implementare: **(a)** nascondere/disabilitare il pulsante "Genera RFQ" quando `magazzinoId === 0`, con un messaggio tipo "scegli una sede per generare un ordine"; **(b)** lasciarlo comunque, sapendo che l'ordine generato non avrà una sede di destinazione precompilata coerente (l'operatore la sceglierà lui nel form RFQ). La scelta (a) è più prudente e coerente con lo spirito dell'intero documento (mai un'azione basata su un dato ambiguo senza controllo esplicito) — ma la decido solo se confermata.

**`app/views/warehouse/reorder.php`:** stesso selettore del Fix 2, stesso posizionamento nella toolbar.

---

### 8.5 Fix 4 (priorità 4, rischio basso) — Dashboard: nome sede nel KPI

Il più semplice dei quattro: nessuna query cambia, si aggiunge solo il nome della sede già nota (`$magazzinoAttivoId`) accanto al numero.

**`app/Controllers/DashboardController.php`:**
```php
// Dopo aver risolto $magazzinoAttivoId (già presente):
$st = $this->pdo->prepare('SELECT nome FROM magazzini WHERE id = ?');
$st->execute([$magazzinoAttivoId]);
$magazzinoAttivoNome = (string) ($st->fetchColumn() ?: '');
// aggiungere 'magazzinoAttivoNome' al compact() passato alla vista
```

**Vista dashboard (KPI "Prodotti Sottoscorta" e widget "vicini al sottoscorta"):** aggiungere un piccolo testo sotto il numero/il titolo del widget, es. `<small class="qk-text-muted">Magazzino: <?= e($magazzinoAttivoNome) ?></small>`. Nessuna logica, solo markup — verificare il file `app/views/warehouse/dashboard.php` per il punto esatto di inserimento quando si implementa.

---

### 8.6 Fix 5 — Spedizioni e riserva stock ordini vendita (confermato fuori scope)

Non è parte di questo piano. Dipende da un prerequisito che questi quattro Fix non toccano: un campo "sede di spedizione" esplicito sugli ordini/preventivi di vendita, che oggi non esiste. Resta tracciato in [Backlog Sviluppi Futuri.md](../Backlog%20Sviluppi%20Futuri.md), punto 3, quinto sotto-punto.

---

### 8.7 Ordine di implementazione consigliato

1. **§8.1** (helper condivisi in `magazzino.php`) — prerequisito puramente additivo, zero rischio, testabile in isolamento prima di collegarlo a qualunque controller.
2. **§8.2** (Nuovo movimento) — priorità più alta (unico Fix che scrive dati), completamente indipendente dagli altri tre, si può fare e verificare da solo.
3. **§8.3** (Giacenze) — usa gli helper del punto 1.
4. **§8.4** (Riordino) — stesso pattern di §8.3, da fare dopo per riusare la stessa logica di risoluzione del parametro già scritta e testata.
5. **§8.5** (Dashboard) — indipendente, rischio quasi nullo, si può inserire in qualunque momento, anche in parallelo.

### 8.8 Piano di verifica manuale (da eseguire nel browser dopo ogni Fix)

Serve almeno **due magazzini attivi** con giacenze diverse per un prodotto di prova, per rendere visibile un eventuale errore (con un solo magazzino, un bug di scoping è invisibile: tutti i numeri coincidono comunque).

- **Fix 1:** creare un movimento scegliendo esplicitamente un magazzino diverso da quello attivo in topbar → verificare che la giacenza cambi in *quel* magazzino, non in quello attivo. Cambiare magazzino nel form a prodotto già selezionato → verificare che "Giacenza attuale" e l'elenco lotti si aggiornino per la nuova sede senza ricaricare la pagina.
- **Fix 2:** con giacenze diverse nelle due sedi, selezionare prima una sede poi l'altra → i numeri devono cambiare coerentemente; selezionare "Tutti i magazzini" → il totale deve essere la somma esatta delle due sedi (verificabile a mano).
- **Fix 3:** stesso controllo di §Fix 2, ma sulla colonna "Libera"/"Scorta min." e sulla quantità consigliata aggregata.
- **Fix 4:** cambiare sede dal topbar → il numero KPI e il nome sede sotto devono cambiare insieme, mai disallineati.

---

*Documento collegato a: [Best Practice UX-UI Multi-Magazzino nei Grandi ERP.md](Best%20Practice%20UX-UI%20Multi-Magazzino%20nei%20Grandi%20ERP.md), Piano di Implementazione Multi-Magazzino §3.3/§3.10. Voce di backlog corrispondente: [Backlog Sviluppi Futuri.md](../Backlog%20Sviluppi%20Futuri.md), punto 3.*
