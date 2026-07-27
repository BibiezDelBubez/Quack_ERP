import pytest
from playwright.sync_api import Page, expect
import random
from datetime import datetime, timedelta
from utils.faker_it import get_listino
from utils.logger import log_scoperta

def test_creazione_listino(logged_page: Page):
    """
    Testa la creazione di un listino di vendita in modo non deterministico.
    """
    page = logged_page
    
    # 1. Navigazione alla lista listini
    page.goto("sales/pricelists")
    
    # Verifica che siamo nella pagina corretta (titolo o pulsante crea)
    expect(page.locator("text=Nuovo listino")).to_be_visible()
    
    # 2. Click su Crea Nuovo
    page.click("text=Nuovo listino")
    
    # Verifica caricamento form
    expect(page.locator("input[name='nome']")).to_be_visible()
    
    # 3. Generazione dati dummy
    dati = get_listino()
    
    # Compilazione nome
    page.fill("input[name='nome']", dati['nome'])
    
    # Compilazione opzionali (non-deterministico, 70% di probabilità)
    compila_opzionali = random.random() > 0.3
    
    if compila_opzionali:
        page.fill("input[name='descrizione']", dati['descrizione'])
        
        # Date di validità (da oggi a 30 giorni)
        oggi = datetime.now()
        valido_al = oggi + timedelta(days=30)
        page.fill("input[name='valido_dal']", oggi.strftime('%Y-%m-%d'))
        page.fill("input[name='valido_al']", valido_al.strftime('%Y-%m-%d'))
        
    # Attivo (non-deterministico)
    # il default è attivo, quindi randomicamente lo disattiviamo
    attivo = random.choice([True, True, True, False]) # 75% di probabilità di essere attivo
    chk_attivo = page.locator("input#pl-attivo")
    if attivo:
        if not chk_attivo.is_checked():
            chk_attivo.check()
    else:
        if chk_attivo.is_checked():
            chk_attivo.uncheck()
            
    # 4. Salvataggio
    page.click("button[type='submit']")
    
    # 5. Verifiche
    try:
        # Aspettiamo il messaggio di successo verde
        success_alert = page.locator(".qk-alert-success")
        expect(success_alert).to_be_visible(timeout=5000)
        
        # Log del successo
        log_scoperta(
            modulo="Listini di Vendita", 
            test_name="Creazione Listino",
            descrizione=f"Listino '{dati['nome']}' creato con successo. (Campi opzionali: {compila_opzionali})",
            dati_usati=dati
        )
    except Exception as e:
        # Se fallisce, facciamo uno screenshot e logghiamo l'errore
        page.screenshot(path="test-results/listino_fail.png")
        log_scoperta(
            modulo="Listini di Vendita", 
            test_name="Creazione Listino - FALLITO",
            descrizione=f"Errore durante la creazione. {str(e)}",
            dati_usati=dati,
            screenshot_path="test-results/listino_fail.png"
        )
        raise e
