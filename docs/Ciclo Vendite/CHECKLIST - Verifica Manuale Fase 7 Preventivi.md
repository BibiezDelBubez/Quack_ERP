# CHECKLIST — Verifica manuale end-to-end del ciclo Preventivi (Fase 7)

> **AGGIORNAMENTO 22/07/2026**: la prima esecuzione (da un altro agente, Antigravity) si è fermata subito allo Scenario A, passo 2 — `PDOException: There is no active transaction` all'apertura di "Nuovo Preventivo". Bug reale, confermato indipendentemente e **corretto** in `includes/functions.php` (`qk_next_sequence_number()`, usata da 14 file — bloccava la creazione di QUALUNQUE documento, non solo dei preventivi). Dettagli in `audit/criticita_risolte.md` (Tappa 0.4) e `audit/scoperte_audit.md`. Va **rieseguita da capo tutta la checklist** ora che il blocco è rimosso.

> Istruzioni per l'agente che esegue questa checklist: sei un agente con accesso a browser e/o shell su questa stessa macchina Windows dove gira XAMPP. Esegui ogni passo nell'ordine indicato, annota il risultato (OK / FAIL + descrizione) accanto a ogni riga, e alla fine riporta un riepilogo con tutti i FAIL trovati. Non modificare codice: questo è solo un test manuale a schermo, il codice è già stato corretto e testato automaticamente — questa checklist verifica solo che il comportamento visibile nell'interfaccia corrisponda.

## Contesto (perché questo test esiste)

Il progetto Quack (ERP acquisti/magazzino/vendita prodotti chimici, PHP+SQLite) ha appena corretto due bug sul ciclo di vita del preventivo di vendita (`preventivi.stato`):

1. Un `<select>` libero nel form permetteva di forzare qualunque stato scelto dal menu, bypassando le regole di transizione — ora rimosso, lo stato si cambia solo tramite i bottoni azione guardati.
2. `qk_preventivo_nuova_versione()` (bottone "Crea Nuova Versione") falliva sempre con un errore di vincolo UNIQUE sul numero preventivo — ora corretto.

Tutto è già verificato con test automatici (290+ asserzioni su database reale, nessuna interfaccia). Quello che manca è la verifica a schermo: che i bottoni giusti compaiano al momento giusto, che i click producano il risultato atteso, che nulla sembri rotto visivamente.

## Prerequisiti

- XAMPP con Apache avviato (verificato già attivo su porta 8080 al momento della stesura di questa checklist).
- URL base: prova `http://localhost:8080/Quack/` — se non funziona, controlla la porta/percorso reali nel pannello di controllo XAMPP o chiedi all'utente.
- Credenziali di login: chiedi all'utente username/password se non le conosci già. Non indovinarle né provare a bypassare il login.
- Database reale in uso: `C:/Quack_ERP_data/db/database.sqlite` (fuori dalla cartella di progetto). Se disponibile, DB Browser for SQLite è installato su questa macchina e può essere usato per interrogazioni di sola lettura di verifica (query SELECT, mai UPDATE/DELETE su dati reali).
- Usa un cliente di test già esistente in anagrafica (Clienti) o creane uno nuovo con un nome chiaramente riconoscibile come test, es. "TEST CHECKLIST 22/07/2026", per non sporcare dati reali e per poterlo ritrovare/ripulire dopo.

---

## Scenario A — Creazione, bozza, invio

1. [ ] Vai su `/sales/quotes` (menu Vendite → Preventivi). La pagina carica senza errori PHP a schermo.
2. [ ] Clicca "Nuovo Preventivo" (`/sales/quotes/create`). Il form si apre.
3. [ ] Compila cliente, almeno una riga prodotto, e clicca **"Salva bozza"**.
   - Atteso: il preventivo viene creato, torni sul form in modifica, stato mostrato = **"Bozza"** in un campo di sola lettura (NON un menu a tendina modificabile — verifica che non esista più un `<select>` con tutti gli stati selezionabili liberamente).
4. [ ] Riapri il preventivo appena creato dalla lista `/sales/quotes`. Controlla che compaia con stato "Bozza" nella lista e nel filtro di ricerca per stato.
5. [ ] Nel form, clicca **"Invia al Cliente"** (bottone con icona aeroplanino, visibile solo quando lo stato è Bozza).
   - Atteso: nessun errore, stato passa a **"Inviato"**, il bottone "Invia al Cliente" scompare e al suo posto compaiono "Accetta e Crea Ordine" e, nella barra a destra, "Rifiuta".
6. [ ] In alternativa/aggiunta, prova anche il percorso "Salva e invia" (bottone verde in alto, disponibile già alla creazione): crea un secondo preventivo di test, compilalo, e clicca direttamente **"Salva e invia"** invece di "Salva bozza" + "Invia al Cliente" separati.
   - Atteso: stato risultante = "Inviato" in un solo passaggio, nessun errore.

## Scenario B — Accettazione (genera ordine cliente)

7. [ ] Su un preventivo in stato "Inviato" (usa quello dello scenario A), clicca **"Accetta e Crea Ordine"**.
   - Atteso: appare una conferma ("Confermare l'accettazione..."), poi dopo conferma lo stato passa a **"Accettato"** e compare un badge/link "Ordine Generato: [numero ordine]".
8. [ ] Clicca sul link dell'ordine generato. Verifica che apra `/sales/orders/edit?id=...` con i dati coerenti (stesso cliente, stesse righe prodotto/quantità del preventivo).
9. [ ] Torna al preventivo accettato: verifica che i bottoni "Invia al Cliente", "Accetta e Crea Ordine", "Rifiuta" NON siano più presenti (stato finale, azioni chiuse) e che non sia più modificabile liberamente.

## Scenario C — Rifiuto e nuova versione

10. [ ] Crea un terzo preventivo di test, portalo in stato "Inviato" (come sopra).
11. [ ] Clicca **"Rifiuta"** (bottone rosso a destra). Conferma il popup ("Segnare il preventivo come rifiutato?").
    - Atteso: stato passa a **"Rifiutato"**, nessun errore.
12. [ ] Ricarica il form del preventivo rifiutato. Verifica che compaia il bottone **"Crea Nuova Versione"**.
    - **Questo è il punto più importante da verificare**: prima della correzione, cliccare questo bottone falliva sempre con un errore di database (UNIQUE constraint). Clicca "Crea Nuova Versione" e conferma che:
    - [ ] Non compare nessun errore PHP/database a schermo.
    - [ ] Si apre (o reindirizza a) un NUOVO preventivo, in stato "Bozza", con lo stesso cliente e le stesse righe prodotto del preventivo originale.
    - [ ] Il numero del nuovo preventivo è quello originale con un suffisso incrementato (es. se l'originale era `PRV-2026-0001`, il nuovo è `PRV-2026-0001-02`).
    - [ ] Tornando al preventivo ORIGINALE (quello rifiutato), il suo stato è ora "Annullato" (non più "Rifiutato") e non è più modificabile.

## Scenario D — Stampa

13. [ ] Su uno qualunque dei preventivi di test, clicca "PDF". Il PDF si genera senza errori e mostra lo stato corretto in chiaro (es. "Inviato", non "Inviata").
14. [ ] Clicca "Word (DOCX)". Stesso controllo: si scarica senza errori, stato scritto correttamente al maschile.

## Scenario E — Verifica che il bypass sia davvero chiuso (opzionale, richiede strumenti dev)

15. [ ] Apri gli strumenti sviluppatore del browser (F12) sul form di un preventivo in Bozza. Verifica nel DOM che il campo `stato` sia un `<input type="hidden">`, non un `<select>` con opzioni multiple selezionabili.
16. [ ] (Solo se comodo con gli strumenti dev) prova a modificare via DevTools il valore nascosto di `stato` a `"accettato"` e a salvare il form (bottone "Salva bozza"). Atteso: il salvataggio DEVE fallire con un messaggio d'errore chiaro tipo "Impossibile salvare: la transizione da 'bozza' a 'accettato' non è ammessa." — se invece salva senza errori e lo stato cambia, è una regressione grave, segnala subito.

---

## Riepilogo da compilare a fine test

- Scenario A (creazione/invio): OK / FAIL — dettagli:
- Scenario B (accettazione/ordine): OK / FAIL — dettagli:
- Scenario C (rifiuto/nuova versione): OK / FAIL — dettagli:
- Scenario D (stampa PDF/DOCX): OK / FAIL — dettagli:
- Scenario E (bypass bloccato): OK / FAIL / non eseguito — dettagli:

Se tutto è OK, questa checklist chiude la Fase 7 di `docs/Ciclo Vendite/PIANO - Uniformare Genere Grammaticale Stati Preventivo.md`. Se qualcosa fallisce, NON correggerlo autonomamente: riporta il dettaglio esatto (screenshot/testo dell'errore) perché va deciso dall'utente come procedere.
