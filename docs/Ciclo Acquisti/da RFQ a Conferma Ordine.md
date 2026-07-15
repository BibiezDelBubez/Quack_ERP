<style>
</style>

da RFQ a Conferma Ordine

## Cosa fanno best in class

## RFQ in stato Bozza

Quando il record si trova nello stato Bozza, l’utente ha il
controllo totale perché si tratta di un documento di lavoro interno non ancora
condiviso con l'esterno.

L'ERP si limita a fare da blocco note. L’utente può cambiare
articoli, quantità, destinatari e prezzi senza che il sistema debba registrare
uno storico delle modifiche o generare transazioni di controllo.

## RFQ in stato INVIATA

Nel momento in cui l’utente invia la RFQ al fornitore e il
record passa allo stato Inviata, la situazione cambia radicalmente perché l’azienda
ha ufficialmente comunicato un'intenzione e una richiesta a una terza parte

Molti sistemi ERP limitano la modifica alle sole righe di
prezzo. Il motivo è dovuto alla natura stessa della trattativa commerciale:

-            L’utente ha chiesto al fornitore la quotazione
per un elenco preciso di prodotti con quantità precise.

o   Quella specifica richiesta è ormai un atto
ufficiale, "congelato" nel tempo.

o   Modificare le quantità in questa fase
distruggerebbe la prova legale di ciò che era stato originariamente richiesto
al fornitore, impedendo di misurare lo scostamento tra Richiesta e Offerta.

Tuttavia, nella realtà operativa si verificano scostamenti
frequenti. Il fornitore potrebbe applicare sconti di volume o imporre lotti
minimi di acquisto. Anziché permettere all'utente di aggiornare e sovrascrivere
le quantità nella RFQ (che sarebbe un gravissimo errore di progettazione e
compliance), il sistema risolve questa deviazione nella fase successiva di
Registrazione Offerta. L'utente registra le nuove quantità proposte (il lotto
minimo) direttamente nella riga dell'Offerta Fornitore. Il sistema calcola lo
scostamento tra la RFQ originale (immutabile) e l'Offerta (flessibile),
garantendo così l'allineamento operativo senza mai bloccare l'utente o
costringerlo a "salti mortali", e preservando al 100% l'integrità
storica del database.
