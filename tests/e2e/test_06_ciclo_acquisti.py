import re
import pytest
import time
import random
from playwright.sync_api import Page, expect
from faker import Faker

fake = Faker("it_IT")

def test_06_ciclo_acquisti(logged_page: Page):
    """
    Test E2E per simulare un intero ciclo passivo completo:
    0. Creazione Fornitore
    1. Creazione Prodotto
    2. Creazione Preventivo Fornitore
    3. Conversione in Ordine d'Acquisto
    4. Ricezione Merce (Carico di magazzino)
    """
    page = logged_page
    
    # Dati generati
    forn_name = "Fornitore " + fake.company()
    prod_sku = "TEST-ACQ-" + str(random.randint(1000, 9999))
    prod_name = "Prodotto da Acquistare " + str(random.randint(1, 100))
    
    # --- FASE 0.1: CREAZIONE FORNITORE ---
    page.goto("suppliers")
    page.click("a:has-text('Nuovo fornitore'), a[href$='suppliers/create']")
    
    expect(page.locator("h1")).to_contain_text("fornitore", ignore_case=True)
    
    page.fill("input[name='company']", forn_name)
    page.fill("input[name='vat']", fake.company_vat())
    page.select_option("select[name='status']", "active")
    
    page.click("button[type='submit'][form='formFornitore']")
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
    
    # --- FASE 0.2: CREAZIONE PRODOTTO ---
    page.goto("products/create")
    expect(page.locator("h1")).to_contain_text("Nuovo prodotto")
    
    page.fill("input[name='nome']", prod_name)
    page.fill("input[name='sku_interno']", prod_sku)
    
    # Assicurati che sia attivo
    chk_attivo = page.locator("input[name='attivo']")
    if not chk_attivo.is_checked():
        chk_attivo.check()
    
    page.click("button:has-text('Crea prodotto'), button[type='submit']")
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
    
    # --- FASE 1: CREAZIONE PREVENTIVO FORNITORE ---
    page.goto("purchases/quotes")
    expect(page.locator("h1")).to_contain_text("Preventivi")
    
    page.click("a.btn-qk-primary[href$='purchases/quotes/create']")
    page.wait_for_load_state("networkidle")
    expect(page.locator("h1")).to_contain_text("preventivo", ignore_case=True)
    
    # Seleziona Fornitore creato
    fornitore_input = page.locator("#off-fornitore-label")
    fornitore_input.click()
    fornitore_input.fill("")
    time.sleep(1) # Aspettiamo che il JS del M2O sia inizializzato
    fornitore_input.type(forn_name, delay=100)
    
    # Aspettiamo l'opzione che corrisponde al nome
    dropdown_opt = page.locator(f"#off-fornitore-dropdown .qk-m2o-option:has-text('{forn_name}')").first
    try:
        dropdown_opt.wait_for(state="visible", timeout=10000)
    except Exception as e:
        print("TIMEOUT! Dropdown HTML:")
        print(page.locator("#off-fornitore-dropdown").inner_html())
        raise e
    dropdown_opt.click()
    
    # Compila Riferimento Fornitore
    page.fill("input[name='riferimento_fornitore']", "PREV-TEST-001")
    
    # Usa la riga già presente di default
    last_row = page.locator("tr.offerta-riga").first
    
    # Cerca prodotto
    prod_input = last_row.locator(".r-prodotto-label")
    prod_input.click()
    prod_input.fill("")
    time.sleep(1) # Aspettiamo JS
    prod_input.type(prod_sku, delay=100)
    
    prod_dropdown = page.locator(f".r-prodotto-dropdown .qk-m2o-option:has-text('{prod_sku}'), .r-prodotto-dropdown .qk-ac-option:has-text('{prod_sku}')").first
    try:
        prod_dropdown.wait_for(state="visible", timeout=10000)
    except Exception as e:
        print("TIMEOUT! Dropdown HTML:")
        print(page.locator(".r-prodotto-dropdown").inner_html())
        raise e
    prod_dropdown.click()
    
    # Quantità e Prezzo
    last_row.locator(".r-qty").fill("10")
    last_row.locator(".r-prezzo").fill("15.50")
    
    # Salva Preventivo
    page.click("button:has-text('Salva Preventivo')")
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
    expect(page).to_have_url(re.compile(r".*/purchases/quotes/edit\?id=\d+"))
    
    # --- FASE 2: CONVERSIONE IN ORDINE ---
    page.click("button:has-text('Accetta e Genera Ordine')")
    
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
    expect(page).to_have_url(re.compile(r".*/purchases/orders/edit\?id=\d+"))
    expect(page.locator("h1")).to_contain_text("Ordine")
    
    # --- FASE 3: RICEZIONE MERCE (CARICO MAGAZZINO) ---
    ricevi_btn = page.locator("a.btn-qk-success:has-text('Ricevi Merce'), button:has-text('Ricevi Merce')")
    if ricevi_btn.count() > 0:
        ricevi_btn.first.click()
        expect(page.locator("h1")).to_contain_text("Ricezione")
        page.click("button[type='submit']:has-text('Salva')")
        expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
