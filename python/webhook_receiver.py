"""KCWPay webhook receiver (Python 3.9+, standard library only).

    export KCW_WEBHOOK_SECRET="whsec_..."    # returned once by PUT /webhook (or the dashboard)
    python webhook_receiver.py               # listens on :8080, path /kcw/webhook

With Flask / Django / FastAPI the rules are the same: verify the RAW request
bytes (not re-serialised JSON), answer 2xx quickly, and skip event ids you
have already handled — a delivery can repeat.
"""
import hashlib
import hmac
import json
import os
import time
from http.server import BaseHTTPRequestHandler, HTTPServer

SECRET = os.environ["KCW_WEBHOOK_SECRET"]
TOLERANCE = 300                       # seconds; older deliveries are refused (replay protection)
seen = set()                          # use your database in production


def verify(raw_body: bytes, header: str) -> bool:
    """X-KCW-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>." + raw body)>"""
    try:
        parts = dict(p.split("=", 1) for p in header.split(","))
        ts, sig = int(parts["t"]), parts["v1"]
    except (KeyError, ValueError):
        return False
    expected = hmac.new(SECRET.encode(), f"{ts}.".encode() + raw_body, hashlib.sha256).hexdigest()
    return abs(time.time() - ts) <= TOLERANCE and hmac.compare_digest(expected, sig)


def handle(event: dict) -> None:
    obj = event["data"]
    if event["type"] == "payment.confirmed":
        # Credit your customer: obj["customer_ref"] / obj["order_ref"], amount obj["amount_received"]
        print("credit", obj.get("customer_ref") or obj.get("order_ref"), obj["amount_received"], obj["network"])
    elif event["type"] in ("payout.completed", "payout.failed", "payout.rejected", "payout.cancelled"):
        print("payout", obj["id"], event["type"])
    else:
        print("event", event["type"])


class Receiver(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path != "/kcw/webhook":
            return self._reply(404)
        raw = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        if not verify(raw, self.headers.get("X-KCW-Signature", "")):
            return self._reply(400)
        event = json.loads(raw)
        if event["id"] not in seen:           # a repeat delivery is acknowledged, not processed twice
            seen.add(event["id"])
            handle(event)
        self._reply(200)

    def _reply(self, code):
        self.send_response(code)
        self.end_headers()

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    HTTPServer(("0.0.0.0", int(os.environ.get("PORT", 8080))), Receiver).serve_forever()
