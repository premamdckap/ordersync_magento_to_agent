<?php

namespace Klizer\OrderSync\Console\Command;

use Klizer\OrderSync\Model\ApiClient;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-off (or occasionally re-run) command: pushes real historical
 * hourly order counts from this site's own sales_order table to
 * mymodel, so services/order_volume_monitor.py's baseline works
 * immediately instead of requiring ~14 days of live-synced traffic to
 * accumulate first. Not fabricated data — every count is a real
 * COUNT(*) over actual past orders.
 *
 * Usage: bin/magento klizer:ordersync:backfill-volume-history [--days=90] [--site=...]
 */
class BackfillVolumeHistory extends Command
{
    private ResourceConnection $resourceConnection;
    private ApiClient $apiClient;
    private StoreManagerInterface $storeManager;

    public function __construct(
        ResourceConnection $resourceConnection,
        ApiClient $apiClient,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct();
        $this->resourceConnection = $resourceConnection;
        $this->apiClient = $apiClient;
        $this->storeManager = $storeManager;
    }

    protected function configure()
    {
        $this->setName('klizer:ordersync:backfill-volume-history')
            ->setDescription(
                'Push real historical hourly order counts from sales_order to mymodel, '
                . 'to seed the order-volume-monitor baseline.'
            )
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of history to backfill', 90)
            ->addOption(
                'site',
                null,
                InputOption::VALUE_REQUIRED,
                'Identifier for this Magento site (default: store base URL)'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $days = (int) $input->getOption('days');
        $site = $input->getOption('site') ?: $this->storeManager->getStore()->getBaseUrl();

        $hourlyCounts = $this->getHourlyCounts($days);

        if (!$hourlyCounts) {
            $output->writeln('<comment>No orders found in that window — nothing to backfill.</comment>');
            return 0;
        }

        $output->writeln(sprintf(
            'Backfilling %d hourly buckets (last %d days) for site "%s"...',
            count($hourlyCounts),
            $days,
            $site
        ));

        $result = $this->apiClient->backfillVolumeHistory($site, $hourlyCounts);

        if ($result === null) {
            $output->writeln('<error>Backfill failed — check var/log/klizer_ordersync.log for details.</error>');
            return 1;
        }

        $output->writeln(sprintf(
            '<info>Done. mymodel loaded %d hours.</info>',
            $result['hours_loaded'] ?? count($hourlyCounts)
        ));

        return 0;
    }

    /**
     * Real per-hour order counts from sales_order, in the same UTC basis
     * Magento's PHP layer normalizes to (see OrderDataCollector's
     * order_hour/order_day_of_week — bootstrap.php forces UTC). '+05:30'
     * is this server's local offset; a different-timezone deployment
     * would need this adjusted.
     */
    private function getHourlyCounts(int $days): array
    {
        $connection = $this->resourceConnection->getConnection();
        $salesOrder = $this->resourceConnection->getTableName('sales_order');

        $sql = "
            SELECT
                DATE_FORMAT(CONVERT_TZ(created_at, '+05:30', '+00:00'), '%Y-%m-%d %H:00') AS hour_start,
                COUNT(*) AS order_count
            FROM {$salesOrder}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY hour_start
            ORDER BY hour_start
        ";

        $rows = $connection->fetchAll($sql, [$days]);

        return array_map(
            static function ($row) {
                return [
                    'hour_start' => $row['hour_start'],
                    'order_count' => (int) $row['order_count'],
                ];
            },
            $rows
        );
    }
}