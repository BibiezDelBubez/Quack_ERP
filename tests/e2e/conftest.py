import pytest
import os
from playwright.sync_api import Playwright, Page

BASE_URL = os.environ.get("QUACK_TEST_URL", "http://localhost:8080/Quack_ERP/")

@pytest.fixture(scope="session")
def quack_base_url():
    url = BASE_URL
    if not url.endswith("/"):
        url += "/"
    return url

@pytest.fixture(scope="session")
def browser_context_args(browser_context_args, quack_base_url):
    return {
        **browser_context_args,
        "base_url": quack_base_url,
        "viewport": {
            "width": 1280,
            "height": 720,
        },
    }

@pytest.fixture(scope="session")
def auth_state(playwright: Playwright, quack_base_url: str) -> str:
    """Esegue il login una volta per tutta la sessione e salva lo stato."""
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(base_url=quack_base_url)
    page = context.new_page()
    
    login_url = f"{quack_base_url}login"
    page.goto(login_url)
    
    try:
        if page.locator("#username").count() > 0 or page.locator("input[name='username']").count() > 0:
            page.fill("#username, input[name='username']", "admin")
            page.fill("#password, input[name='password']", "admin123")
            page.click("button[type='submit'], input[type='submit']")
            page.wait_for_load_state('networkidle')
    except Exception as e:
        print("Login form check exception:", e)

    state_dir = "test-results"
    os.makedirs(state_dir, exist_ok=True)
    state_path = os.path.join(state_dir, "state.json")
    context.storage_state(path=state_path)
    context.close()
    browser.close()
    return state_path

@pytest.hookimpl(tryfirst=True, hookwrapper=True)
def pytest_runtest_makereport(item, call):
    outcome = yield
    rep = outcome.get_result()
    setattr(item, "rep_" + rep.when, rep)

@pytest.fixture
def logged_page(browser, auth_state, quack_base_url, request) -> Page:
    """Fixture che restituisce una pagina gia' loggata."""
    context = browser.new_context(storage_state=auth_state, base_url=quack_base_url)
    page = context.new_page()
    
    console_errors = []
    page.on("console", lambda msg: console_errors.append(msg.text) if msg.type == "error" else None)
    page.on("pageerror", lambda err: console_errors.append(f"Uncaught JS Exception: {err.message}"))
    
    yield page
    
    if hasattr(request.node, "rep_call") and request.node.rep_call.failed:
        test_name = request.node.name
        error_msg = str(request.node.rep_call.longrepr)
        
        ui_error = ""
        try:
            if page.locator(".whoops-container, .exception_message, .exc-title").count() > 0:
                testo = page.locator(".whoops-container, .exception_message, .exc-title").first.inner_text()
                ui_error = f"CRASH APPLICATIVO (500):\n{testo}"
            elif page.locator(".qk-alert-danger").count() > 0:
                testo = page.locator(".qk-alert-danger").first.inner_text()
                ui_error = f"ERRORE DI VALIDAZIONE (UI):\n{testo}"
        except Exception:
            pass
            
        if ui_error:
            error_msg = f"{ui_error}\n\n--- DETTAGLI TEST ---\n{error_msg}"
            
        if console_errors:
            error_msg = "ERRORI CONSOLE JS:\n- " + "\n- ".join(console_errors) + "\n\n" + error_msg
        
        safe_name = "".join([c if c.isalnum() else "_" for c in test_name])
        screenshot_path = f"test-results/BUG_{safe_name}.png"
        
        try:
            page.screenshot(path=screenshot_path, full_page=True)
        except Exception:
            screenshot_path = None
            
        from utils.logger import log_scoperta
        log_scoperta(
            modulo="Global Bug Tracker",
            test_name=f"{test_name} - FATAL ERROR",
            descrizione=error_msg,
            dati_usati={"url_crash": page.url},
            screenshot_path=screenshot_path
        )
        
    context.close()
