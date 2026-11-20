"""Run with Python Playwright against the isolated local app.

Pass a private JSON file with username, password and viewer (same password).
The test creates two synthetic counterparties and one draft.
"""

import json
import re
import secrets
import sys
from pathlib import Path
from urllib.parse import quote

from playwright.sync_api import expect, sync_playwright


def login(page, username, password):
    page.goto("/login")
    page.locator('[name="username"]').fill(username)
    page.locator('[name="password"]').fill(password)
    page.get_by_role("button", name="Войти").click()
    page.wait_for_url("**/rental/objects")


def fill_party(dialog, name, inn):
    fields = {
        "name": name,
        "inn": inn,
        "email": "manager@example.test",
        "bank": "Учебный банк",
        "account": "00000000000000000001",
        "bik": "000000001",
        "correspondent_account": "00000000000000000002",
    }
    for field, value in fields.items():
        dialog.locator(f'[name="{field}"]').fill(value)


def action_response(response):
    return response.request.method == "POST" and response.url.endswith("/rental/action")


root = Path(__file__).resolve().parents[1]
local = root / ".local"
config = json.loads((local / "runtime-settings.json").read_text(encoding="utf-8-sig"))
proof = json.loads((local / "copy-verification.json").read_text(encoding="utf-8-sig"))
assert proof["isolated_test_data"] and proof["exact_copy_before_login"]
assert config["database"] == proof["database"] and config["database"].startswith("ipphp_task_")
assert Path(config["app_root"]).resolve() == root
accounts = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8-sig"))
base_url = f'http://127.0.0.1:{config["port"]}'

with sync_playwright() as driver:
    browser = driver.chromium.launch(channel="chrome")
    page = browser.new_page(base_url=base_url, viewport={"width": 1366, "height": 768})
    login(page, accounts["username"], accounts["password"])
    token = secrets.token_hex(4)
    directory_name = f"Учебный Р-СТРОЙ {token} справочник"
    directory_url = f"/rental/parties?q={token}"
    page.goto(directory_url)
    page.get_by_role("button", name="Создать контрагента").click()
    party = page.locator("dialog[open]")
    save = party.get_by_role("button", name="Сохранить", exact=True)
    fill_party(party, "", "123")
    save.click()
    assert party.locator('[name="name"]').evaluate("(input) => input.validity.valueMissing")
    expect(party.locator('[name="inn"]')).to_have_value("123")

    party.locator('[name="name"]').fill(directory_name)
    with page.expect_response(action_response) as rejected:
        save.click()
    assert rejected.value.status == 422
    expect(party.locator("[data-rental-form-error]")).to_contain_text("ИНН должен содержать 10 или 12 цифр.")
    expect(party.locator('[name="name"]')).to_have_value(directory_name)

    # An empty INN ensures the retry is protected by the command key.
    party.locator('[name="inn"]').fill("")
    key = party.locator('[name="command_key"]').input_value()
    committed = []

    def lose_reply(route):
        response = route.fetch()
        assert response.status == 200
        committed.append(response.json()["createdParty"]["id"])
        route.abort("failed")

    page.route("**/rental/action", lose_reply, times=1)
    save.click()
    expect(party.locator("[data-rental-form-error]")).to_contain_text("Не получено подтверждение")
    expect(party.locator('[name="name"]')).to_have_value(directory_name)
    expect(party.locator('[name="command_key"]')).to_have_value(key)
    with page.expect_response(action_response) as retried:
        save.click()
    assert committed == [retried.value.json()["createdParty"]["id"]]
    expect(party).to_have_count(0)
    expect(page.get_by_role("status").filter(has_text="Контрагент сохранён.")).to_be_visible()
    expect(page).to_have_url(base_url + directory_url)
    expect(page.locator("tr").filter(has_text=directory_name)).to_have_count(1)

    page.set_viewport_size({"width": 480, "height": 390})
    page.goto("/rental/new-contract")
    contract = page.locator("dialog").filter(has=page.locator("form[data-rental-contract-form]"))
    number = contract.locator('[name="number"]')
    draft_number = f"ТЕСТ-05-{token}"
    number.fill(draft_number)
    dates = {"start_date": ("01.12.2026", "2026-12-01"), "end_date": ("31.12.2026", "2026-12-31")}
    for field, (display, _) in dates.items():
        contract.locator(f'[name="{field}"] + .ui-date-field input').fill(display)
    contract.get_by_role("button", name="Создать контрагента").click()
    contract_name = f"Учебный Р-СТРОЙ {token} договор"
    fill_party(party, contract_name, f"{secrets.randbelow(10**8):08d}99")
    with page.expect_response(action_response) as created:
        save.click()
    party_id = created.value.json()["createdParty"]["id"]
    expect(contract).to_be_visible()
    expect(contract.locator("#contract-party")).to_have_value(str(party_id))
    expect(number).to_have_value(draft_number)
    for field, (_, iso) in dates.items():
        expect(contract.locator(f'[name="{field}"]')).to_have_value(iso)
    contract.get_by_role("button", name="Сохранить в черновиках").click()
    expect(page).to_have_url(re.compile(r"/rental/contracts(?:\?|$)"))
    row = page.locator("tr[data-rental-url]").filter(has_text=draft_number)
    expect(row).to_contain_text(contract_name)
    page.goto(row.get_attribute("data-rental-url"))
    expect(contract.locator("#contract-party")).to_have_value(str(party_id))
    expect(number).to_have_value(draft_number)
    for field, (_, iso) in dates.items():
        expect(contract.locator(f'[name="{field}"]')).to_have_value(iso)
    page.goto("/rental/parties?q=" + quote(contract_name))
    expect(page.locator("tr").filter(has_text=contract_name)).to_have_count(1)

    viewer = browser.new_page(base_url=base_url)
    login(viewer, accounts["viewer"], accounts["password"])
    csrf = viewer.locator('form[action="/logout"] [name="_csrf"]').input_value()
    blocked = viewer.goto("/rental/edit-party")
    assert blocked.status == 403
    denied = viewer.request.post("/rental/action", form={
        "_csrf": csrf, "action": "party.save", "id": "0",
        "name": "Запрещённая тестовая запись", "command_key": secrets.token_hex(16),
    }, headers={"X-Requested-With": "ERP-Fetch"})
    assert denied.status == 403
    print("PASS: directory, contract draft, validation, committed lost response and one-row retry, viewer denial.")
    print(f"Synthetic reference: {token}, draft {draft_number}.")
