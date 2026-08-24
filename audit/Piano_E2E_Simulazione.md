# Sistema di Test Automatizzato E2E (End-to-End) per Quack ERP

L'implementazione del sistema automatizzato di simulazione utente tramite browser segue il pattern **End-to-End (E2E) Testing**, emulando le reali interazioni utente sul DOM dell'applicazione web via browser (Playwright + pytest in Python 3.13).

---

## 1. Stack Tecnologico

- **Linguaggio**: Python 3.13
- **Motore di Automazione Browser**: [Playwright](https://playwright.dev/python/)
- **Test Runner**: `pytest`
- **Generazione Dati Dummy**: `Faker` (locale italiano `it_IT`)
- **Gestione Ambiente**: Ambiente virtuale locale in `tests/e2e/.venv/`
- **Log Scoperte & Bug**: `audit/scoperte_simulazione.json`

---

## 2. Architettura delle Suite di Test (`tests/e2e/`)

I test sono organizzati in ordine di dipendenza incrementale Bottom-Up. Ciascun file copre le operazioni **CRUD (Create, Read, Update, Delete/Disattivazione, Duplicazione)** ed i relativi casi limite per le entità di competenza:

1. `conftest.py`: Fixture pytest per setup/teardown browser Playwright, autenticazione automatica e contesti isolati.
2. `utils/logger.py`: Helper per la registrazione strutturata di anomalie ed eccezioni in `audit/scoperte_simulazione.json`.
3. `utils/faker_it.py`: Generatore dati anagrafici e commerciali coerenti.
4. `test_01_aziende_interne.py`: CRUD Aziende Interne ed emittenti.
5. `test_02_categorie_prodotti.py`: CRUD Categorie Prodotti (gerarchia padre/figlio, esclusioni).
6. `test_03_anagrafiche.py`: CRUD Fornitori e Clienti (sedi legali, indirizzi di consegna multipli, contatti, fido).
7. `test_04_prodotti.py`: CRUD Prodotti (Stock UoM, UoM commerciale, conversioni, lotti, compliance).
8. `test_05_listini.py`: CRUD Listini Prezzi d'Acquisto e Listini Prezzi di Vendita (Base #1, cliente).
9. `test_06_ciclo_acquisti.py`: CRUD RFQ -> Offerta Fornitore -> Ordini d'Acquisto -> Ricezioni Magazzino.
10. `test_07_ciclo_vendite.py`: CRUD Preventivi di Vendita (prezzi listino, override, clonazione) -> Ordini Cliente (riserva stock).
11. `test_08_magazzino.py`: CRUD Inventari di Magazzino, Trasferimenti Interni e Conversione Lotti.
12. `test_09_utenti.py`: CRUD Utenti, Ruoli e Permessi.
13. `test_10_impostazioni.py`: CRUD Impostazioni Generali (soglie margine, IVA, numerazioni).
14. `test_11_spedizioni_ddt.py`: CRUD Spedizioni da Ordini Cliente, Selezione Lotti, DDT e DDT Stampa/Annullamento.

*(La gestione ADR è esplicitamente esclusa da questa release).*

---

## 3. Esecuzione

Il sistema può essere lanciato tramite lo script batch dedicato:
- `run_e2e_tests.bat`: Avvia la suite consentendo di scegliere l'esecuzione visiva nel browser (`--headed`) o ultra-veloce in background.
