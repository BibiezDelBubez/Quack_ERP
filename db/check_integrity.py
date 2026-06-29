import sqlite3
db_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite'
try:
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()
    cursor.execute("PRAGMA integrity_check;")
    print(cursor.fetchall())
except Exception as e:
    print('Error:', e)
