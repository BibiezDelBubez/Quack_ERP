import json
import os
import datetime

AUDIT_DIR = os.path.join(os.path.dirname(__file__), '..', '..', '..', 'audit')
SCOPERTE_FILE = os.path.join(AUDIT_DIR, 'scoperte_simulazione.json')

def log_scoperta(modulo, test_name, descrizione, dati_usati=None, screenshot_path=None):
    """
    Registra una scoperta (bug, anomalia o risultato interessante) nel file JSON.
    """
    if not os.path.exists(AUDIT_DIR):
        os.makedirs(AUDIT_DIR)
        
    scoperte = []
    if os.path.exists(SCOPERTE_FILE):
        try:
            with open(SCOPERTE_FILE, 'r', encoding='utf-8') as f:
                scoperte = json.load(f)
        except json.JSONDecodeError:
            pass

    record = {
        "timestamp": datetime.datetime.now().isoformat(),
        "modulo": modulo,
        "test": test_name,
        "descrizione": descrizione,
        "dati_usati": dati_usati or {},
        "screenshot": screenshot_path
    }
    
    scoperte.append(record)
    
    with open(SCOPERTE_FILE, 'w', encoding='utf-8') as f:
        json.dump(scoperte, f, indent=4, ensure_ascii=False)
