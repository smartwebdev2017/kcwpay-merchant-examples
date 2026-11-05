# KCWPay Merchant API — examples

Accept crypto payments on your website or app with the **[KCWPay Merchant API](https://kcwpay.com/business)**:
give every customer their own deposit address, get a signed webhook the moment a
payment is confirmed, and pay out withdrawals — through one REST API.

Ready-to-run examples in **Python**, **Node.js** and **PHP**. No SDK needed:
each example is a single file using only the standard library (plus `requests`
for Python).

- **Low, transparent fees** — [see live pricing](https://kcwpay.com/business#pricing)
- **Networks:** Bitcoin, Ethereum, BNB Smart Chain, and USDT on TRON (TRC20), BNB Smart Chain (BEP20) and Ethereum (ERC20)
- **Test mode:** simulated payments with `mk_test_` keys, nothing real moves
- **Daily settlement** to your own address, payouts through the API, a dashboard for your team

👉 **[KCWPay for Business](https://kcwpay.com/business)** · **[API documentation](https://kcwpay.com/merchant/docs)** · **[Merchant guide](https://kcwpay.com/merchant/guide)** · **[Contact us](https://kcwpay.com/contact?topic=merchant)**

---

## What's inside

| | Quickstart (API calls) | Webhook receiver |
|---|---|---|
| **Python 3.9+** | [`python/quickstart.py`](python/quickstart.py) | [`python/webhook_receiver.py`](python/webhook_receiver.py) |
| **Node.js 18+** | [`node/quickstart.mjs`](node/quickstart.mjs) | [`node/webhook_receiver.mjs`](node/webhook_receiver.mjs) |
| **PHP 7.4+** | [`php/quickstart.php`](php/quickstart.php) | [`php/webhook_receiver.php`](php/webhook_receiver.php) |

Each quickstart walks through the whole flow: check your key, read your
balance, create a deposit address for a customer, simulate a payment, list
payments and create a payout. The simulated payment and the payout run only
with a **test key** — with a live key the script just reads. Each webhook
receiver verifies the signature, refuses old deliveries and skips events it
has already handled.

## 1. Get your keys

1. [Create a KCWPay account](https://kcwpay.com/signup) and [apply for merchant access](https://kcwpay.com/business).
2. Once approved, open **Merchant → Developers** in your dashboard. Start with a **test key** (`mk_test_…`); its secret is shown once, when the key is created.

## 2. Set your environment

```bash
cp .env.example .env        # then fill in your key — never commit .env
export $(grep -v '^#' .env | xargs)
```

| Variable | What it is |
|---|---|
| `KCW_API_BASE` | `https://kcwpay.com/merchant/v1` |
| `KCW_KEY_ID` | Your key id, `mk_test_…` (test) or `mk_live_…` (live) |
| `KCW_SECRET` | The key's secret — keep it on your server only |
| `KCW_WEBHOOK_SECRET` | Your webhook signing secret, `whsec_…` |

## 3. Run a quickstart

```bash
# Python
pip install -r python/requirements.txt
python python/quickstart.py

# Node.js (no packages needed)
node node/quickstart.mjs

# PHP (with the curl extension)
php php/quickstart.php
```

## 4. Receive webhooks

```bash
python python/webhook_receiver.py                 # or
node node/webhook_receiver.mjs                    # or
php -S 0.0.0.0:8080 php/webhook_receiver.php
```

The receivers listen on port `8080` at `/kcw/webhook`. Set your webhook URL in
**Merchant → Developers**, or send a test delivery with `POST /webhooks/test`.

**Credit your customer on `payment.confirmed`** — not as soon as funds appear
at an address.

## How requests are signed

Every request carries three headers:

```
X-KCW-Key:        <your key id>
X-KCW-Timestamp:  <unix time in milliseconds>
X-KCW-Signature:  hex HMAC-SHA256(secret, "<timestamp>\n<METHOD>\n<path with query>\n<sha256 hex of the body>")
```

The path includes `/merchant/v1`. Sign exactly the bytes you send. Each
example's `kcw()` helper does this for you.

Webhooks are signed the same way, in `X-KCW-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(webhook secret, "<t>." + raw body)>`.
Verify the **raw** body, not re-serialised JSON.

## Security checklist

- Keep `KCW_SECRET` and `KCW_WEBHOOK_SECRET` on your server — never in a website, an app or a repository.
- Keys that can send payouts work only from your server IP addresses.
- Use idempotency keys on payouts so a retry never pays twice (see the examples).
- If a secret may have leaked, [contact us](https://kcwpay.com/contact?topic=merchant) right away: we revoke the key and issue a new one.

## Going live

When your integration works in test mode, [contact us](https://kcwpay.com/contact?topic=merchant).
We complete a business check, then set up your live account and live keys (`mk_live_…`).

## Help

- **Full API reference:** https://kcwpay.com/merchant/docs
- **Questions:** https://kcwpay.com/contact?topic=merchant or support@kcwpay.com

## License

[MIT](LICENSE) — copy these examples into your own code freely.
