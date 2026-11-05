<?php
// KCWPay webhook receiver (PHP 7.4+). Point your webhook URL at this script.
//
//   KCW_WEBHOOK_SECRET="whsec_..."   — returned once by PUT /webhook (or the dashboard)
//   Local test:  php -S 0.0.0.0:8080 webhook_receiver.php
//
// Verify the RAW body (php://input), answer 2xx quickly, and skip event ids
// you have already handled — a delivery can repeat.

$secret = getenv('KCW_WEBHOOK_SECRET');
$tolerance = 300;                      // seconds; older deliveries are refused (replay protection)

$raw = file_get_contents('php://input');
$header = $_SERVER['HTTP_X_KCW_SIGNATURE'] ?? '';   // t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>." + raw body)>
$parts = [];
foreach (explode(',', $header) as $piece) {
    [$k, $v] = array_pad(explode('=', $piece, 2), 2, '');
    $parts[$k] = $v;
}
$ts = (int) ($parts['t'] ?? 0);
$expected = hash_hmac('sha256', $ts . '.' . $raw, $secret);
if ($ts === 0 || abs(time() - $ts) > $tolerance || !hash_equals($expected, $parts['v1'] ?? '')) {
    http_response_code(400);
    exit;
}

$event = json_decode($raw, true);
// Skip repeats: store handled event ids in your database (a file here, for the example).
$seenFile = sys_get_temp_dir() . '/kcw_seen_events.txt';
$seen = is_file($seenFile) ? file($seenFile, FILE_IGNORE_NEW_LINES) : [];
if (!in_array($event['id'], $seen, true)) {
    file_put_contents($seenFile, $event['id'] . "\n", FILE_APPEND | LOCK_EX);
    $obj = $event['data'];
    if ($event['type'] === 'payment.confirmed') {
        // Credit your customer: $obj['customer_ref'] / $obj['order_ref'], amount $obj['amount_received']
        error_log("credit " . ($obj['customer_ref'] ?? $obj['order_ref']) . " {$obj['amount_received']} {$obj['network']}");
    } elseif (strpos($event['type'], 'payout.') === 0) {
        error_log("payout {$obj['id']} {$event['type']}");
    }
}
http_response_code(200);
