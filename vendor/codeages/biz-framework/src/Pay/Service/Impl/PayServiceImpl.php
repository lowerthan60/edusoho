<?php

namespace Codeages\Biz\Framework\Pay\Service\Impl;

use Codeages\Biz\Framework\Service\Exception\AccessDeniedException;
use Codeages\Biz\Framework\Service\Exception\InvalidArgumentException;
use Codeages\Biz\Framework\Util\ArrayToolkit;
use Codeages\Biz\Framework\Pay\Service\PayService;
use Codeages\Biz\Framework\Service\BaseService;
use Codeages\Biz\Framework\Targetlog\Service\TargetlogService;

class PayServiceImpl extends BaseService implements PayService
{
    public function createTrade($data)
    {
        $data = ArrayToolkit::parts($data, array(
            'goods_title',
            'goods_detail',
            'attach',
            'order_sn',
            'amount',
            'coin_amount',
            'notify_url',
            'create_ip',
            'pay_type',
            'platform',
            'open_id',
            'device_info',
            'seller_id',
            'user_id',
            'type'
        ));

        if ('recharge' == $data['type']) {
            return $this->createRechargeTrade($data);
        } else if ('purchase' == $data['type']) {
            return $this->createPurchaseTrade($data);
        } else {
            throw new InvalidArgumentException("can't create the type of {$data['type']} trade");
        }
    }

    protected function createPurchaseTrade($data)
    {
        $lock = $this->biz['lock'];

        try {
            $lock->get("trade_create_{$data['order_sn']}");
            $this->beginTransaction();
            $trade = $this->getPaymentTradeDao()->getByOrderSnAndPlatform($data['order_sn'], $data['platform']);
            if(empty($trade)) {
                $trade = $this->createPaymentTrade($data);
            }

            if ($trade['cash_amount'] != 0) {
                $result = $this->createPaymentPlatformTrade($data, $trade);
                $trade = $this->getPaymentTradeDao()->update($trade['id'], array(
                    'platform_created_result' => $result
                ));
            } else {
                $mockNotify = array(
                    'status' => 'paid',
                    'paid_time' => time(),
                    'cash_flow' => '',
                    'cash_type' => '',
                    'trade_sn' => $trade['trade_sn'],
                    'pay_amount' => '0',
                );

                $trade = $this->updateTradeToPaid($mockNotify);
            }

            $this->commit();

            $lock->release("trade_create_{$data['order_sn']}");
        } catch (\Exception $e) {
            $this->rollback();
            $lock->release("trade_create_{$data['order_sn']}");
            throw $e;
        }

        return $trade;
    }

    protected function createRechargeTrade($data)
    {
        $lock = $this->biz['lock'];

        try {
            $lockName = 'trade_create_recharge_trade_'.$this->biz['user']['id'];

            $lock->get($lockName);
            $this->beginTransaction();
            $trade = $this->createPaymentTrade($data);

            $result = $this->createPaymentPlatformTrade($data, $trade);
            $trade = $this->getPaymentTradeDao()->update($trade['id'], array(
                'platform_created_result' => $result
            ));

            $this->commit();
            $lock->release($lockName);
        } catch (\Exception $e) {
            $this->rollback();
            $lock->release($lockName);
            throw $e;
        }
        return $trade;
    }

    public function getTradeByTradeSn($tradeSn)
    {
        return $this->getPaymentTradeDao()->getByTradeSn($tradeSn);
    }

    public function queryTradeFromPlatform($tradeSn)
    {
        $trade = $this->getPaymentTradeDao()->getByTradeSn($tradeSn);
        return $this->getPayment($trade['platform'])->queryTrade($trade);
    }

    public function findTradesByOrderSns($orderSns)
    {
        return $this->getPaymentTradeDao()->findByOrderSns($orderSns);
    }

    public function closeTradesByOrderSn($orderSn)
    {
        $trades = $this->getPaymentTradeDao()->findByOrderSn($orderSn);
        if (empty($trades)) {
            return;
        }

        foreach ($trades as $trade) {
            $this->getTradeContext($trade['id'])->closing();
        }
    }

    public function notifyPaid($payment, $data)
    {
        list($data, $result) = $this->getPayment($payment)->converterNotify($data);
        $this->getTargetlogService()->log(TargetlogService::INFO, 'trade.paid_notify', $data['trade_sn'], "收到第三方支付平台{$payment}的通知，交易号{$data['trade_sn']}，支付状态{$data['status']}", $data);

        $this->updateTradeToPaid($data);
        return $result;
    }

    protected function updateTradeToPaid($data)
    {
        if ($data['status'] == 'paid') {
            $lock = $this->biz['lock'];
            try {
                $lock->get("pay_notify_{$data['trade_sn']}");

                $trade = $this->getPaymentTradeDao()->getByTradeSn($data['trade_sn']);
                if (empty($trade)) {
                    $this->getTargetlogService()->log(TargetlogService::INFO, 'trade.not_found', $data['trade_sn'], "交易号{$data['trade_sn']}不存在", $data);
                    $lock->release("pay_notify_{$data['trade_sn']}");
                    return;
                }

                if ('paying' != $trade['status']) {
                    $this->getTargetlogService()->log(TargetlogService::INFO, 'trade.is_not_paying', $data['trade_sn'], "交易号{$data['trade_sn']}状态不正确，状态为：{$trade['status']}", $data);
                    $lock->release("pay_notify_{$data['trade_sn']}");
                    return;
                }

                $trade = $this->createFlowsAndUpdateTradeStatus($trade, $data);

                $lock->release("pay_notify_{$data['trade_sn']}");
            } catch (\Exception $e) {
                $lock->release("pay_notify_{$data['trade_sn']}");
                $this->getTargetlogService()->log(TargetlogService::INFO, 'pay.error', $data['trade_sn'], "交易号{$data['trade_sn']}处理失败, {$e->getMessage()}", $data);
                throw $e;
            }

            $this->dispatch('payment_trade.paid', $trade, $data);
            return $trade;
        }
    }

    public function searchTrades($conditions, $orderBy, $start, $limit)
    {
        return $this->getPaymentTradeDao()->search($conditions, $orderBy, $start, $limit);
    }

    protected function createFlowsAndUpdateTradeStatus($trade, $data)
    {
        try {
            $this->beginTransaction();
            $trade = $this->getPaymentTradeDao()->update($trade['id'], array(
                'status' => $data['status'],
                'pay_time' => $data['paid_time'],
                'platform_sn' => $data['cash_flow'],
                'notify_data' => $data,
                'currency' => $data['cash_type'],
            ));
            $this->createCashFlows($trade, $data);
            $this->getTargetlogService()->log(TargetlogService::INFO, 'trade.paid', $data['trade_sn'], "交易号{$data['trade_sn']}，账目流水处理成功", $data);
            $this->commit();
            return $trade;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function findEnabledPayments()
    {
        return $this->biz['payment.platforms'];
    }

    public function notifyClosed($data)
    {
        $trade = $this->getPaymentTradeDao()->getByTradeSn($data['sn']);
        return $this->getTradeContext($trade['id'])->closed();
    }

    public function applyRefundByTradeSn($tradeSn)
    {
        $trade = $this->getPaymentTradeDao()->getByTradeSn($tradeSn);

        if (in_array($trade['status'], array('refunding', 'refunded'))) {
            return $trade;
        }

        if ($trade['status'] != 'paid') {
            throw new AccessDeniedException('can not refund, becourse the trade is not paid');
        }

        if ((time() - $trade['pay_time']) > 86400) {
            throw new AccessDeniedException('can not refund, becourse the paid trade is expired.');
        }

        if($this->isRefundByPayment()){
            return $this->refundByPayment($trade);
        }

        return $this->markRefunded($trade);
    }

    protected function isRefundByPayment()
    {
        return empty($this->biz['payment.options']['refunded_notify']) ? false : $this->biz['payment.options']['refunded_notify'];
    }

    protected function refundByPayment($trade)
    {
        $paymentGetWay = $this->getPayment($trade['platform']);
        $response = $paymentGetWay->applyRefund($trade);

        if (!$response->isSuccessful()) {
            return $trade;
        }

        $trade = $this->getPaymentTradeDao()->update($trade['id'], array(
            'status' => 'refunding',
            'apply_refund_time' => time()
        ));
        $this->dispatch('payment_trade.refunding', $trade);

        return $trade;
    }

    protected function markRefunded($trade)
    {
        $flow = $this->createUserFlow('seller', $trade, array(), 'outflow');
        if (!empty($trade['coin_amount'])) {
            $flow = $this->createUserFlow('seller', $trade, $flow, 'outflow', true);
            $this->createUserFlow('buyer', $trade, $flow, 'inflow', true);
        }

        return $this->getTradeContext($trade['id'])->refunded();
    }

    public function notifyRefunded($payment, $data)
    {
        $paymentGetWay = $this->getPayment($payment);
        $response = $paymentGetWay->converterRefundNotify($data);
        $tradeSn = $response[0]['notify_data']['trade_sn'];

        $trade = $this->getPaymentTradeDao()->getByTradeSn($tradeSn);

        return $this->markRefunded($trade);
    }

    protected function validateLogin()
    {
        if (empty($this->biz['user']['id'])) {
            throw new AccessDeniedException('user is not login.');
        }
    }

    protected function createPaymentTrade($data)
    {
        $rate = $this->getCoinRate();

        $trade = array(
            'title' => $data['goods_title'],
            'trade_sn' => $this->generateSn(),
            'order_sn' => $data['order_sn'],
            'platform' => $data['platform'],
            'price_type' => $this->getCurrencyType(),
            'amount' => $data['amount'],
            'rate' => $this->getCoinRate(),
            'seller_id' => empty($data['seller_id']) ? 0 : $data['seller_id'],
            'user_id' => $this->biz['user']['id'],
            'status' => 'paying'
        );

        if (!empty($data['type'])) {
            $trade['type'] = $data['type'];
        }

        if (empty($data['coin_amount'])) {
            $trade['coin_amount'] = 0;
        } else {
            $trade['coin_amount'] = $data['coin_amount'];
        }

        if ('money' == $trade['price_type']) {
            $trade['cash_amount'] = ceil(($trade['amount'] * $trade['rate'] - $trade['coin_amount']) / $trade['rate'] ); // 标价为人民币，可用虚拟币抵扣
        } else {
            $trade['cash_amount'] = ceil(($trade['amount'] - $trade['coin_amount']) / $rate); // 标价为虚拟币
        }

        if ('recharge' == $trade['type']) {
            return $this->getPaymentTradeDao()->create($trade);
        }

        $savedTrade = $this->getPaymentTradeDao()->getByOrderSnAndPlatform($data['order_sn'], $data['platform']);
        if (empty($savedTrade)) {
            $trade = $this->getPaymentTradeDao()->create($trade);
            $this->lockCoin($trade);
            return $trade;
        } else {
            return $this->getPaymentTradeDao()->update($savedTrade['id'], $trade);
        }
    }

    public function findUserCashflowsByTradeSn($sn)
    {
        return $this->getUserCashflowDao()->findByTradeSn($sn);
    }

    protected function lockCoin($trade)
    {
        if ($trade['coin_amount']>0) {
            $user = $this->biz['user'];
            $this->getAccountService()->lockCoin($user['id'], $trade['coin_amount']);
        }
    }

    protected function createCashFlows($trade, $notifyData)
    {
        $flow = $this->createUserFlow('buyer', $trade, array('amount' => $notifyData['pay_amount']), 'inflow');
        $flow = $this->createUserFlow('buyer', $trade, $flow, 'outflow');

        $flow = $this->createUserFlow('seller', $trade, $flow, 'inflow');

        if ('recharge' == $trade['type']) {
            $flow = $this->createUserFlow('seller', $trade, $flow, 'outflow', true);
            $this->createUserFlow('buyer', $trade, $flow, 'inflow', true);
        } elseif ('purchase' == $trade['type']) {
            if (!empty($trade['coin_amount'])) {
                $flow = $this->createUserFlow('buyer', $trade, $flow, 'outflow', true);
                $this->createUserFlow('seller', $trade, $flow, 'inflow', true);
            }
        }
    }

    protected function createUserFlow($userType, $trade, $parentFlow, $flowType, $isCoin = false)
    {
        $userFlow = array(
            'sn' => $this->generateSn(),
            'type' => $flowType,
            'parent_sn' => empty($parentFlow['sn']) ? '' : $parentFlow['sn'],
            'currency' => $isCoin ? 'coin': $trade['currency'],
            'amount_type' => $isCoin ? 'coin': 'money',
            'user_id' => $userType == 'buyer' ? $trade['user_id'] : $trade['seller_id'],
            'trade_sn' => $trade['trade_sn'],
            'order_sn' => $trade['order_sn'],
            'platform' => $trade['platform'],
            'user_type' => $userType
        );

        if ($this->isRechargeCoin($isCoin, $userType, $flowType)
            || $this->isDischargeCoin($isCoin, $userType, $flowType)) {
            $userFlow['amount'] = $trade['cash_amount'] * $this->getCoinRate();
        } else if ($isCoin) {
            $userFlow['amount'] = $trade['coin_amount'];
        } else {
            $userFlow['amount'] = $trade['cash_amount'];
        }

        if ($userFlow['amount'] <= 0) {
            return array();
        }

        $amount = $flowType == 'inflow' ? $userFlow['amount'] : 0 - $userFlow['amount'];

        if ($isCoin) {
            if ($userType == 'buyer' && $flowType == 'outflow') {
                $userBalance = $this->getAccountService()->decreaseLockCoin($userFlow['user_id'], $userFlow['amount']);
            } else {
                $userBalance = $this->getAccountService()->waveAmount($userFlow['user_id'], $amount);
            }
            $userFlow['user_balance'] = empty($userBalance['amount']) ? 0 : $userBalance['amount'];
        } else {
            $userBalance = $this->getAccountService()->waveCashAmount($userFlow['user_id'], $amount);
            $userFlow['user_balance'] = empty($userBalance['cash_amount']) ? 0 : $userBalance['cash_amount'];
        }

        return $this->getUserCashflowDao()->create($userFlow);
    }

    protected function isRechargeCoin($isCoin, $userType, $flowType)
    {
        return $isCoin && $userType == 'buyer' && $flowType == 'inflow';
    }

    protected function isDischargeCoin($isCoin, $userType, $flowType)
    {
        return $isCoin && $userType == 'seller' && $flowType == 'outflow';
    }

    protected function generateSn($prefix = '')
    {
        return $prefix.date('YmdHis', time()).mt_rand(10000, 99999);
    }

    protected function getUserCashflowDao()
    {
        return $this->biz->dao('Pay:UserCashflowDao');
    }

    protected function getTargetlogService()
    {
        return $this->biz->service('Targetlog:TargetlogService');
    }

    protected function getPaymentTradeDao()
    {
        return $this->biz->dao('Pay:PaymentTradeDao');
    }

    protected function getAccountService()
    {
        return $this->biz->service('Pay:AccountService');
    }

    protected function getCoinRate()
    {
        return 1;
    }

    protected function getCurrencyType()
    {
        return 'money';
    }

    protected function getPayment($payment)
    {
        return $this->biz['payment.'.$payment];
    }

    protected function createPaymentPlatformTrade($data, $trade)
    {
        $data['trade_sn'] = $trade['trade_sn'];
        unset($data['user_id']);
        unset($data['seller_id']);
        $data['amount'] = $trade['cash_amount'];

        return $this->getPayment($data['platform'])->createTrade($data);
    }

    protected function getTradeContext($id)
    {
        $tradeContext = $this->biz['payment_trade_context'];

        $trade = $this->getPaymentTradeDao()->get($id);
        if (empty($trade)) {
            throw $this->createNotFoundException("trade #{$trade['id']} is not found");
        }

        $tradeContext->setPaymentTrade($trade);

        return $tradeContext;
    }
}