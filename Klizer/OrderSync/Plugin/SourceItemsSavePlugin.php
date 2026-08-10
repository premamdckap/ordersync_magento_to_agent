<?php

namespace Klizer\OrderSync\Plugin;

use Klizer\OrderSync\Helper\Config as OrderSyncConfig;
use Klizer\OrderSync\Model\ApiClient;
use Klizer\OrderSync\Model\OrderDataCollector;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * A stock-level change can flip an open order's inventory-related risk
 * signal (salable_qty, reserved_qty) without anything about the order
 * itself changing — re-sync whichever currently-open orders reference the
 * affected SKU(s) so their score reflects current stock.
 *
 * Plugged onto the interface (not a concrete class) because there's no
 * classic Magento event dispatched for source item saves in MSI — this
 * covers admin stock edits, imports, and any other caller of
 * SourceItemsSaveInterface::execute().
 */
class SourceItemsSavePlugin
{
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

    public function afterExecute(SourceItemsSaveInterface $subject, $result, array $sourceItems)
    {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        try {
            $skus = array_values(array_unique(array_map(
                static function ($sourceItem) {
                    return $sourceItem->getSku();
                },
                $sourceItems
            )));

            if (!$skus) {
                return $result;
            }

            $orderIds = $this->dataCollector->findOpenOrderIdsForSkus($skus);

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
                        "[Klizer_OrderSync] Failed to re-sync order {$orderId} after inventory change: "
                        . $e->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            // A sync failure must never block the inventory save.
            $this->logger->error(
                '[Klizer_OrderSync] Inventory-change sync failed: ' . $e->getMessage()
            );
        }

        return $result;
    }
}