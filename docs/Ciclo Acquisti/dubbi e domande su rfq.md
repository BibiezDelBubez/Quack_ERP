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

## Logica che avevamo pensato coi colleghi per le consegne parziali

<style>
</style>

Nella rivendita di prodotti chimici, le consegne parziali
sono la norma (mancanza di stock del produttore, camion ADR saturi, ecc.). Ecco
come l'utente e l'ERP gestiscono questo flusso passo dopo passo.

-            [Ordine Confermato: 100 kg]

§  [Camion
Arriva: Scarica solo 60 kg]

§  ERP
carica 60 kg in Giacenza Reale

§  ERP
lascia 40 kg in Giacenza in Arrivo

§  Stato
Ordine: "Ricevuto Parziale"

**Passo 1: L'Attesa**

L'ordine d'acquisto ORDA-2026-0004 è stato confermato.
L'utente ha ordinato **100 Pezzi** di "Cloro Pastiglie Secchio
10kg".

- **A
   schermo:** Lo stato dell'ordine è "Confermato". Nei dettagli
   dell'ordine c'è un grande pulsante blu: **"Registra Ricezione
   Merci"**.
- **Lo
   stock:** La giacenza_reale del cloro è 0. La giacenza_in_arrivo è 100.

**Passo 2: Arriva il primo
camion (Consegna Parziale di 60 Pezzi)**

Il corriere scarica solo 60
secchi perché il resto era finito. Il magazziniere clicca su **"Registra
Ricezione Merci"**.

- **Cosa mostra lo schermo (UI):** Si apre una
   schermata di carico che elenca i prodotti dell'ordine. Accanto al cloro,
   il sistema propone una casella di testo precompilata con il valore di
   default dell'ordine: [ 100 ]. C'è anche un campo vuoto obbligatorio: [
   Numero DDT Fornitore ].

- **Cosa fa l'utente:**
1. Scrive il numero del DDT cartaceo (es.
   "DDT-9954").

2. Cancella il 100 nella casella e scrive 60 (la
   quantità effettivamente contata sul bancale).

3. Clicca su **"Conferma Ricezione"**.
- **Cosa fa l'ERP (Software):**
1. Crea una riga nella testata ricezioni_merci
   (collegata a ORDA-2026-0004 e con DDT "DDT-9954").

2. Crea una riga in ricezioni_merci_righe con
   quantità 60.

3. Aggiorna la giacenza del prodotto:
- giacenza_reale diventa $0 + 60 = 60$

- giacenza_in_arrivo diventa $100 - 60 = 40$
5. L'ERP rileva che $60 < 100$. Cambia
   automaticamente lo stato di ORDA-2026-0004 in **"ricevuto_parziale"**.

**Passo 3: Lo stato intermedio**

L'azienda ha parzialmente la
merce in casa e può già iniziare a venderla.

- **A schermo:** Nella lista degli ordini
   d'acquisto, ORDA-2026-0004 mostra l'etichetta gialla "Ricevuto
   Parziale". Se l'utente lo apre, vede chiaramente: *Ordinati: 100 |
   Ricevuti: 60 | Rimanenti in Arrivo: 40*. Il pulsante "Registra
   Ricezione Merci" è ancora attivo.

**Passo 4: Arriva il secondo
camion (I restanti 40 Pezzi)**

La settimana dopo arriva il saldo
della merce con un nuovo DDT (es. "DDT-10112"). Il magazziniere
clicca di nuovo su **"Registra Ricezione Merci"**.

- **Cosa mostra lo schermo (UI):** La schermata di
   carico si apre mostrando solo il residuo non ancora consegnato. L'input
   della quantità è precompilato con il valore residuo: [ 40 ].

- **Cosa fa l'utente:** Scrive il nuovo numero di
   DDT ("DDT-10112"), verifica che i pezzi siano effettivamente 40
   e clicca su **"Conferma Ricezione"**.

- **Cosa fa l'ERP (Software):**
1. Crea una seconda riga in ricezioni_merci
   (associata allo stesso ordine ma con il nuovo DDT "DDT-10112").

2. Crea una riga in ricezioni_merci_righe con
   quantità 40.

3. Aggiorna la giacenza del prodotto:
- giacenza_reale diventa $60 + 40 = 100$

- giacenza_in_arrivo diventa $40 - 40 = 0$
5. L'ERP rileva che la somma di tutte le ricezioni
   collegate all'ordine ($60 + 40$) è uguale alla quantità totale ordinata
   (100). Cambia lo stato dell'ordine in **"ricevuto"** (chiuso) e nasconde il pulsante per effettuare altri carichi.
