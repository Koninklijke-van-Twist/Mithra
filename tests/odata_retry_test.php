<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_bc.php';

mithra_test('HTTP 409 is altijd retryable', static function (): void {
    mithra_assert_true(mithra_odata_error_is_retryable(new Exception(
        'HTTP 409 from OData: {"error":{"message":"Please try again later."}}'
    )));
    mithra_assert_true(mithra_odata_error_is_retryable(new Exception('HTTP 409 from OData: Conflict')));
});

mithra_test('BC transactie-conflict 409 met Please try again later is retryable', static function (): void {
    $message = 'HTTP 409 from OData: {"error":{"code":"Internal_ServerError","message":"We can\'t save your changes right now, because a record in table \'KVT_IC Puchase Order Information\' is being updated in a transaction done by another session.\r\n\r\nYou\'ll have to wait until the other transaction has completed, which may take a while. Please try again later. CorrelationId: da1a1fdb-4786-4cc8-8b54-0609005880ae."}}';

    mithra_assert_true(mithra_odata_error_is_retryable(new Exception($message)));
    mithra_assert_same(409, mithra_odata_http_code_from_error(new Exception($message)));
});

mithra_test('tijdelijke OData HTTP-codes zijn retryable', static function (): void {
    mithra_assert_true(mithra_odata_error_is_retryable(new Exception('HTTP 503 from OData: Service Unavailable')));
    mithra_assert_true(mithra_odata_error_is_retryable(new Exception('HTTP 429 from OData: Too Many Requests')));
    mithra_assert_false(mithra_odata_error_is_retryable(new Exception('HTTP 404 from OData: Not Found')));
});

mithra_test('Please try again later triggert retry', static function (): void {
    mithra_assert_true(mithra_odata_error_is_retryable(new Exception(
        'HTTP 400 from OData: {"error":{"message":"Please try again later."}}'
    )));
});

mithra_test('cURL netwerkfouten zijn retryable', static function (): void {
    mithra_assert_true(mithra_odata_error_is_retryable(new Exception('cURL error: Connection timed out')));
});

mithra_test('OData HTTP-code wordt uit foutmelding gelezen', static function (): void {
    mithra_assert_same(409, mithra_odata_http_code_from_error(new Exception('HTTP 409 from OData: busy')));
    mithra_assert_same(503, mithra_odata_http_code_from_error(new Exception('HTTP 503 from OData: busy')));
    mithra_assert_same(null, mithra_odata_http_code_from_error(new Exception('Invalid JSON from OData')));
});
