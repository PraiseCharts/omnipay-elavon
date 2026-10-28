# Omnipay: Elavon

**Elavon payment processing driver for the Omnipay PHP payment processing library**

[![Tests](https://github.com/PraiseCharts/omnipay-elavon/actions/workflows/tests.yml/badge.svg)](https://github.com/PraiseCharts/omnipay-elavon/actions/workflows/tests.yml)

[Omnipay](https://github.com/thephpleague/omnipay)  is a framework agnostic, multi-gateway payment processing library for PHP. This package implements Elavon Payments support for Omnipay. Please see the full [Converge documentation](https://developer.elavon.com/products/xml-api/v1/overview) for more information.

## PraiseCharts maintenance fork

This MIT-licensed fork retains upstream history, the Composer name `lxrco/omnipay-elavon`,
the `Omnipay\Elavon` namespace, and the `Elavon_Converge` gateway alias.
Install this fork with a Composer VCS repository pointing to
`https://github.com/PraiseCharts/omnipay-elavon` and pin the reviewed branch commit
in your application lockfile. A plain Packagist install still selects the abandoned upstream release.
Upstream is tracked at `https://github.com/lxrco/omnipay-elavon`.

### XML transport migration (ENG-2047)

All operations now POST URL-encoded `xmldata=<txn>...</txn>` to:

- Demo: `https://api.demo.convergepay.com/VirtualMerchantDemo/processxml.do`
- Production: `https://api.convergepay.com/VirtualMerchant/processxml.do`

The public `merchantId` parameter maps to the documented `ssl_account_id` field.
Response data remains an associative array of string values with the existing OmniPay
response methods. The wire response must be XML; legacy ASCII responses are rejected.
`ssl_show_form` and `ssl_result_format` setters remain available for compatibility,
but those legacy presentation fields are no longer transmitted.

Pass `clientIp` on each `createCard`, `purchase`, or `authorize` request. Valid IPv4 and
IPv6 values are accepted; invalid values fail before any HTTP request. An absent IP
is omitted. Supply the resolved customer IP from your trusted-proxy policy. Background
jobs must omit it rather than substitute a server address or reuse a historical IP.

Token creation preserves `ssl_verify=N`. Sending IP metadata does not establish that
IP fraud rules run during `ccgettoken`; verify behavior with Elavon for your terminal.

Run `composer install`, `composer test`, and `composer check-style`. Ordinary tests mock
HTTP. The inherited integration suite is skipped unless `tests/Mock/myCredentials.json`
is explicitly supplied; use demo credentials only. Never commit credentials or card data.

## Installation

Omnipay is installed via [Composer](http://getcomposer.org/). To install, simply require `league/omnipay` and `lxrco/omnipay-elavon`

```bash
composer require league/omnipay lxrco/omnipay-elavon
```

## Basic Usage

The following gateways are provided by this package:

* Converge

```php
<?php
    $gateway = \Omnipay\Omnipay::create('Elavon_Converge')->initialize([
        'merchantId' => '000000',
        'username' => 'USERNAME',
        'password' => 'PASSWORD',
        'testMode' => true,// False by default
    ]);

    $params = array(
        'amount'                => 10.00,
        'card'                  => $card,
        'ssl_invoice_number'    => 1,
        'clientIp'             => '192.0.2.1', // Resolved customer IP, optional
    );

    $response = $gateway->purchase($params)->send();

    if ($response->isSuccessful()) {
        // successful
        $reference = $response->getTransactionReference();
        // ...
    } else {
        throw new ApplicationException($response->getMessage());
    }
```

For general usage instructions, please see the main [Omnipay](https://github.com/thephpleague/omnipay)
repository.


## Support

If you are having general issues with Omnipay, we suggest posting on
[Stack Overflow](http://stackoverflow.com/). Be sure to add the
[omnipay tag](http://stackoverflow.com/questions/tagged/omnipay) so it can be easily found.

If you want to keep up to date with release anouncements, discuss ideas for the project,
or ask more detailed questions, there is also a [mailing list](https://groups.google.com/forum/#!forum/omnipay) which
you can subscribe to.

If you believe you have found a bug, please report it using the [GitHub issue tracker](https://github.com/PraiseCharts/omnipay-elavon/issues),
or better yet, fork the library and submit a pull request.
