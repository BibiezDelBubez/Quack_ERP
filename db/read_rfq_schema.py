import sqlite3
conn = sqlite3.connect(r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite')
print(conn.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='rfq_testata'").fetchone()[0])
