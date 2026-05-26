<?php

require_once(dirname(__FILE__) . '/ApiKey.php');
require_once dirname(__FILE__) . '/trait-wc-citypay-api.php';

trait WC_Gateway_CP_Subscriptions
{
    use WC_CP_API;

    protected function get_subscription_account_no($subscription, $renewal_order = null)
    {
        if (!$subscription || !method_exists($subscription, 'get_meta')) {
            return '';
        }

        $accountNo = $subscription->get_meta('AccountNo', true);
        if (!empty($accountNo)) {
            return $accountNo;
        }

        $meta_keys = array('AccountNo');
        if (method_exists($this, 'get_subscription_account_meta_key')) {
            $meta_keys[] = $this->get_subscription_account_meta_key();
        }

        $orders_to_check = array();
        if ($renewal_order instanceof WC_Order) {
            $orders_to_check[] = $renewal_order;
        }

        if (method_exists($subscription, 'get_parent_id')) {
            $parent_order = wc_get_order($subscription->get_parent_id());
            if ($parent_order instanceof WC_Order) {
                $orders_to_check[] = $parent_order;
            }
        }

        foreach ($orders_to_check as $order) {
            foreach ($meta_keys as $meta_key) {
                $accountNo = $order->get_meta($meta_key, true);
                if (!empty($accountNo)) {
                    $this->debugLog('Recovered AccountNo from order #' . $order->get_id() . ' using meta key ' . $meta_key . '.');
                    return $accountNo;
                }
            }
        }

        return '';
    }

    protected function mark_renewal_payment_complete($renewal_order, $trans_no = '')
    {
        if ($renewal_order instanceof WC_Order && !empty($trans_no) && method_exists($renewal_order, 'set_transaction_id')) {
            $renewal_order->set_transaction_id((string)$trans_no);
            $renewal_order->save();
        }

        if (class_exists('WC_Subscriptions_Manager') && method_exists('WC_Subscriptions_Manager', 'process_subscription_payments_on_order')) {
            WC_Subscriptions_Manager::process_subscription_payments_on_order($renewal_order);
            $this->debugLog('Recorded renewal payment via WC_Subscriptions_Manager::process_subscription_payments_on_order().');
        }

        if ($renewal_order instanceof WC_Order && !$renewal_order->is_paid()) {
            $renewal_order->payment_complete((string)$trans_no);
            $this->debugLog('Marked renewal order paid via WC_Order::payment_complete().');
        }
    }

    protected function mark_renewal_payment_failed($renewal_order)
    {
        if (class_exists('WC_Subscriptions_Manager') && method_exists('WC_Subscriptions_Manager', 'process_subscription_payment_failure_on_order')) {
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
            $this->debugLog('Recorded renewal failure via WC_Subscriptions_Manager::process_subscription_payment_failure_on_order().');
        }

        if ($renewal_order instanceof WC_Order && $renewal_order->get_status() !== 'failed') {
            $renewal_order->update_status('failed');
        }
    }

    /**
     *  Checks if subscriptions are enabled on the site.
     * @return bool
     */
    public function is_subscriptions_enabled()
    {
        return class_exists('WC_Subscriptions') && $this->cp_subscriptions == 'yes';
    }

    /**
     * Initialize subscription support and hooks.
     * @return void
     */
    public function init_subscriptions()
    {
        if (!$this->is_subscriptions_enabled()) {
            return;
        }

        $this->supports = array_merge(
            $this->supports,
            array(
                'subscriptions',
                'subscription_cancellation',
                'subscription_reactivation',
                'subscription_suspension',
                'subscription_amount_changes',
                'subscription_date_changes'
            )
        );

        // subscription actions
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);
        add_action('woocommerce_subscription_failing_payment_method_updated_' . $this->id, [$this, 'update_failing_payment_method'], 10, 2);
    }

    /**
     * @param $amount_to_charge // the amount to charge
     * @param $renewal_order // A WC_Order object created to record the renewal payment.
     * @return void
     * @throws Exception
     */
    public function scheduled_subscription_payment($amount_to_charge, $renewal_order)
    {
        try {
            $this->debugLog('WC_Gateway_CP_Subscriptions::scheduled_subscription_payment()');

            $renewal_order_id = $renewal_order->get_id();
            $subscriptions =  wcs_get_subscriptions_for_renewal_order($renewal_order_id);
            $subscription = array_values($subscriptions)[0];  // we are assuming that we just accept one subscription
            $subscription_id = $subscription->get_id();

            $this->debugLog('scheduled_subscription_payment subscription_id:' . $subscription_id );

            $merchant_id = $this->get_merchant_id();
            $accountNo = $this->get_subscription_account_no($subscription, $renewal_order);
            if (empty($accountNo)) {
                throw new Exception('Missing CityPay AccountNo for subscription #' . $subscription_id . '.');
            }

            $this->debugLog(
                'Preparing renewal charge for order #' . $renewal_order_id .
                ', subscription #' . $subscription_id .
                ', merchant_id=' . $merchant_id .
                ', amount=' . $amount_to_charge
            );

            $account = $this->account_retrieval($accountNo);

            $token = array_values($account['cards'])[0]['token'];

            if ($token) {
                $this->debugLog('Has token');
                $charge_body = [
                    "amount" => $this->formatedAmount($amount_to_charge),
                    "identifier" => "renewal-" . $merchant_id . $subscription_id . $renewal_order_id,
                    "subscription_id" => $subscription_id,
                    "merchantid" => (int)$merchant_id,
                    "token" => $token,
                    "currency" => $this->merchant_curr,
                    "initiation" => "M", // Merchant
                    "cardholder_agreement" => "R", // Recurring
                    "csc_policy" => "2" // to ignore. Transactions that are ignored will bypass the result and not send the CSC details for authorisation.
                ];

                $response = $this->account_charge($charge_body);

                $response_auth_response = $response['AuthResponse'];

                $this->validateChargeResponseData($response_auth_response);

                $trans_no = $response_auth_response['transno'];
                $authcode = $response_auth_response['authcode'];
                $authorised = $response_auth_response['authorised'];
                $live = $response_auth_response['live'];

                $this->debugLog('Found order:      #' . $renewal_order->get_id());
                $this->debugLog('order status:     ' . $renewal_order->status);
                $this->debugLog('Authorised:       ' . ($authorised ? 'true' : 'false'));
                $this->debugLog('Incoming transNo: ' . $trans_no);


                if ($authorised) {

                    // Transaction authorised
                    $renewal_order->update_meta_data('CityPay TransNo', $trans_no);
                    $renewal_order->update_meta_data('CityPay Identifier', $response_auth_response['identifier']);
                    $maskedpan = $response_auth_response['scheme'] . '/' . $response_auth_response['maskedpan'];
                    $renewal_order->update_meta_data('Card used', $maskedpan);
                    $renewal_order->save_meta_data();

                    $renewal_order->add_order_note(sprintf(__('%s CityPay Renewal Payment OK. TransNo: %s, AuthCode: %s',
                        'wc-payment-gateway-citypay'), $live ? "" : "Test", $trans_no, $authcode));

                    $this->mark_renewal_payment_complete($renewal_order, $trans_no);
                    $this->debugLog('Authorised, renewal payment recorded.');
                    return;
                }

                // Declined/Cancelled
                $this->debugLog('Declined');
                $this->debugLog('Not authorised: ' . $response_auth_response['result_code'] . ' ' . $response_auth_response['result_message']);
                $renewal_order->add_order_note(sprintf(__('CityPay Renewal Payment Not Authorised, TransNo: %s. Result: %s Error: %s: %s.', 'wc-payment-gateway-citypay'),
                    $trans_no, $response_auth_response['result'], $response_auth_response['result_code'], $response_auth_response['result_message']));

            } else {
                $this->debugLog('No token');
                $renewal_order->add_order_note("Something went wrong. Renewal Failed.");
            }
            $this->mark_renewal_payment_failed($renewal_order);
        } catch (Exception $e) {
            $message = $e->getMessage();
            $renewal_order->add_order_note($e->getMessage());
            $this->errorLog('Error generating scheduled subscription payment: ' . $e);
            throw new Exception($message);
        }
    }

    /**
     * @param $subscription // The subscription for which the failing payment method relates.
     * @param $renewal_order // A WC_Order object created to record the renewal payment.
     * @return void
     */
    public function update_failing_payment_method($subscription, $renewal_order)
    {
        $this->debugLog('WC_Gateway_CP_Subscriptions::update_failing_payment_method()');
        $subscriptions =  wcs_get_subscriptions_for_renewal_order($renewal_order->get_id());
        $subscription_id = array_values($subscriptions)[0]->get_id();
        $this->debugLog('update_failing_payment_method subscription_id' . $subscription_id);
    }
}
