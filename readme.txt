=== CityPay Paylink WooCommerce ===
Contributors: _citypay_to_be_confirmed_
Tags: ecommerce, e-commerce, woocommerce, payment gateway
Donate link: http://citypay.com/
Requires at least: 4.0
Tested up to: 4.5.1
Stable tag: 1.0.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

CityPay Paylink WooCommerce is a plugin that supplements WooCommerce with
support for payment processing using CityPay hosted payment forms.

== Description ==

== Installation ==

= Minimum requirements =

* PHP version 5.2.4 or greater with libcurl support
* MySQL version 5.0 or greater
* libcurl version 7.10.5 or later with SSL / TLS support
* openssl, to current patch levels
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

You can include the WooCommerce order identifier in the description sent
to CityPay for the purpose of including a customer-friendly reference in
the email sent to the customer on conclusion of the transaction. This is
achieved by specifying {order_id} as part of the descriptive text appearing
in the text box labeled Transaction Description.

After the settings for the plugin have been configured, they must be saved
by clicking on the button labeled Save Changes before they take effect.

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

= WordPress / WooCommerce displays "Sorry, unable to process your order at this time" at the time of checkout =

WordPress / WooCommerce displays the generic error "Sorry, unable to process
your order at this time" if is not possible for the application to refer the
customer to the CityPay Paylink hosted payment form.

There may be a variety of reasons that such a referral cannot be made. If
connection failure is persistent, then this may be caused by -

1. incorrect configuration of the merchant identifier (the "MID") or the licence
key used by the plugin to obtain a payment session token from the Paylink
service; or
2. incorrect configuration of the IP address registered with CityPay for
receiving API calls from merchant applications to the CityPay Paylink service
API.

The specific cause of the problem can be determined by enabling debug logging
for the plugin and, after attempting to process a transaction, checking the
debug log.

Configuration of the MID, or the licence key for the installed version of 
WordPress / WooCommerce and the CityPay Paylink plugin is performed through
the WooCommerce settings forms; whereas configuration of the IP address
for the merchant application registered with CityPay is administered by
CityPay. To request configuration of the IP address for the merchant application
please contact <support@citypay.com>.

If the connection failure is not persistent, and intermittent in nature, the
problem is most likely caused by connectivity or DNS name resolution problems
affecting the merchant application generally.

= CityPay Paylink service connectivity issues involving WordPress / WooCommerce implementations =

The CityPay Paylink WooCommerce plugin relies upon being able to establish
a secure, encrypted session with the CityPay Paylink service. The OpenSSL
library typically installed with Windows binary versions of PHP is not
accompanied by any certificate authority ("CA") certificates that exist to
certify, through a chain of certificates, the identity of the remote endpoint;
in the present case, the CityPay Paylink service. The problem may affect binary
distributions of PHP for other operating systems as well.

With debug logging enabled in the plugin settings, a problem involving access
to SSL CA certificates may be indicated by a line of the form -

    SSL certificate problem: unable to get local issuer certificate

To resolve this problem, the CA bundle may be downloaded from
<https://raw.githubusercontent.com/bagder/ca-bundle/master/ca-bundle.crt>,
installed in an appropriate location, and referenced in the php.ini
configuration file for PHP using the curl.cainfo configuration setting.

== Screenshots ==


== Changelog ==

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
