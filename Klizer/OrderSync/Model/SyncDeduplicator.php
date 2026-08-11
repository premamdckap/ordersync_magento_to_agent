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
 * Resolve and sync are tracked separately, and deliberately don't block
 * each other: they're not interchangeable. resolveOrder() only flips
 * resolved_at — it never touches order_status/order_state/
 * shipment_failure_probability. Only sendOrderData() carries that fresh
 * data. A resolve firing first in a request (e.g. ShipmentSaveAfter)
 * must never suppress a same-order sync that fires after it (e.g.
 * SourceItemsSavePlugin re-scoring the shipped SKU) — that sync is what
 * actually gets the order's stored order_status/features off their
 * pre-shipment values.
 *
 * A same-order sync repeating within one request is deduped by payload
 * fingerprint, not just order_id: observed live that MSI's
 * SourceItemsSaveInterface::execute() can be invoked twice for one admin
 * inventory save, and Magento's own legacy cataloginventory_stock_item
 * sync (which OrderDataCollector::getInventory() reads is_in_stock from)
 * only catches up between those two calls — so the second call carries
 * a genuinely different is_in_stock/salable_qty than the first, not a
 * literal repeat. Blocking it by order_id alone left the order's stored
 * row pinned to the stale pre-update inventory state. Comparing payload
 * fingerprints keeps true duplicates (identical data, e.g. the
 * shipment/inventory-deduction overlap above) deduped while still
 * letting a same-order sync through whenever the data actually changed.
 */
class SyncDeduplicator
{
    private array $resolvedOrderIds = [];
    private array $syncedFingerprints = [];

    /**
     * Whether a plain re-sync (sendOrderData) for this order should be
     * skipped — true only if this exact payload was already sent for
     * this order earlier in this same request (a prior resolve does not
     * count — see class docblock; a prior sync with *different* data
     * does not count either). Records the fingerprint as a side effect
     * when returning false, so the *next* call sees it as handled.
     */
    public function shouldSkipSync(int $orderId, string $payloadFingerprint): bool
    {
        if (($this->syncedFingerprints[$orderId] ?? null) === $payloadFingerprint) {
            return true;
        }

        $this->syncedFingerprints[$orderId] = $payloadFingerprint;
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
