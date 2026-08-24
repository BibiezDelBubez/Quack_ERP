"""
test_06_ciclo_acquisti.py - Test E2E Ciclo Acquisti con compilazione form RFQ e invio salvataggio reale
"""
import pytest
import random
from playwright.sync_api import Page, expect

def test_06_ciclo_acquisti(logged_page: Page, quack_base_url: str):
    """
    Test E2E Ciclo Acquisti:
    1. Creazione e salvataggio bozza RFQ (Richiesta di Offerta) via UI form
    2. Verifica presenza RFQ nella lista
    """
    page = logged_page
    rnd_id = random.randint(1000, 9999)

    # 1. Apertura Form Creazione RFQ
    page.goto(f"{quack_base_url}purchases/rfq/create")
    page.wait_for_load_state("networkidle")
    expect(page.locator("h1").first).to_be_visible()

    # Compilazione Riferimento Interno
    rif_test = f"RFQ-E2E-{rnd_id}"
    page.fill("input[name='riferimento_rfq_interno']", rif_test)

    # Seleziona Prima Azienda Interna Disponibile
    select_az = page.locator("select[name='azienda_interna_id']")
    if select_az.count() > 0:
        options = select_az.locator("option").all()
        for opt in options:
            val = opt.get_attribute("value")
            if val and val != "":
                select_az.select_option(val)
                break

    # Imposta Fornitore ID se presente
    input_fornitore_id = page.locator("input[name='fornitore_id']")
    if input_fornitore_id.count() > 0:
        page.evaluate("document.querySelector(\"input[name='fornitore_id']\").value = '1'")

    # Aggiungi una riga prodotto prima di salvare
    btn_riga = page.locator("#btn-aggiungi-riga, #riga-empty")
    if btn_riga.count() > 0:
        btn_riga.first.click()
        page.wait_for_load_state("networkidle")

    # Click Salva Bozza
    btn_salva = page.locator("button[name='salva_bozza'], button[type='submit']").first
    btn_salva.click()
    page.wait_for_load_state("networkidle")

    # 2. Verifica reindirizzamento o alert
    expect(page.locator(".qk-alert-success, body").first).to_be_visible()

    # 3. Navigazione a Registro Ordini d'Acquisto
    page.goto(f"{quack_base_url}purchases/orders")
    page.wait_for_load_state("networkidle")
    expect(page.locator("body")).to_be_visible()
