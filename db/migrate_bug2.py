import sqlite3

db_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite'
conn = sqlite3.connect(db_path)
cursor = conn.cursor()

cursor.execute("PRAGMA foreign_keys=off;")
cursor.execute("BEGIN TRANSACTION;")

cursor.execute("""
CREATE TABLE IF NOT EXISTS prodotti_fornitori_prezzi_new (
    id                    INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_fornitore_id INTEGER       NOT NULL REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
    quantita_minima       DECIMAL(10,3) NOT NULL DEFAULT 0,
    prezzo                DECIMAL(10,4) NOT NULL CHECK (prezzo >= 0),
    unita_misura_id       INTEGER       REFERENCES unita_misura(id),
    sconto_perc           DECIMAL(5,2)  NOT NULL DEFAULT 0 CHECK (sconto_perc >= 0 AND sconto_perc <= 100),
    valido_dal            DATE,
    valido_al             DATE,
    note                  TEXT,
	listino_acquisto_id   INTEGER       REFERENCES listini_acquisto(id) ON DELETE CASCADE
);
""")

cursor.execute("""
INSERT INTO prodotti_fornitori_prezzi_new 
(id, prodotto_fornitore_id, quantita_minima, prezzo, unita_misura_id, sconto_perc, valido_dal, valido_al, note, listino_acquisto_id)
SELECT id, prodotto_fornitore_id, quantita_minima, prezzo, unita_misura_id, sconto_perc, valido_dal, valido_al, note, listino_acquisto_id 
FROM prodotti_fornitori_prezzi;
""")

cursor.execute("DROP TABLE prodotti_fornitori_prezzi;")
cursor.execute("ALTER TABLE prodotti_fornitori_prezzi_new RENAME TO prodotti_fornitori_prezzi;")

cursor.execute("COMMIT;")
cursor.execute("PRAGMA foreign_keys=on;")
print("Recreated prodotti_fornitori_prezzi with CHECK constraints.")

conn.close()
