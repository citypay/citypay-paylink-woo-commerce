#!/bin/bash

# the wordpress entry point looks for a file starting with apache2 hence the name, this file actually runs the setup process just before running apache in the foreground
echo ========== Initial Plugin List =============
wp --allow-root plugin list
echo ============================================

wp --allow-root plugin uninstall woocommerce
wp --allow-root plugin install woocommerce --activate --version=$WOOCOMMERCE_VERSION

cd wp-content/plugins
echo ========== Loading CityPay Woo Commerce Plugin List =============
wget https://github.com/citypay/citypay-paylink-woo-commerce/raw/${CITYPAY_PLUGIN_VERSION}/build/citypay-paylink-woocommerce.zip
unzip citypay-paylink-woocommerce.zip
rm citypay-paylink-woocommerce.zip
chown -R www-data:www-data ./*
cd ../../
echo ========== Activate CityPay Woo Commerce Plugin =================
wp --allow-root plugin activate citypay-paylink-woocommerce
echo =================================================================




echo ========== Updated Plugin List =============
wp --allow-root plugin list
echo ============================================


# run apache in the foreground...
apache2-foreground