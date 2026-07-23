#!/usr/bin/env python3
"""
Script di SOLA DIAGNOSI (non scrive nulla) — usato una tantum per calcolare,
verificare e stampare la mappa di correzione mojibake->carattere corretto
per i file individuati da check_utf8_encoding.py. L'applicazione delle
correzioni avviene poi con lo strumento nativo di editing (mai da qui),
come richiesto da .agents/AGENTS.md ("privilegiare SEMPRE i tool nativi").
"""
import re
import sys

PATTERNS = [
    r'Ã[\x80-\xBF]',
    r'Â[\x80-\xBF]',
    r'â€”', r'â€“', r'â€™', r'â€œ', r'â€\x9d', r'â€¦',
]
MOJIBAKE_RE = re.compile('|'.join(PATTERNS))

def fix(s):
    """Round-trip: il testo era UTF-8 corretto, riletto come cp1252, ri-salvato
    come UTF-8. Per tornare indietro: ri-codifica come cp1252 (bytes originali)
    poi decodifica come UTF-8 (testo vero)."""
    return s.encode('cp1252').decode('utf-8')

files = sys.argv[1:]
for path in files:
    with open(path, 'r', encoding='utf-8') as f:
        text = f.read()
    found = {}
    for m in MOJIBAKE_RE.finditer(text):
        found[m.group()] = found.get(m.group(), 0) + 1

    print(f"\n=== {path} ({len(found)} pattern distinti) ===")
    for mb, count in sorted(found.items(), key=lambda x: -x[1]):
        try:
            corrected = fix(mb)
            # verifica di round-trip: ricorrompendo il carattere corretto devo
            # ritrovare esattamente la stessa sequenza mojibake di partenza.
            roundtrip_ok = corrected.encode('utf-8').decode('cp1252') == mb
        except Exception as e:
            corrected = f'<ERRORE: {e}>'
            roundtrip_ok = False
        status = 'OK' if roundtrip_ok else 'ATTENZIONE-VERIFICA-MANUALE'
        print(f"  {mb!r:20s} -> {corrected!r:10s}  x{count:<4d} [{status}]")
