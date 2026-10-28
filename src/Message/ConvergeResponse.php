<?php

namespace Omnipay\Elavon\Message;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RequestInterface;

class ConvergeResponse extends AbstractResponse
{
    public function __construct(RequestInterface $request, $data)
    {
        $this->request = $request;
        // Never expand entities, load a DTD, or include gateway payloads in errors.
        if (!is_string($data) || trim($data) === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $data)) {
            throw new \Omnipay\Common\Exception\InvalidResponseException('Invalid Converge XML response.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = new \DOMDocument();
            if (!$xml->loadXML($data, LIBXML_NONET)
                || $xml->doctype !== null
                || !$xml->documentElement
                || $xml->documentElement->tagName !== 'txn'
            ) {
                throw new \Omnipay\Common\Exception\InvalidResponseException('Invalid Converge XML response.');
            }
            $this->data = array();
            foreach ($xml->documentElement->childNodes as $element) {
                if (!$element instanceof \DOMElement) {
                    continue;
                }
                if (array_key_exists($element->tagName, $this->data)
                    || $element->getElementsByTagName('*')->length
                ) {
                    throw new \Omnipay\Common\Exception\InvalidResponseException('Invalid Converge XML response.');
                }
                $this->data[$element->tagName] = $element->textContent;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function isSuccessful()
    {
        return !isset($this->data['errorCode'])
            && isset($this->data['ssl_result'])
            && $this->data['ssl_result'] === '0';
    }

    public function getTransactionReference()
    {
        return (isset($this->data['ssl_txn_id'])) ? $this->data['ssl_txn_id'] : null;
    }

    public function getMessage()
    {
        if (isset($this->data['errorCode']) && !$this->isSuccessful()) {
            return isset($this->data['errorMessage']) ? $this->data['errorMessage'] : null;
        }

        return isset($this->data['ssl_result_message']) ? $this->data['ssl_result_message'] : null;
    }

    public function getCode()
    {
        if (isset($this->data['errorCode']) && !$this->isSuccessful()) {
            return $this->data['errorCode'];
        }

        return isset($this->data['ssl_result']) ? $this->data['ssl_result'] : null;
    }

    /**
     * Get the credit card token.
     *
     * Note that this function should be called as getCardReference() as per omnipay standards.
     *
     * @return string
     */
    public function getCardToken()
    {
        return (isset($this->data['ssl_token'])) ? $this->data['ssl_token'] : null;
    }

    public function getCardReference()
    {
        return $this->getCardToken();
    }
}
