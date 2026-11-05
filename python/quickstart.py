"""KCWPay Merchant API quickstart (Python 3.9+).

    pip install requests
    export KCW_API_BASE="https://kcwpay.com/merchant/v1"
    export KCW_KEY_ID="mk_test_..."          # from your merchant dashboard → Developers
    export KCW_SECRET="sk_test_..."          # shown once when the key was created
    python quickstart.py

Use a TEST key (mk_test_…) first: test mode simulates payments, nothing real moves.
"""
import hashlib
import hmac
import json
import os
import time
import uuid
from urllib.parse import urlparse

import requests

BASE = os.environ.get("KCW_API_BASE", "https://kcwpay.com/merchant/v1").rstrip("/")
KEY_ID = os.environ["KCW_KEY_ID"]
SECRET = os.environ["KCW_SECRET"]
BASE_PATH = urlparse(BASE).path            # "/merchant/v1" — part of what you sign


def kcw(method, endpoint, body=None, idempotency_key=None):
    """Send one signed request and return the JSON reply.

    Signature = hex HMAC-SHA256(secret,
        "<timestamp ms>\\n<METHOD>\\n<path with query>\\n<sha256 hex of the body>")
    """
    raw = json.dumps(body, separators=(",", ":")).encode() if body is not None else b""
    path = BASE_PATH + endpoint                       # e.g. /merchant/v1/payments?limit=5
    ts = str(int(time.time() * 1000))
    message = f"{ts}\n{method}\n{path}\n{hashlib.sha256(raw).hexdigest()}"
    headers = {
        "X-KCW-Key": KEY_ID,
        "X-KCW-Timestamp": ts,
        "X-KCW-Signature": hmac.new(SECRET.encode(), message.encode(), hashlib.sha256).hexdigest(),
    }
    if body is not None:
        headers["Content-Type"] = "application/json"
        # Same key = same request: retrying after a timeout never creates a second
        # payment or payout. Derive it from YOUR id (order, withdrawal) and reuse it.
        headers["Idempotency-Key"] = idempotency_key or str(uuid.uuid4())
    r = requests.request(method, BASE + endpoint, data=raw or None, headers=headers, timeout=30)
    reply = r.json()
    if r.status_code >= 400:
        err = reply.get("error", {})
        raise RuntimeError(f"{r.status_code} {err.get('code')}: {err.get('message')}")
    return reply


# 1. Check the key and your signing
me = kcw("GET", "/ping")
print("1. ping:", me)                       # {'ok': True, 'merchant_id': '…', 'mode': 'test'}

# 2. Your balance per network
print("2. balance:", kcw("GET", "/balance")["data"])

# 3. A permanent deposit address for one of your customers (same reply every time)
addr = kcw("POST", "/deposit-addresses", {"network": "USDT TRC20", "customer_ref": "player-42"})["data"]
print("3. deposit address for player-42:", addr["address"])

# 4. Test mode only: pretend player-42 sent 25 USDT to their address, then give it its
#    confirmations. With a live key you skip this — the network does it, and you get webhooks.
if me["mode"] == "test":
    pay = kcw("POST", "/sandbox/transfers", {"network": "USDT TRC20", "address": addr["address"], "amount": "25",
                                             "confirmed": False})["data"]
    pay = kcw("POST", f"/sandbox/payments/{pay['id']}/confirm", {})["data"]
    print("4. deposit", pay["id"], pay["status"], "· received", pay["amount_received"], "· net", pay["net"])

# 5. Your latest payments (deposits)
for p in kcw("GET", "/payments?limit=5")["data"]:
    print("5.", p["id"], p["status"], p["amount_received"], p["network"], p["customer_ref"])

# 6. Pay out to a customer (amount + fee is reserved from your available balance).
#    A refusal tells you why, e.g. 422 insufficient_funds / amount_too_small, or a 503
#    to retry later with the SAME Idempotency-Key.
#    Test mode only here: with a live key this would send real money. In your own
#    code, to_address is your customer's withdrawal address.
if me["mode"] == "test":
    try:
        payout = kcw("POST", "/payouts", {"network": "USDT TRC20", "to_address": "TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t",
                                          "amount": "10", "customer_ref": "player-42"},
                     idempotency_key="withdrawal-5001")["data"]          # your own withdrawal id
        print("6. payout", payout["id"], payout["status"])   # queued, or awaiting_approval above your limit
    except RuntimeError as e:
        print("6. payout refused:", e)
else:
    print("6. payout skipped: live key (this example only sends test payouts)")
print("   balance now:", kcw("GET", "/balance")["data"])
