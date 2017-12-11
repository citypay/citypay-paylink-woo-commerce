#!/bin/bash

mkdir -p citypay-paylink-woocommerce
cp -R src/* citypay-paylink-woocommerce/
cp readme.* citypay-paylink-woocommerce/

VERSION=$(awk '/Version: /{print $NF}' src/citypay-paylink-woocommerce.php)
echo $VERSION

zip -r citypay-paylink-woocommerce-$VERSION.zip citypay-paylink-woocommerce \
 && rm -rf citypay-paylink-woocommerce