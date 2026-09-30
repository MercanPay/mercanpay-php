<?php

declare(strict_types=1);

namespace MercanPay;

/**
 * PHP client for the MercanPay TRON payment gateway API. Requires ext-curl and ext-json.
 *
 *   $zp = new \MercanPay\MercanPay('https://pay.example.com', 'mp_...');
 *   $invoice = $zp->createInvoice('USDT', '25', ['order_id' => 'order-42']);
 *   header('Location: ' . $invoice['payment_url']);
 *
 * Amounts are decimal *strings* ("12.5") — never floats.
 */
final class MercanPay
{
    public const SIGNATURE_HEADER = 'X-MercanPay-Signature';
    public const VERSION = '1.0.0';

    private string $baseUrl;

    public function __construct(string $baseUrl, private string $apiKey, private int $timeout = 15)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    // ── status ──────────────────────────────────────────────────────────────────────────────

    public function status(): array
    {
        return $this->request('GET', '/v1/status');
    }

    public function tokens(): array
    {
        return $this->request('GET', '/v1/tokens')['data'];
    }

    public function addressInfo(string $address): array
    {
        return $this->request('GET', '/v1/chain/addresses/' . rawurlencode($address));
    }

    public function transactionStatus(string $txid): array
    {
        return $this->request('GET', '/v1/chain/transactions/' . rawurlencode($txid));
    }

    // ── invoices ────────────────────────────────────────────────────────────────────────────

    /**
     * @param array{order_id?:string, description?:string, callback_url?:string, success_url?:string, return_url?:string, expires_in_minutes?:int, metadata?:array} $options
     * success_url: customer is redirected here after paying (+ invoice_id, order_id, status query params).
     * return_url:  "Back to store" link on the payment page.
     * Creating twice with the same order_id returns the same invoice (safe to retry).
     */
    public function createInvoice(string $token, string $amount, array $options = []): array
    {
        return $this->request('POST', '/v1/invoices', ['token' => $token, 'amount' => $amount] + $options);
    }

    /**
     * USD-priced invoice. Without $options['token'] the customer picks the coin on the payment page
     * (limit the choice with $options['tokens'] = ['USDT', 'TRX']); with it, it is converted now.
     */
    public function createUsdInvoice(string $priceUsd, array $options = []): array
    {
        return $this->request('POST', '/v1/invoices', ['price_usd' => $priceUsd] + $options);
    }

    /** Current USD prices, e.g. ['TRX' => '0.3342', 'USDT' => '1']. */
    public function rates(): array
    {
        return $this->request('GET', '/v1/rates')['rates'];
    }

    public function getInvoice(string $id): array
    {
        return $this->request('GET', '/v1/invoices/' . rawurlencode($id));
    }

    public function getInvoiceByOrder(string $orderId): array
    {
        return $this->request('GET', '/v1/invoices/by-order/' . rawurlencode($orderId));
    }

    public function listInvoices(?string $status = null, int $limit = 20, ?string $before = null): array
    {
        return $this->request('GET', '/v1/invoices', null, array_filter(
            ['status' => $status, 'limit' => $limit, 'before' => $before],
            static fn ($v) => $v !== null,
        ));
    }

    // ── withdrawals ─────────────────────────────────────────────────────────────────────────

    /**
     * Pay out to a TRON address. With a merchant key ('withdrawals' scope) the amount comes from
     * your merchant balance; with the platform admin key it is paid from the hot wallet.
     * Re-sending the same $idempotencyKey never creates a second withdrawal.
     */
    public function createWithdrawal(string $token, string $amount, string $toAddress, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/withdrawals', [
            'token' => $token,
            'amount' => $amount,
            'to_address' => $toAddress,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function getWithdrawal(string $id): array
    {
        return $this->request('GET', '/v1/withdrawals/' . rawurlencode($id));
    }

    public function approveWithdrawal(string $id): array
    {
        return $this->request('POST', '/v1/withdrawals/' . rawurlencode($id) . '/approve');
    }

    public function rejectWithdrawal(string $id, string $reason = 'rejected by admin'): array
    {
        return $this->request('POST', '/v1/withdrawals/' . rawurlencode($id) . '/reject', ['reason' => $reason]);
    }

    // ── webhooks ────────────────────────────────────────────────────────────────────────────

    /**
     * Verifies an incoming webhook. Pass the RAW body: file_get_contents('php://input').
     *
     *   $body = file_get_contents('php://input');
     *   if (!MercanPay::verifyWebhook($secret, $body, $_SERVER['HTTP_X_MERCANPAY_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
     *   $event = json_decode($body, true);
     */
    public static function verifyWebhook(string $secret, string $body, string $signatureHeader, int $toleranceSec = 300): bool
    {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        $ts = (int) $parts['t'];
        if (abs(time() - $ts) > $toleranceSec) {
            return false;
        }
        $expected = hash_hmac('sha256', $ts . '.' . $body, $secret);
        return hash_equals($expected, $parts['v1']);
    }

    // ── transport ───────────────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: mercanpay-php/' . self::VERSION,
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($body !== null || $method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? new \stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new MercanPayException(0, 'network_error', $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $error = is_array($data) ? ($data['error'] ?? []) : [];
            throw new MercanPayException($status, $error['code'] ?? 'http_error', $error['message'] ?? substr((string) $raw, 0, 200));
        }
        if (!is_array($data)) {
            throw new MercanPayException($status, 'invalid_response', 'Response was not valid JSON');
        }
        return $data;
    }
}
