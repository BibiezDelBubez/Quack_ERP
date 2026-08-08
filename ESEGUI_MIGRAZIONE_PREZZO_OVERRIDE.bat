@echo off
cd /d "%~dp0"
echo ============================================
echo Migrazione: preventivi_righe.prezzo_override
echo ============================================
echo.
if exist "C:\xampp\php\php.exe" (
    C:\xampp\php\php.exe db\migrations\2026_08_07_prezzo_override_righe.php
) else (
    echo C:\xampp\php\php.exe non trovato, provo con "php" dal PATH...
    php db\migrations\2026_08_07_prezzo_override_righe.php
)
echo.
echo ============================================
echo Fine. Premi un tasto per chiudere questa finestra.
echo ============================================
pause >nul
