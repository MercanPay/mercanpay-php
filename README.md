# MercanPay PHP client

Official PHP 8+ client for [MercanPay](https://mercanpay.com) — accept TRX and USDT (TRC20) payments. No dependencies besides ext-curl.
Full API reference: https://mercanpay.com/documentation

```bash
composer require mercanpay/mercanpay-php
```

```php
use MercanPay\MercanPay;
use MercanPay\MercanPayException;

$zp = new MercanPay('https://mercanpay.com', getenv('MERCANPAY_API_KEY'));

// Invoice priced in USD — the customer picks the coin on the payment page
$invoice = $zp->createUsdInvoice('25', ['order_id' => 'order-1000']);

// Checkout in a fixed coin: create an invoice and redirect the customer to the hosted payment page
$invoice = $zp->createInvoice('USDT', '49.90', [
    'order_id'     => 'order-1001',              // idempotent: retries return the same invoice
    'callback_url' => 'https://shop.example/mercanpay-webhook.php',
    'metadata'     => ['customer_id' => 42],
]);
header('Location: ' . $invoice['payment_url']);
```

Webhook endpoint (`mercanpay-webhook.php`):

```php
$body = file_get_contents('php://input');
if (!MercanPay::verifyWebhook(getenv('MERCANPAY_WEBHOOK_SECRET'), $body, $_SERVER['HTTP_X_MERCANPAY_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
$event = json_decode($body, true);
if ($event['type'] === 'invoice.paid' || $event['type'] === 'invoice.overpaid') {
    $orderId = $event['data']['invoice']['order_id'];
    // mark order as paid — make this idempotent: the same event id can be delivered more than once
}
http_response_code(200);
```

Payout from your merchant balance (API key needs the `withdrawals` scope; retrying with the same idempotency key is safe):

```php
$w = $zp->createWithdrawal('USDT', '10', 'T...', 'payout-2024-001');
echo $w['status']; // queued -> processing -> completed
```

Errors throw `MercanPayException` (`getHttpStatus()`, `getErrorCode()` e.g. `insufficient_funds`).
