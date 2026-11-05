// KCWPay webhook receiver (Node.js 18+, no packages needed).
//
//   export KCW_WEBHOOK_SECRET="whsec_..."   # returned once by PUT /webhook (or the dashboard)
//   node webhook_receiver.mjs               # listens on :8080, path /kcw/webhook
//
// With Express use express.raw({ type: 'application/json' }) on this route:
// verify the RAW bytes (not re-serialised JSON), answer 2xx quickly, and skip
// event ids you have already handled — a delivery can repeat.
import crypto from 'node:crypto'
import http from 'node:http'

const SECRET = process.env.KCW_WEBHOOK_SECRET
const TOLERANCE = 300                  // seconds; older deliveries are refused (replay protection)
const seen = new Set()                 // use your database in production

// X-KCW-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>." + raw body)>
function verify(rawBody, header) {
  const parts = Object.fromEntries((header || '').split(',').map((p) => p.split('=')))
  const ts = Number(parts.t)
  if (!ts || !parts.v1) return false
  const expected = crypto.createHmac('sha256', SECRET).update(`${ts}.`).update(rawBody).digest('hex')
  const a = Buffer.from(expected), b = Buffer.from(parts.v1)
  return Math.abs(Date.now() / 1000 - ts) <= TOLERANCE && a.length === b.length && crypto.timingSafeEqual(a, b)
}

function handle(event) {
  const obj = event.data
  if (event.type === 'payment.confirmed') {
    // Credit your customer: obj.customer_ref / obj.order_ref, amount obj.amount_received
    console.log('credit', obj.customer_ref || obj.order_ref, obj.amount_received, obj.network)
  } else if (event.type.startsWith('payout.')) {
    console.log('payout', obj.id, event.type)
  } else {
    console.log('event', event.type)
  }
}

http.createServer((req, res) => {
  if (req.method !== 'POST' || req.url !== '/kcw/webhook') { res.writeHead(404).end(); return }
  const chunks = []
  req.on('data', (c) => chunks.push(c))
  req.on('end', () => {
    const raw = Buffer.concat(chunks)
    if (!verify(raw, req.headers['x-kcw-signature'])) { res.writeHead(400).end(); return }
    const event = JSON.parse(raw)
    if (!seen.has(event.id)) {        // a repeat delivery is acknowledged, not processed twice
      seen.add(event.id)
      handle(event)
    }
    res.writeHead(200).end()
  })
}).listen(Number(process.env.PORT || 8080))
