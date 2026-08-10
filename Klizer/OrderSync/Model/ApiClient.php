<?php

namespace Klizer\OrderSync\Model;

use Klizer\OrderSync\Helper\Config as OrderSyncConfig;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ApiClient
{
    private Curl $curl;
    private OrderSyncConfig $config;
    private LoggerInterface $logger;
    private SyncDeduplicator $deduplicator;

    public function __construct(
        Curl $curl,
        OrderSyncConfig $config,
        LoggerInterface $logger,
        SyncDeduplicator $deduplicator
    ) {
        $this->curl = $curl;
        $this->config = $config;
        $this->logger = $logger;
        $this->deduplicator = $deduplicator;
    }

    /**
     * POST the order/inventory/customer payload to the prediction API.
     *
     * @param array $payload
     * @return array|null Decoded response, or null if the call failed
     *                     (including a same-request duplicate — see
     *                     SyncDeduplicator).
     */
    public function sendOrderData(array $payload): ?array
    {
        $orderId = $payload['orders'][0]['order_id'] ?? null;

        if ($orderId !== null && $this->deduplicator->shouldSkipSync((int) $orderId)) {
            $this->logger->info(
                "[Klizer_OrderSync] Order {$orderId}: skipping duplicate sync within this request."
            );
            return null;
        }

        $apiUrl = $this->config->getApiUrl();

        if (!$apiUrl) {
            $this->logger->warning('[Klizer_OrderSync] API URL is not configured; skipping order sync.');
            return null;
        }

        $this->curl->setOption(CURLOPT_TIMEOUT, $this->config->getTimeout());
        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
            'X-API-Key' => (string) $this->config->getApiKey(),
        ]);

        try {
            $this->curl->post($apiUrl, json_encode($payload));
        } catch (\Exception $e) {
            $this->logger->error('[Klizer_OrderSync] API call failed: ' . $e->getMessage());
            return null;
        }

        $status = $this->curl->getStatus();
        $body = $this->curl->getBody();

        if ($status < 200 || $status >= 300) {
            $this->logger->error("[Klizer_OrderSync] Prediction API returned HTTP {$status}: {$body}");
            return null;
        }

        $this->logger->info("[Klizer_OrderSync] Prediction API responded: {$body}");

        return json_decode($body, true);
    }

    /**
     * Tell the prediction API an order is resolved (shipped, or moved to
     * a terminal status) so it stops surfacing as high-risk. Never
     * touches order placement/checkout — only called from the
     * shipment/credit-memo/status-change observers.
     *
     * @return array|null Decoded response, or null if the call failed.
     */
    public function resolveOrder(int $orderId, string $incrementId, string $reason): ?array
    {
        if ($this->deduplicator->shouldSkipResolve($orderId)) {
            $this->logger->info(
                "[Klizer_OrderSync] Order {$orderId}: skipping duplicate resolve within this request."
            );
            return null;
        }

        $resolveUrl = $this->config->getResolveApiUrl();

        if (!$resolveUrl) {
            $this->logger->warning('[Klizer_OrderSync] Resolve API URL is not configured; skipping.');
            return null;
        }

        $this->curl->setOption(CURLOPT_TIMEOUT, $this->config->getTimeout());
        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
            'X-API-Key' => (string) $this->config->getApiKey(),
        ]);

        $payload = [
            'order_id' => $orderId,
            'order_increment_id' => $incrementId,
            'reason' => $reason,
        ];

        try {
            $this->curl->post($resolveUrl, json_encode($payload));
        } catch (\Exception $e) {
            $this->logger->error('[Klizer_OrderSync] Resolve API call failed: ' . $e->getMessage());
            return null;
        }

        $status = $this->curl->getStatus();
        $body = $this->curl->getBody();

        if ($status < 200 || $status >= 300) {
            $this->logger->error("[Klizer_OrderSync] Resolve API returned HTTP {$status}: {$body}");
            return null;
        }

        $this->logger->info("[Klizer_OrderSync] Order {$incrementId} resolved ({$reason}): {$body}");

        return json_decode($body, true);
    }

    /**
     * One-off/re-runnable bulk push, not part of order placement — real
     * historical hourly order counts from this site's own sales_order
     * table, seeding the volume-monitor baseline (see
     * bin/magento klizer:ordersync:backfill-volume-history). Uses a
     * longer timeout than the checkout-path calls above since this can
     * carry weeks of hourly buckets and isn't blocking a customer request.
     *
     * @param array $hourlyCounts [['hour_start' => 'YYYY-MM-DD HH:00', 'order_count' => int], ...]
     * @return array|null Decoded response, or null if the call failed.
     */
    public function backfillVolumeHistory(string $site, array $hourlyCounts): ?array
    {
        $backfillUrl = $this->config->getVolumeHistoryBackfillApiUrl();

        if (!$backfillUrl) {
            $this->logger->warning('[Klizer_OrderSync] Volume-history backfill URL is not configured; skipping.');
            return null;
        }

        $this->curl->setOption(CURLOPT_TIMEOUT, 30);
        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
            'X-API-Key' => (string) $this->config->getApiKey(),
        ]);

        $payload = [
            'site' => $site,
            'hourly_counts' => $hourlyCounts,
        ];

        try {
            $this->curl->post($backfillUrl, json_encode($payload));
        } catch (\Exception $e) {
            $this->logger->error('[Klizer_OrderSync] Volume-history backfill call failed: ' . $e->getMessage());
            return null;
        }

        $status = $this->curl->getStatus();
        $body = $this->curl->getBody();

        if ($status < 200 || $status >= 300) {
            $this->logger->error("[Klizer_OrderSync] Volume-history backfill API returned HTTP {$status}: {$body}");
            return null;
        }

        $this->logger->info("[Klizer_OrderSync] Volume history backfilled for site {$site}: {$body}");

        return json_decode($body, true);
    }
}