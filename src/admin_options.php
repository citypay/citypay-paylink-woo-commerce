<?php
// Admin Panel Options
$configured = true;
if ((empty($this->merchant_curr)) || (empty($this->merchant_id)) || (empty($this->licence_key))) {
    $configured = false;
}
?>


<h3><?php esc_html_e('CityPay PayLink', 'woocommerce'); ?></h3>

<?php if (!$configured) : ?>
    <div id="wc_get_started">
        <span class="main"><?php esc_html_e('CityPay PayLink Payment Page', 'woocommerce'); ?></span>
        <span><a href="http://www.citypay.com/"
                 target="_blank">CityPay</a> <?php esc_html_e('are a PCI DSS Level 1 certified payment gateway. We guarantee that we will handle the storage, processing and transmission of your customer\'s cardholder data in a manner which meets or exceeds the highest standards in the industry.', 'woocommerce'); ?></span>
        <span><br><b>NOTE: </b> You must enter your merchant ID and licence key</span>
    </div>
<?php else : ?>
    <p><?php esc_html_e('CityPay PayLink Payment Page', 'woocommerce'); ?></p>
<?php endif; ?>


<table class="form-table">
    <?php $this->generate_settings_html(); ?>
</table><!--/.form-table-->
