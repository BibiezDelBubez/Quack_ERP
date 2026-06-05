# Alignment of RFQ & Sales Quotes UI and Autocomplete Clipping Fix

Questo piano descrive le modifiche per rendere coerente il design tra la tabella delle RFQ (acquisti) e quella dei Preventivi di Vendita (sales quotes), e per risolvere il problema del clipping (taglio) e dello scrolling forzato del dropdown di autocompletamento dei prodotti nelle righe delle tabelle.

## User Review Required

> [!IMPORTANT]
> **Coerenza dei Controlli e Uniformità Grafica**:
> - Rimuoveremo lo stile CSS inline custom di `app/views/sales/quotes/_form.php` e uniformeremo le tabelle usando la classe `.qk-table`.
> - Sostituiremo la classe custom `.riga-input` dei preventivi con la classe standard `.qk-form-control` per ereditare lo stile di input borderless e pulito delle tabelle ERP di Quack, già definito centralmente.
> - Uniformeremo la navigazione a schede (Tabs) del preventivo per usare la classe premium `.qk-nav-tabs` già definita centralmente, rimuovendo gli stili inline custom.

> [!TIP]
> **Risoluzione Autocomplete Dropdown Clipping**:
> - Imposteremo `.qk-table-wrap` su `overflow: visible !important;` per larghezze schermo desktop (`@media (min-width: 768px)`), consentendo al dropdown assoluto `.qk-ac-dropdown` / `.qk-m2o-dropdown` di fluttuare liberamente sopra la tabella e oltre i bordi senza ritagliare le righe o costringere a scorrere verticalmente.
> - Centralizzeremo e renderemo premium lo stile grafico di `.qk-ac-dropdown` e `.qk-ac-item` direttamente in `assets/css/app.css`, garantendo un hover gradevole ed evidente anche su RFQ.

## Proposed Changes

### Stylesheet

#### [MODIFY] [app.css](file:///c:/Users/miche/OneDrive/Documenti/xampp/htdocs/Quack/assets/css/app.css)
- Centralizzare e standardizzare lo stile dell'autocomplete dropdown (`.qk-ac-wrap`, `.qk-ac-dropdown`, `.qk-ac-item`, `.qk-ac-create`).
- Rilasciare i vincoli di `overflow` per `.qk-table-wrap` a partire da 768px di larghezza per evitare il ritaglio dei dropdown.
- Unificare lo stile di allineamento a destra degli input numerici per le classi comuni delle righe delle tabelle.

### Customer Quotes View

#### [MODIFY] [_form.php (Sales Quotes)](file:///c:/Users/miche/OneDrive/Documenti/xampp/htdocs/Quack/app/views/sales/quotes/_form.php)
- Rimuovere gli stili inline ridondanti per l'autocomplete e le tabelle.
- Aggiungere la classe `.qk-table` al tag table `#tbl-righe`.
- Cambiare tutte le occorrenze di `.riga-input` in `.qk-form-control` (nella generazione dinamica HTML in JS).
- Convertire la struttura dei bottoni dei tab per utilizzare la classe standard `.qk-nav-tabs` e la struttura con `.nav-item` per uniformare l'interfaccia a quella di RFQ.
- Allineare lo stile del pulsante di eliminazione riga (trash can) usando lo stile standard di Quack.

### RFQ View

#### [MODIFY] [_form.php (RFQ)](file:///c:/Users/miche/OneDrive/Documenti/xampp/htdocs/Quack/app/views/purchases/rfq/_form.php)
- Assicurarsi che utilizzi il medesimo stile premium del dropdown autocompletamento.
- Verificare che la struttura della tabella sia fluida e non soffra di ritagli.

---

## Verification Plan

### Manual Verification
1. Aprire la modifica/creazione di un Preventivo di Vendita e di una RFQ. Verificare che l'aspetto visivo delle tabelle (spaziatura, bordi, allineamento, colori di hover) sia identico e coerente.
2. Cliccare sulla casella di ricerca del prodotto in una nuova riga e digitare una lettera: verificare che la lista dei suggerimenti appaia fluida, si sovrapponga a tutti i componenti sottostanti senza tagliare il layout e senza costringere a scrollare la tabella.
3. Selezionare i tab ed accertarsi che abbiano lo stesso stile visivo coerente (underline verde del tema attivo).
