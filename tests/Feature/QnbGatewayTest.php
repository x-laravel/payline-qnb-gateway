<?php

namespace XLaravel\Payline\Gateways\Qnb\Tests\Feature;

use InvalidArgumentException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\Contracts\ProvidesGatewayCapabilities;
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
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Gateways\Qnb\QnbGateway;
use XLaravel\Payline\Gateways\Qnb\Tests\TestCase;

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

    public function test_pay_carries_the_3ds_session_deadline(): void
    {
        Http::fake(['*' => Http::response('<html>3ds form</html>', 200)]);

        $response = $this->gateway->pay($this->makePaymentRequest());

        $this->assertNotNull($response->expiresAt);
        $this->assertSame(
            now()->addMinutes(30)->format('Y-m-d H:i'),
            $response->expiresAt->format('Y-m-d H:i'),
        );
    }

    public function test_the_3ds_session_deadline_is_configurable(): void
    {
        Http::fake(['*' => Http::response('<html>3ds form</html>', 200)]);

        $gateway = new QnbGateway([...$this->config, 'three_ds_session_minutes' => 10]);

        $response = $gateway->pay($this->makePaymentRequest());

        $this->assertSame(
            now()->addMinutes(10)->format('Y-m-d H:i'),
            $response->expiresAt->format('Y-m-d H:i'),
        );
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

    public function test_query_payment_reads_a_void_date_when_the_voided_flag_is_absent(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'VoidDate' => '20260920',
            'VoidTime' => '1142',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Voided, $response->status);
    }

    public function test_query_payment_treats_an_empty_void_date_as_not_voided(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'VoidDate' => null,
            'VoidTime' => '0',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Successful, $response->status);
    }

    public function test_query_payment_reports_a_partial_refund(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'PurchAmount' => '500',
            'RefundedAmount' => '200',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame('partial', $response->metadata['refund_state']);
    }

    public function test_query_payment_reports_a_full_refund(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'PurchAmount' => '500',
            'RefundedAmount' => '500',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame('refunded', $response->metadata['refund_state']);
    }

    public function test_query_payment_reports_no_refund(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'PurchAmount' => '500',
            'RefundedAmount' => '0',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame('none', $response->metadata['refund_state']);
    }

    public function test_query_payment_falls_back_to_the_documented_refund_flag(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'IsRefunded' => 'True',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame('refunded', $response->metadata['refund_state']);
    }

    public function test_query_payment_maps_the_currency_back_to_its_iso_code(): void
    {
        Http::fake(['*' => Http::response([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'Currency' => '840',
        ], 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame('USD', $response->currency);
    }

    public function test_query_payment_leaves_an_unfinished_3ds_order_pending(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'Auth',
            'TxnResult' => 'Failed',
            'TxnStatus' => 'N',
            'ProcReturnCode' => 'V000',
            'ErrMsg' => 'İşlem tamamlanamadı /devam ediyor',
            'AuthCode' => '',
            'HostRefNum' => '',
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Pending, $response->status);
    }

    public function test_query_payment_fails_a_3d_secure_rejection_that_also_reports_no_transaction(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'Auth',
            'TxnResult' => 'Failed',
            'TxnStatus' => 'N',
            'ProcReturnCode' => 'MR15',
            'ErrMsg' => '3D Secure Authorize Error',
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('MR15', $response->gatewayResponseCode);
    }

    public function test_query_payment_fails_a_bank_decline_that_also_reports_no_transaction(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'Auth',
            'TxnResult' => 'Failed',
            'TxnStatus' => 'N',
            'ProcReturnCode' => '14',
            'ErrMsg' => 'Geçersiz Hesap Numarası',
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('Geçersiz Hesap Numarası', $response->gatewayResponseMessage);
    }

    public function test_query_payment_reports_the_refunded_total_in_minor_units(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'Auth',
            'ProcReturnCode' => '00',
            'PurchAmount' => '350.00',
            'RefundedAmount' => '120.50',
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(12050, $response->refundedAmount);
        $this->assertFalse($response->voided);
    }

    public function test_query_payment_reports_a_void(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'Auth',
            'ProcReturnCode' => '00',
            'IsVoided' => 'true',
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertTrue($response->voided);
    }

    public function test_query_payment_claims_nothing_about_an_order_it_cannot_find(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'ProcReturnCode' => 'V013',
            'ErrMsg' => 'Seçili İşlem Bulunamadı!',
            'RefundedAmount' => 0,
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
        $this->assertNull($response->refundedAmount);
        $this->assertNull($response->voided);
    }

    public function test_query_payment_drops_the_blank_references_of_an_unsettled_order(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'Auth',
            'TxnResult' => 'Failed',
            'TxnStatus' => 'N',
            'ProcReturnCode' => 'V000',
            'HostRefNum' => '',
            'AuthCode' => '',
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertNull($response->gatewayOrderId);
        $this->assertNull($response->gatewayAuthCode);
    }

    public function test_query_payment_stays_unknown_when_the_answer_cannot_be_read(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
    }

    public function test_query_payment_asks_in_the_currency_of_the_order(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00', 'TxnType' => 'Auth']), 200)]);

        $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001', currency: 'USD'));

        Http::assertSent(fn ($request) => $request->data()['Currency'] === '840');
    }

    public function test_an_unreadable_inquiry_keeps_the_currency_it_asked_about(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001', currency: 'EUR'));

        $this->assertSame('EUR', $response->currency);
    }

    public function test_an_inquiry_that_names_no_currency_keeps_the_one_it_asked_about(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00', 'TxnType' => 'Auth']), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001', currency: 'USD'));

        $this->assertSame('USD', $response->currency);
    }

    public function test_a_refund_drops_the_blank_references_qnb_pads_its_answer_with(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'ProcReturnCode' => '00',
            'TxnResult' => 'Success',
            'HostRefNum' => '',
            'AuthCode' => '',
        ]), 200)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertNull($response->gatewayOrderId);
        $this->assertNull($response->gatewayAuthCode);
    }

    public function test_void_is_sent_in_the_currency_of_the_sale(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00']), 200)]);

        $response = $this->gateway->void(new VoidData(gatewayTransactionId: 'ORD-001', amount: 10000, currency: 'USD'));

        Http::assertSent(fn ($request) => $request->data()['Currency'] === '840');
        $this->assertSame('USD', $response->currency);
    }

    public function test_query_payment_reads_the_delimited_answer_of_a_successful_sale(): void
    {
        Http::fake(['*' => Http::response($this->delimitedBody([
            'TxnType' => 'Auth',
            'TxnResult' => 'Success',
            'ProcReturnCode' => '00',
            'ErrMsg' => 'Onaylandı',
            'HostRefNum' => '627114307385',
            'AuthCode' => 'S26611',
            'IsVoided' => 'false',
            'RefundedAmount' => '0',
            'PurchAmount' => '350.00',
            'Currency' => '949',
        ]), 200, ['Content-Type' => 'text/plain; charset=utf-8'])]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame('627114307385', $response->gatewayOrderId);
        $this->assertSame('S26611', $response->gatewayAuthCode);
        $this->assertSame('none', $response->metadata['refund_state']);
    }

    public function test_query_payment_reads_the_delimited_answer_of_a_declined_sale(): void
    {
        Http::fake(['*' => Http::response($this->delimitedBody([
            'TxnType' => 'Auth',
            'TxnResult' => 'Failed',
            'ProcReturnCode' => 'MR15',
            'ErrMsg' => '3D Secure Authorize Error',
            'HostRefNum' => '',
            'IsVoided' => 'false',
        ]), 200, ['Content-Type' => 'text/plain; charset=utf-8'])]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('MR15', $response->gatewayResponseCode);
        $this->assertSame('3D Secure Authorize Error', $response->gatewayResponseMessage);
    }

    public function test_query_payment_keeps_base64_values_of_a_delimited_answer_intact(): void
    {
        Http::fake(['*' => Http::response($this->delimitedBody([
            'ProcReturnCode' => '00',
            'TxnType' => 'Auth',
            'ResponseHash' => 'a+b/c==',
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame('a+b/c==', $response->metadata['ResponseHash']);
    }

    public function test_the_inquiry_goes_to_the_json_gateway(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00', 'TxnType' => 'Auth']), 200)]);

        $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Gateway/JsonGate.aspx'));
    }

    public function test_the_json_gateway_can_be_configured_outright(): void
    {
        $gateway = new QnbGateway(array_merge($this->config, [
            'json_endpoint' => 'https://elsewhere.test/Gateway/JsonGate.aspx',
        ]));

        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00']), 200)]);

        $gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://elsewhere.test/'));
    }

    public function test_query_payment_reads_the_nested_payment_request(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'Auth',
            'ProcReturnCode' => '00',
            'HostRefNum' => '627114307385',
            'AuthCode' => 'S26611',
            'Currency' => 949,
            'RefundedAmount' => 0.0,
            'PurchAmount' => 350.0,
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame('627114307385', $response->gatewayOrderId);
        $this->assertSame('S26611', $response->gatewayAuthCode);
        $this->assertSame('TRY', $response->currency);
        $this->assertSame('none', $response->metadata['refund_state']);
    }

    public function test_query_payment_reads_a_numeric_refunded_amount(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'Auth',
            'ProcReturnCode' => '00',
            'PurchAmount' => 350.0,
            'RefundedAmount' => 350.0,
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame('refunded', $response->metadata['refund_state']);
    }

    public function test_an_order_the_bank_cannot_find_is_unknown_rather_than_failed(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody([
            'TxnType' => 'OrderInquiry',
            'ProcReturnCode' => 'V013',
            'ErrMsg' => 'Seçili İşlem Bulunamadı!',
            'TxnResult' => 'Failed',
        ]), 200)]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
        $this->assertSame('V013', $response->gatewayResponseCode);
    }

    public function test_an_unreadable_inquiry_answer_is_unknown(): void
    {
        Http::fake(['*' => Http::response('<html>bir hata sayfası</html>', 200)]);

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

    public function test_pay_sends_a_lira_amount_with_two_decimal_places(): void
    {
        Http::fake(['*' => Http::response('<html>form</html>', 200)]);

        $this->gateway->pay($this->makePaymentRequest(amount: 109900));

        Http::assertSent(fn ($r) => $r->data()['PurchAmount'] === '1099.00');
    }

    public function test_handleCallback_keeps_the_provider_diagnostic(): void
    {
        $post = $this->buildCallbackPayload('V034', '-1', '3D Kullanıcı Doğrulama Adımı Başarısız');
        $post['IrcCode'] = '108';
        $post['IrcDet'] = 'Cardholder MSISDN not found';

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('3DS_FAILED', $response->errorCode);
        $this->assertSame('V034', $response->gatewayResponseCode);
        $this->assertSame('Cardholder MSISDN not found', $response->gatewayResponseMessage);
        $this->assertSame('108', $response->metadata['IrcCode']);
    }

    public function test_handleCallback_records_the_provider_envelope(): void
    {
        $post = $this->buildCallbackPayload('00', '1');
        $post['BatchNo'] = '81';
        $post['RRN'] = '626223244915';
        $post['CardMask'] = '516840******3499';

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame('81', $response->metadata['BatchNo']);
        $this->assertSame('626223244915', $response->metadata['RRN']);
        $this->assertSame('516840******3499', $response->metadata['CardMask']);
    }

    public function test_handleCallback_reads_the_currency_from_the_envelope(): void
    {
        $post = $this->buildCallbackPayload('00', '1');
        $post['Currency'] = '978';

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame('EUR', $response->currency);
    }

    public function test_handleCallback_falls_back_to_lira_for_an_unknown_currency_code(): void
    {
        $post = $this->buildCallbackPayload('00', '1');
        $post['Currency'] = '000';

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame('TRY', $response->currency);
    }

    public function test_handleCallback_records_the_envelope_on_a_declined_payment(): void
    {
        $post = $this->buildCallbackPayload('51', '1', 'Insufficient funds');
        $post['BatchNo'] = '81';

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('81', $response->metadata['BatchNo']);
    }

    public function test_handleCallback_records_nothing_when_the_hash_does_not_match(): void
    {
        $post = $this->buildCallbackPayload('00', '1');
        $post['ResponseHash'] = 'INVALID_HASH';

        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'qnb',
            requestData: $post,
        ));

        $this->assertNull($response->metadata);
    }

    public function test_refund_sends_correct_request_and_returns_refunded_status(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00', 'TxnResult' => 'Success', 'HostRefNum' => 'REF999']), 200)]);

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
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00', 'TxnResult' => 'Success']), 200)]);

        $response = $this->gateway->void(new VoidData(gatewayTransactionId: 'ORD-001', amount: 10000));

        $this->assertSame(TransactionStatus::Voided, $response->status);
        $this->assertSame(TransactionType::Void, $response->type);

        Http::assertSent(fn ($r) => $r->data()['TxnType'] === 'Void'
            && $r->data()['OrgOrderId'] === 'ORD-001');
    }

    public function test_capture_sends_correct_request_and_returns_successful_status(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00', 'TxnResult' => 'Success']), 200)]);

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

    public function test_the_follow_up_operations_go_to_the_json_gateway(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '00', 'TxnResult' => 'Success']), 200)]);

        $this->gateway->refund(new RefundData(gatewayTransactionId: 'ORD-001', amount: 5000, currency: 'TRY'));
        $this->gateway->void(new VoidData(gatewayTransactionId: 'ORD-001', amount: 10000));
        $this->gateway->capture(new CaptureData(gatewayTransactionId: 'ORD-001', amount: 5000, currency: 'TRY'));

        Http::assertSentCount(3);
        Http::recorded(function ($request) {
            $this->assertStringContainsString('/Gateway/JsonGate.aspx', $request->url());

            return true;
        });
    }

    public function test_the_charge_stays_on_the_default_gateway(): void
    {
        Http::fake(['*' => Http::response('<html>form</html>', 200)]);

        $this->gateway->pay($this->makePaymentRequest());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Gateway/Default.aspx'));
    }

    public function test_an_unreadable_refund_answer_is_unknown_rather_than_failed(): void
    {
        Http::fake(['*' => Http::response('<html>bir hata sayfası</html>', 200)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
    }

    public function test_an_unreachable_refund_endpoint_is_unknown_rather_than_failed(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
    }

    public function test_refund_returns_failed_on_non_00_proc_code(): void
    {
        Http::fake(['*' => Http::response($this->jsonBody(['ProcReturnCode' => '05', 'ErrMsg' => 'Do not honor']), 200)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('05', $response->errorCode);
    }

    public function test_refund_reads_a_delimited_answer(): void
    {
        Http::fake(['*' => Http::response($this->delimitedBody([
            'TxnResult' => 'Success',
            'ProcReturnCode' => '00',
            'HostRefNum' => 'REF999',
        ]), 200)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame('REF999', $response->gatewayOrderId);
    }

    public function test_void_reads_a_delimited_answer(): void
    {
        Http::fake(['*' => Http::response($this->delimitedBody([
            'TxnResult' => 'Success',
            'ProcReturnCode' => '00',
        ]), 200)]);

        $response = $this->gateway->void(new VoidData(gatewayTransactionId: 'ORD-001', amount: 10000));

        $this->assertSame(TransactionStatus::Voided, $response->status);
    }

    public function test_a_declined_refund_reads_the_delimited_reason(): void
    {
        Http::fake(['*' => Http::response($this->delimitedBody([
            'TxnResult' => 'Failed',
            'ProcReturnCode' => '05',
            'ErrMsg' => 'Red-Onaylanmadı',
        ]), 200)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('05', $response->errorCode);
        $this->assertSame('Red-Onaylanmadı', $response->errorMessage);
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
        $this->assertInstanceOf(ProvidesGatewayCapabilities::class, $this->gateway);

        $this->assertNotInstanceOf(HandlesWebhooks::class, $this->gateway);
    }

    public function test_capabilities_cover_both_card_kinds(): void
    {
        $methods = $this->gateway->capabilities()->methods;

        $this->assertContains(PaymentMethod::CreditCard, $methods);
        $this->assertContains(PaymentMethod::DebitCard, $methods);
    }

    public function test_capabilities_list_every_currency_the_bank_supports(): void
    {
        $this->assertSame(
            ['TRY', 'USD', 'EUR', 'GBP', 'JPY', 'RUB'],
            $this->gateway->capabilities()->currencies,
        );
    }

    public function test_capabilities_declare_three_d_secure_only(): void
    {
        $capabilities = $this->gateway->capabilities();

        $this->assertTrue($capabilities->threeDs);
        $this->assertFalse($capabilities->nonThreeDs);
    }

    public function test_capabilities_cover_every_operation_the_driver_implements(): void
    {
        $this->assertSame([
            TransactionType::Payment,
            TransactionType::Authorization,
            TransactionType::Capture,
            TransactionType::Refund,
            TransactionType::Void,
        ], $this->gateway->capabilities()->operations);
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

    private function jsonBody(array $fields): string
    {
        return json_encode([
            'PaymentRequest' => $fields,
            'PaymentAddress' => [],
            'ExtraParameters' => [],
            'IsOnUsCard' => false,
        ]);
    }

    private function delimitedBody(array $fields): string
    {
        return implode(';;', array_map(
            fn (string $key, string $value) => "{$key}={$value}",
            array_keys($fields),
            $fields,
        ));
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
