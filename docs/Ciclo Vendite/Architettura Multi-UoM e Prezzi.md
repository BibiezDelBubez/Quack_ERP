# Architettura di Riferimento: Gestione Multi-UoM e Listini Prezzi Enterprise (Quack 2026)

Questo documento definisce le specifiche funzionali e tecniche dell'architettura multi-UoM e della gestione dei prezzi di listino/consigliati (RRP) per l'ERP Quack. L'obiettivo è garantire la massima flessibilità commerciale (acquisto/vendita in unità libere come KG, Litri, Lattine) preservando l'integrità assoluta della logistica (giacenze caricate sempre nella Base UoM) e la storicità contrattuale.

---

## 1. I Tre Pilastri Architetturali dell'ERP 2026

```
                  ┌──────────────────────────────────────────────┐
                  │          ANAGRAFICA PRODOTTO                 │
                  │  - Stock UoM (es. FUSTO)                     │
                  │  - Content UoM (es. KG)                      │
                  │  - Fattore Conversione (es. 25.000000000)   │
                  └──────────────────────┬───────────────────────┘
                                         │
                 ┌───────────────────────┴───────────────────────┐
                 │                                               │
┌────────────────▼──────────────────────────────┐ ┌──────────────▼──────────────────────────────┐
│        LISTINI PREZZI (VENDITA / ACQUISTI)    │ │        DOCUMENTI TRANSAZIONALI              │
│  - Prezzo negoziato (es. 10.00 €)             │ │  - ordered_qty & ordered_uom (es. 100 kg)   │
│  - UoM nativa di negoziazione (es. KG)         │ │  - input_unit_price (es. 10.00 €/kg)        │
│  (Flessibile alle variazioni di packaging)     │ │  - snapshot_factor (es. 25.000000000)       │
└───────────────────────────────────────────────┘ │  - stock_qty & stock_uom (es. 4 fusti)      │
                                                  │  (Congelati storicamente al salvataggio)    │
                                                  └──────────────────────────────────────────────┘
```

### A. Immutabilità Transazionale (Transactional Snapshotting)
Quando un documento (preventivo, ordine di vendita, richiesta di offerta, ordine di acquisto, bolla di ricezione) viene confermato o salvato, **i fattori di conversione e i prezzi in UoM di stock attivi in quel preciso istante vengono copiati e congelati direttamente nella riga del documento**.
*   *Rationale*: Se un domani il packaging di un prodotto cambia (es. da taniche da 25 kg a taniche da 20 kg), i documenti storici non devono subire ricalcoli logistici o finanziari. Lo storico rimane integro e matematicamente congelato.

### B. UoM di Stock (Base UoM) Unica per Prodotto
Il magazzino (giacenze, lotti, movimenti, inventari) ragiona **esclusivamente in UoM di Stock**.
*   *Rationale*: Evita la frammentazione fisica delle giacenze. Non è consentito avere 10 kg e 2 taniche dello stesso prodotto come record di magazzino separati. Tutto viene convertito in unità di stock prima di registrare qualunque movimento fisico.

### C. Prezzi in UoM Nativa di Negoziazione
I listini di vendita (`listini_prezzi`), i prezzi di acquisto del fornitore (`prodotti_fornitori_prezzi`) e i prezzi consigliati (`rrp` in `prodotti_fornitori`) memorizzano il valore del prezzo associato alla **UoM nativa con cui è stato contrattualizzato**.
*   *Rationale*: Se compri o vendi a peso (`10 €/kg`), il prezzo a database deve essere salvato come `10` con UoM `KG`. Se il packaging del fusto (Stock UoM) passa da 25 kg a 20 kg, il prezzo del fusto si adeguerà automaticamente da `250 €/fusto` a `200 €/fusto` senza dover aggiornare manualmente le tabelle dei listini.

---

## 2. Modello Matematico ad Alta Precisione

Per prevenire errori cumulativi di arrotondamento sui numeri periodici (es. $1/3 = 0.33333333$), l'ERP definisce la relazione tra le UoM attraverso un fattore di conversione a virgola fissa ad alta precisione.

### Relazione tra UoM Commerciale (Contenuto) e UoM di Stock
Definiamo:
*   $Q_{comm}$: Quantità inserita dall'utente in UoM commerciale.
*   $P_{comm}$: Prezzo inserito dall'utente riferito alla UoM commerciale.
*   $F_{conv}$: Fattore di conversione (quantità commerciale contenuta in 1 unità di stock, es. 25.000000000 kg/fusto).
*   $Q_{stock}$: Quantità equivalente in UoM di stock.
*   $P_{stock}$: Prezzo equivalente riferito alla UoM di stock.

#### Formule di Conversione (Backend & Frontend)

$$\text{Quantità Stock } (Q_{stock}) = \frac{Q_{comm}}{F_{conv}}$$

$$\text{Prezzo Stock } (P_{stock}) = P_{comm} \times F_{conv}$$

*Esempio*:
*   Stock UoM = `FUSTO`, Commercial UoM = `KG`, $F_{conv} = 25$
*   Utente ordina $100\text{ KG}$ a $10.00\text{ €/KG}$.
*   $Q_{stock} = 100 / 25 = 4\text{ Fusti}$
*   $P_{stock} = 10.00 \times 25 = 250.00\text{ €/Fusto}$
*   Totale Imponibile Riga = $Q_{comm} \times P_{comm} = 100 \times 10.00 = 1000.00\text{ €}$
*   Ricalcolo di Controllo = $Q_{stock} \times P_{stock} = 4 \times 250.00 = 1000.00\text{ €}$ (Nessuna discrepanza).

---

## 3. Matrice Completa dei Casi Limite ed Eccezioni

| Caso Limite / Eccezione | Comportamento del Sistema | Rationale Tecnico |
| :--- | :--- | :--- |
| **1. Modifica della Stock UoM post-movimenti** | **Vietata.** Il campo viene disabilitato in UI e bloccato in Backend. | Se modificata, corromperebbe lo storico dei movimenti di magazzino e il costo medio ponderato. |
| **2. Modifica della Stock UoM senza movimenti** | **Consentita.** Il sistema aggiorna i preventivi/ordini in stato "bozza" associati. | Poiché non ci sono transazioni fisiche a magazzino, è possibile allineare le bozze commerciali. |
| **3. UoM Commerciale non definita** | La UI consente solo la selezione della UoM di Stock. | Evita divisioni per zero o configurazioni invalide. |
| **4. Modifica del fattore di conversione ($F_{conv}$)** | I listini storici continuano a funzionare con il nuovo fattore per le nuove transazioni. I vecchi ordini salvati rimangono intatti. | Lo snapshot `quantita_contenuto_snapshot` sulle righe passate isola lo storico dalle modifiche anagrafiche future. |
| **5. Prezzo consigliato (RRP) multi-UoM** | Il venditore inserisce l'RRP specificando la UoM (es. `200 €/fusto` o `8 €/kg`). | Consente il confronto coerente tra prezzi consigliati dai fornitori e listini interni sia a peso che a pezzo. |

---

## 4. Elenco di Dettaglio dei Cambiamenti Necessari (Codice e Schema)

### A. Modifiche al Database (Migrazioni SQLite / MySQL)

#### 1. Tabella `listini_prezzi` (Vendite)
Aggiungere il riferimento alla UoM per memorizzare il prezzo nella sua unità nativa.
```sql
ALTER TABLE listini_prezzi ADD COLUMN unita_misura_id INTEGER REFERENCES unita_misura(id);
-- Migrazione conservativa dei dati esistenti:
-- Imposta come default la UoM di stock del prodotto per tutti i listini già salvati.
UPDATE listini_prezzi 
SET unita_misura_id = (SELECT unita_misura_stock_id FROM prodotti WHERE prodotti.id = listini_prezzi.prodotto_id);
```

#### 2. Tabella `prodotti_fornitori_prezzi` (Acquisti)
Aggiungere il riferimento alla UoM per memorizzare il prezzo di acquisto negoziato.
```sql
ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN unita_misura_id INTEGER REFERENCES unita_misura(id);
-- Migrazione conservativa:
UPDATE prodotti_fornitori_prezzi 
SET unita_misura_id = (
    SELECT unita_misura_stock_id 
    FROM prodotti 
    JOIN prodotti_fornitori pf ON pf.prodotto_id = prodotti.id 
    WHERE pf.id = prodotti_fornitori_prezzi.prodotto_fornitore_id
);
```

#### 3. Tabella `prodotti_fornitori` (RRP Fornitore)
Aggiungere la colonna per definire la UoM a cui si riferiscono `rrp_min` e `rrp_max`.
```sql
ALTER TABLE prodotti_fornitori ADD COLUMN rrp_unita_misura_id INTEGER REFERENCES unita_misura(id);
-- Migrazione conservativa:
UPDATE prodotti_fornitori 
SET rrp_unita_misura_id = (SELECT unita_misura_stock_id FROM prodotti WHERE prodotti.id = prodotti_fornitori.prodotto_id);
```

#### 4. Tabelle Transazionali (Righe Documenti)
Assicurarsi che le tabelle `preventivi_righe`, `ordini_clienti_righe`, `ordini_acquisto_righe`, `ricevimento_merce_righe` contengano i campi per congelare lo snapshot del fattore di conversione:
*   `quantita_contenuto_snapshot DECIMAL(10,3)`
*   `quantita_stock DECIMAL(10,3)`
*   `unita_misura_stock_id INTEGER REFERENCES unita_misura(id)`

---

### B. Backend PHP (Logica Applicativa)

#### 1. Inibizione Cambio UoM Stock post-movimentazione (`app/Controllers/ProductsController.php`)
Prima di salvare le modifiche del prodotto, controllare se esistono record in `movimenti_magazzino` per il prodotto selezionato:
```php
$stmtMovimenti = $pdo->prepare("SELECT COUNT(*) FROM movimenti_magazzino WHERE prodotto_id = ?");
$stmtMovimenti->execute([$id]);
$haMovimenti = (int)$stmtMovimenti->fetchColumn() > 0;

if ($haMovimenti && (int)$nuovaUomStockId !== (int)$vecchiaUomStockId) {
    // Rifiuta il salvataggio con un messaggio d'errore chiaro
}
```

#### 2. Salvataggio Righe Documenti con Snapshot (`includes/sales_quotes.php`, `purchases_orders.php`, etc.)
In fase di inserimento/aggiornamento riga:
*   Salvare `quantita_contenuto_snapshot` leggendo il valore corrente di `quantita_contenuto` da `prodotti`.
*   Calcolare `quantita_stock` usando BCMath per evitare imprecisioni decimali float:
```php
// Esempio logica in PHP per il calcolo di quantita_stock
$factor = qk_prodotto_get_conversion_factor($pdo, $prodotto_id); // Ritorna quantita_contenuto
if ($um_riga_id === $um_stock_id) {
    $quantita_stock = $quantita_riga;
} elseif ($um_riga_id === $um_commerciale_id && $factor > 0) {
    // Q_stock = Q_comm / F_conv
    $quantita_stock = (float)bcdiv((string)$quantita_riga, (string)$factor, 3);
}
```

---

### C. Frontend JavaScript e Viste

#### 1. Conversione Dinamica UI (`assets/js/app.js`)
Aggiornare `window.qkConvertUom` per supportare in modo sicuro conversioni bidirezionali di prezzo e quantità, inibendo il calcolo se il fattore di conversione non è valido (pari a 1 o non impostato).

#### 2. Selettore UoM su Maschera Preventivo / Ordine
Il selettore dell'UoM a livello di riga deve proporre solo le unità coerenti con il prodotto (Stock UoM e Commercial UoM se presente), popolando i metadati corretti nel dataset HTML della riga.

#### 3. Maschera Listino Vendite (`app/views/sales/pricelists/_form.php`)
Aggiungere la colonna "Unità di Misura del Prezzo" per consentire all'utente di definire se il prezzo inserito è relativo a KG o PZ (Stock).
