#!/usr/bin/env python3
"""Run a real HTTP checkout race against the seeded one-unit product."""

import concurrent.futures
import json
import os
import threading
import urllib.error
import urllib.request
import uuid

API = os.getenv("ORDERFORGE_API_URL", "http://127.0.0.1:8000/api/v1").rstrip("/")
PASSWORD = os.getenv("ORDERFORGE_DEMO_PASSWORD", "OrderForgeLocal2026!")
WORKERS = 20


def request(method, path, body=None, token=None, headers=None):
    payload = None if body is None else json.dumps(body).encode()
    request_headers = {"Accept": "application/json"}
    if payload is not None:
        request_headers["Content-Type"] = "application/json"
    if token:
        request_headers["Authorization"] = f"Bearer {token}"
    request_headers.update(headers or {})
    req = urllib.request.Request(API + path, data=payload, headers=request_headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)


def login(email):
    status, response = request("POST", "/auth/login", {"email": email, "password": PASSWORD})
    if status != 200:
        raise RuntimeError(f"Login failed for {email}: {response}")
    return response["data"]["token"]


def main():
    tokens = [login(f"customer{i}@orderforge.test") for i in (1, 2)]
    status, response = request("GET", "/products?search=OF-KB-001")
    product = next((item for item in response.get("data", []) if item["sku"] == "OF-KB-001"), None)
    if status != 200 or product is None or product["available_stock"] != 1:
        raise RuntimeError("The seeded OF-KB-001 must have exactly one available unit. Use a fresh local seed.")

    barrier = threading.Barrier(WORKERS)

    def checkout(index):
        barrier.wait()
        return request(
            "POST", "/orders", {"items": [{"product_id": product["id"], "quantity": 1}]},
            tokens[index % 2], {"Idempotency-Key": str(uuid.uuid4())},
        )

    with concurrent.futures.ThreadPoolExecutor(max_workers=WORKERS) as pool:
        outcomes = list(pool.map(checkout, range(WORKERS)))

    wins = [(status, data) for status, data in outcomes if status == 201]
    stock_conflicts = [(status, data) for status, data in outcomes
                       if status == 409 and data.get("error", {}).get("code") == "INSUFFICIENT_STOCK"]
    other = [(status, data) for status, data in outcomes
             if status != 201 and (status != 409 or data.get("error", {}).get("code") != "INSUFFICIENT_STOCK")]
    print(f"Checkout race: {len(wins)} created, {len(stock_conflicts)} insufficient stock, {len(other)} other")
    if other:
        print("Unexpected responses:", json.dumps(other[:3], indent=2))

    if wins:
        order = wins[0][1]["data"]
        owner = next(token for token in tokens if request("GET", f"/orders/{order['id']}", token=token)[0] == 200)
        cancel_status, cancel_data = request("POST", f"/orders/{order['id']}/cancel", {}, owner)
        print(f"Cleanup order {order['order_number']}: HTTP {cancel_status}, status {cancel_data.get('data', {}).get('status')}")
    if len(wins) != 1 or len(stock_conflicts) != WORKERS - 1 or other:
        raise SystemExit(1)
    print("PASS: no oversell; exactly one reservation succeeded.")


if __name__ == "__main__":
    main()
