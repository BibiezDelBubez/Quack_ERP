import pytest
import random
from playwright.sync_api import Page, expect
from utils.logger import log_scoperta
from utils.faker_it import get_fornitore, get_cliente

def test_creazione_fornitore(logged_page: Page):
    """
    Testa la creazione di un nuovo fornitore.
    """
    page = logged_page
    fornitore = get_fornitore()
    
    # 1. Navigazione in fornitori
    page.goto("suppliers")
    expect(page.locator("h1:has-text('Fornitori')")).to_be_visible()
    
    # 2. Clicca su Nuovo
    page.click("a.btn-qk-primary[href$='suppliers/create']")
    
    # 3. Compila form
    expect(page.locator("text=Dati azienda")).to_be_visible()
    
    page.fill("input[name='company']", fornitore["company"])
    page.fill("input[name='vat']", fornitore["vat"])
    page.fill("input[name='email']", fornitore["email"])
    page.fill("input[name='phone']", fornitore["phone"])
    page.fill("input[name='website']", fornitore["website"])
    
    # Seleziona status attivo
    page.select_option("select[name='status']", "active")
    
    # Salva (il bottone ha id o classe specifici, usiamo il testo o il tipo submit)
    page.click("button[type='submit'][form='formFornitore']")
    
    # 4. Verifica successo (redirect a edit)
    try:
        page.wait_for_load_state('networkidle')
        expect(page.locator("text=Modifica anagrafica")).to_be_visible(timeout=5000)
        
        log_scoperta(
            modulo="Fornitori", 
            test_name="Creazione Fornitore",
            descrizione=f"Fornitore '{fornitore['company']}' creato con successo.",
            dati_usati=fornitore
        )
    except Exception as e:
        page.screenshot(path="test-results/fornitore_fail.png")
        log_scoperta(
            modulo="Fornitori", 
            test_name="Creazione Fornitore - FALLITO",
            descrizione=f"Impossibile creare fornitore. {str(e)}",
            dati_usati=fornitore,
            screenshot_path="test-results/fornitore_fail.png"
        )
        raise

def test_creazione_cliente(logged_page: Page):
    """
    Testa la creazione di un cliente (privato o azienda).
    Garantisce non-determinismo scegliendo a caso il tipo di cliente.
    """
    page = logged_page
    tipo = random.choice(["azienda", "privato"])
    cliente = get_cliente(tipo=tipo)
    
    # 1. Navigazione in clienti
    page.goto("customers")
    expect(page.locator("h1:has-text('Clienti')")).to_be_visible()
    
    # 2. Clicca su Nuovo
    page.click("a.btn-qk-primary[href$='customers/create']")
    
    # 3. Compila form
    expect(page.locator("text=Tipo soggetto")).to_be_visible()
    
    # Seleziona tipo (forzando il check e l'evento change in JS per bypassare l'UI custom)
    page.locator(f"input[name='type'][value='{tipo}']").evaluate("el => { el.checked = true; el.dispatchEvent(new Event('change', {bubbles: true})); }")
    
    if tipo == "privato":
        page.fill("input[name='first_name']", cliente["first_name"])
        page.fill("input[name='last_name']", cliente["last_name"])
        page.fill("input[name='tax_code']", cliente["tax_code"])
    else:
        page.fill("input[name='company']", cliente["company"])
        page.fill("input[name='vat']", cliente["vat"])
        page.fill("input[name='sdi_code']", cliente["sdi_code"])
        
    page.fill("input[name='email']", cliente["email"])
    page.fill("input[name='phone']", cliente["phone"])
    
    # Salva
    page.click("button[type='submit'][form='formCliente']")
    
    # 4. Verifica successo (redirect a edit)
    try:
        page.wait_for_load_state('networkidle')
        expect(page.locator("text=Modifica anagrafica")).to_be_visible(timeout=5000)
        
        nome_log = cliente["company"] if tipo == "azienda" else f"{cliente['first_name']} {cliente['last_name']}"
        log_scoperta(
            modulo="Clienti", 
            test_name="Creazione Cliente",
            descrizione=f"Cliente {tipo} '{nome_log}' creato con successo.",
            dati_usati=cliente
        )
    except Exception as e:
        page.screenshot(path="test-results/cliente_fail.png")
        log_scoperta(
            modulo="Clienti", 
            test_name="Creazione Cliente - FALLITO",
            descrizione=f"Impossibile creare cliente {tipo}. {str(e)}",
            dati_usati=cliente,
            screenshot_path="test-results/cliente_fail.png"
        )
        raise
