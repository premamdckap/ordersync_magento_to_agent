<?php

namespace Klizer\OrderSync\Observer;

use Klizer\OrderSync\Helper\Config as OrderSyncConfig;
use Klizer\OrderSync\Model\ApiClient;
use Klizer\OrderSync\Model\OrderDataCollector;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * A credit memo doesn't by itself resolve the shipment question (it can
 * be a partial refund on an already-shipped order, or a full refund on
 * one that never shipped) — re-sync the order's current data so the
 * dashboard reflects it, and let the shipment/status-change observers
 * handle actual resolution.
 */
class CreditmemoSaveAfter implements ObserverInterface
{
    private OrderSyncConfig $config;
    private OrderDataCollector $dataCollector;
    private ApiClient $apiClient;
    private LoggerInterface $logger;

    public function __construct(
        OrderSyncConfig $config,
        OrderDataCollector $dataCollector,
        ApiClient $apiClient,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->dataCollector = $dataCollector;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
    }

    public function execute(EventObserver $observer)
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $creditmemo = $observer->getEvent()->getCreditmemo();
        $order = $creditmemo ? $creditmemo->getOrder() : null;

        if (!$order || !$order->getEntityId()) {
            return;
        }

        try {
            $payload = $this->dataCollector->collect($order);

            if (empty($payload['orders'])) {
                return;
            }

            $this->apiClient->sendOrderData($payload);
        } catch (\Throwable $e) {
            // A sync failure must never block credit memo creation.
            $this->logger->error(
                '[Klizer_OrderSync] Failed to sync order after credit memo: ' . $e->getMessage()
            );
        }
    }
}