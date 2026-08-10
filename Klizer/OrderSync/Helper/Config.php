<?php

namespace Klizer\OrderSync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    const XML_PATH_ENABLED = 'klizer_ordersync/general/enabled';
    const XML_PATH_API_URL = 'klizer_ordersync/general/api_url';
    const XML_PATH_API_KEY = 'klizer_ordersync/general/api_key';
    const XML_PATH_TIMEOUT = 'klizer_ordersync/general/timeout';

    private EncryptorInterface $encryptor;

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
    }

    public function isEnabled($storeId = null): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getApiUrl($storeId = null): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_API_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The admin config only stores the /predict/orders URL (that's the
     * endpoint that existed when this field was added) — derive sibling
     * endpoints from it rather than adding more config fields admins
     * would need to fill in separately.
     */
    private function getApiBaseUrl($storeId = null): ?string
    {
        $predictUrl = $this->getApiUrl($storeId);

        if (!$predictUrl) {
            return null;
        }

        if (substr($predictUrl, -strlen('/predict/orders')) === '/predict/orders') {
            return substr($predictUrl, 0, -strlen('/predict/orders'));
        }

        return rtrim($predictUrl, '/');
    }

    public function getResolveApiUrl($storeId = null): ?string
    {
        $base = $this->getApiBaseUrl($storeId);

        return $base ? $base . '/orders/resolve' : null;
    }

    public function getVolumeHistoryBackfillApiUrl($storeId = null): ?string
    {
        $base = $this->getApiBaseUrl($storeId);

        return $base ? $base . '/orders/volume-history/backfill' : null;
    }

    public function getApiKey($storeId = null): ?string
    {
        $encrypted = $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $encrypted ? $this->encryptor->decrypt($encrypted) : $encrypted;
    }

    public function getTimeout($storeId = null): int
    {
        $timeout = (int) $this->scopeConfig->getValue(
            self::XML_PATH_TIMEOUT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $timeout > 0 ? $timeout : 5;
    }
}