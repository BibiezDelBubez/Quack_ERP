import sqlite3

db_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite'
dump_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\dump.sql'

try:
    conn = sqlite3.connect(db_path)
    with open(dump_path, 'w', encoding='utf-8') as f:
        for line in conn.iterdump():
            f.write('%s\n' % line)
    print("Dump successful.")
except Exception as e:
    print('Error during dump:', e)
