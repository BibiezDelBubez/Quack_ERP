import pytest
import random
import time
from playwright.sync_api import Page, expect
from utils.logger import log_scoperta
from utils.faker_it import get_fornitore, get_cliente

def test_gestione_fornitori(logged_page: Page):
    """
    Testa la creazione di 5 fornitori.
    Applica una modifica a uno di essi.
    Esegue un test di cancellazione su un fornitore dummy.
    """
    page = logged_page
    
    # --- CREAZIONE 5 FORNITORI ---
    for i in range(5):
        fornitore = get_fornitore()
        page.goto("suppliers")
        page.click("a.btn-qk-primary[href$='suppliers/create']")
        
        page.fill("input[name='company']", fornitore["company"])
        page.fill("input[name='vat']", fornitore["vat"])
        page.fill("input[name='email']", fornitore["email"])
        page.fill("input[name='phone']", fornitore["phone"])
        page.fill("input[name='website']", fornitore["website"])
        page.select_option("select[name='status']", "active")
        
        page.click("button[type='submit'][form='formFornitore']")
        
        page.wait_for_load_state('networkidle')
        expect(page.locator("text=Modifica anagrafica")).to_be_visible(timeout=5000)
        
        # Test Modifica sull'ultimo fornitore inserito
        if i == 4:
            nuova_email = "modificato_" + fornitore["email"]
            page.fill("input[name='email']", nuova_email)
            page.click("button[type='submit'][form='formFornitore']")
            expect(page.locator(".qk-alert-success")).to_be_visible()
            
        log_scoperta(
            modulo="Fornitori", 
            test_name="Creazione Fornitore",
            descrizione=f"Fornitore '{fornitore['company']}' creato.",
            dati_usati=fornitore
        )

    # --- TEST ELIMINAZIONE FORNITORE ---
    fornitore_dummy = get_fornitore()
    page.goto("suppliers/create")
    page.fill("input[name='company']", fornitore_dummy["company"] + " DUMMY")
    page.fill("input[name='vat']", fornitore_dummy["vat"])
    page.click("button[type='submit'][form='formFornitore']")
    
    page.wait_for_load_state('networkidle')
    
    # Eliminazione
    page.goto("suppliers")
    page.fill("input[name='q']", "DUMMY")
    page.keyboard.press("Enter")
    page.wait_for_load_state("networkidle")
    
    btn_elimina = page.locator(f"table tbody tr:has-text('DUMMY') .btn-qk-danger, table tbody tr:has-text('DUMMY') form[action*='destroy'] button").first
    if btn_elimina.count() > 0:
        page.once("dialog", lambda dialog: dialog.accept())
        btn_elimina.click()
        expect(page.locator(".qk-alert-success")).to_be_visible()

def test_gestione_clienti(logged_page: Page):
    """
    Testa la creazione di 20 clienti.
    Aggiunge test di modifica sull'ultimo.
    """
    page = logged_page
    
    for i in range(20):
        tipo = random.choice(["azienda", "privato"])
        cliente = get_cliente(tipo=tipo)
        
        page.goto("customers/create")
        
        # Tipo soggetto
        page.locator(f"input[name='type'][value='{tipo}']").evaluate("el => { el.checked = true; el.dispatchEvent(new Event('change', {bubbles: true})); }")
        
        if tipo == "privato":
            page.fill("input[name='first_name']", cliente["first_name"])
            page.fill("input[name='last_name']", cliente["last_name"])
            page.fill("input[name='tax_code']", cliente["tax_code"])
            nome_log = f"{cliente['first_name']} {cliente['last_name']}"
        else:
            page.fill("input[name='company']", cliente["company"])
            page.fill("input[name='vat']", cliente["vat"])
            page.fill("input[name='sdi_code']", cliente["sdi_code"])
            nome_log = cliente["company"]
            
        page.fill("input[name='email']", cliente["email"])
        page.fill("input[name='phone']", cliente["phone"])
        
        page.click("button[type='submit'][form='formCliente']")
        page.wait_for_load_state('networkidle')
        expect(page.locator("text=Modifica anagrafica")).to_be_visible(timeout=5000)
        
        # Test Modifica sull'ultimo cliente inserito
        if i == 19:
            nuovo_tel = "000000000"
            page.fill("input[name='phone']", nuovo_tel)
            page.click("button[type='submit'][form='formCliente']")
            expect(page.locator(".qk-alert-success")).to_be_visible()

        log_scoperta(
            modulo="Clienti", 
            test_name="Creazione Cliente",
            descrizione=f"Cliente {tipo} '{nome_log}' creato.",
            dati_usati=cliente
        )
