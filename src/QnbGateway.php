<?php

namespace XLaravel\PaylineQnbDriver;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\ProvidesGatewayCapabilities;
use XLaravel\Payline\Contracts\QueriesPayments;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\GatewayCapabilities;
use XLaravel\Payline\DTOs\PaymentQuery;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class QnbGateway implements AuthorizesPayments, CapturesPayments, ChargesPayments, Gateway, HandlesCallbacks, ProvidesGatewayCapabilities, QueriesPayments, RefundsPayments, VoidsPayments
{
    private const array CURRENCIES = [
        'TRY' => '949',
        'USD' => '840',
        'EUR' => '978',
        'GBP' => '826',
        'JPY' => '392',
        'RUB' => '643',
    ];

    private const int PRE_AUTH_CAPTURE_DAYS = 25;

    public function __construct(private readonly array $config) {}

    public function getName(): string
    {
        return 'qnb';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            operations: [
                TransactionType::Payment,
                TransactionType::Authorization,
                TransactionType::Capture,
                TransactionType::Refund,
                TransactionType::Void,
            ],
            methods: [PaymentMethod::CreditCard, PaymentMethod::DebitCard],
            currencies: array_keys(self::CURRENCIES),
            threeDs: true,
            nonThreeDs: false,
            partialRefunds: true,
            statusQueries: true,
        );
    }

    public function pay(PaymentRequest $data): PaymentResponse
    {
        return $this->initiate($data, 'Auth', TransactionType::Payment);
    }

    public function authorize(PaymentRequest $data): PaymentResponse
    {
        return $this->initiate($data, 'PreAuth', TransactionType::Authorization);
    }

    public function capture(CaptureData $data): PaymentResponse
    {
        $response = Http::asForm()->post($this->config['endpoint'], [
            'MbrId'       => $this->config['mbr_id'],
            'MerchantId'  => $this->config['merchant_id'],
            'UserCode'    => $this->config['user_name'],
            'UserPass'    => $this->config['password'],
            'SecureType'  => 'NonSecure',
            'TxnType'     => 'PostAuth',
            'OrgOrderId'  => $data->gatewayTransactionId,
            'PurchAmount' => $this->formatAmount($data->amount),
            'Currency'    => $this->resolveCurrency($data->currency),
            'Lang'        => $this->config['lang'] ?? 'TR',
        ]);

        return $this->parseNonSecureResponse(
            $response,
            TransactionType::Capture,
            $data->gatewayTransactionId,
            $data->currency,
        );
    }

    public function refund(RefundData $data): PaymentResponse
    {
        $response = Http::asForm()->post($this->config['endpoint'], [
            'MbrId'       => $this->config['mbr_id'],
            'MerchantId'  => $this->config['merchant_id'],
            'UserCode'    => $this->config['user_name'],
            'UserPass'    => $this->config['password'],
            'SecureType'  => 'NonSecure',
            'TxnType'     => 'Refund',
            'OrgOrderId'  => $data->gatewayTransactionId,
            'PurchAmount' => $this->formatAmount($data->amount),
            'Currency'    => $this->resolveCurrency($data->currency),
            'Lang'        => $this->config['lang'] ?? 'TR',
        ]);

        return $this->parseNonSecureResponse(
            $response,
            TransactionType::Refund,
            $data->gatewayTransactionId,
            $data->currency,
        );
    }

    public function void(VoidData $data): PaymentResponse
    {
        $response = Http::asForm()->post($this->config['endpoint'], [
            'MbrId'      => $this->config['mbr_id'],
            'MerchantId' => $this->config['merchant_id'],
            'UserCode'   => $this->config['user_name'],
            'UserPass'   => $this->config['password'],
            'SecureType' => 'NonSecure',
            'TxnType'    => 'Void',
            'OrgOrderId' => $data->gatewayTransactionId,
            'Currency'   => '949',
            'Lang'       => $this->config['lang'] ?? 'TR',
        ]);

        return $this->parseNonSecureResponse($response, TransactionType::Void, $data->gatewayTransactionId);
    }

    public function handleCallback(CallbackData $data): PaymentResponse
    {
        $post = $data->requestData;

        if (! $this->verifyCallbackHash($post)) {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                errorCode: 'HASH_MISMATCH',
                errorMessage: 'Security verification failed.',
            );
        }

        $type = $this->operationType($post['TxnType'] ?? '');
        $orderId = $post['OrderId'] ?? null;
        $hostRefNum = $post['HostRefNum'] ?? null;
        $authCode = $post['AuthCode'] ?? null;
        $procCode = $post['ProcReturnCode'] ?? '';
        $errMsg = $post['ErrMsg'] ?? null;
        $currency = $this->isoCurrency($post['Currency'] ?? null);

        if (($post['3DStatus'] ?? '0') !== '1') {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: $type,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderId,
                gatewayOrderId: $hostRefNum,
                gatewayResponseCode: $procCode,
                gatewayResponseMessage: $post['IrcDet'] ?? null,
                currency: $currency,
                errorCode: '3DS_FAILED',
                errorMessage: $errMsg ?? '3D Secure verification failed.',
                metadata: $post,
            );
        }

        if ($procCode !== '00') {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: $type,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderId,
                gatewayOrderId: $hostRefNum,
                gatewayAuthCode: $authCode,
                gatewayResponseCode: $procCode,
                gatewayResponseMessage: $post['IrcDet'] ?? null,
                currency: $currency,
                errorCode: $procCode,
                errorMessage: $errMsg ?? 'Payment failed.',
                metadata: $post,
            );
        }

        $authorization = $type === TransactionType::Authorization;

        return new PaymentResponse(
            status: $authorization ? TransactionStatus::Authorized : TransactionStatus::Successful,
            type: $type,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orderId,
            gatewayOrderId: $hostRefNum,
            gatewayAuthCode: $authCode,
            gatewayResponseCode: $procCode,
            gatewayResponseMessage: $errMsg,
            currency: $currency,
            metadata: $post,
            expiresAt: $authorization ? now()->addDays(self::PRE_AUTH_CAPTURE_DAYS) : null,
        );
    }

    public function queryPayment(PaymentQuery $query): PaymentResponse
    {
        $orderId = $query->gatewayTransactionId
            ?? throw new InvalidArgumentException('QNB requires the provider order id to query a payment.');

        $response = Http::asForm()->post($this->config['endpoint'], [
            'MbrId' => $this->config['mbr_id'],
            'MerchantId' => $this->config['merchant_id'],
            'UserCode' => $this->config['user_name'],
            'UserPass' => $this->config['password'],
            'SecureType' => 'Inquiry',
            'TxnType' => 'OrderInquiry',
            'OrgOrderId' => $orderId,
            'Currency' => self::CURRENCIES['TRY'],
            'Lang' => $this->config['lang'] ?? 'TR',
        ]);

        if (! $response->successful()) {
            return new PaymentResponse(
                status: TransactionStatus::Unknown,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderId,
                errorCode: (string) $response->status(),
                errorMessage: 'Order inquiry failed.',
            );
        }

        $data = $this->parseResponseBody($response->body());
        $type = $this->operationType($data['TxnType'] ?? '');
        $procCode = $data['ProcReturnCode'] ?? '';

        $status = match (true) {
            $this->isVoided($data) => TransactionStatus::Voided,
            $procCode === '00' => $type === TransactionType::Authorization
                ? TransactionStatus::Authorized
                : TransactionStatus::Successful,
            ($data['TxnResult'] ?? '') === 'Failed' => TransactionStatus::Failed,
            default => TransactionStatus::Unknown,
        };

        return new PaymentResponse(
            status: $status,
            type: $type,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orderId,
            gatewayOrderId: $data['HostRefNum'] ?? null,
            gatewayAuthCode: $data['AuthCode'] ?? null,
            gatewayResponseCode: $procCode,
            gatewayResponseMessage: $data['ErrMsg'] ?? null,
            currency: $this->isoCurrency($data['Currency'] ?? null),
            metadata: $this->withRefundState($data),
        );
    }

    private function initiate(PaymentRequest $data, string $txnType, TransactionType $type): PaymentResponse
    {
        $card = $data->card ?? throw new InvalidArgumentException('Card is required for QNB payment.');

        $rnd = Str::random(32);
        $orderId = (string) Str::uuid();
        $installment = ($data->installments !== null && $data->installments > 1)
            ? (string) $data->installments
            : '0';
        $amount = $this->formatAmount($data->amount);
        $okUrl = $data->callbackUrl;
        $failUrl = $data->callbackUrl;
        $expiry = sprintf('%02d%s', (int) $card->expiryMonth, substr($card->expiryYear, -2));

        $hash = $this->buildPaymentHash($orderId, $amount, $okUrl, $failUrl, $txnType, $installment, $rnd);

        $response = Http::asForm()->post($this->config['endpoint'], [
            'MbrId'            => $this->config['mbr_id'],
            'MerchantID'       => $this->config['merchant_id'],
            'UserCode'         => $this->config['user_name'],
            'UserPass'         => $this->config['password'],
            'SecureType'       => '3DPay',
            'TxnType'          => $txnType,
            'InstallmentCount' => $installment,
            'Currency'         => $this->resolveCurrency($data->currency),
            'OkUrl'            => $okUrl,
            'FailUrl'          => $failUrl,
            'OrderId'          => $orderId,
            'PurchAmount'      => $amount,
            'Pan'              => $card->number,
            'Expiry'           => $expiry,
            'Cvv2'             => $card->cvv,
            'Lang'             => $this->config['lang'] ?? 'TR',
            'Rnd'              => $rnd,
            'Hash'             => $hash,
        ]);

        if (! $response->successful()) {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: $type,
                gatewayName: $this->getName(),
                amount: $data->amount,
                currency: $data->currency,
                errorCode: (string) $response->status(),
                errorMessage: 'Payment initiation failed.',
            );
        }

        return new PaymentResponse(
            status: TransactionStatus::Pending,
            type: $type,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orderId,
            amount: $data->amount,
            currency: $data->currency,
            redirectForm: $response->body(),
        );
    }

    private function parseNonSecureResponse(
        Response $response,
        TransactionType $type,
        string $orgOrderId,
        ?string $currency = null,
    ): PaymentResponse {
        if (! $response->successful()) {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: $type,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orgOrderId,
                currency: $currency ?? 'TRY',
                errorCode: (string) $response->status(),
                errorMessage: 'Request failed.',
            );
        }

        $data = $this->parseResponseBody($response->body());
        $procCode = $data['ProcReturnCode'] ?? '';
        $success = $procCode === '00' || ($data['TxnResult'] ?? '') === 'Success';

        $status = match (true) {
            $success && $type === TransactionType::Void => TransactionStatus::Voided,
            $success => TransactionStatus::Successful,
            default => TransactionStatus::Failed,
        };

        return new PaymentResponse(
            status: $status,
            type: $type,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orgOrderId,
            gatewayOrderId: $data['HostRefNum'] ?? null,
            gatewayAuthCode: $data['AuthCode'] ?? null,
            gatewayResponseCode: $procCode,
            gatewayResponseMessage: $data['ErrMsg'] ?? null,
            currency: $currency ?? 'TRY',
            errorCode: $success ? null : $procCode,
            errorMessage: $success ? null : ($data['ErrMsg'] ?? 'Transaction failed.'),
            metadata: $data ?: null,
        );
    }

    private function buildPaymentHash(
        string $orderId,
        string $amount,
        ?string $okUrl,
        ?string $failUrl,
        string $txnType,
        string $installment,
        string $rnd,
    ): string {
        $str = $this->config['mbr_id']
            . $orderId
            . $amount
            . $okUrl
            . $failUrl
            . $txnType
            . $installment
            . $rnd
            . $this->config['merchant_pass'];

        return base64_encode(sha1($str, true));
    }

    private function verifyCallbackHash(array $data): bool
    {
        $str = $this->config['merchant_id']
            . $this->config['merchant_pass']
            . ($data['OrderId'] ?? '')
            . ($data['AuthCode'] ?? '')
            . ($data['ProcReturnCode'] ?? '')
            . ($data['3DStatus'] ?? '')
            . ($data['ResponseRnd'] ?? '')
            . $this->config['user_name'];

        return hash_equals(
            base64_encode(sha1($str, true)),
            $data['ResponseHash'] ?? '',
        );
    }

    private function parseResponseBody(string $body): array
    {
        $json = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        parse_str(str_replace(["\r\n", "\r", "\n"], '&', trim($body)), $parsed);
        return $parsed ?: [];
    }

    private function operationType(string $txnType): TransactionType
    {
        return $txnType === 'PreAuth'
            ? TransactionType::Authorization
            : TransactionType::Payment;
    }

    private function isVoided(array $data): bool
    {
        if (isset($data['IsVoided'])) {
            return filter_var($data['IsVoided'], FILTER_VALIDATE_BOOL);
        }

        return filled($data['VoidDate'] ?? null) || (int) ($data['VoidTime'] ?? 0) > 0;
    }

    private function withRefundState(array $data): ?array
    {
        if ($data === []) {
            return null;
        }

        $refunded = $data['RefundedAmount'] ?? null;
        $purchased = $data['PurchAmount'] ?? null;

        if ($refunded === null || $purchased === null) {
            $data['refund_state'] = filter_var($data['IsRefunded'] ?? false, FILTER_VALIDATE_BOOL)
                ? 'refunded'
                : 'unknown';

            return $data;
        }

        $data['refund_state'] = match (true) {
            (float) $refunded <= 0.0 => 'none',
            (float) $refunded >= (float) $purchased => 'refunded',
            default => 'partial',
        };

        return $data;
    }

    private function isoCurrency(?string $code): string
    {
        $iso = array_search($code, self::CURRENCIES, true);

        return $iso === false ? 'TRY' : $iso;
    }

    private function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    private function resolveCurrency(string $currency): string
    {
        return self::CURRENCIES[strtoupper($currency)]
            ?? throw new InvalidArgumentException("QNB does not support the currency [{$currency}].");
    }
}
