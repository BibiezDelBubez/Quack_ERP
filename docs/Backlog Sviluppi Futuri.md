# Backlog — Sviluppi Futuri

Elenco vivo di funzionalità individuate durante il lavoro di design/implementazione ma **deliberatamente rimandate**: non sono bug, non sono lavoro dimenticato — sono estensioni riconosciute come utili, ma fuori dallo scope della implementazione corrente. Ogni voce rimanda al documento/sezione che l'ha originata, spiega di cosa si tratta e perché non è (ancora) prioritaria.

Quando una voce viene implementata, va rimossa da qui e il lavoro va tracciato nel changelog/commit come per ogni altra feature.

---

## 1. Stampa documento di trasporto per magazzini `conto_lavoro`/`conto_terzi`

**Fonte:** [Trasferimenti tra Magazzini come Documento.md](Gestione%20Magazzino%20e%20Lotti/Trasferimenti%20tra%20Magazzini%20come%20Documento.md), §7.3 punto 1.

**Di cosa si tratta.** Un magazzino `conto_lavoro` o `conto_terzi` ha un `soggetto_esterno_id` che punta a un record già censito in `clienti_fornitori` (es. "Verniciatore Rossi"). Quando si crea un trasferimento verso quel magazzino, il sistema sa già chi è il destinatario reale e dove si trova (indirizzo dell'anagrafica) — potrebbe quindi generare/stampare un documento di trasporto (DDT) con i dati del destinatario presi automaticamente dall'anagrafica, senza che l'operatore li debba reinserire a mano.

**Stato attuale.** Non esiste alcuna funzione di stampa/generazione DDT per i trasferimenti nel codice (verificato: nessun riferimento a "DDT" o "documento di trasporto" in `WarehouseController.php` o nelle view di trasferimento). Il collegamento dati (`soggetto_esterno_id` → `clienti_fornitori` → indirizzo) è già disponibile e funzionante nell'anagrafica magazzini; manca solo la generazione del documento stampabile.

**Perché è rimandato.** Non è mai stato nello scope di stima del documento sorgente (§9.3): era descritto come una capacità che il modello dati *abilita*, non come un deliverable richiesto. Prioritizzarlo richiede prima capire se Quack ha già un motore di generazione documenti/PDF riusabile (es. quello usato per DDT di vendita, se esiste) da estendere ai trasferimenti, invece di scriverne uno nuovo.

---

## 2. Consegna in più tranche (conferma arrivo parziale di un trasferimento)

**Fonte:** [Trasferimenti tra Magazzini come Documento.md](Gestione%20Magazzino%20e%20Lotti/Trasferimenti%20tra%20Magazzini%20come%20Documento.md), §4.3.

**Di cosa si tratta.** Un trasferimento può avere più righe (es. 500 kg di prodotto A + 300 kg di prodotto B). Nella realtà logistica, non è garantito che tutte le righe arrivino insieme con lo stesso camion: possono arrivare con mezzi diversi in momenti diversi (es. prodotto A oggi, prodotto B domani), oppure la stessa riga può arrivare divisa in più consegne (300 kg oggi, 200 kg domani). Oggi non c'è modo di confermare l'arrivo di *una parte* del trasferimento lasciando il resto ancora "in transito".

**Stato attuale.**
- Lo **schema** già supporta il caso: `quantita_richiesta`/`quantita_arrivata` vivono per riga (`trasferimenti_magazzino_righe`), non aggregate sulla testata — non serve alcuna modifica di tabella.
- La **logica applicativa** no: `qk_conferma_arrivo_trasferimento()` processa sempre tutte le righe del trasferimento in un'unica chiamata, e lo stato della testata è binario (`in_transito` → `completato`), senza uno stato intermedio tipo "parzialmente arrivato".
- La **UI** di conferma arrivo mostra tutte le righe in un unico form/submit; non permette di selezionarne un sottoinsieme e lasciare le altre per una sessione di conferma successiva.

**Cosa servirebbe per implementarlo:**
1. Permettere a `qk_conferma_arrivo_trasferimento()` di operare su un sottoinsieme di righe (conferma parziale), invece che su tutte contemporaneamente.
2. Decidere come rappresentare lo stato "parzialmente arrivato": un nuovo valore esplicito nell'enum `stato` di `trasferimenti_magazzino`, oppure calcolarlo al volo confrontando quante righe hanno già `quantita_arrivata` valorizzata rispetto al totale.
3. Aggiornare la UI di conferma per permettere multiple sessioni di conferma (selezione delle righe da confermare ora vs. quelle da lasciare in attesa).
4. Rivedere il comportamento di `qk_annulla_trasferimento()` per il caso "annullo un trasferimento parzialmente arrivato" — oggi gestisce solo bozza/in_transito/completato, andrebbe esteso al nuovo stato intermedio.
5. Aggiornare badge/filtri nella lista trasferimenti (`transfers_list.php`) per mostrare lo stato intermedio.

**Perché è rimandato.** Il documento sorgente lo definisce esplicitamente "un'estensione naturale, non un redesign" — accettabile così com'è finché non emerge un caso d'uso reale che lo richiede. È un lavoro non banale (va deciso lo stato intermedio e vanno rivisti annullamento/badge/filtri), ma additivo sopra il modello dati esistente, non una riscrittura.

---

## 3. "Magazzino attivo" come filtro invisibile — sostituzione con filtri/campi espliciti

**Fonte:** [Magazzino Attivo - Analisi e Alternative.md](Gestione%20Magazzino%20e%20Lotti/Magazzino%20Attivo%20-%20Analisi%20e%20Alternative.md), §6–7.

**Di cosa si tratta.** Il concetto di "magazzino attivo" di sessione (`qk_magazzino_attivo_id()`) è corretto e allineato alle best practice quando pre-compila un campo esplicito e modificabile su un documento (es. `magazzino_destinazione_id` sugli ordini d'acquisto). Non lo è quando agisce come **filtro invisibile** su schermate di lettura senza alcuna indicazione visibile della sede, o come **unico criterio** per un'azione di scrittura senza campo esplicito — pattern che nessuno dei quattro ERP di riferimento (SAP, NetSuite, Dynamics 365, Odoo) usa come unico meccanismo.

**Interventi individuati, in ordine di priorità:**
1. Campo magazzino esplicito sul form movimento manuale (`app/views/warehouse/_movement_form.php`) — oggi scrive giacenza sul magazzino attivo senza che l'operatore lo veda o lo scelga.
2. Filtro magazzino esplicito su "Proposte di Riordino" (`warehouse/reorder`) — oggi zero indicazione di sede sulla pagina, pur influenzando decisioni di acquisto.
3. Filtro magazzino esplicito su "Giacenze Magazzino" (`warehouse/stock`), con opzione "Tutti i magazzini" a somma corretta (`SUM`/`GROUP BY`, non un valore preso da una riga a caso).
4. Nome sede visibile nel KPI dashboard "Prodotti Sottoscorta".
5. (Più ampio, dopo) Campo sede esplicito su spedizioni e riserva stock ordini vendita — richiede prima introdurre il concetto di sede di evasione sugli ordini di vendita.

**Perché è rimandato.** È un intervento di UI/UX trasversale su più schermate, non una singola feature — richiede una sessione dedicata per essere fatto con lo stesso livello di attenzione (test end-to-end per ciascuna vista toccata) riservato al resto del lavoro multi-magazzino. Non richiede modifiche allo schema dati: `magazzino_id` esiste già ovunque serve.
