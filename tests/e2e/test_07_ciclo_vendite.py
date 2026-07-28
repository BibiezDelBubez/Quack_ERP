import re
import pytest
import time
import random
from playwright.sync_api import Page, expect
from faker import Faker

fake = Faker("it_IT")

def test_07_ciclo_vendite(logged_page: Page):
    """
    Test E2E per simulare un intero ciclo attivo completo:
    0. Creazione Cliente e Prodotto
    1. Creazione Preventivo di Vendita
    2. Conversione in Ordine di Vendita
    3. Evasione Ordine (Spedizione)
    """
    page = logged_page
    
    cliente_name = "Cliente " + fake.company()
    prod_sku = "TEST-VEN-" + str(random.randint(1000, 9999))
    prod_name = "Prodotto da Vendere " + str(random.randint(1, 100))
    
    # --- FASE 0.1: CREAZIONE CLIENTE ---
    page.goto("customers/create")
    expect(page.locator("h1")).to_contain_text("Nuovo Cliente", ignore_case=True)
    expect(page.locator("text=Tipo soggetto")).to_be_visible()
    
    page.locator("input[name='type'][value='azienda']").evaluate("el => { el.checked = true; el.dispatchEvent(new Event('change', {bubbles: true})); }")
    page.locator("input[name='company']").wait_for(state="visible", timeout=10000)
    page.fill("input[name='company']", cliente_name)
    page.fill("input[name='vat']", fake.company_vat())
    
    page.click("button[type='submit'][form='formCliente']")
    expect(page.locator("text=Modifica anagrafica")).to_be_visible(timeout=10000)
    
    # --- FASE 0.2: CREAZIONE PRODOTTO ---
    page.goto("products/create")
    page.fill("input[name='nome']", prod_name)
    page.fill("input[name='sku_interno']", prod_sku)
    page.locator("input[name='tipo'][value='semplice']").check()
    
    chk_attivo = page.locator("input[name='attivo']")
    if not chk_attivo.is_checked():
        chk_attivo.check()
        
    page.click("button:has-text('Crea prodotto')")
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
    
    # --- FASE 1: CREAZIONE PREVENTIVO VENDITA ---
    page.goto("sales/quotes/create")
    expect(page.locator("h1")).to_contain_text("Nuovo preventivo", ignore_case=True)
    
    # Seleziona Cliente (QkM2O)
    cliente_input = page.locator("#cliente_search")
    cliente_input.click()
    cliente_input.fill("")
    time.sleep(1)
    cliente_input.type(cliente_name, delay=100)
    
    cliente_opt = page.locator(f"#ac-clienti-drop .qk-m2o-option:has-text('{cliente_name}'), #ac-clienti-drop .qk-ac-option:has-text('{cliente_name}')").first
    cliente_opt.wait_for(state="visible", timeout=10000)
    cliente_opt.click()
    
    # Usa la riga già presente di default
    last_row = page.locator("tr.offerta-riga").first
    
    # Cerca prodotto (QkM2O/qkAutocomplete)
    prod_input = last_row.locator(".riga-search")
    prod_input.click()
    prod_input.fill("")
    time.sleep(1)
    prod_input.type(prod_name, delay=100)
    
    prod_dropdown = page.locator(f".riga-ac-drop .qk-m2o-option:not(.is-create):has-text('{prod_name}'), .riga-ac-drop .qk-ac-option:not(.is-create):has-text('{prod_name}')").first
    prod_dropdown.wait_for(state="visible", timeout=10000)
    prod_dropdown.click()
    
    # Quantità e Prezzo
    last_row.locator(".inp-qta").fill("5")
    last_row.locator(".inp-pu").fill("25.00")
    
    # Salva Preventivo
    page.click("button:has-text('Salva e invia')")
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
    
    # Riapri il preventivo dalla lista per procedere
    page.locator("table.qk-table tbody tr").first.locator("a.btn-qk").first.click()
    expect(page.locator("h1")).to_contain_text("Preventivo", ignore_case=True)
    
    # --- FASE 2: CONVERSIONE IN ORDINE ---
    btn_accetta = page.locator("button:has-text('Genera Ordine'), a:has-text('Genera Ordine'), button:has-text('Accetta')")
    if btn_accetta.count() > 0:
        btn_accetta.first.click()
        expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
        expect(page.locator("h1")).to_contain_text("Ordine", ignore_case=True)
    
    # --- FASE 3: EVASIONE ORDINE (SPEDIZIONE) ---
    btn_evadi = page.locator("button:has-text('Evadi'), a:has-text('Evadi Ordine'), a:has-text('Crea Spedizione')")
    if btn_evadi.count() > 0:
        btn_evadi.first.click()
        # Se c'è un form di evasione, salvalo
        btn_salva_sped = page.locator("button[type='submit']:has-text('Salva')")
        if btn_salva_sped.count() > 0:
            btn_salva_sped.first.click()
            expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
