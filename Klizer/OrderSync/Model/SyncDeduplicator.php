<?php

namespace Klizer\OrderSync\Model;

/**
 * A single Magento request/CLI process can trigger multiple
 * Klizer_OrderSync observers for the same order — most notably, creating
 * a shipment both fires ShipmentSaveAfter directly AND deducts source
 * item quantities as part of the same operation, which fires
 * SourceItemsSavePlugin for that shipment's SKU(s), which includes the
 * order just being shipped. Without this, that's two outbound calls to
 * mymodel for one real-world event.
 *
 * Magento treats plain classes like this one as singletons by default,
 * so it lives exactly as long as the request it's deduping against —
 * nothing to reset between requests.
 *
 * Resolve and sync are tracked separately because they're not
 * interchangeable: a resolve must never be silently dropped just because
 * a lower-priority re-score happened to reach ApiClient first for the
 * same order in the same request, but a re-score after a resolve (or
 * after another re-score) for that order is genuinely redundant.
 */
class SyncDeduplicator
{
    private array $resolvedOrderIds = [];
    private array $syncedOrderIds = [];

    /**
     * Whether a plain re-sync (sendOrderData) for this order should be
     * skipped — true if it was already resolved OR already synced
     * earlier in this same request. Marks it synced as a side effect
     * when returning false, so the *next* call sees it as handled.
     */
    public function shouldSkipSync(int $orderId): bool
    {
        if (isset($this->resolvedOrderIds[$orderId]) || isset($this->syncedOrderIds[$orderId])) {
            return true;
        }

        $this->syncedOrderIds[$orderId] = true;
        return false;
    }

    /**
     * Whether a resolve for this order should be skipped — true only if
     * it was already resolved earlier in this same request. A prior
     * plain sync never blocks a resolve.
     */
    public function shouldSkipResolve(int $orderId): bool
    {
        if (isset($this->resolvedOrderIds[$orderId])) {
            return true;
        }

        $this->resolvedOrderIds[$orderId] = true;
        return false;
    }
}
