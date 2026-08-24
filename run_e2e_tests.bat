@echo off
title Quack ERP - Simulatore E2E
echo ===================================================
echo     Avvio Simulazione Automatica Quack ERP (E2E)
echo ===================================================
echo.
echo I test verranno eseguiti in ordine bottom-up:
echo 01. Aziende Interne
echo 02. Categorie Prodotti
echo 03. Anagrafiche (Clienti, Fornitori)
echo 04. Prodotti
echo 05. Listini
echo 06. Ciclo Acquisti
echo 07. Ciclo Vendite
echo 08. Magazzino
echo 09. Utenti e Profilo
echo 10. Impostazioni Generali
echo 11. Spedizioni e DDT (Documenti di Trasporto)
echo.
echo.
echo Vuoi vedere il browser in azione durante il test?
echo Se scegli NO (esecuzione in background), il test sara' molto piu' veloce.
echo Se scegli SI, vedrai le schermate muoversi da sole, ma l'esecuzione sara' rallentata.
set /p VIEW_BROWSER="Scegli (S/N) [Default: N]: "

set PYTEST_ARGS=-v
if /I "%VIEW_BROWSER%"=="s" (
    set PYTEST_ARGS=-v --headed --slowmo=500
    echo.
    echo -- Hai scelto di VEDERE il browser. Preparati allo spettacolo! --
) else (
    echo.
    echo -- Hai scelto l'esecuzione VELOCE in background. Nessuna finestra si aprira'. --
)

echo.
:: Calcola dinamicamente l'URL in base al nome della cartella in cui si trova questo file
for %%I in (.) do set CURRENT_DIR=%%~nxI

echo Rilevamento automatico dell'URL del server in corso...
set QUACK_TEST_URL=http://localhost/%CURRENT_DIR%/

:: Prova a contattare la porta 8080
curl -s -f -m 2 "http://localhost:8080/%CURRENT_DIR%/login" >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    set QUACK_TEST_URL=http://localhost:8080/%CURRENT_DIR%/
) else (
    :: Prova a contattare la porta 80
    curl -s -f -m 2 "http://localhost/%CURRENT_DIR%/login" >nul 2>&1
    if %ERRORLEVEL% EQU 0 (
        set QUACK_TEST_URL=http://localhost/%CURRENT_DIR%/
    ) else (
        echo [!] Nessun server locale rilevato su 80 o 8080. Uso l'URL di default.
    )
)

echo URL base impostato per i test: %QUACK_TEST_URL%

:: Attivazione ambiente e lancio pytest
call tests\e2e\.venv\Scripts\activate.bat
pytest tests\e2e %PYTEST_ARGS%

echo.
echo ===================================================
echo Simulazione terminata.
echo.
echo I log delle scoperte e dei bug sono stati salvati in:
echo audit\scoperte_simulazione.json
echo ===================================================
pause
