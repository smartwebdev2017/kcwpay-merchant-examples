// KCWPay Merchant API quickstart (Node.js 18+, no packages needed).
//
//   export KCW_API_BASE="https://kcwpay.com/merchant/v1"
//   export KCW_KEY_ID="mk_test_..."          # from your merchant dashboard → Developers
//   export KCW_SECRET="sk_test_..."          # shown once when the key was created
//   node quickstart.mjs
//
// Use a TEST key (mk_test_…) first: test mode simulates payments, nothing real moves.
import crypto from 'node:crypto'

const BASE = (process.env.KCW_API_BASE || 'https://kcwpay.com/merchant/v1').replace(/\/$/, '')
const KEY_ID = process.env.KCW_KEY_ID
const SECRET = process.env.KCW_SECRET
const BASE_PATH = new URL(BASE).pathname          // "/merchant/v1" — part of what you sign

// Send one signed request and return the JSON reply.
// Signature = hex HMAC-SHA256(secret,
//   "<timestamp ms>\n<METHOD>\n<path with query>\n<sha256 hex of the body>")
async function kcw(method, endpoint, body = null, idempotencyKey = null) {
  const raw = body === null ? '' : JSON.stringify(body)        // sign exactly the bytes you send
  const path = BASE_PATH + endpoint
  const ts = String(Date.now())
  const bodyHash = crypto.createHash('sha256').update(raw).digest('hex')
  const message = `${ts}\n${method}\n${path}\n${bodyHash}`
  const headers = {
    'X-KCW-Key': KEY_ID,
    'X-KCW-Timestamp': ts,
    'X-KCW-Signature': crypto.createHmac('sha256', SECRET).update(message).digest('hex'),
  }
  if (body !== null) {
    headers['Content-Type'] = 'application/json'
    // Same key = same request: retrying after a timeout never creates a second
    // payment or payout. Derive it from YOUR id (order, withdrawal) and reuse it.
    headers['Idempotency-Key'] = idempotencyKey || crypto.randomUUID()
  }
  const res = await fetch(BASE + endpoint, { method, headers, body: body === null ? undefined : raw })
  const reply = await res.json()
  if (!res.ok) throw new Error(`${res.status} ${reply.error?.code}: ${reply.error?.message}`)
  return reply
}

// 1. Check the key and your signing
const me = await kcw('GET', '/ping')
console.log('1. ping:', me)                      // { ok: true, merchant_id: '…', mode: 'test' }

// 2. Your balance per network
console.log('2. balance:', (await kcw('GET', '/balance')).data)

// 3. A permanent deposit address for one of your customers (same reply every time)
const addr = (await kcw('POST', '/deposit-addresses', { network: 'USDT TRC20', customer_ref: 'player-42' })).data
console.log('3. deposit address for player-42:', addr.address)

// 4. Test mode only: pretend player-42 sent 25 USDT to their address, then give it its
//    confirmations. With a live key you skip this — the network does it, and you get webhooks.
if (me.mode === 'test') {
  let pay = (await kcw('POST', '/sandbox/transfers', { network: 'USDT TRC20', address: addr.address, amount: '25', confirmed: false })).data
  pay = (await kcw('POST', `/sandbox/payments/${pay.id}/confirm`, {})).data
  console.log('4. deposit', pay.id, pay.status, '· received', pay.amount_received, '· net', pay.net)
}

// 5. Your latest payments (deposits)
for (const p of (await kcw('GET', '/payments?limit=5')).data) {
  console.log('5.', p.id, p.status, p.amount_received, p.network, p.customer_ref)
}

// 6. Pay out to a customer (amount + fee is reserved from your available balance).
//    A refusal tells you why, e.g. 422 insufficient_funds / amount_too_small, or a 503
//    to retry later with the SAME Idempotency-Key.
//    Test mode only here: with a live key this would send real money. In your own
//    code, to_address is your customer's withdrawal address.
if (me.mode === 'test') {
  try {
    const payout = (await kcw('POST', '/payouts', { network: 'USDT TRC20', to_address: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
      amount: '10', customer_ref: 'player-42' }, 'withdrawal-5001')).data          // your own withdrawal id
    console.log('6. payout', payout.id, payout.status)   // queued, or awaiting_approval above your limit
  } catch (e) {
    console.log('6. payout refused:', e.message)
  }
} else {
  console.log('6. payout skipped: live key (this example only sends test payouts)')
}
console.log('   balance now:', (await kcw('GET', '/balance')).data)
