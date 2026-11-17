"""Offline compiler for the owner-supplied rental baseline. No database/network access."""
import argparse
import hashlib
import json
import re
import zipfile
from collections import Counter, defaultdict
from decimal import Decimal
from pathlib import Path


def read(path):
    return json.loads(Path(path).read_text(encoding="utf-8-sig"), parse_float=Decimal)


def lines(path):
    return [json.loads(x, parse_float=Decimal) for x in Path(path).read_text(encoding="utf-8-sig").splitlines() if x.strip()]


def encoded(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), default=str)


def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def balance_key(raw, side=None):
    account = raw["Account" + side + "_Key"] if side else raw["Account_Key"]
    dims = []
    for i in range(1, 4):
        field = "ExtDimension" + (side or "") + str(i)
        dims.extend([raw.get(field), raw.get(field + "_Type")])
    return tuple(x or "" for x in [account, raw.get("Организация_Key"),
        raw.get("Валюта" + (side or "") + "_Key"), raw.get("Подразделение" + (side or "") + "_Key"), *dims])


def financial_check(archive, opening_path):
    with zipfile.ZipFile(archive) as z:
        name = next(n for n in z.namelist() if n.endswith("/manifest.json"))
        prefix = name.rsplit("/", 1)[0] + "/"
        manifest = json.loads(z.read(name))
        for file, meta in manifest["files"].items():
            if hashlib.sha256(z.read(prefix + file)).hexdigest() != meta["sha256"]:
                raise ValueError("Financial supplement checksum mismatch: " + file)
        selected = {}
        for kind, meta in manifest["details"].items():
            rows = [json.loads(x, parse_float=Decimal) for x in z.read(prefix + meta["outputFile"]).splitlines() if x.strip()]
            if len(rows) != meta["selectedCount"]:
                raise ValueError("Financial supplement count mismatch")
            selected[kind] = [row["raw"] for row in rows]
    opening = read(opening_path)["value"]
    accounts = {x["nativeAccountRef"] for x in manifest["accounts"]}
    calculated = defaultdict(Decimal)
    for row in opening:
        if row["Account_Key"] in accounts:
            calculated[balance_key(row)] += Decimal(str(row["СуммаBalance"]))
    for row in selected["ledger"]:
        if not row["Active"] or not "2026-10-01T00:00:00" <= row["Period"] < "2026-11-01T00:00:00":
            raise ValueError("Ledger row outside the stated boundary")
        for side, sign in [("Dr", 1), ("Cr", -1)]:
            if row["Account" + side + "_Key"] in accounts:
                calculated[balance_key(row, side)] += Decimal(str(row["Сумма"])) * sign
    closing = defaultdict(Decimal)
    for row in selected["closing"]:
        closing[balance_key(row)] += Decimal(str(row["СуммаBalance"]))
    differences = {hashlib.sha256(encoded(key).encode()).hexdigest(): str(calculated[key] - closing[key])
        for key in calculated.keys() | closing.keys() if calculated[key] != closing[key]}
    if differences:
        raise ValueError("Opening + October movements differs from closing: " + encoded(differences))
    return selected, {"opening_grains": len(opening), "october_rows": len(selected["ledger"]),
        "closing_grains": len(selected["closing"]), "difference": "0.00",
        "source_closing_as_of": manifest["sourceClosingAsOf"],
        "equivalence": "31.10.2026 within the preserved archive and these three accounts only",
        "supplement_sha256": sha(archive), "opening_sha256": sha(opening_path)}


def build(args):
    package = Path(args.package)
    manifest = read(package / "manifest.json")
    for file, info in manifest["files"].items():
        if sha(package / file) != info["sha256"]:
            raise ValueError("Package checksum mismatch: " + file)
    for name, path in [("state", args.state), ("reportScope", args.report_scope), ("families", args.families)]:
        if sha(path) != manifest["inputs"][name]["sha256"]:
            raise ValueError("Pinned input differs: " + name)
    source_state = read(args.state)["domain"]
    scope = read(args.report_scope)
    if sha(args.selection) != scope["sourceReport"]["sha256"]:
        raise ValueError("Last-occupant report differs")
    selection = read(args.selection)
    financial, proof = financial_check(args.financial, args.opening)
    reference = read(args.reference)
    if reference["project_id"] != "f6e21e3c-d974-4604-82fb-333da66559d2":
        raise ValueError("Project mismatch")
    original = reference["data"]["tables"]
    tables = {name: [] for name in original}
    stage = {f.stem: lines(f) for f in (package / "staging").glob("*.jsonl")}
    crosswalk = read(args.crosswalk)
    maps = defaultdict(dict)
    for row in crosswalk:
        if row["status"] != "MATCHED" or len(row["target_ids"]) != 1:
            raise ValueError("Unresolved identity in audited crosswalk")
        maps[row["table"]][row["source_key"]] = row["target_ids"][0]
    indexes = {name: {r["id"]: r for r in rows if "id" in r} for name, rows in original.items()}
    source_ids = {r["id"]: r for r in source_state["contracts"]}
    bindings = original["rental_source_bindings"]
    guid_refs = lines(package / "reference/source-guid-bindings.jsonl")
    for table, entity in [("parties", "party"), ("invoices", "invoice"), ("payments", "payment"), ("contracts", "contract")]:
        for row in stage[table]:
            target = maps[table][row["source_key"]]
            current = indexes[table].get(target)
            if current is None:
                raise ValueError("Crosswalk target no longer exists")
            refs = [r for r in guid_refs if r["entity_type"] == entity and r["entity_source_key"] == row["source_key"]]
            found = {r["entity_id"] for r in bindings for ref in refs
                if r["entity_type"] == entity and r["collection"] == ref["source_collection"]
                and (r["source_id"] == ref["source_native_id"] or entity == "payment" and r["source_id"].startswith(ref["source_native_id"] + ":"))}
            if not found and table == "contracts":
                preserved = json.loads(current["source_json"])
                author = source_ids.get(row["source_runtime_id"])
                if not author or preserved.get("id") != author["id"] or any(preserved.get(k) != author.get(k) for k in ["number", "date", "party", "start", "end"]):
                    raise ValueError("Pinned imported contract identity differs")
            elif found != {target}:
                raise ValueError("GUID to target identity differs")
    for source in lines(package / "reference/physical-object-crosswalk108.jsonl"):
        target = indexes["rental_objects"][maps["rental_objects"][source["source_object_key"]]]
        raw = json.loads(target["source_json"])
        cadastre = indexes["cadastre"][target["cadastre_id"]]
        if (target["name"] != source["name"] or str(target["floor"]) != str(source["floor"])
            or Decimal(str(target["area"])) != Decimal(str(source["area"]))
            or cadastre["number"] != source["cadastral_number"] or str(raw.get("sourceRow")) != str(source["sourceRow"])):
            raise ValueError("Protected physical object differs")
    facts = []

    def fact(key, value):
        facts.append({"namespace": "zero_point_data", "source_key": key, "fact_json": encoded(value), "operative": 0})

    def copied(table, row, target_id):
        values = dict(indexes[table][target_id])
        values.update({k: encoded(v) if k == "source_json" else int(v) if isinstance(v, bool) else v
            for k, v in row["values"].items() if v is not None and k in values})
        values["id"] = target_id
        return values

    tables["cadastre"] = original["cadastre"]
    tables["rental_objects"] = original["rental_objects"]
    for row in stage["parties"]:
        values = copied("parties", row, maps["parties"][row["source_key"]])
        values["blocked"] = 0
        values["block_reason"] = None
        tables["parties"].append(values)
    bank_id = max(indexes["party_bank_accounts"], default=0)
    for row in stage["party_bank_accounts"]:
        bank_id += 1
        values = {k: encoded(v) if k == "source_json" else v for k, v in row["values"].items()}
        values.update(id=bank_id, party_id=maps["parties"][row["source_party_key"]], operational=0)
        tables["party_bank_accounts"].append(values)
    excluded = []
    legal = {r["source_contract_key"] for r in stage["rental_billing_parts"]}
    # The owner explicitly identifies number 32 as a legal-address lease despite unknown source use.
    legal |= {r["source_key"] for r in stage["contracts"] if r["values"]["number"] == "32"}
    included = set()
    for row in stage["contracts"]:
        v = row["values"]
        reason = "LEGAL_ADDRESS_OWNER_EXCLUDED" if row["source_key"] in legal else (
            "AFTER_CUTOFF" if (v.get("document_date") or "") > args.as_of or (v.get("start_date") or "") > args.as_of else None)
        if reason:
            excluded.append({"source_key": row["source_key"], "number": v["number"], "reason": reason})
            fact("excluded_contract:" + row["source_key"].split(":")[-1], {"reason": reason, "source": row})
            continue
        included.add(row["source_key"])
        values = copied("contracts", row, maps["contracts"][row["source_key"]])
        values["party_id"] = maps["parties"][row["source_party_key"]]
        raw = dict(row["values"]["source_json"]["authorContract"])
        raw.update(imported=True, history=[], versions=[], financialReview=False,
            zeroPointAsOf=args.as_of, sourceLegalMapping=row["values"]["source_json"]["sourceLegalMapping"])
        values["source_json"] = encoded(raw)
        for field in ["finance_review", "legal_review", "signed"]:
            if field in values:
                values[field] = 0
        if "return_confirmed" in values:
            values["return_confirmed"] = None
        tables["contracts"].append(values)
    for row in stage["contract_objects"]:
        if row["source_contract_key"] in included:
            tables["contract_objects"].append({"contract_id": maps["contracts"][row["source_contract_key"]],
                "object_id": maps["rental_objects"][row["source_object_key"]]})
    # Existing readers select the latest admitted row by id. Older evidence must have a lower id.
    ordered_terms = sorted(stage["contract_terms"], key=lambda r: (r["source_contract_key"],
        r["source_object_key"], r["values"].get("effective_date") or r["values"].get("start_date") or "", r["source_key"]))
    for row in ordered_terms:
        if row["source_contract_key"] not in included:
            continue
        values = dict(row["values"])
        if (values.get("effective_date") or values.get("start_date") or "") > args.as_of:
            fact("future_term:" + row["source_key"].split(":")[-1], row)
            continue
        values.update(id=len(tables["contract_terms"]) + 1, contract_id=maps["contracts"][row["source_contract_key"]],
            object_id=maps["rental_objects"][row["source_object_key"]], source_json=encoded(values["source_json"]))
        tables["contract_terms"].append(values)
    invoice_keys = set()
    for row in stage["invoices"]:
        if row["source_contract_key"] not in included:
            continue
        values = copied("invoices", row, maps["invoices"][row["source_key"]])
        values["contract_id"] = maps["contracts"][row["source_contract_key"]]
        raw = dict(row["values"]["source_json"].get("authorInvoice") or {})
        raw.update(zeroPointAsOf=args.as_of, sourceInvoiceRef=row["source_key"],
            verifiedSourceScope=row["values"]["source_json"], cashReplayAllowed=False)
        values["source_json"] = encoded(raw)
        if (values.get("issued") or "") > args.as_of:
            fact("future_invoice:" + row["source_key"], row)
            continue
        invoice_keys.add(row["source_key"])
        tables["invoices"].append(values)
    for name in ["invoice_objects", "invoice_coverage"]:
        for row in stage[name]:
            if row["source_invoice_key"] in invoice_keys:
                values = dict(row["values"])
                values.update(invoice_id=maps["invoices"][row["source_invoice_key"]],
                    object_id=maps["rental_objects"][row["source_object_key"]])
                if "id" in values:
                    values["id"] = len(tables[name]) + 1
                tables[name].append(values)
    allocations = defaultdict(Decimal)
    paid = defaultdict(Decimal)
    payment_keys = set()
    payment_rows = {r["source_key"]: r for r in stage["payments"]}
    for row in stage["payment_allocations"]:
        if row["source_invoice_key"] not in invoice_keys:
            continue
        receipt = payment_rows[row["source_payment_key"]]
        if (receipt["values"].get("payment_date") or "") > args.as_of:
            continue
        amount = Decimal(row["values"]["amount"])
        payment_keys.add(row["source_payment_key"])
        allocations[row["source_payment_key"]] += amount
        paid[row["source_invoice_key"]] += amount
        tables["payment_allocations"].append({"payment_id": maps["payments"][row["source_payment_key"]],
            "invoice_id": maps["invoices"][row["source_invoice_key"]], "amount": str(amount)})
    for row in stage["payments"]:
        if row["source_key"] not in payment_keys:
            continue
        values = copied("payments", row, maps["payments"][row["source_key"]])
        values["party_id"] = maps["parties"][row["source_party_key"]]
        # This runtime payment represents the physical-rental share, not unrelated bank-document lines.
        values["amount"] = str(allocations[row["source_key"]])
        raw = dict(row["values"]["source_json"])
        raw.update(sourceDocumentAmount=row["native_header_amount"],
            runtimeAmountBasis="PHYSICAL_RENTAL_ALLOCATIONS_ONLY", cashReplayAllowed=False)
        values["source_json"] = encoded(raw)
        tables["payments"].append(values)
    for row in stage["invoices"]:
        if row["source_key"] in invoice_keys:
            target = next(v for v in tables["invoices"] if v["id"] == maps["invoices"][row["source_key"]])
            if paid[row["source_key"]] != Decimal(target["paid"] or "0"):
                raise ValueError("Cutoff allocations do not match invoice paid; review required")
    target_sets = {"party": {r["id"] for r in tables["parties"]}, "contract": {r["id"] for r in tables["contracts"]},
        "invoice": {r["id"] for r in tables["invoices"]}, "payment": {r["id"] for r in tables["payments"]}}
    tables["rental_source_bindings"] = [r for r in bindings if r["entity_type"] not in target_sets or r["entity_id"] in target_sets[r["entity_type"]]]
    last_count = 0
    for row in selection["rows"]:
        if row["selection"] != "Последний" or row.get("partArea") is not None or "юрид" in str(row.get("subject", "")).lower():
            continue
        target_id = maps["rental_objects"].get(row["objectImportKey"])
        if target_id is None:
            continue
        if row.get("end") and row["end"] > args.as_of:
            raise ValueError("Last-occupant evidence after cutoff")
        fact("last_occupant:" + str(target_id), {"as_of": args.as_of, "tenant": row["tenant"],
            "party_1c": row.get("party1C"), "end_date": row.get("end"), "contract_number": row["number"],
            "selection_report_sha256": sha(args.selection), "evidence_ids": row["evidenceIds"]})
        last_count += 1
    for row in financial["closing"]:
        fact("closing:" + hashlib.sha256(encoded(balance_key(row)).encode()).hexdigest(),
            {"scope": "SOURCE_CONTROL_NOT_ADDITIONAL_CUSTOMER_DEBT", "source_as_of": "2026-11-03T14:40:55+03:00", "raw": row})
    fact("financial_reconciliation", proof)
    fact("source_exceptions", read(package / "reference/financial-source-facts.json")["exceptions"])
    fact("accepted_settlements", read(package / "reference/financial-source-facts.json")["acceptedSettlements"])
    for row in lines(package / "reference/planned-conditions.jsonl"):
        fact("source_plan:" + hashlib.sha256(encoded(row).encode()).hexdigest(), row)
    for key in ["reservations", "documents", "journal", "workflowMessages", "workflowOutbox", "alertKeys", "invoiceSignals", "operationGroups", "ownerContractDrafts", "p5LeaseExamples"]:
        facts.append({"namespace": "state", "source_key": key, "fact_json": "[]", "operative": 0})
    limitations = ["Подписанные договоры, ДС и акты в источниках отсутствуют; прежние согласования не восстанавливаются.",
        "Рабочие банковские счета не подтверждены: реквизиты сохранены, рабочий счёт выбирается отдельно.",
        "№144: одна копейка не распределена по объектам; общая сумма договора сохранена.",
        "Финансовый контроль 62.01/62.02/76.09 подтверждён в пределах сохранённого архива; он не добавляется повторно к долгам по счетам."]
    result = {"schema": 1, "kind": "COMMERCIAL_ZERO_POINT", "project_id": reference["project_id"],
        "as_of": args.as_of, "table_schema": reference["table_schema"],
        "data": {"tables": tables, "facts": facts, "context": reference["data"]["context"]},
        "limitations": limitations, "source_pins": {"package_manifest_sha256": sha(package / "manifest.json"),
            "audited_crosswalk_sha256": sha(args.crosswalk), "financial": proof,
            "selection_report_sha256": sha(args.selection)},
        "summary": {"counts": {name: len(rows) for name, rows in tables.items()}, "excluded_contracts": excluded,
            "last_occupants": last_count, "source_legal_address_count": len(legal), "financial": proof}}
    output = Path(args.output)
    with output.open("x", encoding="utf-8") as f:
        f.write(encoded(result))
    print(encoded(result["summary"]))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ["package", "reference", "crosswalk", "financial", "opening", "state", "report-scope", "selection", "families", "output"]:
        parser.add_argument("--" + name, required=True)
    parser.add_argument("--as-of", default="2026-10-31", choices=["2026-10-31"])
    build(parser.parse_args())
