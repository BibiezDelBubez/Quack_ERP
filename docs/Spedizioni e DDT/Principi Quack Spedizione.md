- **Non trattare mai "l'ordine di vendita" come un singolo record che cambia stato fino a diventare "spedito" e poi "fatturato"**.

- Vanno creati tre documenti distinti e collegati:**
1. **L'Ordine di Vendita** (Sales Order) — cosa il cliente ha chiesto: prodotti, quantità, prezzi, condizioni. Non tocca il magazzino.
2. **Il Documento di Spedizione/Consegna** (Delivery / Shipment / Item Fulfillment, a seconda del sistema — l'equivalente del DDT italiano) — cosa è uscito fisicamente dal magazzino, quando, da dove, con che vettore. Tocca il magazzino: scarica la giacenza.
3. **La Fattura** (Invoice) — cosa viene addebitato al cliente. Tocca la contabilità.

Questi tre documenti sono **collegati** (ogni riga di spedizione sa da quale riga d'ordine viene, ogni riga di fattura sa da quale spedizione viene) ma restano **entità separate**, ognuna con il proprio numero, la propria data, il proprio stato. Un ordine da 100 pezzi può generare 3 spedizioni diverse in giorni diversi (magari anche 2 fatture diverse), e il sistema tiene traccia di "quanto è stato spedito finora" e "quanto resta da spedire" confrontando l'ordine con la somma delle spedizioni collegate — mai un singolo numero scritto a mano.

Perché? Perché separare i tre documenti permette al sistema di rispondere sempre, in automatico, a tre domande diverse che nella pratica commerciale sono quasi sempre diverse tra loro:

- Cosa ha comprato il cliente? (l'ordine, che non cambia mai una volta confermato)
- Cosa è successo davvero in magazzino? (le spedizioni, che possono essere più di una, in momenti diversi, anche con differenze rispetto all'ordine)
- Cosa gli sto addebitando, e quando? (la fattura, che può seguire la spedizione con giorni o settimane di ritardo, o essere anticipata, secondo la policy commerciale)

Se questi tre concetti fossero un solo campo "stato" su un solo record, un caso come "ho spedito il 60% dell'ordine, fatturato tutto anticipato, e il cliente ha già pagato solo la prima metà" diventerebbe impossibile da rappresentare con un solo valore. Con tre documenti separati, invece, ognuno racconta una parte diversa e vera della storia.

Tenete a mente questo schema: **Ordine → (una o più) Spedizioni → (una o più) Fatture**.
