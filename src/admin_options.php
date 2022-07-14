<?php
// Admin Panel Options
$configured = true;
$subs_configured_note = false;

if ((empty($this->merchant_curr)) || (empty($this->merchant_id)) || (empty($this->licence_key))) {
    $configured = false;
}

if ($this->cp_subscriptions === 'yes') {
   if (empty($this->client_id) || empty($this->subscriptions_prefix)) {
       $subs_configured_note = true;
   }
}
?>

<h3>CityPay Payment Gateway</h3>
<p class="main">
    Accept <b>CityPay</b> payments on your WooCommerce powered store!</p>

<?php if (!$configured || $subs_configured_note) : ?>
    <div id="wc_get_started">
        <?php if (!$configured) : ?>
            <p><br><b>NOTE: </b> You must enter your merchant ID and licence key</p>
        <?php endif; ?>
        <?php if ($subs_configured_note) : ?>
            <p><b>NOTE: </b> If you have Subscriptions Enable you must enter a Client ID and a Subscriptions prefix</p>
        <?php endif; ?>
        <p>If you do not have an account, visit <a href="https://citypay.com/" target="_blank">citypay.com</a> to setup an account</p>
    </div>
<?php endif; ?>

<p>For any support on the CityPay plugin, please visit our github page
    at <a href="https://github.com/citypay/citypay-paylink-woo-commerce">https://github.com/citypay/citypay-paylink-woo-commerce</a>
    Any correspondence with logging data such as licence keys or merchant ids should be sanitised, alternatively
    contact us via email at <a href="mailto:support@citypay.com">support@citypay.com</a>
</p>

<table class="form-table">
    <?php $this->generate_settings_html(); ?>
</table><!--/.form-table-->
