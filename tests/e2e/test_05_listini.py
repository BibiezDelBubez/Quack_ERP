import pytest
from playwright.sync_api import Page, expect
import random
from datetime import datetime, timedelta
from utils.faker_it import get_listino
from utils.logger import log_scoperta

def crea_listino(page: Page, modulo_url: str, nome_modulo: str):
    page.goto(modulo_url)
    expect(page.locator("a:has-text('Nuovo')").first).to_be_visible()
    page.click("a:has-text('Nuovo')")
    
    try:
        expect(page.locator("input[name='nome_listino']")).to_be_visible(timeout=3000)
        input_nome = "input[name='nome_listino']"
    except:
        expect(page.locator("input[name='nome']")).to_be_visible(timeout=3000)
        input_nome = "input[name='nome']"
        
    dati = get_listino()
    
    # Suffisso per distinguere
    dati['nome'] = f"{dati['nome']} - {nome_modulo}"
    
    page.fill(input_nome, dati['nome'])
    
    compila_opzionali = random.random() > 0.3
    if compila_opzionali:
        try:
            page.fill("input[name='descrizione']", dati['descrizione'])
        except:
            pass
        oggi = datetime.now()
        valido_al = oggi + timedelta(days=30)
        page.fill("input[name='valido_dal']", oggi.strftime('%Y-%m-%d'))
        page.fill("input[name='valido_al']", valido_al.strftime('%Y-%m-%d'))
        
    attivo = random.choice([True, True, True, False])
    chk_attivo = page.locator("input#pl-attivo")
    if chk_attivo.count() > 0:
        if attivo:
            if not chk_attivo.is_checked():
                chk_attivo.check()
        else:
            if chk_attivo.is_checked():
                chk_attivo.uncheck()
            
    page.click("button[type='submit']")
    
    try:
        expect(page.locator(".qk-alert-success")).to_be_visible(timeout=5000)
        
        # --- COMPILAZIONE LISTINO (Aggiunta Riga) ---
        if page.locator("#btn-add-riga").count() > 0:
            page.click("#btn-add-riga")
            page.wait_for_timeout(500)
            
            prod_input = page.locator(".pl-riga-new .r-prodotto-label, .is-editing .r-prodotto-label").first
            if prod_input.count() > 0:
                prod_input.click()
                prod_input.type("e", delay=100)
                try:
                    page.wait_for_selector(".r-prodotto-dropdown .qk-m2o-option:not(.is-create)", state="visible", timeout=5000)
                    opts = page.locator(".r-prodotto-dropdown .qk-m2o-option:not(.is-create)").all()
                    if opts:
                        random.choice(opts).click()
                except:
                    pass
                
                # Compila prezzo
                prezzo_input = page.locator(".pl-riga-new .r-prezzo, .is-editing .r-prezzo").first
                if prezzo_input.count() > 0:
                    prezzo_input.fill(str(round(random.uniform(5.0, 50.0), 2)))
                    page.keyboard.press("Enter")
                    page.wait_for_timeout(1000)
                    
        # Interazione con pulsante visualizzazione Stock / Commerciale in griglia listini
        if page.locator("#uom-toggle").count() > 0:
            page.select_option("#uom-toggle", "comm")
            page.wait_for_timeout(500)
            page.select_option("#uom-toggle", "stock")
            page.wait_for_timeout(500)
                    
        log_scoperta(
            modulo=f"Listini {nome_modulo}", 
            test_name="Creazione Listino",
            descrizione=f"Listino '{dati['nome']}' creato con successo.",
            dati_usati=dati
        )
    except Exception as e:
        page.screenshot(path=f"test-results/listino_fail_{nome_modulo}.png")
        log_scoperta(
            modulo=f"Listini {nome_modulo}", 
            test_name="Creazione Listino - FALLITO",
            descrizione=f"Errore: {str(e)}",
            dati_usati=dati,
            screenshot_path=f"test-results/listino_fail_{nome_modulo}.png"
        )
        raise e

def test_eliminazione_listino(logged_page: Page):
    """
    Test di eliminazione di un listino fittizio.
    """
    page = logged_page
    dati = get_listino()
    
    # 1. Creiamo un listino dummy
    page.goto("sales/pricelists")
    page.click("text=Nuovo listino")
    page.fill("input[name='nome']", dati['nome'] + " DUMMY")
    page.click("button[type='submit']")
    page.wait_for_load_state('networkidle')
    
    # 2. Torniamo alla lista e lo eliminiamo
    page.goto("sales/pricelists")
    page.fill("input[name='q']", "DUMMY")
    page.keyboard.press("Enter")
    page.wait_for_load_state('networkidle')
    
    btn_elimina = page.locator("table tbody tr:has-text('DUMMY') .btn-qk-danger, table tbody tr:has-text('DUMMY') form[action*='destroy'] button").first
    if btn_elimina.count() > 0:
        page.once("dialog", lambda dialog: dialog.accept())
        btn_elimina.click()
        expect(page.locator(".qk-alert-success")).to_be_visible()

def test_creazione_listini(logged_page: Page):
    """
    Testa la creazione di listini di vendita e acquisto.
    """
    page = logged_page
    
    # Creiamo 2 listini di vendita
    for _ in range(2):
        crea_listino(page, "sales/pricelists", "Vendita")
        
    # Creiamo 2 listini di acquisto
    for _ in range(2):
        crea_listino(page, "purchases/pricelists", "Acquisto")
