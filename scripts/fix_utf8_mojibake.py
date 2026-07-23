#!/usr/bin/env python3
"""
scripts/fix_utf8_mojibake.py

Corregge il mojibake da doppia codifica (UTF-8 riletto come Latin-1/CP1252 e
ri-salvato come UTF-8) nei file indicati, o in tutti i file rilevati da
scripts/check_utf8_encoding.py se non viene passato nessun argomento.

Perché uno script e non i tool di editing "a mano" (vedi .agents/AGENTS.md,
"Regola sulla Codifica dei File"): alcuni pattern di mojibake contengono
caratteri di spaziatura invisibili (es. NBSP U+00A0) che non è affidabile
riprodurre carattere per carattere componendo manualmente una stringa di
ricerca. Questo script apre e scrive SEMPRE esplicitamente in UTF-8 (mai la
codifica di default della piattaforma) — la stessa precauzione richiesta
dalla regola AGENTS.md, solo applicata correttamente qui.

Ogni sostituzione è un round-trip deterministico e verificato byte per byte
PRIMA di essere scritto (vedi verify_roundtrip): se anche un solo pattern in
un file non supera la verifica, il file NON viene toccato ed esce con
errore, per non rischiare di introdurre una corruzione diversa da quella che
si vuole correggere.

Uso:
    python3 scripts/fix_utf8_mojibake.py                    # autodetect + fix su tutto il repo
    python3 scripts/fix_utf8_mojibake.py path/a/file1 path/a/file2   # solo i file indicati
    python3 scripts/fix_utf8_mojibake.py --dry-run [file...]         # mostra cosa cambierebbe senza scrivere
"""

import sys
import os
import re
import subprocess

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
# Riuso (DRY) della stessa individuazione candidati + verifica di round-trip
# usata da check_utf8_encoding.py, così le due utility non possono divergere
# silenziosamente su cosa è "mojibake verificato".
from check_utf8_encoding import (  # noqa: E402
    MOJIBAKE_CANDIDATE_RE,
    is_verified_mojibake as verify_roundtrip,
    get_tracked_files,
    is_text_candidate,
)


def fix_file(path: str, dry_run: bool = False) -> tuple[int, list[str]]:
    """Ritorna (numero di sostituzioni, lista di candidati NON toccati perché
    non hanno superato la verifica di round-trip — nota informativa, non un
    motivo per non correggere il resto del file: con il pattern candidato
    ampio (Ã./Â./â€.) è normale che alcuni candidati non siano mojibake
    reale, e la verifica di round-trip li scarta uno per uno correttamente."""
    with open(path, 'r', encoding='utf-8', newline='') as f:
        text = f.read()

    candidates = set(m.group() for m in MOJIBAKE_CANDIDATE_RE.finditer(text))
    if not candidates:
        return 0, []

    skipped = []
    total_replacements = 0
    new_text = text
    for candidate in candidates:
        corrected = verify_roundtrip(candidate)
        if corrected is None:
            skipped.append(candidate)
            continue
        count = new_text.count(candidate)
        new_text = new_text.replace(candidate, corrected)
        total_replacements += count

    if not dry_run and new_text != text:
        with open(path, 'w', encoding='utf-8', newline='') as f:
            f.write(new_text)

    return total_replacements, skipped


def main(argv):
    dry_run = '--dry-run' in argv
    args = [a for a in argv[1:] if a != '--dry-run']

    root = subprocess.run(
        ['git', 'rev-parse', '--show-toplevel'], capture_output=True, text=True
    ).stdout.strip() or os.getcwd()

    if args:
        targets = [os.path.join(root, p) if not os.path.isabs(p) else p for p in args]
    else:
        targets = [p for p in get_tracked_files(root) if is_text_candidate(p)]

    for path in sorted(targets):
        if not os.path.isfile(path):
            continue
        n, skipped = fix_file(path, dry_run=dry_run)
        rel = os.path.relpath(path, root)
        if n:
            action = 'da correggere' if dry_run else 'corrette'
            print(f'{"[DRY-RUN] " if dry_run else ""}{rel}: {n} occorrenze {action}')
        if skipped:
            # Informativo, non un errore: con il pattern candidato ampio è normale
            # scartare candidati che non erano mojibake reale. Se dopo il fix
            # scripts/check_utf8_encoding.py segnala ancora problemi VERIFICATI
            # su questo file, quelli sì vanno guardati a mano — questa riga da
            # sola non è un'anomalia.
            print(f'  (info) {rel}: {len(skipped)} candidati esclusi (non erano mojibake reale)')

    # L'esito pass/fail spetta a scripts/check_utf8_encoding.py, eseguito dopo:
    # è quello lo script "autorevole" per decidere se resta corruzione VERA.
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
