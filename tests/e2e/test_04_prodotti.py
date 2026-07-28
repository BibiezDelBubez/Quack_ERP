import re
import pytest
from playwright.sync_api import Page, expect
import openpyxl

import os
EXCEL_FILE_PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), "../../audit/MaterialePerTest/Prodotti_Tracciato.xlsx"))

def read_products_from_excel(filepath):
    """
    Legge i prodotti dal file Excel.
    """
    wb = openpyxl.load_workbook(filepath, data_only=True)
    sheet = wb.active
    
    headers = [str(cell.value).strip() if cell.value else "" for cell in sheet[1]]
    
    products = []
    for row in sheet.iter_rows(min_row=2, values_only=True):
        if not any(row):
            continue
            
        row_dict = dict(zip(headers, row))
        if not row_dict.get('sku_interno'):
            continue # Salta righe vuote o senza SKU
            
        products.append(row_dict)
        
    return products

def test_creazione_prodotti_da_excel(logged_page: Page):
    """
    Test E2E per la creazione dei prodotti a partire dal file Excel,
    navigando nell'interfaccia UI.
    """
    page = logged_page
    
    # 2. Leggi i prodotti da Excel
    products = read_products_from_excel(EXCEL_FILE_PATH)
    assert len(products) > 0, "Nessun prodotto trovato nel file Excel."
    
    # 3. Itera su ogni prodotto
    for prod in products:
        sku = str(prod.get('sku_interno', '')).strip()
        nome = str(prod.get('nome', '')).strip()
        
        # Vai alla pagina del catalogo
        page.goto("products")
        expect(page.locator("h1")).to_have_text("Prodotti")
        
        # Controlla se lo SKU esiste già, per evitare duplicati
        page.fill("input[name='q']", sku)
        page.keyboard.press("Enter")
        page.wait_for_load_state("networkidle")
        
        # Se trova una riga con questo SKU esatto, saltiamo
        # (Cerchiamo il testo dello SKU nella tabella)
        rows_with_sku = page.locator(f"table tbody tr:has-text('{sku}')").count()
        if rows_with_sku > 0:
            print(f"\nSKU {sku} già presente. Salto la creazione.")
            continue
            
        # Altrimenti, premiamo "Nuovo prodotto"
        page.click("a:has-text('Nuovo prodotto')")
        expect(page.locator("h1")).to_have_text("Nuovo prodotto")
        
        # COMPILA IL FORM
        # Dati base
        page.fill("input[name='nome']", nome)
        page.fill("input[name='sku_interno']", sku)
        
        # Tipo
        tipo = str(prod.get('tipo', 'semplice')).strip().lower()
        if tipo == 'kit':
            page.locator("input[name='tipo'][value='kit']").check()
        else:
            page.locator("input[name='tipo'][value='semplice']").check()
            
        # Categoria (M2O field)
        categoria = str(prod.get('categoria', '')).strip()
        if categoria and categoria != 'None':
            # Scriviamo nell'input del picker
            cat_input = page.locator("#cat-label-input")
            cat_input.click()
            cat_input.fill("")
            cat_input.type(categoria, delay=100)
            # Aspettiamo che appaia il dropdown
            page.wait_for_selector("#cat-m2o-dd .qk-m2o-option", state="visible", timeout=10000)
            
            # Clicchiamo la categoria giusta, se esiste
            opt_exist = page.locator(f"#cat-m2o-dd .qk-m2o-option:not(.is-create):has-text('{categoria}')")
            if opt_exist.count() > 0:
                opt_exist.first.click()
            else:
                # Clicca su crea
                page.click("#cat-m2o-dd .qk-m2o-option.is-create")
                page.wait_for_selector("#modal-new-cat", state="visible")
                page.fill("#new-cat-nome", categoria)
                page.click("#btn-crea-cat")
                page.wait_for_selector("#modal-new-cat", state="hidden")
            
        # Attivo
        attivo = str(prod.get('attivo', '1')).strip()
        chk_attivo = page.locator("input[name='attivo']")
        if attivo in ['1', 'True', 'true', 'si']:
            if not chk_attivo.is_checked():
                chk_attivo.check()
        else:
            if chk_attivo.is_checked():
                chk_attivo.uncheck()
                
        # Traccia Lotti
        traccia = str(prod.get('traccia_lotti', '1')).strip()
        chk_traccia = page.locator("input[name='traccia_lotti']")
        if traccia in ['1', 'True', 'true', 'si']:
            if not chk_traccia.is_checked():
                chk_traccia.check()
        else:
            if chk_traccia.is_checked():
                chk_traccia.uncheck()
                
        # IS ADR
        # il form HTML per ADR si trova nel tab ADR, quindi clicchiamo il tab
        page.click("#ptab-btn-adr")
        page.wait_for_selector("#ptab-adr", state="visible")
        is_adr = str(prod.get('is_adr', '0')).strip()
        chk_adr = page.locator("input[name='is_adr']")
        if is_adr in ['1', 'True', 'true', 'si']:
            if not chk_adr.is_checked():
                chk_adr.check()
        else:
            if chk_adr.is_checked():
                chk_adr.uncheck()
                
        # Torniamo al tab Dati prodotto
        page.click("#ptab-btn-dati")
        
        # Descrizioni
        desc_breve = str(prod.get('descrizione_breve', '')).strip()
        if desc_breve and desc_breve != 'None':
            page.fill("input[name='descrizione_breve']", desc_breve)
            
        desc_conf = str(prod.get('descrizione_confezione', '')).strip()
        if desc_conf and desc_conf != 'None':
            page.fill("input[name='descrizione_confezione']", desc_conf)
            
        # Formato e misure
        um_stock = str(prod.get('unita_misura_stock', 'pz')).strip()
        # the select uses values like "pz", "kg", "lt"
        if um_stock and um_stock != 'None':
            # map or set directly
            page.select_option("select[name='unita_misura']", value=um_stock)
            
        # UM commerciale uses IDs but maybe we can select by label
        um_comm = str(prod.get('unita_misura_commerciale', '')).strip()
        if um_comm and um_comm != 'None':
            # Playwright select_option can match label.
            # L'HTML ha: <option value="id">codice — descrizione</option>
            opt_locator = page.locator(f"select[name='unita_contenuto_id'] option:has-text('{um_comm} ')")
            if opt_locator.count() > 0:
                val = opt_locator.first.get_attribute("value")
                if val:
                    page.select_option("select[name='unita_contenuto_id']", value=val)
            
        qta_cont = str(prod.get('quantita_contenuto', '')).strip()
        if qta_cont and qta_cont != 'None':
            page.fill("input[name='quantita_contenuto']", qta_cont)
            
        # Peso
        peso_n = str(prod.get('peso_netto_kg', '')).strip()
        if peso_n and peso_n != 'None':
            page.fill("input[name='peso_netto_kg']", peso_n)
            
        peso_l = str(prod.get('peso_lordo_kg', '')).strip()
        if peso_l and peso_l != 'None':
            page.fill("input[name='peso_lordo_kg']", peso_l)
            
        # EAN & Ecommerce SKU
        ean = str(prod.get('ean', '')).strip()
        if ean and ean != 'None':
            page.fill("input[name='ean']", ean)
            
        sku_ecommerce = str(prod.get('sku_ecommerce', '')).strip()
        if sku_ecommerce and sku_ecommerce != 'None':
            page.fill("input[name='sku_ecommerce']", sku_ecommerce)
            
        # Note Interne
        note = str(prod.get('note_interne', '')).strip()
        if note and note != 'None':
            page.fill("textarea[name='note_interne']", note)
            
        # Salva
        page.click("button:has-text('Crea prodotto')")
        
        # Verifica successo (dovrebbe ricaricare con il flash message)
        expect(page.locator(".qk-alert-success")).to_be_visible()
        # Assicuriamoci che l'url sia cambiato in edit?id=
        expect(page).to_have_url(re.compile(r".*/products/edit\?id=\d+"))
