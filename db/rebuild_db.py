import sqlite3
import os

db_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite'
sql_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\init_sqlite.sql'

if os.path.exists(db_path):
    os.remove(db_path)
    print("Deleted corrupted database.")

conn = sqlite3.connect(db_path)
cursor = conn.cursor()

with open(sql_path, 'r', encoding='utf-8') as f:
    sql_script = f.read()

try:
    cursor.executescript(sql_script)
    print("Database successfully recreated from init_sqlite.sql.")
except Exception as e:
    print("Error recreating database:", e)

conn.close()
