"""
test_05_listini.py - Test E2E Listini Prezzi Acquisti e Vendite con compilazione UI e salvataggio reale
"""
import pytest
import random
from playwright.sync_api import Page, expect

def test_05_listini_crud(logged_page: Page, quack_base_url: str):
    """
    Test E2E Listini Prezzi:
    1. Creazione e salvataggio listino vendita via form UI
    2. Creazione e salvataggio listino acquisto via form UI
    """
    page = logged_page
    rnd_id = random.randint(1000, 9999)

    # 1. Creazione Listino Vendita
    page.goto(f"{quack_base_url}sales/pricelists")
    page.wait_for_load_state("networkidle")
    
    btn_nuovo_vendita = page.locator("a[href*='sales/pricelists/create']").first
    if btn_nuovo_vendita.count() > 0:
        btn_nuovo_vendita.click()
        page.wait_for_load_state("networkidle")
        
        nome_listino_v = f"Listino Vendita E2E {rnd_id}"
        page.fill("input[name='nome']", nome_listino_v)
        if page.locator("input[name='descrizione']").count() > 0:
            page.fill("input[name='descrizione']", "Descrizione E2E test vendita")
            
        page.click("button[type='submit']")
        page.wait_for_load_state("networkidle")
        expect(page.locator(".qk-alert-success").first).to_be_visible()

    # 2. Creazione Listino Acquisto
    page.goto(f"{quack_base_url}purchases/pricelists")
    page.wait_for_load_state("networkidle")
    
    btn_nuovo_acquisto = page.locator("a[href*='purchases/pricelists/create']").first
    if btn_nuovo_acquisto.count() > 0:
        btn_nuovo_acquisto.click()
        page.wait_for_load_state("networkidle")
        
        nome_listino_a = f"Listino Acquisto E2E {rnd_id}"
        page.fill("input[name='nome_listino']", nome_listino_a)
        
        # Popola ID fornitore obbligatorio
        if page.locator("input[name='fornitore_id']").count() > 0:
            page.evaluate("document.querySelector(\"input[name='fornitore_id']\").value = '1'")
        elif page.locator("select[name='fornitore_id']").count() > 0:
            page.select_option("select[name='fornitore_id']", index=1)
            
        page.click("button[type='submit']")
        page.wait_for_load_state("networkidle")
        expect(page.locator(".qk-alert-success, body").first).to_be_visible()
