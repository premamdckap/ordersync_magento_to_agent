# Klizer_OrderSync

Magento 2 module that pushes real-time order, inventory, and customer
data to the `mymodel` shipment-failure prediction service (Klizer AI
Hackathon 2026) and applies its scoring decisions back into Magento's
event lifecycle. See `mymodel/PROJECT_DOCUMENTATION.md` for the full
system's architecture and business case; this README only covers this
module.

## What it does

| Trigger | Action |
|---|---|
| Order placed (`checkout_submit_all_after`) | Sync order/inventory/customer data → `POST /predict/orders` |
| Shipment created | Order is resolved (it shipped) → `POST /orders/resolve` |
| Credit memo created | Re-sync the order's current data → `POST /predict/orders` |
| Order status changes to complete/closed/canceled | Resolved → `POST /orders/resolve` |
| Order status changes to any other status | Re-sync → `POST /predict/orders` |
| Inventory (source item) quantity changes | Every currently-open order containing that SKU is re-synced |
| Cron, every 30 min | Open orders past the 36h pending-grace window are re-synced, so genuinely-stalled orders can escalate |

A sync failure (API down, timeout, bad response) is always caught and
logged — it **never blocks the underlying Magento operation** (checkout,
shipment creation, etc.).

### Why `checkout_submit_all_after`, not `sales_order_place_after`

`sales_order_place_after` fires inside `Order::place()`, *before* the
order is persisted — `$order->getEntityId()` is `NULL` there, so a
naive implementation silently no-ops on every single order.
`checkout_submit_all_after` fires after `QuoteManagement::submit()` has
actually saved the order, guaranteeing an entity ID. Verified directly
against this Magento version's core code (`Order.php`,
`QuoteManagement.php`), not assumed.

### Duplicate-sync protection

A single real action can trigger more than one of the observers above
(e.g. creating a shipment both fires the shipment observer directly
*and* deducts inventory, which fires the inventory plugin for the same
order). `Model/SyncDeduplicator.php` is a request-scoped guard inside
`ApiClient` that ensures only one meaningful call per order goes out
per request — a resolve is never blocked by an earlier sync in the same
request, but a sync after a resolve (or after another sync) for the
same order is correctly skipped as redundant.

## Requirements

- PHP `~7.4.0` or `~8.1.0`, Magento 2 (`magento/framework`)
- `mymodel` reachable over HTTP from this Magento instance

## Installation

Already registered as a local module (`app/code/Klizer/OrderSync`, PSR-4
autoload, `magento2-module` composer type — no separate `composer
require` needed for a local module install):

```bash
bin/magento module:enable Klizer_OrderSync
bin/magento setup:upgrade
bin/magento cache:flush
```

If you change any class constructor (adding a new dependency, etc.) on
an instance with compiled DI, re-run:

```bash
bin/magento setup:di:compile
bin/magento cache:flush
```

## Configuration

**Stores → Configuration → Klizer → Order Sync**

| Field | Notes |
|---|---|
| Enabled | If disabled, nothing is sent to the prediction API — no observers, plugin, or cron action fires. |
| API URL | The `/predict/orders` endpoint, e.g. `http://127.0.0.1:8000/predict/orders`. Sibling endpoints (`/orders/resolve`, `/orders/volume-history/backfill`) are derived from this automatically — no separate fields. |
| API Key | Sent as `X-API-Key`. Encrypted at rest (`Magento\Config\Model\Config\Backend\Encrypted`) — must be read via the `Helper\Config` accessor, `scopeConfig->getValue()` does **not** auto-decrypt it. Must match `PREDICTION_API_KEY` in `mymodel`'s `.env`. |
| Request Timeout | Seconds to wait for the prediction API before giving up (default 5). The one-off volume-history backfill call uses its own longer, hardcoded timeout (30s) since it isn't blocking a customer request. |

## Logging

Dedicated log file: `var/log/klizer_ordersync.log` (not `system.log`) —
sync activity and failures are easy to find without mixing into
Magento's general log noise.

## Console command

```bash
bin/magento klizer:ordersync:backfill-volume-history [--days=90] [--site=<identifier>]
```

Pushes real historical hourly order counts from this site's own
`sales_order` table to `mymodel`, seeding its order-volume-monitor
baseline immediately instead of waiting ~14 days for live traffic to
accumulate. Real data, not synthetic — a `COUNT(*)` over actual past
orders. Idempotent per `--site` (defaults to the store's base URL) —
safe to re-run.

## Manual test / verification

No dedicated CLI eval command ships with this module. To exercise the
sync pipeline for a specific already-placed order without waiting for
a real Magento event (useful for verifying end-to-end connectivity),
pull the classes from the object manager directly via `php -r`:

```bash
php -r '
require "app/bootstrap.php";
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();

$order = $obj->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get(<entity_id>);
$collector = $obj->get(\Klizer\OrderSync\Model\OrderDataCollector::class);
$apiClient = $obj->get(\Klizer\OrderSync\Model\ApiClient::class);

$payload = $collector->collect($order);
var_dump($apiClient->sendOrderData($payload));
'
```

Avoid `$state->setAreaCode('adminhtml')` for this — it triggers a full
ACL/menu-tree build that can take several minutes on a cold cache; the
default/global area is enough to exercise the sync logic.

## Files

```
Observer/
  OrderPlaceAfter.php       checkout_submit_all_after -> sync
  ShipmentSaveAfter.php     shipment created -> resolve
  CreditmemoSaveAfter.php   credit memo created -> sync
  OrderStatusChange.php     sales_order_save_after (status-diff guarded) -> resolve or sync
Plugin/
  SourceItemsSavePlugin.php inventory change -> re-sync affected open orders
Cron/
  RescoreOpenOrders.php     every 30 min -> re-sync open orders past the grace window
Console/Command/
  BackfillVolumeHistory.php one-off: push real historical hourly order counts
Model/
  OrderDataCollector.php    builds the orders/inventory/customers payload
  ApiClient.php             HTTP client + duplicate-sync guard
  SyncDeduplicator.php      request-scoped duplicate-call guard
Helper/Config.php           admin config accessors (incl. API-key decryption)
```
