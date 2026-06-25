<?php

/**
 * Joomla 5 Scheduler task that settles pending Monero (xmr-pay) orders. Monero has no payment
 * webhook, so on each run we sweep every published xmr-pay HikaShop payment method, scan its pending
 * orders with the view-key engine, and mark paid the ones a confirmed on-chain payment has covered.
 * The heavy lifting is the shared engine + Settler, vendored in the payment plugin; this is just the
 * scheduled entry point. The checkout poll settles a single order on demand; this is the backstop.
 */

namespace XmrPay\Plugin\Task\XmrpaySettle\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\Settler;

final class XmrpaySettle extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected $autoloadLanguage = true;

    private const TASKS_MAP = [
        'xmrpay.settle' => [
            'langConstPrefix' => 'PLG_TASK_XMRPAYSETTLE_SETTLE',
            'method'          => 'settle',
        ],
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask'     => 'standardRoutineHandler',
        ];
    }

    private function settle(ExecuteTaskEvent $event): int
    {
        $plgDir = JPATH_PLUGINS . '/hikashoppayment/xmrpay';
        $helper = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

        if (!function_exists('hikashop_get')) {
            if (!is_file($helper)) {
                $this->logTask('xmrpay settle: HikaShop is not installed', 'warning');
                return Status::KNOCKOUT;
            }
            require_once $helper;
        }
        require_once $plgDir . '/engine/load.php';
        require_once $plgDir . '/HikashopOrderStore.php';

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['payment_id', 'payment_params']))
            ->from($db->quoteName('#__hikashop_payment'))
            ->where($db->quoteName('payment_type') . ' = ' . $db->quote('xmrpay'))
            ->where($db->quoteName('payment_published') . ' = 1');
        $db->setQuery($q);
        $methods = (array) $db->loadObjectList();

        $methodsRun = 0;
        $checked    = 0;
        $settled    = 0;

        foreach ($methods as $m) {
            $p = $this->unserializeParams($m->payment_params);
            if (empty($p->address) || empty($p->view_key)) {
                continue;   // method not configured yet
            }

            $cfg = [
                'address'           => $p->address,
                'view_key'          => $p->view_key,
                'nodes'             => $p->nodes ?? '',
                'network'           => !empty($p->network) ? $p->network : 'mainnet',
                'min_confirmations' => (int) ($p->min_confirmations ?? 10),
                'index_offset'      => (int) ($p->index_offset ?? 0),
            ];

            $store = new \HikashopOrderStore([
                'method_name'    => 'xmrpay',
                'payment_id'     => (int) $m->payment_id,
                'pending_status' => !empty($p->order_status) ? $p->order_status : 'created',
                'paid_status'    => !empty($p->paid_status) ? $p->paid_status : 'confirmed',
                'notify_email'   => (bool) ($p->status_notif_email ?? 0),
            ]);

            $report = (new Settler(new Gateway($cfg), $store, ['min_confirmations' => $cfg['min_confirmations']]))->run();

            $methodsRun++;
            $checked += (int) $report['checked'];
            $settled += (int) $report['settled'];
            $this->logTask(sprintf(
                'xmrpay method %d: checked=%d settled=%d status=%s',
                (int) $m->payment_id, $report['checked'], $report['settled'], $report['status']
            ));
        }

        $this->logTask(sprintf('xmrpay settle done: %d method(s), checked=%d settled=%d', $methodsRun, $checked, $settled));
        return Status::OK;
    }

    private function unserializeParams($raw)
    {
        $p = function_exists('hikashop_unserialize') ? hikashop_unserialize($raw) : @unserialize((string) $raw);
        return is_object($p) ? $p : new \stdClass();
    }
}
