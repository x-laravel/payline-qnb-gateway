# payline-qnb-driver

[![Tests](https://github.com/x-laravel/payline-qnb-driver/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline-qnb-driver/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

QNB Finansbank VPOS driver for [x-laravel/payline](https://github.com/x-laravel/payline).

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- x-laravel/payline ^1.0

## Installation

```bash
composer require x-laravel/payline-qnb-driver
```

## Configuration

Add the `qnb` block to `config/payline.php` under `gateways`:

```php
'gateways' => [
    'qnb' => [
        'mbr_id'        => env('QNB_MBR_ID', '5'),
        'merchant_id'   => env('QNB_MERCHANT_ID'),
        'user_name'     => env('QNB_USER_NAME'),
        'password'      => env('QNB_PASSWORD'),
        'merchant_pass' => env('QNB_MERCHANT_PASS'),
        'endpoint'      => env('QNB_ENDPOINT', 'https://vpostest.qnb.com.tr/Gateway/Default.aspx'),
        'lang'          => env('QNB_LANG', 'TR'),
    ],
],
```

Set the corresponding environment variables in `.env`:

```dotenv
PAYLINE_DRIVER=qnb

QNB_MBR_ID=5
QNB_MERCHANT_ID=your-merchant-id
QNB_USER_NAME=your-user-name
QNB_PASSWORD=your-password
QNB_MERCHANT_PASS=your-merchant-pass
QNB_ENDPOINT=https://vpos.qnb.com.tr/Gateway/Default.aspx
```

> **Sandbox endpoint:** `https://vpostest.qnb.com.tr/Gateway/Default.aspx`  
> **Production endpoint:** `https://vpos.qnb.com.tr/Gateway/Default.aspx`

## Usage

### Charging a payment

```php
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\PaymentRequest;

$data = PaymentRequest::fromPayable(
    payable: $order,
    card: new Card(
        holderName: 'John Doe',
        number: '4111111111111111',
        expiryMonth: '12',
        expiryYear: '2030',
        cvv: '123',
    ),
    installments: 1,
    customerIp: $request->ip(),
);

$response = $order->pay('qnb')->charge($data);
```

QNB uses a **3DS HTML form** flow. On success, `pay()` returns a `PaymentResponse` with `status = Pending` and a `redirectForm` containing a self-submitting HTML form that forwards the customer to their bank's 3DS page:

```php
if ($response->requiresRedirect()) {
    return response($response->redirectForm); // renders and auto-submits the form
}
```

### Handling the callback

Payline handles the callback automatically via its built-in route (`/payline/callback/qnb`). After 3DS completes, QNB POSTs back to this URL. The driver verifies the response hash and the user is redirected to `payline.callback_success_url` or `payline.callback_failure_url`.

You can listen to the dispatched events for any post-payment logic:

```php
use XLaravel\Payline\Events\PaymentSucceeded;
use XLaravel\Payline\Events\PaymentFailed;

class HandlePaymentSucceeded
{
    public function handle(PaymentSucceeded $event): void
    {
        $event->payment;     // Payment model
        $event->transaction; // Transaction model
        $event->response;    // PaymentResponse DTO
    }
}
```

### Pre-authorization & Capture

```php
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\Facades\Payline;

// 1. Pre-authorize (TxnType=PreAuth, 3DS flow)
$response = $order->pay('qnb')->authorize($data);

// 2. Capture later
Payline::via('qnb')->capture(
    new CaptureData(
        gatewayTransactionId: $transaction->gateway_transaction_id,
        amount: $transaction->amount,
        currency: $transaction->currency,
    ),
    $payment,
    $transaction,
);
```

### Refund

```php
use XLaravel\Payline\DTOs\RefundData;

Payline::via('qnb')->refund(
    new RefundData(
        gatewayTransactionId: $transaction->gateway_transaction_id,
        amount: 5000, // kuruş
        currency: 'TRY',
    ),
    $payment,
    $transaction,
);
```

### Void (Cancel)

```php
use XLaravel\Payline\DTOs\VoidData;

Payline::via('qnb')->void(
    new VoidData(gatewayTransactionId: $transaction->gateway_transaction_id),
    $payment,
    $transaction,
);
```

## Supported Currencies

| ISO Code | QNB Code |
|----------|----------|
| TRY      | 949      |
| USD      | 840      |
| EUR      | 978      |
| GBP      | 826      |
| JPY      | 392      |
| RUB      | 643      |

## Supported Operations

| Operation   | Supported | Notes |
|-------------|-----------|-------|
| Pay (3DS)   | ✓ | Returns a self-submitting HTML form (`redirectForm`) |
| Authorize   | ✓ | PreAuth + 3DS flow, capture window is 25 days |
| Capture     | ✓ | PostAuth via `OrgOrderId`, partial capture is TRY only |
| Refund      | ✓ | Partial or full, must fall in a later batch than the sale |
| Void/Cancel | ✓ | Must fall in the same batch as the sale |
| Reconcile   | ✓ | `OrderInquiry`, keyed on the provider order id |
| Webhooks    | ✗ | QNB uses a callback-only flow |

The driver generates a UUID as the QNB `OrderId` for every attempt and records it as
`gateway_transaction_id`. Follow-up operations and reconciliation are keyed on that value,
not on the merchant reference.

## What the Driver Records

QNB answers every operation with the same record, and the driver stores it whole on
`payline_transactions.metadata`. Payline merges it into the metadata the application
supplied, so both survive.

| Field | Use |
|-------|-----|
| `BatchNo`, `ReqDate`, `SysDate` | Which batch the sale landed in. A cancellation in the same batch is a `Void`, a later one is a `Refund` |
| `RRN`, `F37`, `HostRefNum` | The reference the bank and the card scheme share |
| `AuthCode`, `AuthId`, `TerminalID` | Authorisation identifiers |
| `CardMask`, `CardType`, `DsBrand`, `Eci` | Card and 3D Secure detail |
| `VoidDate`, `VoidTime`, `VoidUserCode` | Set once the order has been cancelled |
| `RefundedAmount`, `RefundedPoint` | How much of the order has been returned |

A callback whose hash does not verify records nothing at all.

`queryPayment()` adds `refund_state` to that metadata, one of `none`, `partial`,
`refunded` or `unknown`. It comes from `RefundedAmount` against `PurchAmount`, and falls
back to the `IsRefunded` flag the integration document describes. The status of the queried
transaction is unaffected: in Payline a refund is its own transaction, so a returned sale
stays `successful` and the refund is recorded separately.

## Testing

```bash
# Build first (once per PHP version)
DOCKER_BUILDKIT=0 docker compose --profile php83 build

# Run tests
docker compose --profile php83 up
docker compose --profile php84 up
docker compose --profile php85 up
```

Or directly:

```bash
composer test
```

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).