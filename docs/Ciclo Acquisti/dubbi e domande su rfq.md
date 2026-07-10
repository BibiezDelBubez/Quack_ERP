## DOMANDE SU QUANTO GIA SCRITTO

T_RFQ_Testata.Stato
Quando uso i vari valori? Esempi specifici.

T_RFQ_Righe.ID_Articolo nullable per articoli non a catalogo
Perché? cioè, lo capisco per le offerte del fornitore, ma per le bozze di rfq dovrebbero esserci solo prodotti in anagrafica. O no? Gli ERP permettono di richiedere prezzi per cose non a catalogo? Cosa fanno in questi casi?

T_Offerte_Testata.Motivo_Rifiuto. 
è nullable?
Conviene generalizzarlo in "Motivi decisione" per tracciare sia motivi di accettazione che di rifiuto? o è over engineering?

T_Offerte_Righe.ID_Riga_RFQ_Origine. Chiave Esterna su T_RFQ_Righe
Chi crea questo "collegamento" (quindi popola questo campo)? E come e quando?

T_Ordini_Righe.ID_Riga_Offerta_Origine (Chiave Esterna su T_Offerte_Righe)
Chi crea questo "collegamento" (quindi popola questo campo)? E come e quando?

T_Ordini_Righe.Tolleranza_Esubero_Percentuale 
Non mi è chiaro quando va compilata. E chi e quando, come, dove stabilisce la tolleranza

## APPROFONDIMENTI

### Fase 3

Manca completamente la fase 3, l'accordo, quando l'offerta fornitore diventa ordine, diventa contratto.

### Invio della RFQ

Il nostro ERP gira su xampp o laragon, è puro js+php e gira solo in locale. Non manda email (però permette stampa in pdf e docx). Suggerisci soluzione elegante e ux comoda e intuitiva per il cambio di stato della RFQ

### Cosa fa il sistema se il fornitore propone prodotto sostitutivo non presente in anagrafica

Hai detto quanto segue

1. Il sistema avvia un workflow in background: invia una notifica all'ufficio Anagrafiche/Master Data.

2. L'anagrafista valida il prodotto, gli assegna un codice interno ufficiale (es. ART-9988) e lo approva.

In realtà non so se il nostro ERP permette una cosa del genere. C'è una alternativa più semplice o elegante? tipo una creazione contestuale del nuovo prodotto e basta? Sappi che noi abbiamo una tabella prodotti e una tabella prodotti_fornitori, e l'utente deve essere sicuro se conviene inserirlo come variante dello stesso prodotto (quindi una riga in più di prodotti_fornitori) o un prodotto veramente diverso (quindi con caratteristiche diverse) per cui va creato un record nuovo nella tabella prodotti

### Gestione delle consegne scaglionate

Ti chiedo se è veramente necessario sdoppiare le righe dell'offerta. TI incollo la nostra logica che avevamo pensato coi colleghi finora. Ti chiedo se è abbastanza professionale o troppo superficiale. In breve mi pare che la logica fosse.  

1) Se i prodotti vengono spediti scaglionati ma il resto (prezzo, numero totale) è uguale, l'utente che riceve l'offerta la considera uguale alla richiesta.  
2) eventuali consegne parziali vengono gestite all'ingressamento merce

## Logica di Auto-Split per le consegne parziali (MRP e Pianificazione)

<style>
</style>

Nella rivendita di prodotti chimici, le consegne parziali sono la norma (mancanza di stock del produttore, camion ADR saturi, ecc.). A differenza di un sistema basato solo sulle giacenze, il nostro ERP gestisce questo flusso usando il modello **Auto-Split** basato su **Tabelle di Pianificazione** (best practice enterprise in ottica MRP, equivalente alle *Schedule Lines* di SAP).

-            [Ordine Confermato: 100 kg pianificati per il 01/06]
§  [Camion Arriva: Scarica solo 60 kg il 01/06]
§  ERP carica 60 kg in Giacenza Reale
§  ERP scala 60 kg dalla Giacenza in Arrivo (rimangono 40 kg in arrivo)
§  ERP **spezza la pianificazione**:
   - Riga 1: 60 kg -> "Consegnata"
   - Riga 2 (Residuo): 40 kg -> "In Attesa"
§  Stato Ordine: "Ricevuto Parziale"

**Passo 1: L'Attesa e la Pianificazione**

L'ordine d'acquisto ORDA-2026-0004 è stato confermato. L'utente ha ordinato **100 Pezzi** di "Cloro Pastiglie Secchio 10kg".

- **A schermo:** Lo stato dell'ordine è "Confermato". Nei dettagli dell'ordine c'è un grande pulsante blu: **"Registra Ricezione Merci"**.
- **Lo stock e il database:** La giacenza_reale del cloro è 0. La giacenza_in_arrivo è 100.
Nella tabella `ordini_righe_pianificazione` c'è una riga in stato "In Attesa" per 100 pezzi con la data prevista.

**Passo 2: Arriva il primo camion (Consegna Parziale di 60 Pezzi imprevista)**

Il corriere scarica solo 60 secchi perché il resto era finito. Il fornitore non aveva preavvisato. Il magazziniere clicca su **"Registra Ricezione Merci"**.

- **Cosa mostra lo schermo (UI):** Si apre una schermata di carico che elenca i prodotti dell'ordine. Il sistema legge la pianificazione e propone una casella di testo precompilata con il valore atteso: [ 100 ]. C'è anche il campo: [ Numero DDT Fornitore ].
- **Cosa fa l'utente:**
1. Scrive il numero del DDT cartaceo (es. "DDT-9954").
2. Cancella il 100 nella casella e scrive 60 (la quantità effettivamente contata).
3. Clicca su **"Conferma Ricezione"**.
- **Cosa fa l'ERP (Software):**
1. Crea una testata in `ricezioni_merci_testata` ("DDT-9954").
2. Crea una riga in `ricezioni_merci_righe` con quantità 60.
3. Aggiorna la giacenza del prodotto:
   - giacenza_reale diventa 0 + 60 = 60
   - giacenza_in_arrivo diventa 100 - 60 = 40
4. Esegue l'**Auto-Split** sulla riga di pianificazione:
   - Modifica la riga originaria da 100 a 60 e la imposta su "Consegnata".
   - Crea una **nuova riga** di pianificazione per il residuo di 40 pezzi, collegata a quella padre, in stato "In Attesa" (con nota "Generato da split automatico").
5. Cambia automaticamente lo stato dell'ordine in **"ricevuto_parziale"**.

**Passo 3: Lo stato intermedio (Il Follow-up)**

L'azienda ha parzialmente la merce e l'Ufficio Acquisti ha uno strumento potente per sollecitare il fornitore.

- **A schermo:** Nella lista degli ordini d'acquisto, ORDA-2026-0004 è "Ricevuto Parziale". Se l'utente apre il dettaglio, vede: *Ordinati: 100 | Ricevuti: 60 | Rimanenti in Arrivo: 40*. 
Ma, soprattutto, **nella scheda di pianificazione dell'ordine**, il buyer vede la riga orfana di 40 pezzi. Può chiamare il fornitore e aggiornare la data prevista di quei 40 pezzi al "15 Giugno" senza intaccare lo storico dell'ordine.

**Passo 4: Arriva il secondo camion (I restanti 40 Pezzi)**

Arriva il saldo della merce con un nuovo DDT (es. "DDT-10112").

- **Cosa mostra lo schermo (UI):** La schermata di carico si apre proponendo l'unica riga di pianificazione rimasta "In Attesa". L'input è precompilato con: [ 40 ].
- **Cosa fa l'utente:** Inserisce il DDT ("DDT-10112"), verifica e clicca su **"Conferma Ricezione"**.
- **Cosa fa l'ERP (Software):**
1. Crea testata e righe in `ricezioni_merci`.
2. Aggiorna lo stock fisico a 100 e azzera quello in arrivo.
3. Prende la riga di pianificazione da 40 pezzi e la segna come "Consegnata".
4. Verificando che la quantità totale ordinata (100) coincide con la somma delle ricezioni, cambia lo stato dell'ordine in **"ricevuto"** (chiuso) e nasconde il pulsante per nuovi carichi.

