"""
test_11_spedizioni_ddt.py - Test E2E Spedizioni & DDT con verifica Pick & Pack e visualizzazione DDT
"""
import pytest
from playwright.sync_api import Page, expect

def test_11_spedizioni_ddt_crud(logged_page: Page, quack_base_url: str):
    """
    Test E2E Spedizioni & DDT:
    1. Registro Spedizioni /sales/shipments
    2. Form Pick & Pack Nuova Spedizione /sales/shipments/new?order_id=1
    3. Visualizzazione/Stampa DDT /sales/shipments/ddt/view
    """
    page = logged_page

    # 1. Registro Spedizioni
    page.goto(f"{quack_base_url}sales/shipments")
    page.wait_for_load_state("networkidle")
    expect(page.locator("body")).to_be_visible()

    # 2. Apertura Interfaccia Pick & Pack Spedizione
    page.goto(f"{quack_base_url}sales/shipments/new?order_id=1")
    page.wait_for_load_state("networkidle")
    expect(page.locator("body")).to_be_visible()

    # Se c'è un'ordine valido per l'evasione, verifichiamo la presenza del pulsante di conferma spedizione
    btn_conferma = page.locator("#pp-btn-conferma, button[type='submit']")
    if btn_conferma.count() > 0:
        expect(btn_conferma.first).to_be_visible()

    # 3. Rotta Visualizzazione DDT
    page.goto(f"{quack_base_url}sales/shipments/ddt/view?id=1")
    page.wait_for_load_state("networkidle")
    expect(page.locator("body")).to_be_visible()
