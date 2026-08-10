<?php

namespace Klizer\OrderSync\Observer;

use Klizer\OrderSync\Helper\Config as OrderSyncConfig;
use Klizer\OrderSync\Model\ApiClient;
use Klizer\OrderSync\Model\OrderDataCollector;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * sales_order_save_after fires on every order save (totals recalculation,
 * address edits, admin comments, ...), not just status changes — most of
 * those don't need a re-sync. Only act when getOrigData('status') shows
 * the status actually changed. A brand-new order (placed via checkout)
 * has no orig data at all (never loaded from DB before this save), so
 * this naturally skips placement — OrderPlaceAfter already covers that.
 */
class OrderStatusChange implements ObserverInterface
{
    private const TERMINAL_STATUSES = ['complete', 'closed', 'canceled'];

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

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getEntityId()) {
            return;
        }

        $newStatus = $order->getStatus();
        $origStatus = $order->getOrigData('status');

        if ($origStatus === null || $origStatus === $newStatus) {
            return;
        }

        try {
            if (in_array($newStatus, self::TERMINAL_STATUSES, true)) {
                $this->apiClient->resolveOrder(
                    (int) $order->getEntityId(),
                    (string) $order->getIncrementId(),
                    'status:' . $newStatus
                );
                return;
            }

            $payload = $this->dataCollector->collect($order);

            if (empty($payload['orders'])) {
                return;
            }

            $this->apiClient->sendOrderData($payload);
        } catch (\Throwable $e) {
            // A sync failure must never block the order save.
            $this->logger->error(
                '[Klizer_OrderSync] Failed to sync order after status change: ' . $e->getMessage()
            );
        }
    }
}