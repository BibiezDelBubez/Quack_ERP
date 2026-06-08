# Quack ERP — Audit Generale

> **Data audit:** 2026-06-08  
> **Versione codebase analizzata:** HEAD (branch main)  
> **Metodologia:** analisi statica del codice sorgente (routes.php, tutti i controller in `app/Controllers/`, tutte le view in `app/views/`, sidebar, include helper)

---

## Indice

1. [Link Rotti](#1-link-rotti)
2. [PHP Monolitici](#2-php-monolitici)
3. [Codice Non DRY](#3-codice-non-dry)
4. [Logiche Contraddittorie o Duplicate](#4-logiche-contraddittorie-o-duplicate)
5. [Non Conformità UI/UX](#5-non-conformità-uiux)
6. [Riepilogo Conteggi](#6-riepilogo-conteggi)
7. [Quick Wins](#7-quick-wins)

---

## 1. LINK ROTTI

### 1.1 Route → Controller: tutti OK

Tutti i controller referenziati in `app/routes.php` esistono come file in `app/Controllers/`. Nessun controller mancante.

### 1.2 Metodi mancanti nei controller

Tutti i metodi richiamati nelle route esistono nel controller corrispondente. Nessun metodo mancante.

### 1.3 `SostanzeAdrController.php` — controller orfano

| | |
|---|---|
| **File** | `app/Controllers/SostanzeAdrController.php` |
| **Righe** | 1–505 (intero file) |
| **Severità** | MEDIO |

`SostanzeAdrController.php` (505 righe) esiste nella cartella `app/Controllers/` ma **non è registrato in nessuna route** di `app/routes.php`. È dead code. Non è raggiungibile dall'applicazione. Va eliminato o confermato che sia un refactoring in corso.

### 1.4 Sidebar: link a route inesistente (`ecommerce/sales.php`)

| | |
|---|---|
| **File** | `includes/sidebar.php` |
| **Righe** | 245–246 |
| **Severità** | CRITICO |

```php
<a href="<?= url('ecommerce/sales.php') ?>" title="Vendite in-sito"
```

La route `/ecommerce/sales.php` **non esiste** in `app/routes.php`. La route `/ecommerce/sync` esiste, ma `/ecommerce/sales.php` non è né una route controller né un redirect registrato. Cliccando su "Vendite in-sito" nella sidebar si ottiene un 404.

### 1.5 Vista orfana: `app/views/orders/_list.php`

| | |
|---|---|
| **File** | `app/views/orders/_list.php` |
| **Righe** | intero file |
| **Severità** | MEDIO |

La vista `app/views/orders/_list.php` esiste ma **nessun controller la include**. Non c'è nessuna route `/orders` (senza prefisso `sales/`). I redirect legacy `/orders/index.php` → `/sales/orders` e `/orders/create.php` → `/sales/orders/create` sono corretti. La vista è dead code.

---

## 2. PHP MONOLITICI

### 2.1 Controller con HTML inline (violazione MVC)

#### `SalesOrdersController.php` — metodo `index()` con HTML inline

| | |
|---|---|
| **File** | `app/Controllers/SalesOrdersController.php` |
| **Righe** | 39–84 (metodo `index`) |
| **Severità** | CRITICO |

Il metodo `index()` inietta direttamente HTML nel flusso PHP senza usare una view separata. Dopo `require INCLUDES_DIR . '/header.php'` il controller apre tag `?>` e scrive direttamente `<div class="qk-card">`, `<table class="qk-table">`, `<tbody>`, ogni `<tr>` con PHP interpolato, ecc. (circa 40 righe di HTML nel corpo del controller). Il resto dei metodi (`create`, `edit`) usa correttamente `_coming_soon.php` o viste separate.

**Causa probabile:** la view `app/views/sales/orders/_list.php` non esiste ancora — il controller contiene il markup come soluzione temporanea che non è mai stata estratta.

#### `SpedizioniController.php` — metodo `index()` con HTML inline

| | |
|---|---|
| **File** | `app/Controllers/SpedizioniController.php` |
| **Righe** | 51–130 (metodo `index`) |
| **Severità** | CRITICO |

Stesso pattern: `require INCLUDES_DIR . '/header.php'` seguito da `?>` e ~70 righe di HTML inline (`<div class="qk-card">`, `<table class="qk-table">`, ciclo `foreach` con `<tr>`, ecc.). Non esiste una view separata `app/views/sales/shipments/_list.php`.

#### `RfqController.php` e `SalesQuotesController.php` — PDF come stringa PHP

| | |
|---|---|
| **File** | `app/Controllers/RfqController.php`, righe 254–604 (metodo `pdf`) e 605–916 (metodo `docx`) |
| **File** | `app/Controllers/SalesQuotesController.php`, righe 726–1053 (metodo `pdf`) e 1054–1304 (metodo `docx`) |
| **Severità** | MEDIO |

I metodi `pdf()` e `docx()` generano template HTML complessi come stringhe PHP concatenate (con `style="..."` inline, `color:#1a5276`, tabelle, intestazioni, piè di pagina). Non è una violazione MVC stricto sensu (il PDF è output binario), ma si tratta di ~300 righe di markup per ciascun controller, con **codice CSS e struttura tabellare identica** replicata (vedi sezione 3).

### 2.2 Controller oltre 800 righe

Questi controller sono legittimamente grandi a causa della complessità del modulo, ma superano abbondantemente la soglia di 300 righe indicata per la revisione:

| Controller | Righe | Nota |
|---|---|---|
| `SalesQuotesController.php` | 1.304 | include PDF+DOCX inline |
| `RfqController.php` | 1.297 | include PDF+DOCX inline |
| `ProductsController.php` | 1.187 | gestisce catalogo, fornitori, kit, tag, categorie, compliance |
| `AdrController.php` | 883 | gestisce codici ONU, classificazioni, importazioni CSV |
| `SalesPricelistsController.php` | 840 | |
| `CustomersController.php` | 805 | include compliance upload/verify/approve |

Severità: **BASSO** (la complessità è giustificata dalla ricchezza funzionale; il problema reale è il codice PDF duplicato, trattato in sezione 3).

---

## 3. CODICE NON DRY

### 3.1 Generazione PDF header: codice identico in RfqController e SalesQuotesController

| | |
|---|---|
| **File A** | `app/Controllers/RfqController.php`, righe 323–358 |
| **File B** | `app/Controllers/SalesQuotesController.php`, righe 777–813 |
| **Severità** | MEDIO |

Il blocco che costruisce `$headerHtml` (logica: usa header_path se esiste, altrimenti tabella logo+titolo, altrimenti tabella solo testo) è **identico parola per parola** nei due controller, con l'unica differenza del titolo del documento ("RICHIESTA DI PREVENTIVO (RFQ)" vs "PREVENTIVO DI VENDITA"). Stesso identico HTML, stesso `$mpdf->SetHTMLHeader()`, stesso `$mpdf->SetHTMLFooter()`, stesso blocco CSS inline (`.righe-table`, `.info-table`, `.totals-table`, `.cond-table`) duplicato per oltre 60 righe in ciascun file.

**Fix:** estrarre in `includes/pdf_helper.php` una funzione `qk_pdf_build_header(PDO $pdo, int $aziendaId, string $titolo): string`.

### 3.2 Generazione DOCX: codice duplicato tra RfqController e PurchaseOrdersController

| | |
|---|---|
| **File A** | `app/Controllers/RfqController.php`, righe 605–916 (metodo `docx`) |
| **File B** | `app/Controllers/PurchaseOrdersController.php`, righe 184–462 (metodo `docx`) |
| **Severità** | MEDIO |

Entrambi usano `word_helper.php` per generare DOCX, con struttura di chiamata identica (carica azienda, carica righe, costruisce tabella intestazione + righe prodotto + totali). La logica di composizione è sostanzialmente replicata.

### 3.3 Query di ricerca prodotti duplicate

| | |
|---|---|
| **File A** | `includes/prodotti.php`, funzione `qk_search_fornitori()` (riga 191) |
| **File B** | `includes/purchases_rfq.php` — usa `qk_fornitori_list()` da `anagrafiche.php` |
| **File C** | `includes/sales_quotes.php`, funzione `qk_search_prodotti_sales()` (riga 100) |
| **Severità** | BASSO |

Esistono funzioni di ricerca prodotti/fornitori separate in `prodotti.php`, `anagrafiche.php` e `sales_quotes.php` con query simili ma non identiche. Le query di ricerca clienti in `sales_quotes.php::qk_search_clienti()` e in `anagrafiche.php::qk_clienti_list()` si sovrappongono parzialmente.

### 3.4 Blocco compliance check duplicato in `SalesQuotesController.php`

| | |
|---|---|
| **File** | `app/Controllers/SalesQuotesController.php` |
| **Righe** | 88–103 (in `create()`) e 222–237 (in `edit()`) |
| **Severità** | MEDIO |

Il blocco che chiama `qk_compliance_check_sale()`, costruisce `$violation_msgs`, genera l'errore con il link HTML `style='color:#721c24;font-weight:bold;text-decoration:underline;'`, e popola `$errors['_global']` è **copiato pari pari** nei metodi `create()` e `edit()`. Dovrebbe essere estratto in un metodo privato `checkComplianceForRighe()`.

---

## 4. LOGICHE CONTRADDITTORIE O DUPLICATE

### 4.1 Due cicli acquisti paralleli — separazione chiara

L'analisi mostra che i due cicli **non si sovrappongono a livello di route o controller**:

- **Ciclo RFQ/Offerte/Ordini v2** (attivo): `RfqController` → `PurchasesQuotesController` → `PurchaseOrdersController` → `ReceiptsController`. Routes: `/purchases/rfq`, `/purchases/quotes`, `/purchases/orders`, `/purchases/receipts`. Sidebar punta a questi.
- **Ciclo legacy `PurchasesController`**: gestisce solo listini acquisto (`/purchases/pricelists`) e la funzione `apiOttieniPrezzo`. Non gestisce più ordini di acquisto diretti.

La sidebar punta correttamente al ciclo v2. I redirect legacy (`/purchases/offerte`, `/purchases/ordini`, `/purchases/ricezioni`) reindirizzano correttamente alle route v2.

**Problema residuo:** `PurchasesController` contiene il metodo interno `getPurchasePrice()` (riga 283, non registrato come route) usato dal metodo `apiOttieniPrezzo`. Questo metodo è un helper interno, non una route — è corretto. Non è un link rotto.

**Conclusione:** la separazione è chiara e non c'è sovrapposizione funzionale attiva. OK.

### 4.2 Due tabelle per le schede di sicurezza: `schede_sicurezza` vs `sostanze_chimiche_schede`

| | |
|---|---|
| **File** | `db/init_sqlite.sql`, righe 489 e 553 |
| **Severità** | CRITICO |

Esistono **due tabelle distinte** per le schede di sicurezza:

1. `sostanze_chimiche_schede` (riga 489 in `init_sqlite.sql`): collegata a `sostanze_chimiche.id`. Usata attivamente da `SostanzeChimicheController.php` e dalla view `app/views/adr/sostanze/_form.php`.

2. `schede_sicurezza` (riga 553 in `init_sqlite.sql`): collegata a `prodotti.id` (colonna `prodotto_id`). Ha campi `language`, `revision_date`, `file_path`, `is_active`. **Non è usata da nessun controller PHP**, nessuna query la referenzia nel codice applicativo.

**La tabella `schede_sicurezza` è definita nello schema ma mai interrogata.** Il tab "SDS" nella scheda prodotto (se esiste) punta alla sostanza chimica collegata, non a questa tabella. Si tratta di una tabella zombie che genera confusione sullo schema.

**Fix:** decidere se `schede_sicurezza` è destinata a rimpiazzare `sostanze_chimiche_schede` o se va eliminata. Aggiornare `init_sqlite.sql` e `init_mysql.sql` di conseguenza.

### 4.3 Compliance check: percorso doppio coerente, ma duplicato internamente

Il doppio percorso in `includes/compliance.php` (Percorso 1: restrizioni dirette sulla sostanza; Percorso 2: fallback codice ONU) è documentato, intenzionale e coerente. Viene usato identicamente sia in `qk_compliance_check_sale()` che in `qk_compliance_log_transactions()`.

**Problema:** la logica di fetch delle restrizioni (identica nei due blocchi, righe 64–106 per `check_sale` e righe 218–250 per `log_transactions`) è duplicata internamente al file. Dovrebbe essere estratta in una funzione privata `_get_product_restrictions()`.

Severità: **BASSO** — non causa bug, ma aumenta il rischio di divergenza futura.

---

## 5. NON CONFORMITÀ UI/UX A `UI_UX_STANDARDS.md`

### 5.1 Colonna primaria senza link cliccabile

La regola: *"The primary text column (Name, SKU, Document Number) MUST be a clickable link"*.

#### `app/views/orders/_list.php` — ID ordine non cliccabile

| | |
|---|---|
| **File** | `app/views/orders/_list.php` |
| **Riga** | 46 |
| **Severità** | CRITICO |

```php
<td class="fw-semibold"><?= e($o['id'] ?? '') ?></td>
```

La colonna "N° Ordine" (identificatore primario) è testo statico. Non punta a nessuna route di dettaglio. La colonna `col-actions` dell'ultima colonna è vuota (`<td class="col-actions"></td>`). Non c'è nessun modo di aprire il dettaglio di un ordine da questa lista. Nota: questa è la view legacy, ma rimane nel codebase.

#### `app/views/purchases/pricelists_list.php` — nome fornitore non cliccabile

| | |
|---|---|
| **File** | `app/views/purchases/pricelists_list.php` |
| **Riga** | 71 |
| **Severità** | MEDIO |

```php
<div class="fw-semibold"><?= e($la['fornitore_nome'] ?? 'Fornitore non trovato') ?></div>
```

Il nome del fornitore nella seconda colonna è testo statico. Il link esiste sulla colonna del nome del listino (riga precedente), ma il fornitore dovrebbe avere un link a `/suppliers/edit?id=...`.

#### `app/views/settings/aziende/_list.php` — nome azienda non cliccabile

| | |
|---|---|
| **File** | `app/views/settings/aziende/_list.php` |
| **Riga** | 43 |
| **Severità** | MEDIO |

```php
<div class="fw-semibold">
```

La `fw-semibold` non è un link. Il nome azienda è l'identificatore primario e non è cliccabile.

#### `app/views/warehouse/inventories_list.php` — ID inventario non cliccabile

| | |
|---|---|
| **File** | `app/views/warehouse/inventories_list.php` |
| **Riga** | 41 |
| **Severità** | MEDIO |

```php
<td class="fw-semibold">#<?= $inv['id'] ?></td>
```

L'ID inventario non è un link al dettaglio/form dell'inventario.

#### `app/views/warehouse/reorder.php` — prodotto non cliccabile

| | |
|---|---|
| **File** | `app/views/warehouse/reorder.php` |
| **Riga** | 47 |
| **Severità** | BASSO |

```php
<td class="fw-semibold">
```

Il nome prodotto nella lista di riordino non è un link a `/products/edit?id=...`.

### 5.2 Breadcrumb mancanti nelle view (BASSO per le list view, le breadcrumbs sono nel controller)

Le view non contengono i breadcrumb direttamente — è corretto, vengono passati come `$breadcrumbs` dal controller e renderizzati da `includes/header.php`. L'analisi mostra che i controller principali (Customers, Warehouse, ADR, ecc.) definiscono correttamente `$breadcrumbs`.

**Eccezione: `SalesOrdersController`** — il metodo `index()` definisce `$breadcrumbs` ma poi non usa una view separata, gestisce tutto inline. Il breadcrumb viene comunque passato a `header.php` → OK.

**Eccezione: profilo** — `ProfileController::index()` usa `$this->page()` senza `$breadcrumbs` definito. Il `header.php` gestisce `$breadcrumbs` vuoto con un controllo `!empty()` → nessun errore di runtime, ma manca la breadcrumb nella pagina profilo.

| | |
|---|---|
| **File** | `app/Controllers/ProfileController.php` |
| **Righe** | intero metodo `index()` |
| **Severità** | BASSO |

### 5.3 Colori hardcoded in hex

La regola: *"Never hardcode hexadecimal or RGB colors in inline HTML styles. Always use theme variables."*

#### `app/views/products/_form.php` — `color:#f59e0b` hardcoded

| | |
|---|---|
| **File** | `app/views/products/_form.php` |
| **Riga** | 118 |
| **Severità** | BASSO |

```php
<i class="fa-solid fa-triangle-exclamation fa-fw me-1" style="color:#f59e0b"></i>ADR
```

Il colore amber `#f59e0b` dovrebbe essere `var(--qk-warning)`.

#### `app/views/products/_form.php` — `color:#f59e0b` hardcoded (secondo)

| | |
|---|---|
| **File** | `app/views/products/_form.php` |
| **Riga** | 1003 |
| **Severità** | BASSO |

```php
<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i>
```

Stesso problema, seconda occorrenza.

#### `app/views/settings/index.php` — `color:#3858e9` hardcoded

| | |
|---|---|
| **File** | `app/views/settings/index.php` |
| **Riga** | 58 |
| **Severità** | BASSO |

```php
<i class="fa-brands fa-wordpress" style="color:#3858e9"></i>
```

#### `app/views/settings/woocommerce.php` — `color:#3858e9` hardcoded

| | |
|---|---|
| **File** | `app/views/settings/woocommerce.php` |
| **Riga** | 37 |
| **Severità** | BASSO |

```php
<i class="fa-brands fa-wordpress me-2" style="color:#3858e9"></i>
```

#### `app/Controllers/SalesQuotesController.php` — colore hardcoded in stringa HTML del controller

| | |
|---|---|
| **File** | `app/Controllers/SalesQuotesController.php` |
| **Righe** | 102 e 236 |
| **Severità** | BASSO |

```php
style='color:#721c24;font-weight:bold;text-decoration:underline;'
```

Colore hex hardcoded in una stringa PHP che genera HTML, dentro un controller. Doppia violazione: colore hardcoded + HTML nel controller.

### 5.4 Più di un pulsante `btn-qk-primary` per pagina

La regola: *"One distinct primary button per layout."*

Le seguenti view contengono più occorrenze di `btn-qk-primary`. In molti casi la molteplicità è giustificata (tab separati, modali, sezioni distinte della pagina — ADR index ha 16 perché gestisce 6 tab ciascuna con il proprio "Aggiungi"). Quelli che violano lo spirito della regola in una singola sezione visibile sono:

#### `app/views/adr/sostanze/_form.php` — 3 primary buttons

| | |
|---|---|
| **File** | `app/views/adr/sostanze/_form.php` |
| **Severità** | BASSO |

La form sostanza ha 3 bottoni primari visibili: "Salva sostanza", "Carica SDS", e uno nei modali delle restrizioni. I modali sono nascosti per default, ma il "Carica SDS" e "Salva sostanza" sono contemporaneamente visibili.

#### `app/views/sales/quotes/_form.php` — 6 primary buttons

| | |
|---|---|
| **File** | `app/views/sales/quotes/_form.php` |
| **Severità** | MEDIO |

6 occorrenze di `btn-qk-primary` nella form preventivo vendita. Anche distribuiti tra modali e action bar, è un numero elevato. Andrebbe verificato quanti sono visibili contemporaneamente nell'action bar principale.

#### `app/views/purchases/rfq/_form.php` — 4 primary buttons

| | |
|---|---|
| **File** | `app/views/purchases/rfq/_form.php` |
| **Severità** | MEDIO |

4 primary buttons nella form RFQ.

### 5.5 Inline style per layout invece di classi utility

La regola: *"Avoid inline styling for standard layouts. Always use the built-in custom utilities."*

#### `app/views/dashboard/_index.php` — `style="grid-column: span 1;"`

| | |
|---|---|
| **File** | `app/views/dashboard/_index.php` |
| **Riga** | 157 |
| **Severità** | BASSO |

```php
<div style="grid-column: span 1;">
```

Dovrebbe usare una classe utility o Bootstrap `col-`.

#### `app/views/purchases/pricelists_form.php` — `style="grid-template-columns:1fr 1fr"`

| | |
|---|---|
| **File** | `app/views/purchases/pricelists_form.php` |
| **Riga** | 70 |
| **Severità** | BASSO |

```php
<div class="qk-form-row" style="grid-template-columns:1fr 1fr">
```

La griglia è inline invece di usare una variante classe.

#### `app/views/sales/pricelists/_form_testata.php` e `_modal_riga.php` — `style="grid-template-columns:..."`

| | |
|---|---|
| **File** | `app/views/sales/pricelists/_form_testata.php`, riga 41; `app/views/sales/pricelists/_modal_riga.php`, riga 30 |
| **Severità** | BASSO |

Stesso pattern.

#### `app/views/suppliers/_list.php` — `style="font-family:monospace;font-size:.82rem"`

| | |
|---|---|
| **File** | `app/views/suppliers/_list.php` |
| **Riga** | ~62 |
| **Severità** | BASSO |

Stile inline per la colonna P.IVA. Potrebbe essere una classe `qk-mono-sm`.

---

## 6. RIEPILOGO CONTEGGI

| Categoria | CRITICO | MEDIO | BASSO | Totale |
|---|---|---|---|---|
| 1. Link rotti | 1 | 2 | 0 | **3** |
| 2. PHP monolitici | 2 | 1 | 3 | **6** |
| 3. Codice non DRY | 0 | 3 | 1 | **4** |
| 4. Logiche contraddittorie | 1 | 0 | 1 | **2** |
| 5. Non conformità UI/UX | 1 | 5 | 10 | **16** |
| **TOTALE** | **5** | **11** | **15** | **31** |

---

## 7. QUICK WINS

Problemi ad alto impatto e basso sforzo da risolvere subito.

### QW-1 — Rimuovere link rotto sidebar `ecommerce/sales.php` (30 min)

**File:** `includes/sidebar.php`, righe 244–250.  
Sostituire `url('ecommerce/sales.php')` con `url('ecommerce/sync')` oppure commentare il link fino alla realizzazione del modulo. **Causa un 404 visibile a ogni utente che apre la sezione Ecommerce.**

### QW-2 — Estrarre HTML inline da `SalesOrdersController::index()` in una view (1h)

**File:** `app/Controllers/SalesOrdersController.php`, righe 39–84.  
Creare `app/views/sales/orders/_list.php` con il markup esistente. Sostituire il blocco inline con:
```php
require APP_ROOT_DIR . '/app/views/sales/orders/_list.php';
```
Pattern identico già usato da tutti gli altri moduli.

### QW-3 — Estrarre HTML inline da `SpedizioniController::index()` in una view (45 min)

**File:** `app/Controllers/SpedizioniController.php`, righe 51–130.  
Creare `app/views/sales/shipments/_list.php`. Stessa procedura di QW-2.

### QW-4 — Sostituire colori hardcoded in `products/_form.php` (10 min)

**File:** `app/views/products/_form.php`, righe 118 e 1003.  
`style="color:#f59e0b"` → `style="color:var(--qk-warning)"` (o aggiungere una classe utility).

### QW-5 — Rendere cliccabile la colonna ordine in `orders/_list.php` (20 min)

**File:** `app/views/orders/_list.php`, riga 46.  
```php
// Da:
<td class="fw-semibold"><?= e($o['id'] ?? '') ?></td>
// A:
<td class="fw-semibold">
  <a href="<?= url('sales/orders/edit?id=' . ($o['id'] ?? '')) ?>" class="qk-text-accent text-decoration-none">
    <?= e($o['id'] ?? '') ?>
  </a>
</td>
```

### QW-6 — Eliminare `SostanzeAdrController.php` (5 min)

**File:** `app/Controllers/SostanzeAdrController.php`.  
Nessuna route lo usa. Eliminare per evitare confusione. Verificare prima che non sia incluso via `require` da altri file (una grep conferma che non lo è).

### QW-7 — Estrarre blocco compliance-check duplicato in metodo privato (30 min)

**File:** `app/Controllers/SalesQuotesController.php`, righe 88–103 e 222–237.  
Creare metodo privato:
```php
private function buildComplianceError(int $customerId, array $righe): ?string { ... }
```
Eliminare il colore hardcoded `#721c24` sostituendolo con `var(--qk-danger)` o una classe.

### QW-8 — Chiarire la doppia tabella SDS (1h di analisi + decisione)

**File:** `db/init_sqlite.sql`, righe 489 e 553.  
La tabella `schede_sicurezza` (collegata a `prodotti`) non ha nessun codice PHP che la usa. Prendere una decisione esplicita:
- Se si vuole SDS direttamente sui prodotti (oltre che sulle sostanze): implementare il controller.
- Se non serve: eliminare la tabella dallo schema per non generare confusione.

---

*Fine report — generato il 2026-06-08*
