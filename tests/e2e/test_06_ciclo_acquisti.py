import pytest
import time
import random
import re
from playwright.sync_api import Page, expect

def test_06_ciclo_acquisti(logged_page: Page):
    """
    Test E2E per simulare un intero ciclo passivo.
    Seleziona Fornitori e Prodotti randomici già creati.
    Esegue molteplici RFQ, ne testa la modifica e l'eliminazione relazionale.
    """
    page = logged_page
    
    for i in range(3):
        # --- FASE 1: CREAZIONE PREVENTIVO FORNITORE ---
        page.goto("purchases/quotes/create")
        expect(page.locator("h1")).to_contain_text("preventivo", ignore_case=True)
        
        # Seleziona Fornitore Casuale
        fornitore_input = page.locator("#off-fornitore-label")
        fornitore_input.click()
        fornitore_input.fill("")
        fornitore_input.type("a", delay=100)
        page.keyboard.press("Enter")
        page.wait_for_selector("#off-fornitore-dropdown .qk-m2o-option:not(.is-create)", state="visible", timeout=5000)
        f_options = page.locator("#off-fornitore-dropdown .qk-m2o-option:not(.is-create)").all()
        if f_options:
            random.choice(f_options).click()
        
        page.fill("input[name='riferimento_fornitore']", f"PREV-TEST-{random.randint(1000, 9999)}")
        
        # Interazione con pulsante Stock/Commerciale (se presente)
        uom_select = page.locator("select[name='uom_mode']")
        if uom_select.count() > 0:
            val = random.choice(["stock", "commercial"])
            uom_select.select_option(val)
            time.sleep(0.5)
        
        # Aggiungiamo 2 righe
        for r_idx in range(2):
            if r_idx > 0:
                btn_add = page.locator("button:has-text('Aggiungi riga'), button:has-text('Aggiungi Riga')").first
                if btn_add.count() > 0:
                    btn_add.click()
                time.sleep(0.5)
                
            last_row = page.locator("tr.offerta-riga").nth(r_idx)
            if last_row.count() == 0:
                continue
                
            try:
                prod_input = last_row.locator(".r-prodotto-label")
                prod_input.click()
                # Il click focalizza il campo e in QkM2O innesca search('') che carica tutti i prodotti.

                drop_locator = last_row.locator(".r-prodotto-dropdown")
                drop_locator.locator(".qk-m2o-option:not(.is-create):not(.is-empty), .qk-ac-option:not(.is-create):not(.is-empty)").first.wait_for(state="visible", timeout=5000)
                    
                p_options = drop_locator.locator(".qk-m2o-option:not(.is-create):not(.is-empty), .qk-ac-option:not(.is-create):not(.is-empty)").all()
                if p_options:
                    random.choice(p_options).click()
            except:
                pass # Se non trova, lasciamo vuoto
            
            last_row.locator(".r-qty").fill(str(random.randint(1, 50)))
            time.sleep(0.5) # Diamo tempo al JS di aggiornare il prezzo dal listino
            
            val = last_row.locator(".r-prezzo").input_value()
            if not val or float(val) == 0:
                last_row.locator(".r-prezzo").fill(str(round(random.uniform(5.0, 50.0), 2)))
        
        page.click("button:has-text('Salva Preventivo')")
        expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
        expect(page).to_have_url(re.compile(r".*/purchases/quotes/edit\?id=\d+"))
        
        # --- FASE 1.5: MODIFICA ORDINE ---
        # Modifichiamo la quantità della prima riga per testare l'edit
        page.locator("tr.offerta-riga").first.locator(".r-qty").fill("99")
        page.click("button:has-text('Salva Preventivo')")
        expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
        
        # Salviamo l'id del fornitore usato
        id_fornitore = page.locator("input[name='fornitore_id']").input_value()
        
        # --- FASE 2: CONVERSIONE IN ORDINE ---
        btn_accetta = page.locator("button:has-text('Accetta e Genera Ordine')")
        if btn_accetta.count() > 0:
            btn_accetta.click()
            expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
        
        # --- FASE 3: TEST ELIMINAZIONE RELAZIONALE FORNITORE ---
        if i == 2 and id_fornitore:
            page.goto(f"suppliers/edit?id={id_fornitore}")
            
            # Clicca elimina
            btn_elimina = page.locator("form[action*='destroy'] button, button.btn-qk-danger:has-text('Elimina')").first
            if btn_elimina.count() > 0:
                page.once("dialog", lambda dialog: dialog.accept())
                btn_elimina.click()
                
                # Ci aspettiamo che NON ci sia un Fatal Error (Whoops)
                # Gestirà o con un errore amichevole (SoftDelete/Restrict) o cancellerà
                expect(page.locator("text=Whoops")).not_to_be_visible(timeout=2000)
