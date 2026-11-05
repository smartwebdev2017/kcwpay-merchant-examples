<?php
// KCWPay Merchant API quickstart (PHP 7.4+ with the curl extension).
//
//   export KCW_API_BASE="https://kcwpay.com/merchant/v1"
//   export KCW_KEY_ID="mk_test_..."          # from your merchant dashboard → Developers
//   export KCW_SECRET="sk_test_..."          # shown once when the key was created
//   php quickstart.php
//
// Use a TEST key (mk_test_…) first: test mode simulates payments, nothing real moves.

$BASE = rtrim(getenv('KCW_API_BASE') ?: 'https://kcwpay.com/merchant/v1', '/');
$KEY_ID = getenv('KCW_KEY_ID');
$SECRET = getenv('KCW_SECRET');
$BASE_PATH = parse_url($BASE, PHP_URL_PATH);      // "/merchant/v1" — part of what you sign

// Send one signed request and return the JSON reply as an array.
// Signature = hex HMAC-SHA256(secret,
//   "<timestamp ms>\n<METHOD>\n<path with query>\n<sha256 hex of the body>")
function kcw(string $method, string $endpoint, ?array $body = null, ?string $idempotencyKey = null): array
{
    global $BASE, $KEY_ID, $SECRET, $BASE_PATH;
    // Sign exactly the bytes you send. An empty array must go out as {} (not []).
    $raw = $body === null ? '' : json_encode($body === [] ? new stdClass() : $body, JSON_UNESCAPED_SLASHES);
    $ts = (string) (int) (microtime(true) * 1000);       // milliseconds
    $message = $ts . "\n" . $method . "\n" . $BASE_PATH . $endpoint . "\n" . hash('sha256', $raw);
    $headers = [
        "X-KCW-Key: $KEY_ID",
        "X-KCW-Timestamp: $ts",
        'X-KCW-Signature: ' . hash_hmac('sha256', $message, $SECRET),
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        // Same key = same request: retrying after a timeout never creates a second
        // payment or payout. Derive it from YOUR id (order, withdrawal) and reuse it.
        $headers[] = 'Idempotency-Key: ' . ($idempotencyKey ?: bin2hex(random_bytes(16)));
    }
    $ch = curl_init($BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    }
    $out = curl_exec($ch);
    if ($out === false) {
        throw new RuntimeException('Network error: ' . curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $reply = json_decode($out, true);
    if ($status >= 400) {
        throw new RuntimeException("$status {$reply['error']['code']}: {$reply['error']['message']}");
    }
    return $reply;
}

// 1. Check the key and your signing
$me = kcw('GET', '/ping');
echo "1. ping: mode={$me['mode']} merchant={$me['merchant_id']}\n";

// 2. Your balance per network
foreach (kcw('GET', '/balance')['data'] as $b) {
    echo "2. balance {$b['asset']}: available {$b['available']}\n";
}

// 3. A permanent deposit address for one of your customers (same reply every time)
$addr = kcw('POST', '/deposit-addresses', ['network' => 'USDT TRC20', 'customer_ref' => 'player-42'])['data'];
echo "3. deposit address for player-42: {$addr['address']}\n";

// 4. Test mode only: pretend player-42 sent 25 USDT to their address, then give it its
//    confirmations. With a live key you skip this — the network does it, and you get webhooks.
if ($me['mode'] === 'test') {
    $pay = kcw('POST', '/sandbox/transfers', ['network' => 'USDT TRC20', 'address' => $addr['address'],
                                              'amount' => '25', 'confirmed' => false])['data'];
    $pay = kcw('POST', "/sandbox/payments/{$pay['id']}/confirm", [])['data'];
    echo "4. deposit {$pay['id']} {$pay['status']} · received {$pay['amount_received']} · net {$pay['net']}\n";
}

// 5. Your latest payments (deposits)
foreach (kcw('GET', '/payments?limit=5')['data'] as $p) {
    echo "5. {$p['id']} {$p['status']} {$p['amount_received']} {$p['network']} {$p['customer_ref']}\n";
}

// 6. Pay out to a customer (amount + fee is reserved from your available balance).
//    A refusal tells you why, e.g. 422 insufficient_funds / amount_too_small, or a 503
//    to retry later with the SAME Idempotency-Key.
//    Test mode only here: with a live key this would send real money. In your own
//    code, to_address is your customer's withdrawal address.
if ($me['mode'] === 'test') {
    try {
        $payout = kcw('POST', '/payouts', ['network' => 'USDT TRC20', 'to_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                                           'amount' => '10', 'customer_ref' => 'player-42'],
                      'withdrawal-5001')['data'];                                   // your own withdrawal id
        echo "6. payout {$payout['id']} {$payout['status']}\n";  // queued, or awaiting_approval above your limit
    } catch (RuntimeException $e) {
        echo '6. payout refused: ' . $e->getMessage() . "\n";
    }
} else {
    echo "6. payout skipped: live key (this example only sends test payouts)\n";
}
