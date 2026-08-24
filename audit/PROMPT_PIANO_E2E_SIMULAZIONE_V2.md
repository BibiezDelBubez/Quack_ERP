# PROMPT PER L'AGGIORNAMENTO ED AMPLIAMENTO DEL PIANO E2E SIMULAZIONE

Copia ed incolla il seguente prompt per avviare l'aggiornamento completo del piano di simulazione automatizzata E2E via browser:

```markdown
# PROMPT OPERATIVO: SISTEMA DI SIMULAZIONE E2E INCREMENTALE VIA BROWSER CON CRUD TOTALE (QUACK ERP)

Sei incaricato di aggiornare, riprogettare ed ampliare il sistema di test automatizzato End-to-End (E2E) via browser per **Quack ERP**, basandoti sul documento `audit/Piano_E2E_Simulazione.md` e sul framework esistente in `tests/e2e/` e `run_e2e_tests.bat`.

Il sistema di test deve emulare al 100% l'esperienza utente reale via browser (utilizzando **Python 3.13 + Playwright + pytest + Faker** in `.venv`), testando **IL CICLO DI VITA CRUD COMPLETO (Create, Read, Update, Delete/Disattivazione, Duplicazione/Clonazione)** per ogni singola entità del sistema, inclusi tutti i casi limite, eccezioni, restrizioni di compliance ed immutabilità.

---

## 1. MAPPATURA LOGICA DELLE DIPENDENZE ED ORDINE INCREMENTALE DELLE ATTIVITÀ

I test devono essere strutturati secondo una rigida sequenza propedeutica (Bottom-Up). Nessun test di livello successivo può essere eseguito prima di aver popolato le entità da cui dipende:

1. **LIVELLO 0: Configurazione di Sistema, Utenti ed Aziende Interne**
   - **Aziende Interne**: CRUD completo (Creazione, visualizzazione scheda/lista, modifica dati societari/logo/IBAN, disattivazione).
   - **Impostazioni Generali**: CRUD impostazioni (soglie margine verde/giallo, aliquote IVA, numeratori sequenze).
   - **Utenti e Ruoli**: CRUD utenti (Creazione, filtro per ruolo/stato, modifica password/permessi, disattivazione utente).

2. **LIVELLO 1: Anagrafiche di Base (Soggetti ed Indirizzi)**
   - **Fornitori**: CRUD completo (Creazione, filtri di ricerca, scheda dettaglio, modifica IBAN/sedi/contatti, disattivazione).
   - **Clienti**: CRUD completo (Creazione, filtri di ricerca, scheda dettaglio, aggiunta/modifica sedi legali ed indirizzi di consegna multipli, gestione fido e condizioni di pagamento, disattivazione).
   - *Regola*: le anagrafiche dei fornitori e clienti devono precedere i prodotti ed i listini per poter associare SKU fornitore, costi e listini dedicati.

3. **LIVELLO 2: Categorie, Catalogo Prodotti & Listini**
   - **Categorie Prodotti**: CRUD completo (Creazione categorie padre/figlio, modifica nome/genitore, eliminazione con blocco se ci sono prodotti associati).
   - **Prodotti**: CRUD completo (Creazione con Stock UoM, UoM commerciale, conversioni, flag lotti, restrizioni chimiche/compliance, modifica parametri, disattivazione prodotto).
   - **Listini Prezzi d'Acquisto**: CRUD completo (Creazione listino fornitore, aggiunta/modifica/eliminazione righe scaglioni di quantità, disattivazione listino).
   - **Listini Prezzi di Vendita**: CRUD completo (Listino Base #1, listini clienti dedicati, aggiunta/modifica/eliminazione righe scaglioni e sconti, verifica ereditarietà cascading NetSuite).

4. **LIVELLO 3: Ciclo Acquisti (Procurement & Ricezioni Magazzino)**
   - **RFQ (Request for Quotation)**: CRUD completo (Creazione bozza, aggiunta/rimozione righe, modifica, invio, registrazione offerta fornitore, accettazione -> conversione in Ordine d'Acquisto, annullamento/cancellazione bozza).
   - **Ordini d'Acquisto**: CRUD completo (Creazione diretta o da RFQ, modifica bozza, conferma ordine, ricezione merce totale o parziale con creazione automatica lotti, gestione discrepanze, annullamento ordine).

5. **LIVELLO 4: Gestione Magazzino, Giacenze e Movimentazioni Interne**
   - **Inventari di Magazzino**: CRUD completo (Creazione conteggio fisico, inserimento giacenze riscontrate, modifica bozza, verifica blocco movimentazioni durante inventario aperto, conferma e chiusura con rettifica automatica stock).
   - **Trasferimenti Interni**: CRUD completo (Creazione trasferimento da magazzino origine a destinazione, modifica bozza, conferma trasferimento con scarico/carico automatizzato, annullamento con ripristino stock).
   - **Tracciabilità Lotti**: Conversione prodotti `0 -> 1` con gestione lotti e scadenze.

6. **LIVELLO 5: Ciclo Vendite (Offerte, Trattative ed Ordini Cliente)**
   - **Preventivi di Vendita**: CRUD completo (Creazione bozza, aggiunta/rimozione righe, verifica prezzi automatici `fa-tag` blu, modifica manuale prezzo con penna gialla `fa-pen-to-square`, **Duplicazione/Clonazione del preventivo**, invio, rifiuto/scadenza, accettazione -> conversione in Ordine Cliente, annullamento/cancellazione bozza).
   - **Ordini Cliente**: CRUD completo (Creazione diretta o da Preventivo, modifica bozza, conferma ordine con riserva stock, gestione avviso vendita allo scoperto, annullamento ordine con rilascio riserva stock).

7. **LIVELLO 6: Evasione, Spedizioni e DDT (Documenti di Trasporto)**
   - **Spedizioni e DDT**: CRUD completo (Generazione Spedizione da Ordine Cliente, selezione righe/quantità/lotti FEFO, creazione DDT in bozza, modifica dati vettore/causale, conferma DDT con scarico effettivo giacenze/lotti, anteprima/stampa PDF DDT, annullamento DDT con ripristino giacenze e lotti, gestione evasione parziale e completamento ordine).

*(Nota: La gestione ADR è esplicitamente ESCLUSA da questo piano di test)*.

---

## 2. REGOLE RIGOROSE DI TEST SU OGNI OPERAZIONE CRUD

Per **OGNI SINGOLA ENTITÀ E MODULO** elencato sopra, la suite di test deve verificare programmaticamente l'intero ciclo CRUD:

1. **CREATE (Inserimento)**:
   - Compilazione form via browser con dati validi generati da `Faker`.
   - Verifica salvataggio e presenza del messaggio di successo/toast notification.
   - Test di errore su campi obbligatori mancanti (`is-invalid`).

2. **READ (Ricerca e Visualizzazione)**:
   - Ricerca dell'entità creata tramite filtri di testo, stato e pagination.
   - Verifica apertura scheda di dettaglio e corrispondenza di tutti i dati salvati.
   - Test ricerca `QkM2O`: 1 carattere (chiusura dropdown) vs 2+ caratteri (selezione opzione `.qk-m2o-option`).

3. **UPDATE (Modifica)**:
   - Modifica dei campi dell'entità e salvataggio.
   - Verifica aggiornamento effettivo nel DOM e nel backend.
   - **Test di Immutabilità**: verifica che la modifica sia bloccata per entità in stato definitivo (`inviato`, `accettato`, `confermato`, `completato`).

4. **DELETE / DISATTIVAZIONE**:
   - Cancellazione di entità in stato bozza.
   - Disattivazione/Soft-delete per entità master (Clienti, Fornitori, Prodotti).
   - **Test di Vincolo di Integrità**: verifica che l'eliminazione sia bloccata se l'entità ha relazioni attive (es. tentativo di eliminare una categoria con prodotti associati o un cliente con ordini attivi).

5. **DUPLICAZIONE / CLONAZIONE** (laddove prevista, es. Preventivi):
   - Clonazione del documento ed esecuzione verifica della nuova bozza generata.

---

## 3. ARCHITETTURA DI AUTOMAZIONE E REGOLE DI ESECUZIONE BROWSER

1. **Browser Reale Obbligatorio (Playwright)**:
   - Tutti i test devono interagire esclusivamente con il DOM del browser visibile (o headless), simulando click del mouse, digitazione da tastiera, scroll al centro (`scrollIntoView({block: 'center'})`) e gestione dei dropdown `QkM2O` e modali Bootstrap.
   - È vietato considerare superato un test solo dall'assenza di crash PHP o dall'Exit Code CLI: il test deve verificare l'effettiva presenza degli elementi HTML nel DOM (es. righe `<tr>` create in `#tbody-righe`).

2. **Gestione del Logging Scoperte (`audit/scoperte_simulazione.json`)**:
   - Qualsiasi bug, discrepanza visiva, errore JS in console o eccezione HTTP riscontrata durante la simulazione deve essere salvata strutturata in `audit/scoperte_simulazione.json` con: `modulo`, `url`, `test_case`, `operazione_crud`, `errore_dettaglio`, `timestamp`.

3. **Esecuzione tramite Launcher**:
   - Mantenere e completare lo script `run_e2e_tests.bat` per consentire la scelta tra esecuzione visiva guidata (`--headed --slowmo=500`) o ultra-veloce in background.

---

## 4. OUTPUT RICHIESTO

1. Aggiorna ed estendi il file documentale `audit/Piano_E2E_Simulazione.md` con l'architettura completa dei 11+ moduli di test e l'elenco esplicito delle operazioni CRUD testate.
2. Genera/aggiorna le suite in `tests/e2e/` (da `test_01_aziende_interne.py` a `test_11_spedizioni_ddt.py`) applicando la copertura CRUD totale per ciascuna entità.
```
