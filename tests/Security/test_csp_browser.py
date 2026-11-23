"""Browser regression under nonce-only CSP. Requires Playwright/Chromium and a Twig autoloader."""
import os
from pathlib import Path
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Thread
from urllib.parse import parse_qs

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
HTML = subprocess.check_output([os.environ.get("PHP", "php"), str(Path(__file__).with_name("render-csp-fixture.php"))])
posts = []


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_GET(self):
        script = self.path == "/js/uxconnector.js"
        body = (ROOT / "js/uxconnector.js").read_bytes() if script else HTML
        self.send_response(200)
        self.send_header("Content-Type", "application/javascript" if script else "text/html; charset=utf-8")
        self.send_header("Content-Security-Policy", "default-src 'self'; script-src 'nonce-qa-nonce'; script-src-attr 'none'; object-src 'none'; base-uri 'self'")
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        posts.append(parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode()))
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"OK")


server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
Thread(target=server.serve_forever, daemon=True).start()
try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
        page = browser.new_page()
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.on("console", lambda message: errors.append(message.text) if message.type == 'error' else None)
        page.goto(f"http://127.0.0.1:{server.server_port}/")
        dialogs = []

        def respond(accept):
            def callback(dialog):
                dialogs.append(dialog.message)
                dialog.accept() if accept else dialog.dismiss()
            page.once("dialog", callback)

        for index in range(11):
            before = len(posts)
            respond(False)
            page.locator(f"#confirm-{index} button").click()
            assert len(posts) == before, (index, dialogs, errors)
            assert '« QA & "quote" <text> »' in dialogs[-1]
            respond(True)
            with page.expect_response(lambda response: response.request.method == "POST"):
                page.locator(f"#confirm-{index} button").click()

        respond(False)
        before = len(dialogs)
        page.locator("#confirm-0 input").press("Enter")
        assert len(dialogs) == before + 1
        page.evaluate("""() => {
            const form = document.createElement('form');
            form.id = 'dynamic'; form.dataset.uxconnectorConfirm = 'Dynamic?';
            form.innerHTML = '<button>Dynamic</button>'; document.body.append(form);
        }""")
        respond(False)
        page.locator("#dynamic button").click()
        assert dialogs[-1] == "Dynamic?"

        before = len(dialogs)
        with page.expect_response(lambda response: response.request.method == "POST"):
            page.locator('#credentials button[value="reveal"]').click()
        assert len(dialogs) == before
        respond(False)
        page.locator('#credentials button[value="rotate"]').click()
        assert dialogs[-1] == "Rotate?"

        # Cancellation must not latch the double-submit protection.
        respond(False)
        page.locator("#run button").click()
        expect(page.locator("#run button")).to_be_enabled()
        respond(True)
        before = len(dialogs)
        before_posts = len(posts)
        with page.expect_response(lambda response: response.request.method == "POST") as response_info:
            page.evaluate("""() => {
                const form = document.getElementById('run'), button = form.querySelector('button');
                form.requestSubmit(button); form.requestSubmit(button);
            }""")
        assert len(dialogs) == before + 1
        assert len(posts) == before_posts + 1
        assert parse_qs(response_info.value.request.post_data)["run_job"] == ["1"]
        expect(page.locator("#run button")).to_be_disabled()
        page.evaluate("window.dispatchEvent(new Event('pageshow'))")
        expect(page.locator("#run button")).to_be_enabled()

        page.locator("#uxconnector-select-all-jobs-toolbar").check()
        expect(page.locator("#uxconnector-selected-count")).to_have_text("2 selected")
        with page.expect_response(lambda response: response.request.method == "POST") as response_info:
            page.locator("#uxconnector-bulk-jobs button").click()
        assert parse_qs(response_info.value.request.post_data)["job_ids[]"] == ["28", "31"]
        page.locator("#uxconnector-auth-method").select_option("glpi_rest_v1")
        expect(page.locator("#uxconnector-generic-credential")).to_be_hidden()
        expect(page.locator("#uxconnector-glpi-rest-credentials")).to_be_visible()
        page.locator("#uxconnector-auth-method").select_option("bearer_token")
        expect(page.locator("#uxconnector-generic-credential")).to_be_visible()
        page.locator("[data-uxconnector-connector-selector]").select_option("a&b=1")
        page.wait_for_url("**/connection.form.php?connector=a%26b%3D1")
        assert errors == [], errors
        print(f"OK: 11 translated confirmations, keyboard, dynamic forms, rotation, bulk actions, double submission, credential panels and encoded redirect; CSP nonce-only; Chromium {browser.version}.")
        browser.close()
finally:
    server.shutdown()
