import pytest
from playwright.sync_api import Playwright, Page, expect

import os

BASE_URL = os.environ.get("QUACK_TEST_URL", "http://localhost/Quack/")

@pytest.fixture(scope="session")
def browser_context_args(browser_context_args):
    return {
        **browser_context_args,
        "base_url": BASE_URL,
        "viewport": {
            "width": 1280,
            "height": 720,
        },
    }

@pytest.fixture(scope="session")
def auth_state(playwright: Playwright) -> str:
    """Esegue il login una volta per tutta la sessione e salva lo stato."""
    # Uso un context separato per il login
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(base_url=BASE_URL)
    page = context.new_page()
    
    page.goto("index.php")
    
    # Aspettiamo che il form di login sia visibile.
    # Gestisco il caso in cui potrebbe già essere loggato o richiedere il login.
    # A seconda del sistema, l'input potrebbe avere name="username" o simile.
    # In Quack, usiamo selettori generici che possiamo affinare.
    try:
        if page.locator("input[name='username']").count() > 0 or page.locator("input[type='text']").first.count() > 0:
            # Compila il login
            page.fill("input[name='username']", "admin")
            page.fill("input[name='password']", "admin123")
            page.click("button[type='submit'], input[type='submit']")
            page.wait_for_load_state('networkidle')
    except Exception as e:
        print("Login form not found or already logged in:", e)

    # Salva lo stato in un file temporaneo
    import os
    state_dir = "test-results"
    os.makedirs(state_dir, exist_ok=True)
    state_path = os.path.join(state_dir, "state.json")
    context.storage_state(path=state_path)
    context.close()
    browser.close()
    return state_path

@pytest.fixture
def logged_page(browser, auth_state) -> Page:
    """Fixture che restituisce una pagina già loggata."""
    context = browser.new_context(storage_state=auth_state, base_url=BASE_URL)
    page = context.new_page()
    yield page
    context.close()
