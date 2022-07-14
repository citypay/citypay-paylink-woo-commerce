<?php

require_once(dirname(__FILE__) . '/ApiKey.php');
require_once dirname(__FILE__) . '/trait-wc-citypay-api.php';

trait WC_Gateway_CP_Subscriptions
{
    use WC_CP_API;

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
            $subscription_id = array_values($subscriptions)[0]->get_id();  // we are assuming that we just accept one subscription

            $this->debugLog('scheduled_subscription_payment subscription_id:' . $subscription_id );

            $merchant_id = $this->get_merchant_id();

            $account = $this->account_retrieval($this->subscriptions_prefix  . '-' . $subscription_id);

            $token = array_values($account['CardHolderAccount']['cards'])[0]['token']; // TODO

            $charge_body = [
                "amount" => $amount_to_charge,
                "identifier" => "renewal-" . $merchant_id . $subscription_id . $renewal_order_id,
                "merchantid" => (int) $merchant_id ,
                "token" => $token,
                "currency" => $this->merchant_curr,
                "initiation" => "M", // Merchant
                "cardholder_agreement" => "R", // Recurring
                "csc_policy" => "2" // to ignore. Transactions that are ignored will bypass the result and not send the CSC details for authorisation.
            ];

            $response = $this->account_charge($charge_body);

            $response_auth_response = $response['AuthResponse']; // TODO if not AuthResponse error

            $this->validateChargeResponseData($response_auth_response);

            $trans_no = $response_auth_response['transno'];
            $authcode = $response_auth_response['authcode'];
            $authorised = $response_auth_response['authorised'];
            $live= $response_auth_response['live'];

            $this->debugLog('Found order:      #' . $renewal_order->get_id());
            $this->debugLog('order status:     ' . $renewal_order->status);
            $this->debugLog('Authorised:       ' . ($authorised ? 'true' : 'false'));
            $this->debugLog('Incoming transNo: ' . $trans_no);


            if ($authorised) {

                // Transaction authorised
                update_post_meta($renewal_order->get_id(), 'CityPay TransNo', $trans_no);
                update_post_meta($renewal_order->get_id(), 'CityPay Identifier', $response_auth_response['identifier']);
                $maskedpan = $response_auth_response['scheme'] . '/' . $response_auth_response['maskedpan'];
                update_post_meta($renewal_order->get_id(), 'Card used', $maskedpan);

                $renewal_order->add_order_note(sprintf(__('%s CityPay Renewal Payment OK. TransNo: %s, AuthCode: %s',
                    'wc-payment-gateway-citypay'), $live ? "" : "Test", $trans_no, $authcode));

                $renewal_order->payment_complete();
                $this->debugLog('Authorised, Payment complete.');
                return;
            }

            // Declined/Cancelled
            $this->debugLog('Declined');
            $this->debugLog('Not authorised: ' . $response_auth_response['result_code'] . ' ' . $response_auth_response['result_message']);
            $renewal_order->add_order_note(sprintf(__('CityPay Renewal Payment Not Authorised, TransNo: %s. Result: %s Error: %s: %s.', 'wc-payment-gateway-citypay'),
                $trans_no, $response_auth_response['result'], $response_auth_response['result_code'], $response_auth_response['result_message']));
            $renewal_order->update_status('failed');

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