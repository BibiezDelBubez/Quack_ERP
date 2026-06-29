import sqlite3
import re

db_path = r'C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite'

def migrate_table(conn, table_name):
    print(f"Migrating {table_name}...")
    # Get original schema
    sql = conn.execute(f"SELECT sql FROM sqlite_master WHERE type='table' AND name='{table_name}'").fetchone()[0]
    
    # Replace the foreign keys
    new_sql = sql.replace("REFERENCES indirizzi(id)", "REFERENCES indirizzi_documenti(id)")
    new_sql = new_sql.replace("REFERENCES clienti_fornitori(id)", "REFERENCES contatti_documenti(id)")
    
    # Wait, the table definition has 'fornitore_id INTEGER REFERENCES clienti_fornitori(id)'
    # If I blindly replace 'REFERENCES clienti_fornitori(id)', I will ruin fornitore_id!
    # I must use regex to only target contatto_id.
    
    new_sql = re.sub(r'indirizzo_consegna_id\s+INTEGER\s+REFERENCES\s+indirizzi\(id\)', 'indirizzo_consegna_id INTEGER REFERENCES indirizzi_documenti(id)', new_sql)
    new_sql = re.sub(r'contatto_id\s+INTEGER\s+REFERENCES\s+clienti_fornitori\(id\)', 'contatto_id INTEGER REFERENCES contatti_documenti(id)', new_sql)
    
    # Change table name to _old
    new_sql = new_sql.replace(f"CREATE TABLE {table_name}", f"CREATE TABLE {table_name}_new")
    
    # Execute migration
    conn.execute(f"ALTER TABLE {table_name} RENAME TO {table_name}_old")
    conn.execute(new_sql)
    
    # Get columns
    cols = conn.execute(f"PRAGMA table_info({table_name}_old)").fetchall()
    col_names = [c[1] for c in cols]
    col_str = ", ".join(col_names)
    
    conn.execute(f"INSERT INTO {table_name}_new ({col_str}) SELECT {col_str} FROM {table_name}_old")
    conn.execute(f"DROP TABLE {table_name}_old")
    conn.execute(f"ALTER TABLE {table_name}_new RENAME TO {table_name}")
    print(f"Done migrating {table_name}.")

try:
    conn = sqlite3.connect(db_path)
    conn.execute('PRAGMA foreign_keys=OFF')
    conn.execute('BEGIN TRANSACTION')
    
    migrate_table(conn, 'rfq_testata')
    migrate_table(conn, 'offerte_testata')
    migrate_table(conn, 'ordini_testata')
    
    conn.execute('COMMIT')
    conn.execute('PRAGMA foreign_keys=ON')
    print("Migration successful!")
except Exception as e:
    conn.execute('ROLLBACK')
    print("Migration failed:", e)
finally:
    conn.close()
