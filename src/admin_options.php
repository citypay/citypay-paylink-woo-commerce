<?php
// Admin Panel Options
$configured = true;
if ((empty($this->merchant_curr)) || (empty($this->merchant_id)) || (empty($this->licence_key))) {
    $configured = false;
}
?>

<h3>CityPay Payment Gateway</h3>
<p class="main">
    Accept <b>CityPay</b> payments on your WooCommerce powered store!</p>

<?php if (!$configured) : ?>
    <div id="wc_get_started">
        <p><br><b>NOTE: </b> You must enter your merchant ID and licence key</p>
        <p>If you do not have an account, visit <a href="https://citypay.com/" target="_blank">citypay.com</a> to setup an account</p>
    </div>
<?php endif; ?>

<p>For any support on the CityPay plugin, please visit our github page
    at <a href="https://github.com/citypay/citypay-paylink-woo-commerce">https://github.com/citypay/citypay-paylink-woo-commerce</a>
    Any correspondance with logging data such as licence keys or merchant ids should be sanitised, alternatively
    contact us via email at <a href="mailto:support@citypay.com">support@citypay.com</a>
</p>

<table class="form-table">
    <?php $this->generate_settings_html(); ?>
</table><!--/.form-table-->
