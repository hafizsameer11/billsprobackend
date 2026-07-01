#!/usr/bin/env python3
"""Analyze Tatum webhook payloads from SQL dumps."""
import json
import re
import sys
from collections import Counter
from pathlib import Path


def parse_sql_payloads(path: str) -> list[dict]:
    payloads: list[dict] = []
    text = Path(path).read_text(encoding="utf-8", errors="replace")
    for line in text.splitlines():
        line = line.strip()
        if not line.startswith("("):
            continue
        start = line.find('{"')
        if start == -1:
            start = line.find('{\\"')
        if start == -1:
            continue
        depth = 0
        end = -1
        for i in range(start, len(line)):
            ch = line[i]
            if ch == "{" and (i == start or line[i - 1] != "\\"):
                depth += 1
            elif ch == "}" and line[i - 1] != "\\":
                depth -= 1
                if depth == 0:
                    end = i + 1
                    break
        if end == -1:
            continue
        raw = line[start:end].replace('\\"', '"')
        try:
            payloads.append(json.loads(raw))
        except json.JSONDecodeError:
            pass
    return payloads


def normalize_payload(p: dict) -> dict:
    if isinstance(p.get("data"), dict):
        inner = dict(p["data"])
        for k in ("subscriptionType", "type", "chain", "subscriptionId"):
            if k not in inner and k in p:
                inner[k] = p[k]
        return inner
    return p


def summarize(name: str, payloads: list[dict]) -> None:
    print(f"\n=== {name} ({len(payloads)} payloads) ===")
    normalized = [normalize_payload(p) for p in payloads]

    sub_types = Counter(p.get("subscriptionType", "?") for p in normalized)
    print("subscriptionTypes:", dict(sub_types))

    types = Counter(str(p.get("type", "?")) for p in normalized)
    print("type field:", dict(types))

    currencies = Counter(str(p.get("currency", p.get("asset", "?"))) for p in normalized)
    print("currency/asset top:", currencies.most_common(20))

    chains = Counter(str(p.get("chain", "?")) for p in normalized)
    print("chain top:", chains.most_common(15))

    keys = Counter()
    for p in payloads:
        for k in p:
            keys[k] += 1
        if isinstance(p.get("data"), dict):
            for k in p["data"]:
                keys[f"data.{k}"] += 1

    print("top keys:", keys.most_common(30))

    fees = sum(
        1
        for p in normalized
        if str(p.get("type", "")).lower() == "fee"
        or (p.get("amount") is not None and str(p.get("amount")).startswith("-"))
    )
    print("fee/negative amount:", fees)

    no_sender = sum(
        1
        for p in normalized
        if not any(p.get(k) for k in ("counterAddress", "counteraddress", "from", "counter_address"))
    )
    print("no sender (BTC coinbase etc):", no_sender)

    has_account = sum(1 for p in normalized if p.get("accountId") or p.get("account_id"))
    print("has accountId:", has_account)

    has_asset = sum(1 for p in normalized if p.get("asset"))
    print("has asset field:", has_asset)

    has_contract = sum(1 for p in normalized if p.get("contractAddress"))
    print("has contractAddress:", has_contract)

    has_token_id = sum(1 for p in normalized if p.get("tokenId"))
    print("has tokenId:", has_token_id)

    has_to = sum(1 for p in normalized if p.get("to"))
    print("has to:", has_to)

    has_address_only = sum(1 for p in normalized if p.get("address") and not p.get("to"))
    print("address without to:", has_address_only)

    # Unique shape signatures
    shapes = Counter()
    for p in normalized:
        shape = (
            p.get("subscriptionType"),
            p.get("type"),
            "to" if p.get("to") else "addr" if p.get("address") else "noaddr",
            "asset" if p.get("asset") else "contract" if p.get("contractAddress") else "currency",
        )
        shapes[shape] += 1
    print("shape combos:", len(shapes))
    for shape, count in shapes.most_common(25):
        print(f"  {count:4d} {shape}")


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    tatum_path = root / "tatum_raw_webhooks.sql"
    webhook_path = root / "webhook_raw_payloads.sql"

    tatum = parse_sql_payloads(str(tatum_path))
    webhook = parse_sql_payloads(str(webhook_path))

    summarize("tatum_raw_webhooks", tatum)
    summarize("webhook_raw_payloads", webhook)

    # Cross-check gaps vs current handler assumptions
    all_p = [normalize_payload(p) for p in tatum + webhook]
    unsupported_sub = [
        p.get("subscriptionType")
        for p in all_p
        if p.get("subscriptionType")
        not in (
            "ADDRESS_EVENT",
            "INCOMING_NATIVE_TX",
            "INCOMING_FUNGIBLE_TX",
            "ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION",
        )
    ]
    if unsupported_sub:
        print("\nUnsupported subscriptionTypes:", Counter(unsupported_sub))

    deposit_types = {"native", "token", None, "?"}
    skip_types = {"fee", "internal"}
    risky = [
        p
        for p in all_p
        if str(p.get("type", "")).lower() not in skip_types
        and p.get("subscriptionType")
        in (
            "ADDRESS_EVENT",
            "INCOMING_NATIVE_TX",
            "INCOMING_FUNGIBLE_TX",
            "ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION",
        )
        and float(str(p.get("amount", "0") or "0").replace("-", "") or 0) > 0
    ]
    print(f"\nTotal positive deposit-like payloads: {len(risky)}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
