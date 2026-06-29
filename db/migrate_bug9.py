import sqlite3
import os
import sys

db_path = os.path.join(os.path.dirname(__file__), 'database.sqlite')

if not os.path.exists(db_path):
    print(f"Error: Database not found at {db_path}")
    sys.exit(1)

conn = sqlite3.connect(db_path)
cursor = conn.cursor()

try:
    cursor.execute("BEGIN TRANSACTION")

    # 1. Create the new table with the UNIQUE constraint
    cursor.execute("""
    CREATE TABLE listini_prezzi_new (
        id              INTEGER       PRIMARY KEY AUTOINCREMENT,
        listino_id      INTEGER       NOT NULL REFERENCES listini(id) ON DELETE CASCADE,
        prodotto_id     INTEGER       NOT NULL REFERENCES prodotti(id),
        quantita_minima DECIMAL(10,3) NOT NULL DEFAULT 0,
        prezzo_unitario DECIMAL(10,4) NOT NULL,
        unita_misura_id INTEGER       REFERENCES unita_misura(id),
        sconto_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
        note            TEXT,
        created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (listino_id, prodotto_id, quantita_minima, unita_misura_id)
    )
    """)

    # 2. Copy data, keeping the most recently updated/created row in case of duplicates
    cursor.execute("""
    INSERT INTO listini_prezzi_new (
        id, listino_id, prodotto_id, quantita_minima, 
        prezzo_unitario, unita_misura_id, sconto_perc, note, created_at
    )
    SELECT 
        id, listino_id, prodotto_id, quantita_minima, 
        prezzo_unitario, unita_misura_id, sconto_perc, note, created_at
    FROM listini_prezzi
    WHERE id IN (
        SELECT MAX(id)
        FROM listini_prezzi
        GROUP BY listino_id, prodotto_id, quantita_minima, unita_misura_id
    )
    """)

    # 3. Drop old table
    cursor.execute("DROP TABLE listini_prezzi")

    # 4. Rename new table to original name
    cursor.execute("ALTER TABLE listini_prezzi_new RENAME TO listini_prezzi")

    # 5. Recreate index
    cursor.execute("""
    CREATE INDEX IF NOT EXISTS idx_listini_prezzi_listino_prodotto 
    ON listini_prezzi(listino_id, prodotto_id)
    """)

    conn.commit()
    print("Migration Bug 9 applied successfully.")
    
except Exception as e:
    conn.rollback()
    print(f"Migration failed: {e}")
    sys.exit(1)
finally:
    conn.close()
