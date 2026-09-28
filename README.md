# payline-qnb-gateway

[![Tests](https://github.com/x-laravel/payline-qnb-gateway/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline-qnb-gateway/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

QNB Finansbank VPOS gateway for [x-laravel/payline](https://github.com/x-laravel/payline).

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- x-laravel/payline ^1.0

## Installation

```bash
composer require x-laravel/payline-qnb-gateway
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
        'lang'          => env('QNB_LANG', 'TR'),
        'three_ds_session_minutes' => env('QNB_3DS_SESSION_MINUTES', 30),
    ],
],
```

Set the corresponding environment variables in `.env`:

```dotenv
PAYLINE_GATEWAY=qnb
PAYLINE_TEST_MODE=true

QNB_MBR_ID=5
QNB_MERCHANT_ID=your-merchant-id
QNB_USER_NAME=your-user-name
QNB_PASSWORD=your-password
QNB_MERCHANT_PASS=your-merchant-pass
```

## Test and Live

The gateway ships both QNB addresses and picks between them with `payline.test_mode`:

| `test_mode` | Host |
|-------------|------|
| `true` | `https://vpostest.qnb.com.tr` |
| `false` | `https://vpos.qnb.com.tr` |

`PAYLINE_TEST_MODE` defaults to `false`, so an installation that never sets it talks to
the live one. Each environment issues its own merchant identifier, user name and
passwords, so switching the flag also means switching those values.

A `base_url` in the `qnb` block wins over both, for a merchant QNB handed an address of
its own. It carries a scheme and a host only; the gateway appends its own paths.

QNB answers the same request in a different format per gateway page. The 3D Secure step
has to come back as an HTML form, so it goes to `/Gateway/Default.aspx`. Everything else
goes to `/Gateway/JsonGate.aspx`, which takes the same form encoded request and answers
JSON under a `PaymentRequest` key. Both are built from the same host.

`Default.aspx` answers `key=value` pairs joined by `;;` on one line, and the gateway
still reads that format.

## Unfinished 3D Secure

An order whose customer has not come back from the 3D Secure page answers `OrderInquiry`
with `ProcReturnCode` `V000`, `TxnStatus` `N` and `TxnResult` `Failed`:

```
ErrMsg: İşlem tamamlanamadı /devam ediyor
```

QNB sends the same answer whether the customer walked away an hour ago or is typing the
one time password right now, so the gateway reports it as `Pending` rather than `Failed`.
Payline settles it as `expired` once the transaction passes the deadline the gateway sets
from `three_ds_session_minutes`.

`V000` is the only part of that answer worth reading. `TxnStatus` is `N` for a genuine
rejection too, both for a declined card (`14`) and for a failed 3D Secure step (`MR15`),
so it says nothing about whether the order is still running.

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

Payline handles the callback automatically via its built-in route (`/payline/callback/qnb`). After 3DS completes, QNB POSTs back to this URL. The gateway verifies the response hash and the user is redirected to `payline.callback_success_url` or `payline.callback_failure_url`.

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
Payline::payment($payment)->capture();
```

Payline finds the authorization itself and captures it in full. Pass an `amount` in minor
units to collect part of it, and an `idempotencyKey` to make a retry safe.

### Refund

```php
Payline::payment($payment)->refund(amount: 5000);
```

### Void (Cancel)

```php
Payline::payment($payment)->void();
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
| Reconcile   | ✓ | `OrderInquiry`, keyed on the provider order id, reports `RefundedAmount` and the void flag |
| Webhooks    | ✗ | QNB uses a callback-only flow |

The gateway generates a UUID as the QNB `OrderId` for every attempt and records it as
`gateway_transaction_id`. Follow-up operations and reconciliation are keyed on that value,
not on the merchant reference.

An answer the gateway cannot read, and an order the bank reports as `V013`, leave the
transaction `unknown` rather than `failed`. A refund the bank accepted but Payline
recorded as failed would not consume the refund ceiling, and the next attempt would
return the money twice.

The gateway declares the table above through `ProvidesGatewayCapabilities`, so commission
routing skips it for a request it cannot take: an operation it does not implement, a card
kind or currency it does not accept, or a charge asked for without 3D Secure. Credit and
debit cards are both accepted.

## Amounts

Payline works in the minor unit, QNB's `PurchAmount` is the lira amount with two decimal
places and a dot, so the gateway divides by a hundred on the way out:

| Payline | Sent as | Charged |
|---------|---------|---------|
| `109900` | `1099.00` | 1099,00 TL |
| `10050` | `100.50` | 100,50 TL |

The `Exponent` field in the answer is the number of decimal places the currency has, not a
statement about the amount that was sent. It reads `2` for lira on every response,
including one that carries no amount at all.

## What the Gateway Records

QNB answers every operation with the same record, and the gateway stores it whole on
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