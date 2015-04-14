=== CityPay Paylink WooCommerce ===
Contributors: _citypay_to_be_confirmed_
Tags: ecommerce, e-commerce, woocommerce, payment gateway
Donate link: http://citypay.com/
Requires at least: 4.0
Tested up to: 4.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

CityPay Paylink WooCommerce is a plugin that supplements WooCommerce with
support for payment processing using CityPay hosted payment forms.

== Description ==

== Installation ==

= Minimum requirements =

* PHP version 5.2.4 or greater
* MySQL version 5.0 or greater
* WordPress 4.0 or greater
* WooCommerce 2.3 or greater

= Automatic installation =

To perform an automatic installation of the CityPay Paylink WooCommerce plugin,
login to your WordPress dashboard, select the Plugins menu and click Add New.

In the search field, type "CityPay" and click Search Plugins. Once you have
found our payment gateway plugin, it may be installed by clicking Install Now.

= Manual installation =

The perform a manual installation of the CityPay Paylink WooCommerce plugin,
login to your WordPress dashboard, select the Plugins menu and click Add New. 

Then select Upload Plugin, browse to the location of the ZIP file containing
the plugin (typically named *citypay-paylink-woocommerce.zip*) and then click
Install Now.

= Post installation: the plugin settings form =

Once the plugin has been installed, you may need to activate it by selecting
the Plugins menu, clicking Installed Plugins and then activating the plugin
with the name "CityPay WooCommerce Payments" by clicking on the link labeled
Activate.

The merchant account, the license key, the transaction currency and other
information relating to the processing of transactions through the CityPay
Paylink hosted form payment gateway may be configured by selecting the
plugin configuration form which is accessed indirectly through the
WooCommerce settings page upon selecting the Checkout tab, and clicking on
the link labeled CityPay which appears in the list of available payment
methods.

= Processing test transactions =

To test the operation of an e-commerce solution based on WooCommerce in
combination with the CityPay Paylink WooCommerce plugin without processing
transactions that will be settled by the upstream acquirer, the check box
labeled Test Mode appearing on the plugin settings form should be ticked.

= Processing live transactions =

To process live transactions for settlement by the upstream acquirer, the
check box labeled Test Mode referenced in the paragraph above must be
unticked.

= Enabling logging =

The interaction between WordPress, WooCommerce and the CityPay Paylink
hosted payment form service may be monitored by ticking the check box labeled
Debug Log appearing on the plugin settings form.

Log payment events appearing in the resultant log file will help to trace
any difficulties you may experience accepting payments using the CityPay
Paylink service.

The location of the log file is provided on the plugin settings form.

== Frequently Asked Questions ==


== Screenshots ==


== Changelog ==

= 1.0.1 =
* Support for WooCommerce versions 2.3 and above.

= 1.0.0 =
* Initial version.

== Upgrade Notice ==

= 1.0.1 =
Upgrade supports WooCommerce versions 2.3 and above.

= 1.0.0 =

