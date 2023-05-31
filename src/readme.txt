=== CityPay Paylink WooCommerce ===
Contributors: citypay
Tags: ecommerce, e-commerce, woocommerce, payment gateway
Requires at least: 4.0
Tested up to: 6.2
Stable tag: 2.1.0
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

CityPay Paylink WooCommerce is a plugin that supplements WooCommerce with
support for payment processing using CityPay hosted payment forms and Paylink 3.

== Description ==


The WooCommerce plugin works by creating a token and redirecting to the
Paylink form for cardholders to enter their card details directly into
the CityPay secure web application. Once a payment has been completed it
will attempt to connect via a webhook or postback to your website.

== Installation ==

= Minimum requirements =

* PHP version 5.2.4 or greater (Tested up to: 8.2.4)
* MySQL version 5.0 or greater
* WordPress 4.0 or greater (Tested up to: 6.2)
* WooCommerce 3 or greater (Tested up to: 7.5.1)

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
with the name "CityPay WooCommerce Plugin" by clicking on the link labeled
Activate.

You will need to edit WooCommerce checkout settings by navigating to the
WooCommerce administration panel, selecting WooCommerce, Settings and then
the checkout tab. If installed correctly, you should see CityPay as a link
under the Checkout Options.

The merchant account, the licence key and other
information relating to the processing of transactions through the CityPay
Paylink hosted form payment gateway may be configured by selecting the
plugin configuration form which is accessed indirectly through the
WooCommerce settings page upon selecting the Checkout tab, and clicking on
the link labeled CityPay which appears in the list of available payment
methods.

You can include the WooCommerce order identifier in the description sent
to CityPay for the purpose of including a customer-friendly reference in
the email sent to the customer. This is
achieved by specifying {order_id} as part of the descriptive text appearing
in the text box labeled Transaction Description.

After the settings for the plugin have been configured, they must be saved
by clicking on the button labeled Save Changes before they take effect.

= Developer Postback Testing =

The Paylink service cannot send a postback/webhook to your localhost test server
to update the order status. Therefore token requests fail to be created when
a localhost or local network address is detected in the postback URL. To work
around this, we recommend using [https://ngrok.com](https://ngrok.com) to create
a secure tunnel to your localhost server. As your WordPress installation may be
on localhost, the CityPay settings page allows the addition of a
**Postback Site Address (URL)** which you can customise with your ngrok address i.e.
https://12345678abc.ngrok.io. The value should be the host and protocol part of the
URL.

= Processing test transactions =

To test the operation of an e-commerce solution based on WooCommerce in
combination with the CityPay Paylink WooCommerce plugin without processing
transactions that will be settled by the upstream acquirer, the check box
labeled Test Mode appearing on the plugin settings form should be ticked.

= Processing live transactions =

To process live transactions for settlement by the upstream acquirer, the
checkbox labeled Test Mode referenced in the paragraph above must be
unticked.

= Enabling logging =

The interaction between WordPress, WooCommerce and the CityPay Paylink
hosted payment form service may be monitored by ticking the check box labeled
Debug Log appearing on the plugin settings form.

Log payment events appearing in the resultant log file will help to trace
any difficulties you may experience accepting payments using the CityPay
Paylink service.

The location of the log file is provided on the plugin settings form.


== Changelog ==

= 2.1.0 =

* Updated tested up to for WordPress.

= 2.0.9 =

* Fixed CityPay API URL when processing subscriptions.

= 2.0.8 =

* Added Transaction Identifier Prefix.

= 2.0.7 =

* Updated tested up to for WordPress.

= 2.0.6 =

* Updated readme files.

= 2.0.5 =

* Fixed decimal amount in renewals.

= 2.0.4 =

* Fixed bug when doing an account retrieval to process renewals.

= 2.0.3 =

* Fixed bug when plugin tries to embed wp-admin/includes/plugin.php file.

= 2.0.2 =

* Integrated WooCommerce Subscriptions.

= 2.0.1 =

* Fixed issue where cancelled transactions could result in marking an order as complete.
* Fixed issue where retrying cancelled transactions results in order notes being missed


= 2.0.0 =

* Refactored library to be fully 3.0 compatible and remove support for 2.X of WooCommerce.
* Addition of postback URL testing for use with Ngrok or similar
* Refactoring of method of payment to ensure linkage to the Paylink form is seamless
* Prevented multiple postback calls from reverting the status of an approved authorisation
* addition of further notes to the order screen

= 1.1.0 =

* Refactored library to remove curl and redundant code

= 1.0.7 =

* Refer postback messages to the http / https server used by Wordpress
  preventing problems testing.

= 1.0.6 =

= 1.0.5 =

= 1.0.4 =

= 1.0.3 =

= 1.0.2 =

* Introduces improved error reporting for SSL connectivity issues.

= 1.0.1 =

* Support for WooCommerce versions 2.3 and above.

= 1.0.0 =

* Initial version.

== Upgrade Notice ==

= 1.0.2 =

* Update improves error reporting for SSL connectivity issues.

= 1.0.1 =

* Upgrade supports WooCommerce versions 2.3 and above.

= 1.0.0 =

* Initial version.
