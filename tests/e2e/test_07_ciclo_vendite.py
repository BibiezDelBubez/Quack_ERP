import pytest
import time
import random
import re
from playwright.sync_api import Page, expect

def test_07_ciclo_vendite(logged_page: Page):
    """
    Test E2E per simulare un intero ciclo attivo completo.
    Utilizza anagrafiche e prodotti randomici.
    Itera più volte e testa la cancellazione relazionale del cliente.
    """
    page = logged_page
    
    for i in range(3):
        # --- FASE 1: CREAZIONE PREVENTIVO VENDITA ---
        page.goto("sales/quotes/create")
        expect(page.locator("h1")).to_contain_text("Nuovo preventivo", ignore_case=True)
        
        # Seleziona Cliente Casuale
        cliente_input = page.locator("#cliente_search")
        if cliente_input.count() > 0:
            cliente_input.click()
            cliente_input.fill("")
            cliente_input.type("a", delay=100)
            page.keyboard.press("Enter")

            try:
                page.wait_for_selector("#ac-clienti-drop .qk-m2o-option:not(.is-create), #ac-clienti-drop .qk-ac-option:not(.is-create)", state="visible", timeout=5000)
                c_options = page.locator("#ac-clienti-drop .qk-m2o-option:not(.is-create), #ac-clienti-drop .qk-ac-option:not(.is-create)").all()
                if c_options:
                    random.choice(c_options).click()
            except:
                pass
        
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
                
            prod_input = last_row.locator(".riga-search")
            prod_input.click()
            # Il click focalizza il campo e in QkM2O innesca search('') che carica tutti i prodotti.

            try:
                drop_locator = last_row.locator(".riga-ac-drop")
                # Escludiamo esplicitamente l'opzione .is-empty ("Nessun risultato")
                drop_locator.locator(".qk-m2o-option:not(.is-create):not(.is-empty)").first.wait_for(state="visible", timeout=5000)
                p_options = drop_locator.locator(".qk-m2o-option:not(.is-create):not(.is-empty)").all()
                if p_options:
                    random.choice(p_options).click()
            except:
                pass
            
            last_row.locator(".inp-qta").fill(str(random.randint(1, 10)))
            time.sleep(1) # Attendi compilazione prezzo ajax
            
            val = last_row.locator(".inp-pu").input_value()
            if not val or float(val) == 0:
                # Prezzo non caricato automaticamente. Lo impostiamo noi.
                last_row.locator(".inp-pu").fill(str(round(random.uniform(10.0, 150.0), 2)))
            else:
                # Il RRP o Listino ha funzionato!
                print(f"RRP / Listino auto-popolato con successo: {val}")
                
            # Aggiungiamo uno sconto random
            sconto_input = last_row.locator(".inp-sconto").first
            if sconto_input.count() > 0:
                sconto_input.fill(str(random.randint(0, 15)))
        
        # Estrarre ID cliente scelto prima di salvare
        id_cliente = page.locator("input[name='cliente_id']").input_value()
        
        # Salva Preventivo
        btn_salva = page.locator("button:has-text('Salva e invia'), button:has-text('Salva Preventivo')").first
        if btn_salva.count() > 0:
            btn_salva.click()
            expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
            
        # Il salvataggio reindirizza alla lista preventivi. 
        # Clicchiamo sul primo (quello appena creato) per entrare in modifica.
        page.click("table.qk-table tbody tr:first-child a[title='Modifica']")
        page.wait_for_selector("h1")
        
        # --- FASE 2: CONVERSIONE IN ORDINE ---
        btn_accetta = page.locator("button:has-text('Genera Ordine'), a:has-text('Genera Ordine'), button:has-text('Accetta')")
        if btn_accetta.count() > 0:
            btn_accetta.first.click()
            expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
        
        # --- FASE 3: TEST ELIMINAZIONE RELAZIONALE CLIENTE ---
        if i == 2 and id_cliente:
            page.goto(f"customers/edit?id={id_cliente}")
            
            # Clicca elimina
            btn_elimina = page.locator("form[action*='destroy'] button, button.btn-qk-danger:has-text('Elimina')").first
            if btn_elimina.count() > 0:
                page.once("dialog", lambda dialog: dialog.accept())
                btn_elimina.click()
                
                # Ci aspettiamo che NON ci sia un Fatal Error (Whoops)
                expect(page.locator("text=Whoops")).not_to_be_visible(timeout=2000)
