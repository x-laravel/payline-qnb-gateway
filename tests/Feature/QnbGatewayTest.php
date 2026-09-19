<?php

namespace XLaravel\PaylineQnbDriver\Tests\Feature;

use InvalidArgumentException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\Contracts\QueriesPayments;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\PaymentQuery;
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
                && Str::isUuid($body['OrderId'])
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

    public function test_handleCallback_carries_no_identifier_when_the_hash_does_not_match(): void
    {
        $post = $this->buildCallbackPayload('00', '1');
        $post['ResponseHash'] = 'INVALID_HASH';

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertNull($response->gatewayTransactionId);
        $this->assertNull($response->gatewayOrderId);
    }

    public function test_handleCallback_maps_a_preauth_to_an_authorization(): void
    {
        $post = $this->buildCallbackPayload('00', '1', txnType: 'PreAuth');

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionType::Authorization, $response->type);
        $this->assertSame(TransactionStatus::Authorized, $response->status);
    }

    public function test_handleCallback_gives_an_authorization_a_twenty_five_day_capture_window(): void
    {
        $post = $this->buildCallbackPayload('00', '1', txnType: 'PreAuth');

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertNotNull($response->expiresAt);
        $this->assertSame(now()->addDays(25)->format('Y-m-d'), $response->expiresAt->format('Y-m-d'));
    }

    public function test_handleCallback_leaves_a_sale_without_an_expiry(): void
    {
        $post = $this->buildCallbackPayload('00', '1');

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionType::Payment, $response->type);
        $this->assertNull($response->expiresAt);
    }

    public function test_handleCallback_keeps_the_operation_type_on_a_declined_preauth(): void
    {
        $post = $this->buildCallbackPayload('51', '1', 'Insufficient funds', 'PreAuth');

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionType::Authorization, $response->type);
        $this->assertSame(TransactionStatus::Failed, $response->status);
    }

    public function test_pay_generates_a_distinct_order_id_per_attempt(): void
    {
        Http::fake(['*' => Http::response('<html>form</html>', 200)]);

        $data = $this->makePaymentRequest();
        $this->gateway->pay($data);
        $this->gateway->pay($data);

        $orderIds = [];

        Http::assertSentCount(2);
        Http::recorded(function ($request) use (&$orderIds) {
            $orderIds[] = $request->data()['OrderId'];

            return true;
        });

        $this->assertCount(2, array_unique($orderIds));
    }

    public function test_query_payment_reports_a_successful_sale(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'IsVoided' => 'False',
            'IsRefunded' => 'False',
            'HostRefNum' => 'REF999',
            'AuthCode' => 'AUTH456',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame(TransactionType::Payment, $response->type);
        $this->assertSame('REF999', $response->gatewayOrderId);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['SecureType'] === 'Inquiry'
                && $body['TxnType'] === 'OrderInquiry'
                && $body['OrgOrderId'] === 'ORD-001';
        });
    }

    public function test_query_payment_reports_a_voided_sale(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'IsVoided' => true,
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Voided, $response->status);
    }

    public function test_query_payment_reads_a_string_voided_flag(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'IsVoided' => 'True',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Voided, $response->status);
    }

    public function test_query_payment_reports_an_authorization_as_authorized(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'PreAuth',
            'IsVoided' => 'False',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionType::Authorization, $response->type);
        $this->assertSame(TransactionStatus::Authorized, $response->status);
    }

    public function test_query_payment_stays_unknown_when_the_answer_cannot_be_read(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
    }

    public function test_query_payment_requires_the_provider_order_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->gateway->queryPayment(new PaymentQuery(reference: 'ORD-001'));
    }

    public function test_an_unsupported_currency_is_rejected(): void
    {
        Http::fake(['*' => Http::response('<html>form</html>', 200)]);

        $this->expectException(InvalidArgumentException::class);

        $this->gateway->pay($this->makePaymentRequest(currency: 'CHF'));
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
        $this->assertInstanceOf(QueriesPayments::class, $this->gateway);

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

    private function buildCallbackPayload(
        string $procCode,
        string $threeDsStatus,
        string $errMsg = '',
        string $txnType = 'Auth',
    ): array {
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
            'TxnType' => $txnType,
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
