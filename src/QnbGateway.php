<?php

namespace XLaravel\PaylineQnbDriver;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class QnbGateway implements AuthorizesPayments, CapturesPayments, ChargesPayments, Gateway, HandlesCallbacks, RefundsPayments, VoidsPayments
{
    private const array CURRENCIES = [
        'TRY' => '949',
        'USD' => '840',
        'EUR' => '978',
        'GBP' => '826',
    ];

    public function __construct(private readonly array $config) {}

    public function getName(): string
    {
        return 'qnb';
    }

    public function supportedMethods(): array
    {
        return [PaymentMethod::CreditCard];
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

        return $this->parseNonSecureResponse($response, TransactionType::Capture, $data->gatewayTransactionId);
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

        return $this->parseNonSecureResponse($response, TransactionType::Refund, $data->gatewayTransactionId);
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
                gatewayTransactionId: $post['OrderId'] ?? null,
                errorCode: 'HASH_MISMATCH',
                errorMessage: 'Security verification failed.',
            );
        }

        $orderId = $post['OrderId'] ?? null;
        $hostRefNum = $post['HostRefNum'] ?? null;
        $authCode = $post['AuthCode'] ?? null;
        $procCode = $post['ProcReturnCode'] ?? '';
        $errMsg = $post['ErrMsg'] ?? null;

        if (($post['3DStatus'] ?? '0') !== '1') {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderId,
                gatewayOrderId: $hostRefNum,
                gatewayResponseCode: $procCode,
                errorCode: '3DS_FAILED',
                errorMessage: $errMsg ?? '3D Secure verification failed.',
            );
        }

        if ($procCode !== '00') {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderId,
                gatewayOrderId: $hostRefNum,
                gatewayAuthCode: $authCode,
                gatewayResponseCode: $procCode,
                errorCode: $procCode,
                errorMessage: $errMsg ?? 'Payment failed.',
            );
        }

        return new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orderId,
            gatewayOrderId: $hostRefNum,
            gatewayAuthCode: $authCode,
            gatewayResponseCode: $procCode,
        );
    }

    private function initiate(PaymentRequest $data, string $txnType, TransactionType $type): PaymentResponse
    {
        $card = $data->card ?? throw new \InvalidArgumentException('Card is required for QNB payment.');

        $rnd = Str::random(32);
        $orderId = $data->reference;
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

    private function parseNonSecureResponse(Response $response, TransactionType $type, string $orgOrderId): PaymentResponse
    {
        if (! $response->successful()) {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: $type,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orgOrderId,
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

    private function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    private function resolveCurrency(string $currency): string
    {
        return self::CURRENCIES[$currency] ?? '949';
    }
}
