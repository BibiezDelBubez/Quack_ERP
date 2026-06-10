# RRP (Recommended Retail Price) — Analisi Best Practice e Piano di Implementazione Quack ERP

> Documento generato il 05/06/2026  
> Contesto: Quack ERP v4.2 — JS + PHP + SQLite (→ MySQL)  
> Scopo: ricerca ERP enterprise, valutazione DB esistente, piano modifiche frontend/backend

---

## 1. Come gestiscono il RRP i principali ERP enterprise

### 1.1 SAP S/4HANA — SD Condition Technique

SAP non tratta il RRP come un campo fisso sulla tabella prodotto. Lo modella come un **Condition Type dedicato** all'interno di una Pricing Procedure configurabile. Il modello dati ruota su tre entità:

- **`KONH`** (Condition Header): chiave della condizione (es. fornitore + materiale + sales area), date di validità `ValidFrom`/`ValidTo`, valuta.
- **`KONP`** (Condition Item): valore, scala quantitativa, unità di misura, percentuale vs importo assoluto.
- **`PRCD_ELEMENTS`** (S/4HANA, sostituisce il vecchio `KONV`): snapshot di tutte le condizioni attivate su ogni riga documento al momento della creazione. I campi `KZWI1–KZWI6` in `VBAP`/`VBRP` memorizzano subtotali intermedi (uno viene tipicamente usato per il valore RRP o il costo, rendendolo disponibile per CO-PA e BW/BI).

**Historicity e versioning:** tramite validity period (ValidFrom/ValidTo). Quando il prezzo cambia si crea un nuovo record con nuova data; il vecchio viene chiuso. Non esiste un version number esplicito, ma la storia è ricostruibile via change documents.

**Multi-currency:** sì, ogni condition record ha la propria valuta. Esiste anche un Currency Conversion List per convertire automaticamente.

**Multi-fornitore:** sì, tramite *access sequence* configurabile. Si può includere il Vendor nella key combination, ottenendo RRP diversi per fornitore.

**Comportamento nel preventivo/ordine:** un condition type marcato come `Statistical` viene calcolato ma non contribuisce al net value — perfetto per il RRP "solo informativo". Il valore viene scritto in `PRCD_ELEMENTS` e opzionalmente in un subtotale `KZWI`, disponibile per report ma invisibile al cliente sul documento.

**API:** OData `/A_PricingCondition` in S/4HANA Cloud; BAPI `BAPI_PRICES_CONDITIONS` e RFC per on-premise.

---

### 1.2 Oracle Fusion Cloud Pricing

Oracle separa fisicamente il concetto di "costo" dal "prezzo di listino/RRP":

- **Price List** (header + righe item): ogni item ha un `Base List Price` (= MSRP), scale break per quantità, attributi di pricing, secondary price list come fallback. Una Price List ha date di validità sull'header.
- **Cost List** (separato): contiene i costi per item; viene usato esclusivamente per il calcolo del margine. Questa separazione fisica è la più pulita tra i tre ERP.
- **Pricing Algorithm** (Groovy scripts): legge Price List + Cost List + Discount List, produce `Price Elements` (oggetti che catturano prezzo, costo, sconto, margine, tasse). Il `Base List Price` è il primo elemento calcolato e funge da riferimento RRP.

**Multi-currency:** Price List in valuta base + Currency Conversion List per conversione a runtime.

**API REST:** `/fscmRestApi/resources/latest/priceListItems` (CRUD su price list items) e `/fscmRestApi/priceExecution/documentPrices/priceSalesTransaction` (pricing real-time su transazione, risposta include `BasePrice`, `AdjustedPrice`, `NetPrice`, `Margin`).

---

### 1.3 Microsoft Dynamics 365 Finance & Operations / Commerce

Il RRP si mappa sul campo `Base Price` (campo `Price` nella scheda Sell della Released Product, tabella `InventTable` / `EcoResProduct`). La documentazione Microsoft afferma esplicitamente: *"Most customers put a high default price here, such as MSRP"*.

- **Trade Agreement Price** (tabella `PriceDiscTable`): righe con scope (Table/Group/All), date validità, valuta, quantità minima.
- **Margin alert nativo con semaforo a tre colori** sulle righe ordine/preventivo: verde (margine OK), giallo (sotto soglia "acceptable"), rosso (sotto soglia "questionable"). Le soglie sono configurabili. **Questo è il sistema più pronto all'uso dei tre per il nostro caso d'uso.**
- **API `GetActivePrices`:** restituisce `BasePrice`, `TradeAgreementPrice`, `AdjustedPrice` in una singola chiamata REST.

---

### 1.4 Sintesi comparativa

| Aspetto | SAP S/4HANA | Oracle Fusion | Dynamics 365 |
|---|---|---|---|
| Struttura DB RRP | Tabella condizioni (KONH/KONP) | Price List item separato | Campo base price su prodotto + trade agreement |
| Storicità / Versioning | ValidFrom/ValidTo + change docs | Validity dates su price list | Validity dates su trade agreement |
| Multi-currency | Sì, per condition record | Sì, con Currency Conversion List | Sì, per trade agreement/price list |
| Multi-fornitore | Sì, tramite access sequence | Parziale | No nativo |
| Calcolo margine real-time | Sì, VPRS+PR00 in pricing procedure | Sì, via Cost List + Price Element | Sì, con margin alert |
| Semaforo UI | No nativo (serve customization) | No nativo | **Sì nativo (verde/giallo/rosso)** |
| ADR/chimici | Modulo DGM integrato, surcharge condition | No nativo | No nativo |

**Lezione chiave per noi:** tutti e tre gli ERP separano il RRP dal prezzo di vendita applicato; lo trattano come dato informativo/statistico che non influenza il totale; e lo collegano alla coppia prodotto-fornitore (non al solo prodotto).

---

## 2. Analisi approfondita del DB Quack ERP

### 2.1 Dove sono i dati RRP adesso

La tabella `prodotti_fornitori` (sezione 14 di `init_sqlite.sql`) contiene già:

```sql
rrp_min  DECIMAL(10,4),  -- prezzo di vendita MINIMO consigliato dal fornitore (in unita_misura_acquisto_id)
rrp_max  DECIMAL(10,4),  -- prezzo di vendita MASSIMO consigliato dal fornitore (in unita_misura_acquisto_id)
```

Questo è **già corretto** rispetto alle best practice enterprise: il RRP è legato alla **coppia prodotto-fornitore**, non al solo prodotto. SAP usa la stessa logica (key combination include il vendor). Benissimo.

### 2.2 Cosa funziona bene

| Elemento | Giudizio | Note |
|---|---|---|
| RRP in `prodotti_fornitori` (non in `prodotti`) | ✅ Corretto | Stesso approccio di SAP/Oracle: RRP per coppia prodotto-fornitore |
| Multi-fornitore nativo | ✅ Corretto | Un prodotto può avere RRP diversi da fornitori diversi |
| `ordine_preferenza_fornitore` in `prodotti_fornitori` | ✅ Utile | Permette di sapere quale fornitore è il preferred per il default display |
| Separazione costo (`prodotti_fornitori_prezzi`) da RRP (`prodotti_fornitori.rrp_*`) | ✅ Corretto | Oracle fa la stessa cosa con Price List vs Cost List |
| Margine calcolato e salvato in `preventivi` e `preventivi_righe` | ✅ Buono | `margine_riga`, `margine_euro`, `margine_perc` già presenti |
| `costo_unitario` salvato per riga preventivo | ✅ Best practice | Snapshot del costo al momento dell'offerta, integrità storica |
| `ruoli.can_view_costs` per controllo accesso ai costi | ✅ Ottimo | Permette di nascondere costi/margini agli utenti non autorizzati |

### 2.3 Lacune rispetto alle best practice enterprise

#### Lacuna 1 — Nessuna storicità sul RRP (CRITICA per ADR)

`rrp_min` e `rrp_max` sono campi semplici su `prodotti_fornitori`. Non hanno `valido_dal`/`valido_al`. Quando il fornitore aggiorna il suo RRP, il vecchio valore viene sovrascritto. Problema:

- I prodotti ADR hanno RRP che variano con le revisioni biennali ADR e con i listini annuali dei fornitori.
- Se un preventivo emesso 6 mesi fa viene ristampato o riaperto, non è possibile sapere qual era il RRP consigliato al momento dell'offerta.

SAP risolve con validity period sulla condition record. Oracle con validity dates sulla Price List.

**Soluzione raccomandata:** due opzioni, dalla più semplice alla più robusta.

*Opzione A (semplice, raccomandato per ora):* aggiungere `rrp_valido_dal DATE` e `rrp_valido_al DATE` direttamente in `prodotti_fornitori`. Semplice, nessuna migrazione di tabelle. Sufficiente per un'azienda con pochi aggiornamenti all'anno.

*Opzione B (robusta, come SAP/Oracle):* creare una tabella storica separata `prodotti_fornitori_rrp` con chiave `(prodotto_fornitore_id, valido_dal)`. Permette storico completo e query "qual era il RRP al 15/03/2025?". Richiede più lavoro ma è la scelta giusta se si prevede di auditare o comparare RRP nel tempo.

Per ora, l'Opzione A è sufficiente e non rompe nulla.

#### Lacuna 2 — UoM del RRP non esplicitata

`rrp_min`/`rrp_max` sono commentati come "in `unita_misura_acquisto_id`". Questa è l'unità di misura di acquisto del fornitore. Problema: nel preventivo di vendita si lavora con `unita_misura_commerciale` (es. €/kg o €/l), che può differire dall'unità d'acquisto (es. il fornitore quota in fusti da 25 kg ma noi vendiamo in €/kg).

La conversione esiste già nell'app (tramite `quantita_contenuto` e `fattore_base` in `unita_misura`), ma deve essere applicata anche al RRP quando viene esposto nell'API di pricing.

**Soluzione:** nessuna modifica al DB necessaria (l'UoM è già quella di acquisto per convenzione). La conversione va gestita nel backend al momento dell'esposizione, esattamente come già avviene per `costo_unitario` nel `SalesQuotesController`.

#### Lacuna 3 — Nessuna valuta esplicita sul RRP

Il RRP eredita implicitamente la valuta dalla colonna `valuta` in `dettagli_fornitori`. Per la maggior parte dei casi (fornitori italiani in EUR) va bene. Problema futuro: se si aggiunge un fornitore che quota in USD o GBP, il RRP non ha la valuta esplicita.

**Soluzione:** aggiungere `rrp_valuta CHAR(3) DEFAULT 'EUR'` a `prodotti_fornitori`. Piccola modifica, zero impatto sulle query esistenti.

#### Lacuna 4 — L'API di ricerca prodotti non espone il RRP

`qk_search_prodotti_sales()` in `includes/sales_quotes.php` (riga 100) esegue:

```sql
SELECT p.id, p.nome, p.sku_interno, um.codice, p.unita_misura_stock_id,
       p.descrizione_confezione, p.unita_contenuto_id, um_comm.codice, p.quantita_contenuto
FROM prodotti p ...
```

Non joinano `prodotti_fornitori`. Quindi il catalogo prodotti nella form preventivi **non ha accesso ai valori RRP** al momento della ricerca. Il catalogo mostrato nel mockup (con Costo, Min, Max su ogni card) non è ancora implementato.

#### Lacuna 5 — `apiOttieniPrezzo` non restituisce RRP né costo

Il metodo `SalesPricelistsController::apiOttieniPrezzo()` restituisce:

```json
{
  "ok": true,
  "prezzo_unitario": 0.89,
  "sconto_perc": 0,
  "valuta": "EUR",
  "listino_id": 1,
  "prezzo_sorgente": "Listino Standard: Base",
  "origine": "dedicato"
}
```

Non restituisce né `rrp_min`, né `rrp_max`, né `costo_unitario`. Il JS del preventivo (`fetchQuotePrice()`) non mostra quindi range consigliato né calcola il delta/margine visivo per riga. Il margine viene calcolato solo al salvataggio (backend PHP), non in real-time nel frontend.

#### Lacuna 6 — La form preventivi non mostra RRP né margine per riga

L'header della tabella righe preventivo è attualmente:
```
Cerca prodotto | Descrizione | Confezione | Qtà | U.M. | Prezzo unit. | Sc. % | IVA % | Totale | [X]
```

Mancano: Costo €/kg, Range consigliato (rrp_min → rrp_max), Margine/Delta per riga. Il mockup richiede esattamente queste colonne.

#### Lacuna 7 — Il modal riga listino vendita non mostra il RRP

`_modal_riga.php` per i listini di vendita mostra solo: prodotto, quantità minima, prezzo unitario, sconto, note. Non mostra il range RRP del fornitore come riferimento per chi sta impostando il prezzo nel listino. Questo è il punto più diretto di utilizzo del RRP: il pricing manager deve vedere il consigliato mentre imposta il prezzo.

### 2.4 Cosa NON serve modificare

- **La tabella `preventivi_righe` non deve salvare `rrp_min`/`rrp_max`.** Questi valori sono informativi e cambiano nel tempo: salvarli nella riga preventivo creerebbe ridondanza senza utilità, esattamente come SAP usa il flag `Statistical` per non far contribuire il RRP al valore del documento. Il margine (che invece deve essere cristallizzato) è già salvato in `margine_riga`.
- **La tabella `listini_prezzi` non deve includere RRP.** Il RRP appartiene alla relazione prodotto-fornitore, non al listino di vendita.

---

## 3. Piano modifiche frontend e backend

### 3.1 Modifiche al DB (opzionali ma raccomandate)

#### Migration A — Storicità RRP su `prodotti_fornitori`

```sql
-- Aggiunge periodo di validità al RRP (Opzione A, semplice)
ALTER TABLE prodotti_fornitori ADD COLUMN rrp_valido_dal DATE;
ALTER TABLE prodotti_fornitori ADD COLUMN rrp_valido_al  DATE;
ALTER TABLE prodotti_fornitori ADD COLUMN rrp_valuta CHAR(3) DEFAULT 'EUR';

-- Aggiunge indice per query "RRP attivo alla data X"
CREATE INDEX idx_prodotti_fornitori_rrp_validita 
    ON prodotti_fornitori(prodotto_id, fornitore_id, rrp_valido_dal, rrp_valido_al);
```

Queste tre colonne sono completamente additive: non rompono nessuna query o logica esistente. Il valore DEFAULT lascia NULL le righe esistenti, che continuano a funzionare esattamente come prima (= sempre valide).

Nota: per la migrazione MySQL, `ALTER TABLE ... ADD COLUMN` funziona identicamente. Nessun problema di compatibilità.

---

### 3.2 Modifiche backend

#### B1 — Aggiornare `qk_search_prodotti_sales()` in `includes/sales_quotes.php`

La funzione deve joinare `prodotti_fornitori` (fornitore preferito, `ordine_preferenza_fornitore = 1`) per restituire:
- `costo_unitario`: prezzo minimo dal fornitore preferito (`prodotti_fornitori_prezzi`)
- `rrp_min`: dal fornitore preferito
- `rrp_max`: dal fornitore preferito
- `rrp_fornitore_id`: per sapere a quale fornitore si riferisce il range

```php
// Query da aggiornare (in qk_search_prodotti_sales):
SELECT p.id, 
       p.nome AS label, 
       p.sku_interno AS sku, 
       um.codice AS unita_misura,
       p.unita_misura_stock_id,
       p.descrizione_confezione AS quantita_confezione,
       p.unita_contenuto_id,
       um_comm.codice AS unita_misura_commerciale,
       p.quantita_contenuto,
       -- RRP dal fornitore preferito
       pf.rrp_min,
       pf.rrp_max,
       pf.fornitore_id AS rrp_fornitore_id,
       -- Costo dal fornitore preferito (prezzo base, quantita_minima = 0)
       (SELECT MIN(pfp2.prezzo) 
        FROM prodotti_fornitori_prezzi pfp2 
        WHERE pfp2.prodotto_fornitore_id = pf.id 
          AND pfp2.quantita_minima = 0) AS costo_base
FROM prodotti p
LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
-- Join sul fornitore preferito (ordine 1); se non c'è, LEFT JOIN lascia NULL
LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id 
    AND pf.attivo = 1 
    AND pf.ordine_preferenza_fornitore = (
        SELECT MIN(pf2.ordine_preferenza_fornitore) 
        FROM prodotti_fornitori pf2 
        WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1
    )
WHERE p.attivo = 1
  AND (p.nome LIKE :q OR p.sku_interno LIKE :q)
ORDER BY p.nome
LIMIT 50
```

#### B2 — Aggiornare `apiOttieniPrezzo()` in `SalesPricelistsController`

Il metodo deve restituire anche `rrp_min`, `rrp_max` e `costo_unitario`, con conversione UoM se il cliente ha selezionato "unità di stock" vs "unità commerciale":

```php
// Aggiungere alla query di getSalePrice() o direttamente in apiOttieniPrezzo():
$stRrp = $this->pdo->prepare('
    SELECT pf.rrp_min, pf.rrp_max, pf.unita_misura_acquisto_id,
           MIN(pfp.prezzo) AS costo_unitario
    FROM prodotti_fornitori pf
    LEFT JOIN prodotti_fornitori_prezzi pfp ON pfp.prodotto_fornitore_id = pf.id 
        AND pfp.quantita_minima = 0
    WHERE pf.prodotto_id = ? AND pf.attivo = 1
    ORDER BY pf.ordine_preferenza_fornitore ASC
    LIMIT 1
');
$stRrp->execute([$prodottoId]);
$rrpData = $stRrp->fetch(PDO::FETCH_ASSOC);

// Nel JSON di risposta aggiungere:
'rrp_min'        => $rrpData ? (float)($rrpData['rrp_min'] ?? 0) : null,
'rrp_max'        => $rrpData ? (float)($rrpData['rrp_max'] ?? 0) : null,
'costo_unitario' => $rrpData ? (float)($rrpData['costo_unitario'] ?? 0) : null,
```

**Nota sulla conversione UoM:** se il preventivo è in modalità "unità commerciale" e il RRP è in unità d'acquisto che corrisponde a quella commerciale, nessuna conversione. Se il cliente ha scelto "unità di stock", applicare lo stesso fattore `quantita_contenuto` già usato per `prezzo_unitario`.

#### B3 — Rispettare `can_view_costs` nel JSON

L'API non deve restituire `rrp_min`, `rrp_max`, `costo_unitario` se l'utente loggato ha `can_view_costs = 0`:

```php
$mostraCosti = (bool)($_SESSION['user']['can_view_costs'] ?? false);
if (!$mostraCosti) {
    unset($response['rrp_min'], $response['rrp_max'], $response['costo_unitario']);
}
```

#### B4 — Aggiornare `SalesPricelistsController::apiSaveRiga()` e `apiProdotti()`

La funzione `apiProdotti()` della form listini vendita deve anch'essa restituire `rrp_min` e `rrp_max` per mostrare il range nel modal di inserimento prezzo.

---

### 3.3 Modifiche frontend

#### F1 — Catalogo prodotti nella form preventivi

Il widget "Catalogo Prodotti" (grid di card cliccabili, vedere mockup) deve mostrare per ogni card:
- Costo: valore grezzo da `costo_base` (già nella risposta search)
- Min/Max badge (verde/arancione) da `rrp_min`/`rrp_max`
- Nascondere questi dati se l'utente non ha `can_view_costs`

```js
// Esempio rendering card (aggiunta ai badge esistenti):
if (data.can_view_costs && prod.rrp_min != null) {
  cardHtml += `<span class="badge-rrp-min">Min: €${fmt(prod.rrp_min)}</span>`;
  cardHtml += `<span class="badge-rrp-max">Max: €${fmt(prod.rrp_max)}</span>`;
}
if (data.can_view_costs && prod.costo_base != null) {
  cardHtml += `<span class="badge-costo">Costo: €${fmt(prod.costo_base)}</span>`;
}
```

#### F2 — Colonne aggiuntive nella tabella righe preventivo

Aggiungere le colonne nascoste/mostrabili con il toggle esistente:

```html
<!-- Nuovi th da aggiungere (visibili di default solo se can_view_costs) -->
<th class="col-costo col-extra" data-col="costo">Costo €/<span class="um-label">kg</span></th>
<th class="col-range col-extra" data-col="range">Range consigliato</th>
<th class="col-delta col-extra" data-col="delta">Delta</th>
```

In ogni riga generata via JS aggiungere le corrispondenti `<td>`:

```js
// Nella funzione buildRow() (o equivalente):
const costoTd  = `<td class="col-costo col-extra" data-col="costo">${canViewCosts ? fmtEur(costo) : '—'}</td>`;
const rangeTd  = `<td class="col-range col-extra" data-col="range">
    ${canViewCosts && rrpMin ? 
      `<span class="rrp-range">€${fmt(rrpMin)} → €${fmt(rrpMax)}</span>` : '—'}
</td>`;
const deltaTd  = `<td class="col-delta col-extra" data-col="delta">
    <span class="delta-badge ${deltaClass}">${canViewCosts ? fmtEur(delta) : '—'}</span>
</td>`;
```

Il **delta** per riga è: `imponibile_riga - totale_costo_riga` — già calcolato al salvataggio, deve essere calcolato anche in real-time nel JS per ogni variazione di prezzo/quantità.

#### F3 — Totali con margine in fondo al preventivo

Aggiungere ai box totali (già presenti: Imponibile, IVA, Totale) due nuovi box:
- **Margine €** (verde se > soglia, rosso se < soglia)
- **Margine %** (con semaforo colore: verde > 20%, giallo 10–20%, rosso < 10%)

Queste soglie possono essere inserite in `Impostazioni` (chiave `margine_soglia_verde`, `margine_soglia_giallo`) per renderle configurabili senza toccare il codice.

```js
// In calcTotali():
const margineEuro = imponibile - totaleCosto;
const marginePerc = imponibile > 0 ? (margineEuro / imponibile) * 100 : 0;
const marginClass = marginePerc >= sogliVerde ? 'margine-ok' 
                  : marginePerc >= sogliaGialla ? 'margine-warn' 
                  : 'margine-danger';
```

#### F4 — Toggle visibilità colonne (bottone esistente)

Il meccanismo di toggle colonne già presente nell'app va esteso per includere le nuove colonne `col-costo`, `col-range`, `col-delta`. Lo stato deve essere salvato in `localStorage` per persistere tra sessioni. La preferenza utente su quali colonne mostrare non deve essere salvata nel DB (è preferenza UI, non dato aziendale).

```js
const COL_PREFS_KEY = 'quack_quote_cols_v1';
// Salvo/leggo da localStorage
const savedCols = JSON.parse(localStorage.getItem(COL_PREFS_KEY) || '{"costo":true,"range":true,"delta":true}');
```

#### F5 — Modal riga listino vendita (`_modal_riga.php`)

Aggiungere un box informativo (non editabile) sotto il campo prezzo:

```html
<!-- Da aggiungere in _modal_riga.php dopo il campo prezzo -->
<div id="mr-rrp-hint" class="qk-alert qk-alert-info mt-2 d-none">
  <i class="fa-solid fa-circle-info me-1"></i>
  Range consigliato fornitore: 
  <strong id="mr-rrp-min">—</strong> → <strong id="mr-rrp-max">—</strong>
  <span class="text-muted ms-1" id="mr-rrp-fornitore" style="font-size:.8rem"></span>
</div>
```

Il JS del modal popola questo box quando viene selezionato un prodotto, usando `apiProdotti()` arricchita con i dati RRP.

---

### 3.4 Cosa NON modificare e perché

| Elemento | Decisione | Motivazione |
|---|---|---|
| `preventivi_righe` | NON aggiungere `rrp_min`/`rrp_max` | Il RRP è informativo, non parte del contratto. Il snapshot necessario è `costo_unitario` (già salvato). Stessa logica del flag `Statistical` di SAP. |
| Stampa PDF/DOCX | NON includere RRP | Corretto per design: è dato interno di pricing, non va sul documento cliente. |
| `listini_prezzi` | NON aggiungere campi RRP | RRP appartiene alla relazione prodotto-fornitore, non al listino di vendita. |
| `ordini_clienti_righe` | NON aggiungere RRP | L'ordine è generato dal preventivo; il margine è già storicizzato nel preventivo. |

---

## 4. Ciclo d'uso previsto per Quack ERP

1. **Inserimento RRP** (Acquisti / Scheda prodotto-fornitore): il buyer o il responsabile acquisti apre la scheda fornitore di un prodotto e inserisce/aggiorna `rrp_min` e `rrp_max` (con le nuove colonne `rrp_valido_dal`/`rrp_valido_al` opzionali). Questo tipicamente avviene quando si riceve il nuovo listino dal fornitore (cadenza mensile o trimestrale per prodotti chimici).

2. **Compilazione preventivo** (Vendite / Nuovo preventivo): l'agente commerciale cerca un prodotto nel catalogo. Il widget catalogo mostra già Costo, Min, Max (visibili solo a chi ha `can_view_costs`). Aggiunge la riga. Nelle colonne della tabella vede il range consigliato e il delta (margine per riga) in real-time mentre modifica il prezzo. Se il delta è rosso, sa che sta vendendo sotto costo o sotto il margine minimo.

3. **Gestione listini vendita** (Vendite / Listini): il pricing manager apre il modal di inserimento prezzo per un prodotto. Vede il range consigliato dal fornitore come riferimento. Imposta il prezzo nel listino di vendita coerentemente.

4. **Audit margini** (Report): i totali Margine € e Margine % sulla testata preventivo permettono una verifica immediata prima dell'invio al cliente.

5. **Aggiornamento stagionale RRP** (Acquisti): quando il fornitore aggiorna il suo listino, il buyer aggiorna `rrp_min`/`rrp_max` con le nuove date di validità. I preventivi precedenti non sono impattati (il RRP non è salvato sulla riga preventivo).

---

## 5. Riepilogo priorità di implementazione

| Priorità | Intervento | Impatto | Difficoltà |
|---|---|---|---|
| 🔴 Alta | B1 — Aggiornare `qk_search_prodotti_sales()` con JOIN su `prodotti_fornitori` | Senza questo nulla funziona nel frontend | Media |
| 🔴 Alta | B2 — Aggiornare `apiOttieniPrezzo()` per restituire `rrp_min`, `rrp_max`, `costo_unitario` | Necessario per F2 e F3 | Bassa |
| 🔴 Alta | F1 — Widget catalogo con Costo/Min/Max badge | Visibile immediatamente all'utente | Bassa |
| 🟠 Media | F2 — Colonne Costo/Range/Delta nella tabella righe preventivo | Core della feature richiesta | Media |
| 🟠 Media | F3 — Box Margine € e Margine % nei totali preventivo | Molto utile per il commerciale | Bassa |
| 🟡 Bassa | F4 — Toggle persistente colonne in localStorage | UX migliorativa | Bassa |
| 🟡 Bassa | F5 — Hint RRP nel modal listino vendita | Utile al pricing manager | Bassa |
| 🟢 Facoltativo | DB Migration A — `rrp_valido_dal`, `rrp_valido_al`, `rrp_valuta` | Robustezza a lungo termine | Bassa |
| 🟢 Facoltativo | B3 — Guard `can_view_costs` nell'API JSON | Sicurezza dati commerciali | Bassa |

---

## 6. Note specifiche per prodotti ADR/chimici

- Il RRP di un prodotto chimico può variare per fornitore anche a parità di denominazione commerciale (diversi produttori, diverse concentrazioni, diverso gruppo imballaggio ADR → diverso costo di trasporto → diverso RRP consigliato). Il modello attuale (RRP per coppia prodotto-fornitore) gestisce già questo correttamente.
- Per prodotti con `is_adr = true` nelle righe preventivo, sarebbe utile in futuro mostrare un'indicazione che il prezzo include o esclude il supplemento ADR. Questo non è RRP in senso stretto ma è un'informazione correlata che i fornitori chimici spesso specificano nel listino.
- Il campo `rrp_valido_al` diventa particolarmente rilevante per prodotti ADR: le revisioni biennali ADR (es. ADR 2025, ADR 2027) cambiano classificazioni e possono impattare i costi di trasporto e quindi il RRP consigliato dal fornitore.

---

*Fine documento — Quack ERP RRP Analysis v1.0*
