# 00 — Best Practice di Mercato (Multi-UoM e Pricing)

> **Questo file è materiale comparativo/ispirativo, non normativo.** Descrive come i grandi ERP enterprise (SAP, Oracle Cloud SCM, Microsoft Dynamics 365, Odoo, NetSuite) affrontano in astratto la gestione di unità di misura multiple e prezzi di listino. **Non descrive l'architettura reale di Quack.** Per l'architettura reale, il flusso operativo e lo stato di implementazione, vedi [01 - Ciclo Multi-UoM e Prezzi - Guida Completa.md](01%20-%20Ciclo%20Multi-UoM%20e%20Prezzi%20-%20Guida%20Completa.md). Per lo schema tecnico e i riferimenti a file/funzioni reali, vedi [02 - Dettaglio Tecnico - Schema DB e Codice.md](02%20-%20Dettaglio%20Tecnico%20-%20Schema%20DB%20e%20Codice.md).

---

## 1. La filosofia cardine degli ERP enterprise

Se si tenta di calcolare dinamicamente quantità storiche e prezzi basandosi *unicamente* sull'anagrafica prodotto corrente, un ERP fallisce al primo cambio di packaging o di densità. I sistemi di classe mondiale si basano su tre pilastri:

### A. Immutabilità transazionale (Transactional Snapshotting)

Quando un documento (preventivo, ordine, fattura) viene confermato, **tutti i fattori di conversione, i prezzi unitari e le UoM attive in quel momento vengono copiati (snapshottati) direttamente nelle righe del documento**. Se l'anagrafica prodotto cambia domani, i documenti passati restano matematicamente identici e intatti.

### B. Base UoM (Stock UoM)

La **Base UoM** (in SAP: *Base Unit of Measure*, BUoM) è l'unità in cui viene tenuto il saldo di magazzino, ed è immutabile una volta avvenuti movimenti fisici. Tutte le transazioni in unità alternative vengono convertite e salvate anche nella Base UoM per aggiornare i saldi di inventario.

### C. Native UoM Pricing (mantenimento delle UoM native nei listini)

I listini prezzi **non** vengono forzatamente convertiti in Base UoM nel database. Se un fornitore vende un prodotto a `12.50 €/kg`, il listino memorizza esattamente `prezzo = 12.50`, `uom = KG`. La conversione in unità di stock avviene solo durante la transazione o la visualizzazione.

---

## 2. Come i leader di mercato gestiscono le UoM

### SAP ERP (ECC & S/4HANA)

- **Tabella Master `MARM`**: gestisce le unità alternative con coefficienti **frazionari** (Numeratore/Denominatore) rispetto alla Base UoM, invece di un singolo float — evita errori di arrotondamento su numeri periodici (es. `1/3 = 0.3333...`).
- **Pricing Unit (`KPEIN`)**: permette di definire prezzi per "quantità di prezzo" (es. `150 € per 100 kg`), utile per importi decimali microscopici per unità singola.

### Microsoft Dynamics 365

- **Classi di conversione**: *Intra-class* (conversioni standard fisse, es. g→kg) vs *Inter-class* (conversioni specifiche per prodotto, es. L→kg per un acido con densità nota).
- **Catch Weight (doppia misura)**: pattern comune nel settore chimico/alimentare — traccia contemporaneamente l'inventario "a pezzi" (es. 5 fusti) e il peso reale effettivo pesato al carico/scarico (es. 1023 kg), fatturando sul peso realmente rilevato invece che su quello teorico. **Nota per Quack**: questo pattern non è implementato — Quack ha deciso (13/07/2026) di considerare per ora i propri prodotti come non deperibili e a peso stabile, quindi non ne ha bisogno. Se la situazione cambia, la proposta di implementazione è tracciata in `docs/Backlog Sviluppi Futuri.md`, voce 7.

---

## 3. Tre approcci UX per il pricing sulle righe documento

Analisi comparativa di tre pattern di interazione per il calcolo del prezzo su una riga di preventivo/ordine.

### Approccio A — Calcolo continuo in tempo reale (Odoo / SAP)

Il sistema ricalcola il prezzo a ogni variazione di quantità/UoM/testata, a meno che la riga non sia bloccata in modalità manuale.

- **Pregi**: massima accuratezza, applica sempre scaglioni e campagne corrette.
- **Difetti**: rischia di sovrascrivere prezzi concordati manualmente se non rileva l'override; più chiamate di rete.
- **UX richiesta**: il campo prezzo deve poter passare a stato "Override/Forzato" quando l'utente digita, con un'icona di reset per tornare al prezzo di listino.

### Approccio B — Suggerimento solo all'inserimento

Il prezzo viene recuperato solo quando il prodotto è aggiunto alla riga; poi la riga si scollega dal listino.

- **Pregi**: controllo totale, nessun rischio di sovrascritture accidentali.
- **Difetti**: non applica mai automaticamente sconti quantità successivi; un cambio di listino in testata non aggiorna le righe già inserite.
- **UX richiesta**: pulsante esplicito "Ricalcola prezzi in base al listino corrente".

### Approccio C — Spiegazione dell'origine del prezzo (UX tracciabile)

L'interfaccia evidenzia graficamente l'origine e la regola che ha determinato il prezzo mostrato.

- **Pregi**: trasparenza totale, debug immediato di date/scaglioni mal configurati.
- **Difetti**: appesantisce la UI; richiede che l'API restituisca anche la stringa descrittiva della sorgente, non solo il numero.
- **UX richiesta**: icona informativa accanto al prezzo, tooltip/popover con la provenienza (es. *"Prezzo da: Listino Grossisti 2026 — Scaglione ≥ 100 pz"*).

**Quack adotta principalmente l'Approccio C** (badge origine prezzo, cascata di fallback tracciata) con elementi dell'Approccio A per il ricalcolo automatico — vedi 01, sezione sul motore di pricing.

---

## 4. Schema SQL generico di ispirazione (NON lo schema reale di Quack)

> ⚠️ **Avviso**: questo schema è un esempio illustrativo generico, in inglese, con nomi di tabella (`uom_registry`, `products`, `price_lists`, `sales_order_lines`) che **non corrispondono** alla nomenclatura reale di Quack. In particolare, la tabella `product_uom_conversions` sotto rappresenta un'architettura N-a-N (un prodotto con più UoM commerciali alternative contemporaneamente) che per Quack è **solo un'ipotesi di roadmap futura, mai implementata** (Quack usa l'Approccio 1: una sola UoM commerciale per prodotto — vedi 01, sezione 1). Per lo schema reale di Quack, vedi il documento 02.

```sql
-- 1. REGISTRO UNITÀ DI MISURA GLOBALE
CREATE TABLE uom_registry (
    uom_code VARCHAR(10) PRIMARY KEY,
    uom_name VARCHAR(50) NOT NULL,
    uom_type ENUM('COUNT', 'WEIGHT', 'VOLUME') NOT NULL,
    is_active TINYINT(1) DEFAULT 1 NOT NULL
);

-- 2. ANAGRAFICA PRODOTTI
CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) UNIQUE NOT NULL,
    stock_uom_code VARCHAR(10) NOT NULL, -- Base UoM, immutabile se ha movimenti
    FOREIGN KEY (stock_uom_code) REFERENCES uom_registry(uom_code)
);

-- 3. CONVERSIONI N-A-N PER PRODOTTO (roadmap futura, NON implementata in Quack)
-- 1 Unità Alternativa = (num_factor / den_factor) * Unità di Stock
CREATE TABLE product_uom_conversions (
    product_id INT NOT NULL,
    alt_uom_code VARCHAR(10) NOT NULL,
    num_factor DECIMAL(18,9) NOT NULL DEFAULT 1,
    den_factor DECIMAL(18,9) NOT NULL DEFAULT 1,
    PRIMARY KEY (product_id, alt_uom_code)
);

-- 4. LISTINI PREZZI (mantengono la UoM nativa di negoziazione)
CREATE TABLE price_lists (
    price_list_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    price_type ENUM('PURCHASE', 'SALES', 'RRP') NOT NULL,
    price_value DECIMAL(18,4) NOT NULL,
    price_uom_code VARCHAR(10) NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE
);

-- 5. RIGHE ORDINE (congelano lo snapshot al momento del salvataggio)
CREATE TABLE sales_order_lines (
    line_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    ordered_qty DECIMAL(18,4) NOT NULL,
    ordered_uom_code VARCHAR(10) NOT NULL,
    input_unit_price DECIMAL(18,4) NOT NULL,
    num_factor_snapshot DECIMAL(18,9) NOT NULL,
    den_factor_snapshot DECIMAL(18,9) NOT NULL,
    stock_qty DECIMAL(18,4) NOT NULL,
    system_unit_price_stock DECIMAL(18,4) NOT NULL
);
```

### Logica di calcolo matematico (frazioni vs float)

Per evitare che `0.3333...` si perda in database, la relazione si definisce come frazione:

$$\text{1 Unità Commerciale} = \left(\frac{\text{Numeratore}}{\text{Denominatore}}\right) \times \text{Unità di Stock}$$

Esempio: Stock = FUSTO, Commerciale = KG, 1 fusto = 25 kg → `1 KG = 1/25 FUSTO` → `num_factor=1, den_factor=25`.

- Conversione quantità (commerciale → stock): `Q_stock = Q_comm × (num/den)`
- Conversione prezzo (commerciale → stock): `P_stock = P_comm × (den/num)`

**Nota**: questa è la stessa tecnica realmente adottata da Quack (vedi 02, "Convenzione num_factor/den_factor"), inclusa la correzione del 14/07/2026 sul verso della frazione.

---

## 5. Matrice generica dei casi limite (visione di mercato, non Quack-specifica)

| Caso limite | Cosa succede senza soluzione | Soluzione best-practice generica |
|---|---|---|
| Cambio Base UoM dopo movimenti | Sballa storico magazzino e costo medio ponderato | Divieto assoluto, permanente dal primo movimento |
| UoM commerciale mai inserita | Rischio divisione per zero | Transazioni permesse solo in Stock UoM |
| Cambio fattore di conversione | Documenti storici a rischio | Snapshot per riga documento, mai ricalcolo retroattivo |
| Eliminazione di una UoM dal registro | Riferimenti rotti | Soft-delete (`is_active=0`), mai cancellazione fisica se referenziata |
| Discrepanze di arrotondamento (`0.333...`) | Derive cumulative sullo stock | Coefficienti frazionari interi (num/den), mai float singolo |

Questa matrice generica è stata **sostituita, per Quack**, da un'unica matrice verificata contro il codice reale — vedi 01, sezione "Matrice dei casi limite (stato reale)".

---

## Nota di provenienza

Questo file consolida il materiale di ricerca comparativa originariamente in `Architettura, Analisi e Best Practice Multi-UoM e Listini Prezzi - Versione Definitiva.md` (PARTE 1, §1-4) e in `Listini nella formulazione offerte.md` (§2, Approcci A/B/C). Il codice di esempio generico che accompagnava questo materiale (classi `UomConverter`/`OrderProcessor`) **non è stato riportato qui**: è stato verificato (vedi registro storico in `REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md`, punto C6) che divergeva da tre comportamenti reali di Quack (arrotondamento, gestione errori, separazione calcolo/persistenza) e rischiava di essere copiato per errore come riferimento — è stato quindi cancellato, non spostato. Per il codice reale, vedi il documento 02.
