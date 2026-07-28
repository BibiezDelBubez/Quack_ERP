import pytest
import random
from playwright.sync_api import Page, expect
from faker import Faker

fake = Faker("it_IT")

def test_09_utenti(logged_page: Page):
    """
    Test E2E per il modulo Utenti e Profilo:
    1. Creazione di un nuovo utente con ruolo
    2. Modifica del proprio profilo
    """
    page = logged_page
    
    nome = fake.first_name()
    cognome = fake.last_name()
    email = f"test.user.{random.randint(1000, 9999)}@quack.local"
    
    # --- FASE 1: CREAZIONE UTENTE ---
    page.goto("users/create")
    
    # Potrebbe esserci una redirect o errore se manca il permesso, ma andiamo su users/create
    if page.locator("text=Pagina non trovata").count() > 0 or page.locator(".error-page").count() > 0:
        page.goto("settings/users/create")
        
    expect(page.locator("h1")).to_contain_text("utente", ignore_case=True)
    
    page.fill("input[name='first_name'], input[name='nome']", nome)
    page.fill("input[name='last_name'], input[name='cognome']", cognome)
    page.fill("input[name='email']", email)
    page.fill("input[name='username']", email.split('@')[0])
    
    # Password
    page.fill("input[name='password']", "Password123!")
    page.fill("input[name='password_confirm']", "Password123!")
        
    # Ruolo
    if page.locator("input[name='role_id']").count() > 0 or page.locator("input[type='radio'][name='role_id']").count() > 0:
        # Sembra che i ruoli siano radio button! Selezioniamo il primo o un admin
        pass # Di default è già selezionato un radio
    else:
        try:
            page.select_option("select[name='role_id']", index=1)
        except:
            pass
        
    page.click("button[type='submit']")
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
    
    # --- FASE 2: PROFILO ---
    page.goto("profile")
    expect(page.locator("h1")).to_contain_text("Profilo", ignore_case=True)
    
    # Modifichiamo un campo a caso, es. telefono o lingua
    if page.locator("input[name='phone']").count() > 0:
        page.fill("input[name='phone']", fake.phone_number())
        
    page.click("button[type='submit']")
    expect(page.locator(".qk-alert-success")).to_be_visible(timeout=10000)
