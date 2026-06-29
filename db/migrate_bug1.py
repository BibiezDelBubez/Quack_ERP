import sqlite3
import os

db_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite'
conn = sqlite3.connect(db_path)
cursor = conn.cursor()

try:
    # 1. Add attivo to listini_acquisto
    cursor.execute("ALTER TABLE listini_acquisto ADD COLUMN attivo BOOLEAN NOT NULL DEFAULT 1")
    print("Added attivo to listini_acquisto.")
except sqlite3.OperationalError as e:
    if "duplicate column name" in str(e).lower():
        print("Column attivo already exists.")
    else:
        print("Error adding column:", e)

# 2. Add ON DELETE CASCADE to prodotti_fornitori_prezzi
# SQLite doesn't support ALTER TABLE for changing foreign keys.
# We must recreate the table.
cursor.execute("PRAGMA foreign_keys=off;")
cursor.execute("BEGIN TRANSACTION;")

cursor.execute("""
CREATE TABLE IF NOT EXISTS prodotti_fornitori_prezzi_new (
    id                    INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_fornitore_id INTEGER       NOT NULL REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
    quantita_minima       DECIMAL(10,3) NOT NULL DEFAULT 0,
    prezzo                DECIMAL(10,4) NOT NULL,
    unita_misura_id       INTEGER       REFERENCES unita_misura(id),
    sconto_perc           DECIMAL(5,2)  NOT NULL DEFAULT 0,
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
print("Recreated prodotti_fornitori_prezzi with ON DELETE CASCADE.")

conn.close()
