<?php

namespace Klizer\OrderSync\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

/**
 * Builds the orders/inventory/customers payload for one placed order, using
 * the same feature definitions the prediction model was trained on (see
 * mymodel/queries to export training data), scoped down to just this
 * order's SKUs and customer instead of a full-table export.
 */
class OrderDataCollector
{
    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function collect(Order $order): array
    {
        $items = array_values($order->getAllVisibleItems());

        if (!$items) {
            return ['orders' => [], 'inventory' => [], 'customers' => []];
        }

        $skus = array_values(array_unique(array_map(
            static function ($item) {
                return $item->getSku();
            },
            $items
        )));

        $customerId = $order->getCustomerId();
        $skuStats = $this->getSkuFailureStats($skus);
        $createdAtTs = strtotime($order->getCreatedAt());
        $shippingAddress = $order->getShippingAddress();

        $orders = [];
        foreach ($items as $item) {
            $sku = $item->getSku();

            $orders[] = [
                'order_id' => (int) $order->getEntityId(),
                'order_increment_id' => (string) $order->getIncrementId(),
                'customer_id' => $customerId !== null ? (int) $customerId : 0,
                'sku' => $sku,
                'ordered_qty' => (float) $item->getQtyOrdered(),
                'order_age_hours' => round((time() - $createdAtTs) / 3600, 2),
                'sku_total_orders' => (float) ($skuStats[$sku]['sku_total_orders'] ?? 0),
                'sku_orders_without_shipment' => (float) ($skuStats[$sku]['sku_orders_without_shipment'] ?? 0),
                'sku_failure_rate' => (float) ($skuStats[$sku]['sku_failure_rate'] ?? 0),
                'payment_method' => $order->getPayment()
                    ? (string) $order->getPayment()->getMethod()
                    : 'unknown',
                'order_status' => (string) $order->getStatus(),
                'order_state' => (string) $order->getState(),
                'hold_before_state' => $order->getHoldBeforeState(),
                // No invoice exists yet at order-place time.
                'hours_to_invoice' => null,
                'order_total_qty_ordered' => (float) $order->getTotalQtyOrdered(),
                'order_grand_total' => (float) $order->getGrandTotal(),
                'order_weight' => (float) $order->getWeight(),
                'shipping_region' => $shippingAddress
                    ? (string) $shippingAddress->getRegion()
                    : 'unknown',
                'shipping_postcode' => $shippingAddress ? $shippingAddress->getPostcode() : null,
                'shipping_country' => $shippingAddress
                    ? (string) $shippingAddress->getCountryId()
                    : 'unknown',
                'distinct_sku_count' => count($skus),
                'total_line_items' => count($items),
                // date('w') is Sun=0..Sat=6; +1 matches MySQL DAYOFWEEK() (Sun=1..Sat=7),
                // which is what the model was trained against.
                'order_day_of_week' => (int) date('w', $createdAtTs) + 1,
                'order_hour' => (int) date('G', $createdAtTs),
            ];
        }

        return [
            'orders' => $orders,
            'inventory' => $this->withMissingSkusFlagged($skus, $this->getInventory($skus)),
            'customers' => $this->getCustomerStats($customerId),
        ];
    }

    /**
     * getInventory() queries catalog_product_entity, so a SKU that
     * doesn't exist there at all (e.g. the product was deleted after this
     * order was placed) simply has no row in its result — silently, not
     * as a zero-stock row. A product that no longer exists can never
     * ship, which is a certainty mymodel should treat as such rather
     * than inferring from a zero-valued inventory row indistinguishable
     * from "exists, just out of stock". Fill in an explicit sku_exists
     * false entry for anything requested but not returned.
     */
    private function withMissingSkusFlagged(array $requestedSkus, array $inventory): array
    {
        $foundSkus = array_column($inventory, 'sku');

        foreach ($requestedSkus as $sku) {
            if (in_array($sku, $foundSkus, true)) {
                continue;
            }

            $inventory[] = [
                'sku' => $sku,
                'available_qty' => 0,
                'reserved_qty' => 0,
                'salable_qty' => 0,
                'product_weight' => 0,
                'open_demand' => 0,
                'sku_exists' => false,
                'is_in_stock' => false,
                'is_backorder' => false,
            ];
        }

        return $inventory;
    }

    /**
     * SKU-level no-shipment rate, same design as getCustomerStats() below
     * (same "no shipment" definition as the target, same
     * PENDING_GRACE_HOURS right-censoring so a SKU's own brand-new order
     * doesn't inflate its own rate). Replaces the old raw
     * total_ordered/total_failed counts, which were ~uncorrelated with
     * the actual target (total_ordered: unbounded lifetime volume, not a
     * rate; total_failed: only canceled/refunded qty, a narrower
     * definition than "never shipped") and produced noisy, spurious
     * SHAP-driven flags.
     */
    private function getSkuFailureStats(array $skus): array
    {
        if (!$skus) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $salesOrderItem = $this->resourceConnection->getTableName('sales_order_item');
        $salesOrder = $this->resourceConnection->getTableName('sales_order');
        $salesShipment = $this->resourceConnection->getTableName('sales_shipment');
        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        $sql = "
            SELECT
                soi.sku,
                COUNT(DISTINCT so.entity_id) AS sku_total_orders,
                SUM(CASE WHEN os.order_id IS NULL THEN 1 ELSE 0 END) AS sku_orders_without_shipment,
                ROUND(
                    100 * SUM(CASE WHEN os.order_id IS NULL THEN 1 ELSE 0 END)
                    / COUNT(DISTINCT so.entity_id),
                2) AS sku_failure_rate
            FROM {$salesOrderItem} soi
            INNER JOIN {$salesOrder} so ON so.entity_id = soi.order_id
            LEFT JOIN (SELECT DISTINCT order_id FROM {$salesShipment}) os ON os.order_id = so.entity_id
            WHERE soi.sku IN ({$placeholders})
              AND soi.parent_item_id IS NULL
              AND (
                  os.order_id IS NOT NULL
                  OR so.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
              )
            GROUP BY soi.sku
        ";

        $params = array_merge($skus, [self::PENDING_GRACE_HOURS]);

        $stats = [];
        foreach ($connection->fetchAll($sql, $params) as $row) {
            $stats[$row['sku']] = $row;
        }

        return $stats;
    }

    private function getInventory(array $skus): array
    {
        if (!$skus) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $catalogProductEntity = $this->resourceConnection->getTableName('catalog_product_entity');
        $inventorySourceItem = $this->resourceConnection->getTableName('inventory_source_item');
        $inventorySourceStockLink = $this->resourceConnection->getTableName('inventory_source_stock_link');
        $inventoryReservation = $this->resourceConnection->getTableName('inventory_reservation');
        $catalogProductEntityDecimal = $this->resourceConnection->getTableName('catalog_product_entity_decimal');
        $eavAttribute = $this->resourceConnection->getTableName('eav_attribute');
        $eavEntityType = $this->resourceConnection->getTableName('eav_entity_type');
        $salesOrderItem = $this->resourceConnection->getTableName('sales_order_item');
        $salesOrder = $this->resourceConnection->getTableName('sales_order');
        $catalogInventoryStockItem = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        $sql = "
            SELECT
                cpe.sku,
                COALESCE(ia.available_qty, 0) AS available_qty,
                COALESCE(ir.reserved_qty, 0) AS reserved_qty,
                ROUND(GREATEST(COALESCE(ia.available_qty, 0) - COALESCE(ir.reserved_qty, 0), 0), 4) AS salable_qty,
                w.weight AS product_weight,
                COALESCE(od.open_demand, 0) AS open_demand,
                COALESCE(csi.is_in_stock, 1) AS is_in_stock,
                COALESCE(csi.backorders, 0) AS backorders
            FROM {$catalogProductEntity} cpe
            LEFT JOIN (
                SELECT isi.sku, SUM(isi.quantity) AS available_qty
                FROM {$inventorySourceItem} isi
                INNER JOIN {$inventorySourceStockLink} issl
                    ON issl.source_code = isi.source_code AND issl.stock_id = 1
                GROUP BY isi.sku
            ) ia ON ia.sku = cpe.sku
            LEFT JOIN (
                SELECT sku, (0 - SUM(quantity)) AS reserved_qty
                FROM {$inventoryReservation} WHERE stock_id = 1 GROUP BY sku
            ) ir ON ir.sku = cpe.sku
            LEFT JOIN (
                SELECT cped.row_id, cped.value AS weight
                FROM {$catalogProductEntityDecimal} cped
                INNER JOIN {$eavAttribute} ea ON ea.attribute_id = cped.attribute_id
                INNER JOIN {$eavEntityType} eet
                    ON eet.entity_type_id = ea.entity_type_id AND eet.entity_type_code = 'catalog_product'
                WHERE ea.attribute_code = 'weight' AND cped.store_id = 0
            ) w ON w.row_id = cpe.row_id
            LEFT JOIN (
                -- Units still pending fulfillment (ordered minus whatever's
                -- already shipped/canceled/refunded) across every currently
                -- open order for this SKU: current physical demand right
                -- now, not a historical/trained signal — see
                -- getSkuFailureStats()'s docblock for why that distinction
                -- matters.
                SELECT
                    soi.sku,
                    SUM(GREATEST(soi.qty_ordered - soi.qty_shipped - soi.qty_canceled - soi.qty_refunded, 0)) AS open_demand
                FROM {$salesOrderItem} soi
                INNER JOIN {$salesOrder} so ON so.entity_id = soi.order_id
                WHERE soi.parent_item_id IS NULL
                  AND so.status NOT IN ('complete', 'closed', 'canceled')
                GROUP BY soi.sku
            ) od ON od.sku = cpe.sku
            LEFT JOIN {$catalogInventoryStockItem} csi
                ON csi.product_id = cpe.entity_id AND csi.stock_id = 1
            WHERE cpe.sku IN ({$placeholders})
        ";

        $rows = $connection->fetchAll($sql, $skus);

        return array_map(
            static function ($row) {
                return [
                    'sku' => $row['sku'],
                    'available_qty' => (float) $row['available_qty'],
                    'reserved_qty' => (float) $row['reserved_qty'],
                    'salable_qty' => (float) $row['salable_qty'],
                    'product_weight' => $row['product_weight'] !== null ? (float) $row['product_weight'] : 0,
                    'open_demand' => (float) $row['open_demand'],
                    'sku_exists' => true,
                    'is_in_stock' => (bool) $row['is_in_stock'],
                    'is_backorder' => ((int) $row['backorders']) > 0,
                ];
            },
            $rows
        );
    }

    // Matches mymodel's train_shipment_failure_model.PENDING_GRACE_HOURS /
    // RescoreOpenOrders::PENDING_GRACE_HOURS — kept in sync manually.
    private const PENDING_GRACE_HOURS = 36;

    private function getCustomerStats($customerId): array
    {
        $default = [[
            'customer_id' => $customerId ? (int) $customerId : 0,
            'customer_total_orders' => 0,
            'customer_orders_without_shipment' => 0,
            'customer_failure_rate' => 0,
        ]];

        if (!$customerId) {
            return $default;
        }

        $connection = $this->resourceConnection->getConnection();
        $salesOrder = $this->resourceConnection->getTableName('sales_order');
        $salesShipment = $this->resourceConnection->getTableName('sales_shipment');

        // Without the age filter below, a customer's own just-placed,
        // not-yet-shipped order counts as one of their "failures" when
        // computing their historical rate — every brand-new customer's
        // first order would then show a 100% failure rate purely from
        // being too new to have shipped yet, not from any real signal.
        // Only count an order toward this customer's rate once its
        // outcome is actually knowable: it shipped, or it's had a fair
        // chance to (past PENDING_GRACE_HOURS).
        $sql = "
            SELECT
                so.customer_id,
                COUNT(DISTINCT so.entity_id) AS customer_total_orders,
                SUM(CASE WHEN os.order_id IS NULL THEN 1 ELSE 0 END) AS customer_orders_without_shipment,
                ROUND(
                    100 * SUM(CASE WHEN os.order_id IS NULL THEN 1 ELSE 0 END)
                    / COUNT(DISTINCT so.entity_id),
                2) AS customer_failure_rate
            FROM {$salesOrder} so
            LEFT JOIN (SELECT DISTINCT order_id FROM {$salesShipment}) os ON os.order_id = so.entity_id
            WHERE so.customer_id = ?
              AND (
                  os.order_id IS NOT NULL
                  OR so.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
              )
            GROUP BY so.customer_id
        ";

        $row = $connection->fetchRow($sql, [$customerId, self::PENDING_GRACE_HOURS]);

        if (!$row) {
            return $default;
        }

        return [[
            'customer_id' => (int) $row['customer_id'],
            'customer_total_orders' => (int) $row['customer_total_orders'],
            'customer_orders_without_shipment' => (int) $row['customer_orders_without_shipment'],
            'customer_failure_rate' => (float) $row['customer_failure_rate'],
        ]];
    }

    /**
     * Entity IDs of currently-open orders (not shipped, status not
     * complete/closed/canceled) that contain at least one of the given
     * SKUs. Used by the inventory-change plugin to find which open
     * orders need re-scoring when stock levels move.
     */
    public function findOpenOrderIdsForSkus(array $skus): array
    {
        if (!$skus) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $salesOrder = $this->resourceConnection->getTableName('sales_order');
        $salesOrderItem = $this->resourceConnection->getTableName('sales_order_item');
        $salesShipment = $this->resourceConnection->getTableName('sales_shipment');
        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        $sql = "
            SELECT DISTINCT so.entity_id
            FROM {$salesOrder} so
            INNER JOIN {$salesOrderItem} soi
                ON soi.order_id = so.entity_id AND soi.parent_item_id IS NULL
            WHERE soi.sku IN ({$placeholders})
              AND so.status NOT IN ('complete', 'closed', 'canceled')
              AND NOT EXISTS (
                  SELECT 1 FROM {$salesShipment} ss WHERE ss.order_id = so.entity_id
              )
        ";

        return array_map('intval', $connection->fetchCol($sql, $skus));
    }

    /**
     * Entity IDs of currently-open orders older than $hours — feeds the
     * periodic re-scoring cron. Orders inside the normal pending window
     * are deliberately excluded by the caller passing PENDING_GRACE_HOURS:
     * they were already scored once at checkout and don't need
     * re-evaluating until they've had a fair chance to ship.
     */
    public function findOpenOrderIdsOlderThan(int $hours): array
    {
        $connection = $this->resourceConnection->getConnection();
        $salesOrder = $this->resourceConnection->getTableName('sales_order');
        $salesShipment = $this->resourceConnection->getTableName('sales_shipment');

        $sql = "
            SELECT so.entity_id
            FROM {$salesOrder} so
            WHERE so.status NOT IN ('complete', 'closed', 'canceled')
              AND so.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
              AND NOT EXISTS (
                  SELECT 1 FROM {$salesShipment} ss WHERE ss.order_id = so.entity_id
              )
        ";

        return array_map('intval', $connection->fetchCol($sql, [$hours]));
    }
}