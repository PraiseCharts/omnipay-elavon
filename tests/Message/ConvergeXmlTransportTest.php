<?php

namespace Omnipay\Elavon\Message;

use GuzzleHttp\Psr7\Response;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Exception\InvalidResponseException;
use Omnipay\Common\Http\ClientInterface;
use Omnipay\Elavon\ConvergeGateway;
use PHPUnit\Framework\TestCase;

class ConvergeXmlTransportTest extends TestCase
{
    /** @dataProvider operations */
    public function testEveryOperationUsesXmlAndPreservesItsTransactionType($method, $parameters, $type, $testMode)
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturnCallback(
            function ($verb, $url, $headers, $body) use ($type, $testMode) {
                $this->assertSame('POST', $verb);
                $this->assertSame($testMode
                    ? 'https://api.demo.convergepay.com/VirtualMerchantDemo/processxml.do'
                    : 'https://api.convergepay.com/VirtualMerchant/processxml.do', $url);
                $this->assertSame('application/x-www-form-urlencoded', $headers['Content-Type']);
                parse_str($body, $form);
                $this->assertSame(['xmldata'], array_keys($form));
                $this->assertStringStartsWith('<txn>', $form['xmldata']);
                $xml = simplexml_load_string($form['xmldata']);
                $this->assertFalse(isset($xml->ssl_result_format));
                $this->assertFalse(isset($xml->ssl_show_form));
                $this->assertSame('txn', $xml->getName());
                $this->assertSame($type, (string) $xml->ssl_transaction_type);
                $this->assertSame('0012345', (string) $xml->ssl_account_id);
                $this->assertSame('pin&<"é', (string) $xml->ssl_pin);
                $this->assertSame('INV&<"é', (string) $xml->ssl_invoice_number);
                $this->assertFalse(isset($xml->ssl_merchant_id));

                return new Response(200, [], '<txn><ssl_result>0</ssl_result><ssl_txn_id>txn-1</ssl_txn_id></txn>');
            }
        );
        $gateway = new ConvergeGateway($client);
        $gateway->initialize([
            'merchantId' => '0012345', 'username' => 'sandbox', 'password' => 'pin&<"é',
            'testMode' => $testMode, 'ssl_invoice_number' => 'INV&<"é',
        ]);
        $response = $gateway->$method($parameters + ['ssl_invoice_number' => 'INV&<"é'])->send();
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('txn-1', $response->getTransactionReference());
    }

    public static function operations()
    {
        $operations = [
            ['createCard', ['card' => self::card()], 'ccgettoken'],
            ['purchase', ['amount' => '1.00', 'card' => self::card()], 'ccsale'],
            ['purchase', ['amount' => '1.00', 'cardReference' => 'token-1'], 'ccsale'],
            ['authorize', ['amount' => '1.00', 'card' => self::card()], 'ccauthonly'],
            ['authorize', ['amount' => '1.00', 'cardReference' => 'token-1'], 'ccauthonly'],
            ['capture', ['amount' => '1.00', 'transactionReference' => 'txn-1'], 'cccomplete'],
            ['refund', ['amount' => '1.00', 'transactionReference' => 'txn-1'], 'ccreturn'],
            ['void', ['transactionReference' => 'txn-1'], 'ccvoid'],
        ];
        foreach ($operations as $operation) {
            foreach ([true, false] as $mode) {
                yield array_merge($operation, [$mode]);
            }
        }
    }

    /** @dataProvider customerRequests */
    public function testIpIsIncludedOnlyWhenPresent($method, $parameters, $ip)
    {
        $request = (new ConvergeGateway())->$method($parameters + ['clientIp' => $ip]);
        $data = $request->getData();
        if ($ip === null || $ip === '') {
            $this->assertArrayNotHasKey('ssl_cardholder_ip', $data);
        } else {
            $this->assertSame($ip, $data['ssl_cardholder_ip']);
        }
        if ($method === 'createCard') {
            $this->assertSame('N', $data['ssl_verify']);
        }
    }

    public static function customerRequests()
    {
        foreach ([null, '', '192.0.2.1', '2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001'] as $ip) {
            yield ['createCard', ['card' => self::card()], $ip];
            foreach (['purchase', 'authorize'] as $method) {
                yield [$method, ['amount' => '1.00', 'card' => self::card()], $ip];
                yield [$method, ['amount' => '1.00', 'cardReference' => 'token-1'], $ip];
            }
        }
    }

    /** @dataProvider invalidIps */
    public function testInvalidIpCannotReachTheGateway($ip)
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');
        $this->expectException(InvalidRequestException::class);
        (new ConvergeGateway($client))->purchase([
            'amount' => '1.00', 'cardReference' => 'token-1', 'clientIp' => $ip,
        ])->send();
    }

    public static function invalidIps()
    {
        return [['192.0.2.1, 198.51.100.1'], ['999.1.1.1'], ['attacker.example'], [str_repeat('1', 41)]];
    }

    /** @dataProvider invalidResponses */
    public function testInvalidResponseFailsWithoutExposingItsContents($body)
    {
        $request = (new ConvergeGateway())->purchase(['amount' => '1.00', 'cardReference' => 'token-1']);
        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('Invalid Converge XML response.');
        new ConvergeResponse($request, $body);
    }

    public static function invalidResponses()
    {
        return [
            [''], ['not xml'], ['<html>upstream error</html>'], ['<txn><ssl_result>0'],
            ['<!DOCTYPE txn [<!ENTITY secret SYSTEM "file:///etc/passwd">]><txn><ssl_result>&secret;</ssl_result></txn>'],
            ['<txn><ssl_result>1</ssl_result><ssl_result>0</ssl_result></txn>'],
            ['<txn><ssl_result><nested>0</nested></ssl_result></txn>'],
        ];
    }

    /** @dataProvider responseEncodings */
    public function testDoctypeIsRejectedRegardlessOfXmlEncoding($encoding, $doctype, $message)
    {
        $body = '<?xml version="1.0" encoding="' . $encoding . '"?>' . $doctype .
            '<txn><ssl_result>0</ssl_result><ssl_result_message>' . $message . '</ssl_result_message></txn>';
        $body = mb_convert_encoding($body, $encoding, 'UTF-8');
        $request = (new ConvergeGateway())->purchase(['amount' => '1.00', 'cardReference' => 'token-1']);
        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('Invalid Converge XML response.');
        new ConvergeResponse($request, $body);
    }

    public static function responseEncodings()
    {
        foreach (['UTF-8', 'UTF-16LE', 'UTF-16BE'] as $encoding) {
            yield [$encoding, '<!DOCTYPE txn [<!ENTITY approval "APPROVAL">]>', '&approval;'];
            yield [$encoding, '<!DOCTYPE txn SYSTEM "file:///nonexistent-converge-response.dtd">', 'APPROVAL'];
        }
    }

    public function testValidUtf16ResponsesRemainSupported()
    {
        $request = (new ConvergeGateway())->purchase(['amount' => '1.00', 'cardReference' => 'token-1']);
        foreach (['UTF-16LE', 'UTF-16BE'] as $encoding) {
            $body = '<?xml version="1.0" encoding="' . $encoding . '"?>' .
                '<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message></txn>';
            $response = new ConvergeResponse($request, mb_convert_encoding($body, $encoding, 'UTF-8'));
            $this->assertTrue($response->isSuccessful());
            $this->assertSame('APPROVAL', $response->getMessage());
        }
    }

    public function testResponsePreservesStringsAndDecodedSpecialCharacters()
    {
        $request = (new ConvergeGateway())->createCard(['card' => self::card()]);
        $response = new ConvergeResponse($request,
            '<txn><ssl_result>0</ssl_result><ssl_token>001234</ssl_token>' .
            '<ssl_result_message>A&amp;B + C</ssl_result_message><ssl_card_number>****1111</ssl_card_number></txn>');
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('001234', $response->getCardReference());
        $this->assertSame('A&B + C', $response->getMessage());
        $this->assertSame('****1111', $response->getData()['ssl_card_number']);
    }

    public function testMissingOrConflictingSuccessCannotApproveAPayment()
    {
        $request = (new ConvergeGateway())->purchase(['amount' => '1.00', 'cardReference' => 'token-1']);
        foreach (['<txn/>', '<txn><ssl_result>0</ssl_result><errorCode>4013</errorCode></txn>'] as $body) {
            $this->assertFalse((new ConvergeResponse($request, $body))->isSuccessful());
        }
    }

    private static function card()
    {
        return ['number' => '4111111111111111', 'expiryMonth' => '12', 'expiryYear' => '2035', 'cvv' => '123'];
    }
}
