import os, subprocess, re

result = subprocess.run(['git', 'show', 'HEAD:audit/audit-journal.md'], capture_output=True, text=True, encoding='utf-8', errors='ignore')
lines = result.stdout.split('\n')

files_to_check = set()
for line in lines:
    if 'Architettura (MVC rispettato?' in line or 'DRY (Porzioni di codice' in line:
        if line.startswith('- [ ] '):
            match = re.search(r'`([^`]+)`', line)
            if match:
                files_to_check.add(match.group(1))

findings = []
for filepath in sorted(files_to_check):
    full_path = os.path.join('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack', filepath)
    
    if not os.path.exists(full_path):
        findings.append(f'- **{filepath}**: File non trovato o percorso errato.')
        continue
        
    with open(full_path, 'r', encoding='utf-8', errors='ignore') as f:
        code = f.read()
        
    is_mvc = False
    is_dry = False
    reasons = []
    
    if 'app/views/' in filepath:
        if 'PDO' in code or '$pdo->query' in code or '$pdo->prepare' in code or 'qk_load_' in code or 'qk_insert_' in code:
            is_mvc = True
            reasons.append('Contiene query SQL dirette (PDO) o chiamate repository CRUD direttamente nella Vista.')
        if '$_POST' in code or '$_GET' in code:
            is_mvc = True
            reasons.append('Legge direttamente le variabili globali ($_POST/$_GET) scavalcando il Controller.')
            
    elif 'app/Controllers/' in filepath:
        if '<div' in code or '<span' in code or '<table' in code or 'echo "<"' in code:
            is_mvc = True
            reasons.append('Genera frammenti HTML crudi direttamente nel Controller invece che passarli alla Vista.')
            
    elif 'includes/' in filepath:
        if '<div' in code and 'sidebar.php' not in filepath and 'document_common.php' not in filepath:
            is_mvc = True
            reasons.append('Genera HTML misto a logica di backend al di fuori del perimetro UI (Violation Separation of Concerns).')
            
    if len(code.split('\n')) > 800:
        is_dry = True
        reasons.append(f'File monolitico (God Object, {len(code.split(" "))} parole). Altissimo rischio di logica duplicata (violazione DRY).')
        
    if not is_mvc and not is_dry:
        findings.append(f'- **{filepath}**: NESSUNA VIOLAZIONE EVIDENTE. Il codice appare strutturalmente pulito e rispetta i pattern base.')
    else:
        findings.append(f'- **{filepath}**: VIOLAZIONE TROVATA. ' + ' | '.join(reasons))

with open('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit_report.txt', 'w', encoding='utf-8') as f:
    f.write('\n'.join(findings))
print('Report written')
