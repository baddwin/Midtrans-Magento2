<?php

namespace Midtrans\Snap\Controller\Payment;

use Magento\Sales\Model\Order;
use Midtrans\Snap\Gateway\Config\Config;
use Midtrans\Snap\Gateway\Notification as MidtransNotification;

/**
 * Class Notification
 * Handle notifications from midtrans http notifications
 */
class Notification extends AbstractAction
{
    public function execute()
    {
        $input_source = "php://input";
        $body = json_decode(file_get_contents($input_source), true);
        $orderIdRequest = $body['order_id'];
        $bodyOrder = $this->getQuoteByOrderId($orderIdRequest);
        /**
         * Do not process if order not found,
         * if Log enable, add record to /var/log/midtrans/notification.log
         */
        if ($bodyOrder->isEmpty()) {
            $_info = "404 NOT FOUND - Order with orderId: " . $orderIdRequest . " not found on Magento 2";
            $this->_midtransLogger->midtransNotification($_info);
            return $this->getResponse()->setBody('404 Order not found');
        }

        $this->getResponse()->setBody('OK');

        $paymentCode = $bodyOrder->getPayment()->getMethod();

        Config::$serverKey = $this->getData()->getServerKey($paymentCode);
        Config::$isProduction = $this->getData()->isProduction();

        $notif = new MidtransNotification();
        $orderId = $notif->order_id;
        $order = $this->getQuoteByOrderId($orderId);

        $transaction = $notif->transaction_status;
        $trxId = $notif->transaction_id;
        $fraud = $notif->fraud_status;
        $payment_type = $notif->payment_type;

        $note_prefix = "MIDTRANS NOTIFICATION  |  ";
        if ($transaction == 'capture') {
            if ($fraud == 'challenge') {
                $order_note = $note_prefix . 'Payment status challenged. Please take action on your Merchant Administration Portal - ' . $payment_type;
                $this->setOrderStateAndStatus($orderId, Order::STATE_PAYMENT_REVIEW, $order_note, $trxId);
            } elseif ($fraud == 'accept') {
                $order_note = $note_prefix . 'Payment Completed - ' . $payment_type;
                if ($order->canInvoice() && !$order->hasInvoices()) {
                    $this->generateInvoice($orderId, $payment_type);
                }
                $this->setOrderStateAndStatus($orderId, Order::STATE_PROCESSING, $order_note);
            }
        } elseif ($transaction == 'settlement') {
            if ($payment_type != 'credit_card') {
                $order_note = $note_prefix . 'Payment Completed - ' . $payment_type;
                if ($order->canInvoice() && !$order->hasInvoices()) {
                    $this->generateInvoice($orderId, $payment_type);
                }
                $this->setOrderStateAndStatus($orderId, Order::STATE_PROCESSING, $order_note);
            }
        } elseif ($transaction == 'pending') {
            $order_note = $note_prefix . 'Awating Payment - ' . $payment_type;
            $this->setOrderStateAndStatus($orderId, Order::STATE_PENDING_PAYMENT, $order_note, $trxId);
        } elseif ($transaction == 'cancel') {
            if ($order->canCancel()) {
                $order_note = $note_prefix . 'Canceled Payment - ' . $payment_type;
                $this->cancelOrder($orderId, Order::STATE_CANCELED, $order_note);
            }
        } elseif ($transaction == 'expire') {
            if ($order->canCancel()) {
                $order_note = $note_prefix . 'Expired Payment - ' . $payment_type;
                $this->cancelOrder($orderId, Order::STATE_CANCELED, $order_note);
            }
        } elseif ($transaction == 'deny') {
            $order_note = $note_prefix . 'Payment Deny - ' . $payment_type;
            $order->addStatusToHistory(Order::STATE_PAYMENT_REVIEW, $order_note, false);
        } elseif ($transaction == 'refund' || $transaction == 'partial_refund') {
            $isFullRefund = ($transaction == 'refund') ? true : false;

            /**
             * Get last array object from refunds array and get the value from last refund object
             */
            $refunds = $notif->refunds;
            $refund[] = end($refunds);
            $refund_key = $refund[0]->refund_key;
            $refund_amount = $refund[0]->refund_amount;
            $refund_reason = $refund[0]->reason;

            /**
             * Do not process if the notification contain 'bank_confirmed_at' from request body
             */
            $refundRaw[] = end($body['refunds']);
            if (isset($refundRaw[0]['bank_confirmed_at'])) {
                return $this->getResponse()->setBody('OK');
            }

            /**
             * Handle fullRefund: if refunded from midtrans dashboard close order and add comment history ,
             * If refund from magento dashboard add only comment history.
             */
            if ($isFullRefund) {
                $refund_note = $note_prefix . 'Full Refunded: ' . $refund_amount . '  |  Refund-Key: ' . $refund_key . '  |  Reason: ' . $refund_reason;
                if ($order->getStatus() != Order::STATE_CLOSED || $order->getState() != Order::STATE_CLOSED && $this->canFullRefund($refund_key, $order, $refund_amount) == true) {
                    $this->cancelOrder($orderId, Order::STATE_CLOSED, $refund_note);
                } else {
                    $order->addStatusToHistory(Order::STATE_CLOSED, $refund_note, false);
                }
            }

            /**
             * Handle partial refund from midtrans dashboard to add comment history
             */
            if (!$isFullRefund && $order->getStatus() === Order::STATE_PROCESSING) {
                $partialRefundNote = $note_prefix . 'Partial Refunded: ' . $refund_amount . '  |  Refund-Key: ' . $refund_key . '  |  Reason: ' . $refund_reason;
                $order->addStatusToHistory(Order::STATE_PROCESSING, $partialRefundNote, false);
            }
        }
        $this->saveOrder($order);

        /**
         * If log request isEnabled, add request payload to var/log/midtrans/request.log
         */
        $_info = "status : " . $transaction . " , order : " . $orderId . ", payment type : " . $payment_type;
        $this->_midtransLogger->midtransNotification($_info);
    }
}
