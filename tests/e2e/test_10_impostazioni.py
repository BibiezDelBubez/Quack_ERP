import pytest
from playwright.sync_api import Page, expect

def test_10_impostazioni(logged_page: Page):
    """
    Test E2E per il modulo Impostazioni Generali:
    1. Lettura e salvataggio dei dati aziendali base
    """
    page = logged_page
    
    # --- FASE 1: IMPOSTAZIONI GENERALI ---
    page.goto("settings/generali")
    expect(page.locator("h1")).to_contain_text("Impostazioni Generali", ignore_case=True)
    
    # Compilazione nome azienda (aggiungendo qualcosa per testare il salvataggio)
    nome_input = page.locator("input[name='azienda_nome']")
    if nome_input.count() > 0:
        val_attuale = nome_input.input_value()
        # Se vuoto o null, mettiamo Quack ERP, altrimenti appendiamo test
        if not val_attuale:
            nome_input.fill("Quack ERP (Test)")
        else:
            # per non sporcare troppo, se c'è (Test) lo togliamo, sennò lo mettiamo
            if "(Test)" in val_attuale:
                nome_input.fill(val_attuale.replace(" (Test)", ""))
            else:
                nome_input.fill(f"{val_attuale} (Test)")
                
    page.click("button[type='submit']")
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
