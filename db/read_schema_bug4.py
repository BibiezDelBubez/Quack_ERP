import sqlite3
import json

conn = sqlite3.connect(r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite')
rfq = conn.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='rfq_testata'").fetchone()[0]
offerte = conn.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='offerte_testata'").fetchone()[0]
ordini = conn.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_testata'").fetchone()[0]

with open(r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\schema_bug4.txt', 'w', encoding='utf-8') as f:
    f.write("RFQ:\n" + rfq + "\n\nOFFERTE:\n" + offerte + "\n\nORDINI:\n" + ordini)
