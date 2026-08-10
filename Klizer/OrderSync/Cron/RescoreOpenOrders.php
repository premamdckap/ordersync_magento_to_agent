<?php

namespace Klizer\OrderSync\Cron;

use Klizer\OrderSync\Helper\Config as OrderSyncConfig;
use Klizer\OrderSync\Model\ApiClient;
use Klizer\OrderSync\Model\OrderDataCollector;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs every 30 minutes (see etc/crontab.xml). Re-scores currently-open
 * orders older than PENDING_GRACE_HOURS so genuinely-stalled orders can
 * escalate past mymodel's pending-grace-period score cap (see
 * train_shipment_failure_model.PENDING_GRACE_HOURS on the mymodel side —
 * kept in sync manually, both represent "how long an order can sit
 * unshipped before that's a real signal"). Orders still inside that
 * window are deliberately left alone — they were already scored once at
 * checkout (OrderPlaceAfter) and don't need re-evaluating yet.
 */
class RescoreOpenOrders
{
    private const PENDING_GRACE_HOURS = 36;

    private OrderSyncConfig $config;
    private OrderDataCollector $dataCollector;
    private ApiClient $apiClient;
    private OrderRepositoryInterface $orderRepository;
    private LoggerInterface $logger;

    public function __construct(
        OrderSyncConfig $config,
        OrderDataCollector $dataCollector,
        ApiClient $apiClient,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->dataCollector = $dataCollector;
        $this->apiClient = $apiClient;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $orderIds = $this->dataCollector->findOpenOrderIdsOlderThan(self::PENDING_GRACE_HOURS);

        if (!$orderIds) {
            return;
        }

        $this->logger->info(
            '[Klizer_OrderSync] Periodic re-score: ' . count($orderIds) . ' open order(s) past '
            . self::PENDING_GRACE_HOURS . 'h'
        );

        foreach ($orderIds as $orderId) {
            try {
                $order = $this->orderRepository->get($orderId);
                $payload = $this->dataCollector->collect($order);

                if (empty($payload['orders'])) {
                    continue;
                }

                $this->apiClient->sendOrderData($payload);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "[Klizer_OrderSync] Periodic re-score failed for order {$orderId}: " . $e->getMessage()
                );
            }
        }
    }
}