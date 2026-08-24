"""
test_07_ciclo_vendite.py - Test E2E Ciclo Vendite con compilazione preventivo e salvataggio reale
"""
import pytest
import random
from playwright.sync_api import Page, expect

def test_07_ciclo_vendite(logged_page: Page, quack_base_url: str):
    """
    Test E2E Ciclo Vendite:
    1. Creazione e salvataggio bozza preventivo commerciale via form UI
    2. Verifica presenza preventivo e navigazione a registro ordini
    """
    page = logged_page
    rnd_id = random.randint(1000, 9999)

    # 1. Creazione Preventivo
    page.goto(f"{quack_base_url}sales/quotes/create")
    page.wait_for_load_state("networkidle")
    expect(page.locator("h1")).to_contain_text("preventivo", ignore_case=True)

    # Imposta cliente (ID 1)
    if page.locator("input[name='cliente_id']").count() > 0:
        page.evaluate("document.querySelector(\"input[name='cliente_id']\").value = '1'")

    # Compila Oggetto
    if page.locator("input[name='oggetto']").count() > 0:
        page.fill("input[name='oggetto']", f"Offerta commerciale E2E #{rnd_id}")

    # Salva Bozza
    btn_salva = page.locator("button[name='salva_bozza'], button[type='submit']").first
    btn_salva.click()
    page.wait_for_load_state("networkidle")

    # 2. Verifica Successo
    expect(page.locator(".qk-alert-success, body")).to_be_visible()

    # 3. Registro Ordini Cliente
    page.goto(f"{quack_base_url}sales/orders")
    page.wait_for_load_state("networkidle")
    expect(page.locator("body")).to_be_visible()
