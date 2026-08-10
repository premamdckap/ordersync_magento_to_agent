<?php

namespace Klizer\OrderSync\Observer;

use Klizer\OrderSync\Helper\Config as OrderSyncConfig;
use Klizer\OrderSync\Model\ApiClient;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * A shipment being created settles the question the prediction model was
 * asked ("will this order fail to ship?") — it shipped, so resolve it
 * rather than keep it in the active high-risk feed. See
 * ApiClient::resolveOrder() / mymodel's POST /orders/resolve.
 */
class ShipmentSaveAfter implements ObserverInterface
{
    private OrderSyncConfig $config;
    private ApiClient $apiClient;
    private LoggerInterface $logger;

    public function __construct(
        OrderSyncConfig $config,
        ApiClient $apiClient,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
    }

    public function execute(EventObserver $observer)
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment ? $shipment->getOrder() : null;

        if (!$order || !$order->getEntityId()) {
            return;
        }

        try {
            $this->apiClient->resolveOrder(
                (int) $order->getEntityId(),
                (string) $order->getIncrementId(),
                'shipped'
            );
        } catch (\Throwable $e) {
            // A sync failure must never block shipment creation.
            $this->logger->error(
                '[Klizer_OrderSync] Failed to resolve shipped order: ' . $e->getMessage()
            );
        }
    }
}