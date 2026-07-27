@echo off
title Quack ERP - Simulatore E2E
echo ===================================================
echo     Avvio Simulazione Automatica Quack ERP (E2E)
echo ===================================================
echo.
echo I test verranno eseguiti in ordine bottom-up:
echo dalle anagrafiche di base fino ai flussi complessi.
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
