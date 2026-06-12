# UX/UI dei Prezzi nella Scheda Prodotto — Analisi ERP Best-in-Class

> **Obiettivo:** capire come i principali ERP gestiscono la visualizzazione e modifica dei prezzi di vendita, listini speciali e scaglioni volume **dall'interno dell'anagrafica prodotto** (non dalla sezione listini separata), e identificare il pattern migliore da adottare in Quack.

---

## 1. Il problema da risolvere

Inserire i prezzi nella scheda prodotto crea una tensione strutturale tra tre esigenze opposte:

| Esigenza | Descrizione |
|---|---|
| **Immediatezza** | Per il 90% dei prodotti basta un prezzo base. Non bisogna costringere l'utente a navigare in una schermata dedicata per impostare il primo prezzo. |
| **Scalabilità** | Per prodotti complessi ci possono essere N listini × M scaglioni di quantità = matrice potenzialmente grande. |
| **Pulizia visiva** | Mostrare tutti i prezzi sempre significa una griglia enorme e illeggibile. Nasconderli tutti significa opacità operativa. |

Nessun ERP risolve questo problema in modo perfetto, ma emergono tre pattern distinti.

---

## 2. Come lo risolvono i principali ERP

### 2.1 Oracle NetSuite — La Pricing Matrix (pattern "matrice esplicita")

NetSuite è l'ERP che porta questa logica più in profondità nell'anagrafica prodotto.

**Struttura UI:**
- La scheda prodotto ha un subtab dedicato chiamato **Sales/Pricing**.
- All'interno c'è una **matrice bidimensionale** inline:
  - Le **righe** sono i Price Level (Base Price, Wholesale, Online Price, ecc.)
  - Le **colonne** sono le soglie di quantità (Qty 1, Qty 5, Qty 10, ecc.), configurabili globalmente da admin
- Ogni cella è direttamente editabile in modalità edit.

**Caratteristiche chiave:**
- Senza funzionalità avanzate attive: singolo campo `Sales Price` nel tab General, zero overhead.
- Abilitando `Multiple Prices`: appare la matrice per righe (listini).
- Abilitando `Quantity Pricing`: si aggiungono le colonne di quantità. Il numero di colonne è un'impostazione globale (`Max # of Quantity-based Price Levels`).
- Per la gestione multi-valuta, ogni valuta ha una propria sotto-tab nella matrice.
- È possibile associare uno `Quantity Pricing Schedule` predefinito al prodotto, che compila automaticamente la matrice.

**Punti di forza:** tutto visibile in un posto, editing diretto, struttura progressiva (si espande in base alle feature abilitate).

**Punti deboli:** con molti listini e molti scaglioni la matrice diventa ampia. Non c'è collasso/espansione, è sempre mostrata.

---

### 2.2 SAP Business One — Il Tab "Prezzi" (pattern "tab con lista piatta")

SAP B1 adotta un approccio più semplice ma meno potente inline.

**Struttura UI:**
- Nell'Item Master Data esiste una **sezione/tab "Prices"** (o accessibile dal tab General tramite il campo Unit Price di base).
- Il campo `Unit Price` nel tab General è il prezzo base del listino principale.
- Per accedere agli altri listini, esiste una finestra/scheda separata che mostra tutti i Price List con la colonna prezzo per quell'item, modificabile inline con doppio click sulla riga.
- Gli scaglioni di quantità si gestiscono aprendo una ulteriore finestra **Unit of Measure Prices** dal doppio-click sulla riga del listino.

**Caratteristiche chiave:**
- Il prezzo base è nel tab principale — massima immediatezza.
- I listini aggiuntivi sono in una vista tabellare flat (una riga per listino).
- Gli scaglioni sono uno step ulteriore (finestra modale da un listino specifico).
- Fino a 10 listini predefiniti, espandibili.

**Punti di forza:** prezzo base sempre visibile senza attrito, struttura a livelli progressivi di profondità.

**Punti deboli:** gli scaglioni volume richiedono due livelli di drill-down; nessuna vista consolidata "tutto in uno schermo".

---

### 2.3 Microsoft Dynamics 365 Business Central — FastTab + Action (pattern "campo singolo + link esterno")

Business Central segue la filosofia dei **FastTab** (sezioni collassabili sulla scheda prodotto).

**Struttura UI:**
- La scheda prodotto (Item Card) ha un FastTab **"Price & Sales"** che contiene principalmente il campo `Unit Price` (prezzo base, IVA inclusa/esclusa configurabile).
- Per accedere ai listini multipli e agli sconti, l'utente usa una **Action** nel menu della scheda: `Prices & Discounts → Sales Price Lists` che apre una pagina lista separata.
- Dal 2020 (Wave 2, versione 17.1) è disponibile la nuova **"New Sales Pricing Experience"** che unifica prezzi e sconti in un'unica entità "Sales Price List" — ma è sempre una pagina separata accessibile via action, non inline sulla scheda prodotto.
- Non esiste una vista degli scaglioni di quantità inline sulla item card; si gestiscono all'interno delle singole price list.

**Caratteristiche chiave:**
- Massima semplicità del form principale: un solo campo prezzo.
- Complessità delegata a pagine secondarie.
- La nuova pricing experience (2025+ default per SaaS) unifica prezzi+sconti in un'unica lista, eliminando il salto tra due schermate.

**Punti di forza:** zero cluttering sulla scheda prodotto, interfaccia pulitissima per la maggior parte dei casi.

**Punti deboli:** per chi lavora sui prezzi frequentemente, il continuo cambio di contesto (item card → price list page) è lento.

---

### 2.4 Odoo 17/18 — Tab "Sales" + modulo pricelist (pattern ibrido)

Odoo adotta un approccio intermedio, influenzato dalla sua natura modulare.

**Struttura UI:**
- Il form del prodotto ha un tab **"Sales"** che di base mostra solo il `Sales Price` (prezzo base) e alcune impostazioni di vendita.
- Abilitando la funzionalità Pricelist nelle impostazioni Sales, il tab Sales si arricchisce con sezione per gestire i prezzi per pricelist specifiche.
- In Odoo 19 è stato introdotto un tab **"Prices"** dedicato sul form prodotto, che permette di impostare prezzi per varianti e assegnarli a specifici pricelist direttamente dalla scheda.
- Gli scaglioni di quantità si configurano nelle **Price Rules** di ogni pricelist (pagina separata), non direttamente sulla scheda prodotto (salvo moduli aggiuntivi).

**Caratteristiche chiave:**
- Architettura progressiva basata su feature toggle.
- Moduli terze parti (es. `tgm_website_product_pricelist`) aggiungono tabelle di prezzo tiered direttamente sulla scheda.
- Il prezzo base è sempre prominente e di facile editing.

**Punti di forza:** modulare e configurabile; versioni recenti (18/19) avvicinano la gestione prezzi al form prodotto.

**Punti deboli:** con molti listini, il tab diventa verboso; la gestione scaglioni remain separata nelle versioni standard.

---

## 3. Analisi comparativa dei pattern

| Dimensione | NetSuite | SAP B1 | Business Central | Odoo |
|---|---|---|---|---|
| **Prezzo base nella scheda** | Tab Sales/Pricing | Tab General (campo singolo) | FastTab Price&Sales | Tab Sales |
| **Listini multipli inline** | ✅ Matrice nel subtab | ⚠️ Lista flat, doppio click | ❌ Pagina separata | ⚠️ Tab Sales (con feature) |
| **Scaglioni quantità inline** | ✅ Colonne della matrice | ❌ Modale di 2° livello | ❌ Nella price list | ❌ Nelle price rules |
| **Scalabilità** | Media (matrice si allarga) | Alta (lazy drill-down) | Alta (tutto separato) | Media |
| **Immediatezza** | Alta | Alta | Alta | Alta |
| **Anti-cluttering** | Media | Alta | Massima | Media |
| **Vista consolidata** | ✅ | ❌ | ❌ | ❌ |

**Conclusione comparativa:** NetSuite è l'unico che offre una vista consolidata inline, ma lo fa a costo di potenziale cluttering per cataloghi complessi. Gli altri tre privilegiano pulizia e delegano complessità a viste separate.

---

## 4. Soluzione proposta per Quack

### Filosofia: "Progressive Disclosure con Matrice Lazy-Load"

Il pattern proposto prende il meglio di NetSuite (visibilità consolidata) e di Business Central (pulizia base), aggiungendo una logica di **visibilità progressiva** controllata dall'utente e una **matrice collassabile**.

### Struttura UI proposta

**A — Campo prezzo base nel tab principale (sempre visibile)**

Nella sezione principale della scheda prodotto, accanto ai dati fondamentali, un campo `Prezzo Listino Base` con label chiara e editabile inline. Questo copre il 90% dei casi senza nessun attrito.

```
[ Codice ]  [ Descrizione ]  [ UM ]  [ Prezzo Base €  ____ ]
```

**B — Sezione "Prezzi & Listini" collassabile (collapsed di default)**

Un accordion/collapsible panel sotto il form principale, etichettato **"Prezzi di Vendita"**, chiuso di default. L'utente che non gestisce listini multipli non lo vede mai. Chi lo apre trova:

```
▼ Prezzi di Vendita  [+ Aggiungi Riga]  [Salva]

┌──────────────────┬──────────┬──────────┬──────────┬──────────┐
│ Listino          │ Prezzo   │ Da Qty 1 │ Da Qty 5 │ Da Qty 10│
├──────────────────┼──────────┼──────────┼──────────┼──────────┤
│ Listino Base     │  100,00  │    —     │    —     │    —     │
│ Listino Ingrosso │   80,00  │  78,00   │  75,00   │  70,00   │
│ Listino Export   │   85,00  │    —     │    —     │    —     │
└──────────────────┴──────────┴──────────┴──────────┴──────────┘
```

- Le colonne di quantità sono **visibili solo se almeno un prodotto del catalogo usa scaglioni** (o se l'utente ha abilitato la feature globalmente). Altrimenti la tabella mostra solo Listino + Prezzo.
- Ogni cella è editabile inline con click singolo.
- Il pulsante `+ Aggiungi Riga` apre un selector di listini già configurati nel sistema.
- I listini non assegnati a quel prodotto non compaiono (no righe vuote di default).

**C — Indicatore visivo nel tab (quando collapsed)**

Quando la sezione è chiusa, mostrare un sommario testuale sotto il prezzo base:
```
Prezzo Base: €100,00  ·  3 listini configurati  ·  Scaglioni: sì
```
Cliccabile per espandere direttamente la sezione.

### Regole di rendering anti-cluttering

1. **Nessuna colonna di quantità** se tutti i listini del prodotto hanno scaglioni disabilitati.
2. **Massimo 4 colonne di quantità** visualizzate di default; ulteriori soglie collassate in un sub-expand "Mostra tutti gli scaglioni".
3. **Listini non assegnati** non compaiono come righe vuote. L'utente li aggiunge esplicitamente.
4. **Sezione chiusa di default** per prodotti semplici (1 solo listino, no scaglioni). Si apre automaticamente se ci sono 2+ listini o scaglioni configurati.
5. Il **prezzo base nel form principale** è sempre sincronizzato col prezzo del Listino Base nella tabella (non duplicato a livello dati, solo di presentazione).

### Flusso operativo tipico

**Caso 1 — Prodotto semplice (nuovo inserimento):**
Operatore inserisce il codice, la descrizione, e il prezzo nel campo `Prezzo Base`. Fine. Non tocca mai la sezione prezzi.

**Caso 2 — Prodotto con listino ingrosso:**
Operatore apre la sezione "Prezzi di Vendita", clicca "+ Aggiungi Riga", seleziona "Listino Ingrosso", inserisce il prezzo. Salva. 30 secondi.

**Caso 3 — Prodotto chimico con scaglioni (caso tipico Quack):**
La sezione si apre automaticamente. Operatore vede la matrice listini × quantità. Edita le celle direttamente. Le colonne di quantità riflettono le soglie globali configurate (es. 1, 5, 25, 100 kg/L).

### Considerazioni implementative (JS+PHP+SQLite)

**Struttura dati suggerita:**

```sql
-- Tabella delle assegnazioni prezzo prodotto-listino
CREATE TABLE prezzi_prodotto (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  prodotto_id INTEGER NOT NULL REFERENCES prodotti(id),
  listino_id INTEGER NOT NULL REFERENCES listini(id),
  qty_da DECIMAL(10,3) NOT NULL DEFAULT 0,  -- soglia quantità (0 = nessuno scaglione)
  prezzo DECIMAL(10,4) NOT NULL,
  valuta TEXT NOT NULL DEFAULT 'EUR',
  data_inizio DATE,
  data_fine DATE,
  UNIQUE(prodotto_id, listino_id, qty_da)
);
```

**API endpoint:**
- `GET /api/prodotti/:id/prezzi` → restituisce la matrice per la scheda prodotto
- `PUT /api/prodotti/:id/prezzi` → salva batch di modifiche (array di righe)

**Frontend:**
- La sezione prezzi si carica lazy (via fetch) solo quando l'utente apre l'accordion, evitando di pesare sul caricamento della scheda per prodotti semplici.
- L'editing inline usa un pattern "click-to-edit" con un `<input>` che sostituisce il testo al click, e `blur` o `Enter` per confermare (con salvataggio ottimistico + debounce).

---

## 5. Raccomandazione finale

**Adottare il pattern "Progressive Disclosure con Matrice Lazy-Load".**

È il pattern che meglio bilancia i tre obiettivi. Il prezzo base è sempre accessibile con zero attrito (come SAP B1 e Business Central). La complessità — listini multipli e scaglioni — è disponibile inline nella stessa scheda (come NetSuite) ma non ingombra la vista di default (come Business Central). La matrice è visibile solo quando serve e si espande solo su richiesta esplicita.

Per Quack specificamente, dove i prodotti chimici ADR hanno spesso pricing a scaglione per quantità fisiche (kg, L, cisterne), la colonna di quantità nella matrice è particolarmente utile e motivata — ma va mostrata solo per i prodotti che la usano, non per tutto il catalogo.

---

## Fonti

- [NetSuite - Pricing Sublist / Pricing Matrix (Oracle Docs)](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_1502207768.html)
- [NetSuite - Working with the Pricing Sublist on Item Records](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_159531460854.html)
- [NetSuite - Item Pricing Setup Guide (VNMT Solutions)](https://www.vnmtsolutions.com/item-pricing-setup-in-netsuite/)
- [SAP Business One - Item Master Data (SAP Help)](https://help.sap.com/docs/SAP_BUSINESS_ONE/68a2e87fb29941b5bf959a184d9c6727/45221d4673c80108e10000000a114a6b.html)
- [SAP Business One - Price Lists (SAP Help)](https://help.sap.com/docs/SAP_BUSINESS_ONE/68a2e87fb29941b5bf959a184d9c6727/452365399e152b31e10000000a1553f7.html)
- [Business Central - Set up prices and discounts (Microsoft Learn)](https://learn.microsoft.com/en-us/dynamics365/business-central/across-prices-and-discounts)
- [Business Central - Designing card pages (Microsoft Learn)](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-designing-card-pages)
- [The New Sales Pricing Experience in Business Central (ERP Software Blog, 2024)](https://erpsoftwareblog.com/2024/07/the-new-sales-pricing-experience-in-business-central/)
- [Enhancing Business Central's New Sales Pricing Experience (miljan.rs, 2024)](https://miljan.rs/2024/11/13/enhancing-business-centrals-new-sales-pricing-experience/)
- [Odoo - Pricelists, discounts, and formulas (Odoo 17 Docs)](https://www.odoo.com/documentation/17.0/applications/sales/sales/products_prices/prices/pricing.html)
- [Odoo Display Pricelist Price On Products (WebKul)](https://store.webkul.com/Odoo-Display-Pricelist-Price-On-Products.html)
