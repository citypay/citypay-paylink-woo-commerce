#!/bin/bash

# the wordpress entry point looks for a file starting with apache2 hence the name, this file actually runs the setup process just before running apache in the foreground
echo ========== Initial Plugin List =============
wp --allow-root plugin list
echo ============================================

wp --allow-root plugin uninstall woocommerce
wp --allow-root plugin install woocommerce
wp --allow-root plugin activate woocommerce

cd wp-content/plugins
echo ========== Loading CityPay Woo Commerce Plugin List =============
wget https://github.com/citypay/citypay-paylink-woo-commerce/archive/${CITYPAY_PLUGIN_VERSION}.zip
unzip citypay-paylink-woo-commerce-${CITYPAY_PLUGIN_VERSION}.zip
rm citypay-paylink-woo-commerce-${CITYPAY_PLUGIN_VERSION}.zip
chown -R www-data:www-data ./*
cd ../../
echo ========== Activate CityPay Woo Commerce Plugin =================
wp --allow-root plugin activate citypay-paylink-woo-commerce-${CITYPAY_PLUGIN_VERSION}
echo =================================================================




echo ========== Updated Plugin List =============
wp --allow-root plugin list
echo ============================================


echo ========= Starting NGROK... ================

if [[ -z "${NGROK_AUTHTOKEN}" ]]; then
  echo "No NGROK_AUTHTOKEN in env"
  exit
else
  ngrok authtoken $NGROK_AUTHTOKEN
  echo "web_addr: 0.0.0.0:4040" >>./ngrok.conf
  nohup ngrok http -log=ngrok.log -region=eu --config=ngrok.conf 80 &
fi

sleep 4
NGROK_URL=$(curl http://127.0.0.1:4040/api/tunnels | jq '.tunnels[].public_url'  | grep http:)
NGROK_URL=$(sed -e 's/^"//' -e 's/"$//' <<<"$NGROK_URL")

echo 'ngrokurl=' $NGROK_URL
echo ============================================


# run apache in the foreground...
apache2-foreground