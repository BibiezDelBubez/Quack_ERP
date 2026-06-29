import sqlite3
db_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite'
try:
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()
    cursor.execute("ALTER TABLE listini_acquisto ADD COLUMN attivo BOOLEAN NOT NULL DEFAULT 1")
    print("Added attivo to listini_acquisto.")
except Exception as e:
    print('Error:', e)
