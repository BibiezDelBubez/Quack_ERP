#!/usr/bin/env python3
"""
scripts/check_utf8_encoding.py

Controllo a tappeto dell'encoding di tutti i file di testo del progetto Quack ERP.

Rileva DUE classi distinte di corruzione (vedi CLAUDE.md, regola 10, e
.agents/AGENTS.md, "Regola sulla Codifica dei File"):

  1. BYTE NON VALIDI COME UTF-8 — corruzione "vera": il file contiene sequenze
     di byte che non sono UTF-8 valido (es. salvataggio accidentale in
     Latin-1/Windows-1252 con caratteri accentati). PHP e altri parser possono
     comportarsi in modo imprevedibile o esplicito solo a runtime.

  2. MOJIBAKE DA DOPPIA CODIFICA — corruzione "silenziosa": il file è UTF-8
     valido a livello di byte, ma il testo è sbagliato perché una stringa
     UTF-8 è stata reinterpretata come Latin-1/Windows-1252 e ri-salvata come
     UTF-8 (es. "è" -> byte 0xC3 0xA8 -> letti come 2 caratteri Latin-1 "Ã¨"
     -> ri-codificati in UTF-8 come 4 byte). Questo NON viene rilevato da un
     controllo di sola validità UTF-8, quindi serve un controllo separato per
     pattern noti (Ã€-ÃŸ seguito da un carattere di continuazione, â€” per
     l'em-dash, â€™ per l'apostrofo tipografico, ecc.).

Uso:
    python3 scripts/check_utf8_encoding.py            # scandisce tutto il repo
    python3 scripts/check_utf8_encoding.py file1 file2 # scandisce solo i file indicati (usato dal pre-commit hook)

Exit code: 0 se nessun problema, 1 se trovata almeno una corruzione.
Pensato per essere invocato sia manualmente sia da .githooks/pre-commit.
"""

import sys
import os
import re
import subprocess

# Estensioni considerate "testo di progetto" — tutto ciò che nel progetto è
# dichiarato UTF-8 da CLAUDE.md (PHP, JS, SQL, MD, JSON, CSS, HTML).
TEXT_EXTENSIONS = {
    '.php', '.js', '.sql', '.md', '.json', '.css', '.html', '.htm',
    '.txt', '.yml', '.yaml', '.xml', '.svg', '.htaccess', '.env',
}

# Directory da escludere sempre dalla scansione.
EXCLUDED_DIRS = {'.git', 'node_modules', 'vendor', '.claude'}

# Pattern CANDIDATI di mojibake: 'Ã'/'Â' (U+00C3/U+00C2, primo byte UTF-8 di
# qualunque carattere Latin-1 Supplement doppio-codificato) o 'â€' (prefisso
# tipico della famiglia di punteggiatura tipografica U+2000-U+20xx doppio-
# codificata: em-dash, apostrofo/virgolette tipografiche, ellissi, €, ecc.)
# seguiti da un qualunque carattere.
#
# NON basta elencare a mano le combinazioni note (es. 'Ã¨', 'â€”'): il
# secondo byte originale può cadere nel range 0x80-0x9F di CP1252, che
# CP1252 rimappa a caratteri tipografici (em-dash, virgolette...) con
# codepoint FUORI dal blocco Latin-1 (es. 'Ã' + em-dash reale, per la
# doppia codifica di '×') — un pattern basato solo su range di codepoint
# li perderebbe silenziosamente. Per questo qui si individuano solo i
# CANDIDATI, e la validazione vera è delegata a is_verified_mojibake():
# il candidato è accettato SOLO se il round-trip CP1252<->UTF-8 è
# byte-per-byte reversibile (vedi anche scripts/fix_utf8_mojibake.py).
MOJIBAKE_CANDIDATE_RE = re.compile(r'Ã.|Â.|â€.')


def is_verified_mojibake(candidate: str) -> str | None:
    """Ritorna il carattere corretto se il candidato è mojibake verificato
    (round-trip CP1252->UTF-8 byte-per-byte reversibile), altrimenti None."""
    try:
        corrected = candidate.encode('cp1252').decode('utf-8')
    except (UnicodeEncodeError, UnicodeDecodeError):
        return None
    try:
        if corrected.encode('utf-8').decode('cp1252') != candidate:
            return None
    except (UnicodeEncodeError, UnicodeDecodeError):
        return None
    return corrected


class _MojibakeRegexAdapter:
    """Espone .finditer() filtrando MOJIBAKE_CANDIDATE_RE con la verifica di
    round-trip, così il resto dello script (che itera su MOJIBAKE_RE.finditer)
    non deve sapere nulla della distinzione candidato/verificato."""
    def finditer(self, text):
        for m in MOJIBAKE_CANDIDATE_RE.finditer(text):
            if is_verified_mojibake(m.group()) is not None:
                yield m


MOJIBAKE_RE = _MojibakeRegexAdapter()


def get_tracked_files(root):
    """Usa git ls-files se disponibile (rispetta .gitignore), altrimenti os.walk."""
    try:
        out = subprocess.run(
            ['git', 'ls-files'], cwd=root, capture_output=True, text=True, check=True
        )
        files = [os.path.join(root, f) for f in out.stdout.splitlines() if f.strip()]
        if files:
            return files
    except Exception:
        pass

    files = []
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in EXCLUDED_DIRS]
        for fn in filenames:
            files.append(os.path.join(dirpath, fn))
    return files


def is_text_candidate(path):
    _, ext = os.path.splitext(path)
    if ext.lower() in TEXT_EXTENSIONS:
        return True
    # File senza estensione ma con nomi noti di testo del progetto.
    basename = os.path.basename(path)
    return basename in {'.htaccess', '.gitignore', 'CLAUDE.md', 'AGENTS.md'}


def check_file(path):
    """Ritorna una lista di problemi trovati nel file, vuota se pulito."""
    problems = []
    try:
        with open(path, 'rb') as f:
            raw = f.read()
    except OSError as e:
        return [f'impossibile leggere il file: {e}']

    if not raw:
        return []

    # 1) Validità UTF-8 a livello di byte.
    try:
        text = raw.decode('utf-8')
    except UnicodeDecodeError as e:
        problems.append(
            f'BYTE NON VALIDI COME UTF-8 a offset {e.start}-{e.end} '
            f'({e.reason}): {raw[max(0, e.start-15):e.end+15]!r}'
        )
        # Non possiamo proseguire con il controllo mojibake su testo che non decodifica.
        return problems

    # 2) Mojibake da doppia codifica (solo su testo che è comunque UTF-8 valido).
    for m in MOJIBAKE_RE.finditer(text):
        line_no = text.count('\n', 0, m.start()) + 1
        line_start = text.rfind('\n', 0, m.start()) + 1
        line_end = text.find('\n', m.start())
        if line_end == -1:
            line_end = len(text)
        snippet = text[line_start:line_end].strip()
        problems.append(f'MOJIBAKE riga {line_no}: "{m.group()}" nel contesto: {snippet[:120]}')

    return problems


def main(argv):
    root = subprocess.run(
        ['git', 'rev-parse', '--show-toplevel'], capture_output=True, text=True
    ).stdout.strip() or os.getcwd()

    if len(argv) > 1:
        candidates = [os.path.join(root, p) if not os.path.isabs(p) else p for p in argv[1:]]
    else:
        candidates = [p for p in get_tracked_files(root) if is_text_candidate(p)]

    total_problems = 0
    files_with_problems = 0
    for path in sorted(candidates):
        if not os.path.isfile(path):
            continue
        problems = check_file(path)
        if problems:
            files_with_problems += 1
            rel = os.path.relpath(path, root)
            print(f'\n=== {rel} ===')
            for p in problems:
                print(f'  - {p}')
                total_problems += 1

    print('\n--- Riepilogo controllo encoding ---')
    print(f'File controllati: {len(candidates)}')
    print(f'File con problemi: {files_with_problems}')
    print(f'Problemi totali: {total_problems}')

    return 1 if total_problems > 0 else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
