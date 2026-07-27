import pytest
from playwright.sync_api import Page, expect
import random
from utils.faker_it import get_azienda_interna
from utils.logger import log_scoperta

def test_creazione_azienda_interna(logged_page: Page):
    """
    Testa la creazione di un'azienda interna in modo non deterministico.
    Compila sempre i campi obbligatori, mentre gli altri sono casuali.
    """
    page = logged_page
    
    # 1. Navigazione alla lista aziende
    page.goto("settings/aziende")
    
    # Verifica che siamo nella pagina corretta (titolo o pulsante crea)
    expect(page.locator("text=Nuova Azienda")).to_be_visible()
    
    # 2. Click su Crea Nuova
    page.click("text=Nuova Azienda")
    
    # Verifica caricamento form
    expect(page.locator("input[name='ragione_sociale']")).to_be_visible()
    
    # 3. Generazione dati dummy
    dati = get_azienda_interna()
    
    # Compilazione campi obbligatori
    page.fill("input[name='ragione_sociale']", dati['ragione_sociale'])
    page.fill("input[name='partita_iva']", dati['partita_iva'])
    
    # Compilazione campi opzionali (non-deterministico, 70% di probabilità)
    compila_opzionali = random.random() > 0.3
    
    if compila_opzionali:
        page.fill("input[name='email']", dati['email'])
        page.fill("input[name='telefono']", dati['telefono'])
        page.fill("input[name='sito_web']", f"www.{dati['ragione_sociale'].replace(' ', '').lower()}.it")
        
        # Checkbox casuali
        if random.choice([True, False]):
            page.check("input[name='socio_unico']")
            
    # 4. Salvataggio
    # Il pulsante di submit è un button type submit dentro il formAzienda o col testo 'Crea azienda'
    page.click("button[type='submit'][form='formAzienda']")
    
    # 5. Verifiche
    # Ci aspettiamo un redirect alla lista (settings/aziende) o al dettaglio (settings/aziende/edit?id=...)
    # e un messaggio di successo.
    try:
        # Aspettiamo il messaggio di successo verde
        success_alert = page.locator(".qk-alert-success")
        expect(success_alert).to_be_visible(timeout=5000)
        
        # Log del successo
        log_scoperta(
            modulo="Aziende Interne", 
            test_name="Creazione Azienda",
            descrizione=f"Azienda '{dati['ragione_sociale']}' creata con successo. (Campi opzionali: {compila_opzionali})",
            dati_usati=dati
        )
    except Exception as e:
        # Se fallisce, facciamo uno screenshot e logghiamo l'errore
        page.screenshot(path="test-results/azienda_fail.png")
        log_scoperta(
            modulo="Aziende Interne", 
            test_name="Creazione Azienda - FALLITO",
            descrizione=f"Errore durante la creazione. {str(e)}",
            dati_usati=dati,
            screenshot_path="test-results/azienda_fail.png"
        )
        raise e
