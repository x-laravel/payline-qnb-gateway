<?php

namespace XLaravel\PaylineQnbDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\PaylineQnbDriver\QnbGateway;
use XLaravel\PaylineQnbDriver\Tests\TestCase;

class QnbGatewayTest extends TestCase
{
    private QnbGateway $gateway;
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = config('payline.gateways.qnb');
        $this->gateway = new QnbGateway($this->config);
    }

    public function test_pay_returns_pending_response_with_redirect_form(): void
    {
        Http::fake([
            '*' => Http::response('<html><body><form method="POST" action="https://3ds.bank.com">...</form></body></html>', 200),
        ]);

        $data = $this->makePaymentRequest();
        $response = $this->gateway->pay($data);

        $this->assertSame(TransactionStatus::Pending, $response->status);
        $this->assertSame(TransactionType::Payment, $response->type);
        $this->assertSame('ORD-001', $response->gatewayTransactionId);
        $this->assertNotNull($response->redirectForm);
        $this->assertTrue($response->requiresRedirect());
    }

    public function test_authorize_returns_pending_with_authorization_type(): void
    {
        Http::fake([
            '*' => Http::response('<html>3ds form</html>', 200),
        ]);

        $data = $this->makePaymentRequest();
        $response = $this->gateway->authorize($data);

        $this->assertSame(TransactionStatus::Pending, $response->status);
        $this->assertSame(TransactionType::Authorization, $response->type);
    }

    public function test_pay_returns_failed_when_endpoint_returns_error(): void
    {
        Http::fake([
            '*' => Http::response('', 500),
        ]);

        $data = $this->makePaymentRequest();
        $response = $this->gateway->pay($data);

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('500', $response->errorCode);
    }

    public function test_pay_sends_correct_fields_to_qnb(): void
    {
        Http::fake(['*' => Http::response('<html>form</html>', 200)]);

        $data = $this->makePaymentRequest(amount: 15000, installments: 3);
        $this->gateway->pay($data);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['MbrId'] === '5'
                && $body['MerchantID'] === 'TEST_MERCHANT'
                && $body['SecureType'] === '3DPay'
                && $body['TxnType'] === 'Auth'
                && $body['OrderId'] === 'ORD-001'
                && $body['PurchAmount'] === '150.00'
                && $body['Currency'] === '949'
                && $body['InstallmentCount'] === '3';
        });
    }

    public function test_pay_formats_amount_correctly_for_single_payment(): void
    {
        Http::fake(['*' => Http::response('<html>form</html>', 200)]);

        $data = $this->makePaymentRequest(amount: 10050);
        $this->gateway->pay($data);

        Http::assertSent(fn ($r) => $r->data()['PurchAmount'] === '100.50');
    }

    public function test_pay_sets_installment_zero_for_single_payment(): void
    {
        Http::fake(['*' => Http::response('<html>form</html>', 200)]);

        $data = $this->makePaymentRequest(installments: 1);
        $this->gateway->pay($data);

        Http::assertSent(fn ($r) => $r->data()['InstallmentCount'] === '0');
    }

    public function test_pay_maps_usd_currency_code(): void
    {
        Http::fake(['*' => Http::response('<html>form</html>', 200)]);

        $data = $this->makePaymentRequest(currency: 'USD');
        $this->gateway->pay($data);

        Http::assertSent(fn ($r) => $r->data()['Currency'] === '840');
    }

    public function test_handleCallback_returns_successful_on_valid_hash_and_proc_00(): void
    {
        $post = $this->buildCallbackPayload('00', '1');

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame('ORD-001', $response->gatewayTransactionId);
        $this->assertSame('HOST123', $response->gatewayOrderId);
        $this->assertSame('AUTH456', $response->gatewayAuthCode);
    }

    public function test_handleCallback_returns_failed_on_hash_mismatch(): void
    {
        $post = $this->buildCallbackPayload('00', '1');
        $post['ResponseHash'] = 'INVALID_HASH';

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('HASH_MISMATCH', $response->errorCode);
    }

    public function test_handleCallback_returns_failed_when_3ds_status_is_not_1(): void
    {
        $post = $this->buildCallbackPayload('00', '0');

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('3DS_FAILED', $response->errorCode);
    }

    public function test_handleCallback_returns_failed_when_proc_code_is_not_00(): void
    {
        $post = $this->buildCallbackPayload('51', '1', 'Insufficient funds');

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('51', $response->errorCode);
        $this->assertSame('Insufficient funds', $response->errorMessage);
    }

    public function test_refund_sends_correct_request_and_returns_refunded_status(): void
    {
        Http::fake(['*' => Http::response('ProcReturnCode=00&TxnResult=Success&HostRefNum=REF999', 200)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame(TransactionType::Refund, $response->type);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['TxnType'] === 'Refund'
                && $body['OrgOrderId'] === 'ORD-001'
                && $body['PurchAmount'] === '50.00'
                && $body['SecureType'] === 'NonSecure';
        });
    }

    public function test_void_sends_correct_request_and_returns_voided_status(): void
    {
        Http::fake(['*' => Http::response('ProcReturnCode=00&TxnResult=Success', 200)]);

        $response = $this->gateway->void(new VoidData(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Voided, $response->status);
        $this->assertSame(TransactionType::Void, $response->type);

        Http::assertSent(fn ($r) => $r->data()['TxnType'] === 'Void'
            && $r->data()['OrgOrderId'] === 'ORD-001');
    }

    public function test_capture_sends_correct_request_and_returns_successful_status(): void
    {
        Http::fake(['*' => Http::response('ProcReturnCode=00&TxnResult=Success', 200)]);

        $response = $this->gateway->capture(new CaptureData(
            gatewayTransactionId: 'ORD-001',
            amount: 10000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame(TransactionType::Capture, $response->type);

        Http::assertSent(fn ($r) => $r->data()['TxnType'] === 'PostAuth'
            && $r->data()['OrgOrderId'] === 'ORD-001');
    }

    public function test_refund_returns_failed_on_non_00_proc_code(): void
    {
        Http::fake(['*' => Http::response('ProcReturnCode=05&ErrMsg=Do+not+honor', 200)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('05', $response->errorCode);
    }

    public function test_get_name_returns_qnb(): void
    {
        $this->assertSame('qnb', $this->gateway->getName());
    }

    public function test_declares_only_the_operations_qnb_supports(): void
    {
        $this->assertInstanceOf(ChargesPayments::class, $this->gateway);
        $this->assertInstanceOf(AuthorizesPayments::class, $this->gateway);
        $this->assertInstanceOf(CapturesPayments::class, $this->gateway);
        $this->assertInstanceOf(RefundsPayments::class, $this->gateway);
        $this->assertInstanceOf(VoidsPayments::class, $this->gateway);
        $this->assertInstanceOf(HandlesCallbacks::class, $this->gateway);

        $this->assertNotInstanceOf(HandlesWebhooks::class, $this->gateway);
    }

    private function makePaymentRequest(
        int $amount = 10000,
        string $currency = 'TRY',
        ?int $installments = null,
    ): PaymentRequest {
        return new PaymentRequest(
            reference: 'ORD-001',
            amount: $amount,
            currency: $currency,
            callbackUrl: 'https://example.com/callback',
            installments: $installments,
            card: new Card(
                holderName: 'Test User',
                number: '4111111111111111',
                expiryMonth: '12',
                expiryYear: '2030',
                cvv: '123',
            ),
        );
    }

    private function buildCallbackPayload(string $procCode, string $threeDsStatus, string $errMsg = ''): array
    {
        $orderId = 'ORD-001';
        $authCode = 'AUTH456';
        $responseRnd = 'RND123456789';
        $hostRefNum = 'HOST123';

        $str = $this->config['merchant_id']
            . $this->config['merchant_pass']
            . $orderId
            . $authCode
            . $procCode
            . $threeDsStatus
            . $responseRnd
            . $this->config['user_name'];

        $hash = base64_encode(sha1($str, true));

        return [
            'OrderId' => $orderId,
            'AuthCode' => $authCode,
            'ProcReturnCode' => $procCode,
            '3DStatus' => $threeDsStatus,
            'ResponseRnd' => $responseRnd,
            'HostRefNum' => $hostRefNum,
            'ResponseHash' => $hash,
            'ErrMsg' => $errMsg,
        ];
    }
}
