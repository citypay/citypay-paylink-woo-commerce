CityPay WooCommerce Subscriptions
==================================

Allows you to sell products and services with recurring payments using [CityPay Paylink WooCommerce plugin](../readme.md) to process the payments.

## Minimum requirements

* PHP version 7.4 or greater (Tested up to: 8.2.4)
* MySQL version 5.0 or greater 
* WordPress 6.0.1 or greater (Tested up to: 6.9.1)
* WooCommerce 6.7.0 or greater (Tested up to: 10.0.2)
* WooCommerce Subscriptions 4.4.0 or greater (Tested up to: 5.0.1)
* Cron [Working WP Cron](https://woocommerce.com/document/subscriptions/requirements/#section-2)
* Site URL [Live site served exclusively on one URL](https://woocommerce.com/document/subscriptions-handles-staging-sites/#section-11)
* [CityPay Paylink WooCommerce Plugin 2.1.10](https://github.com/citypay/citypay-paylink-woo-commerce)

## Set up 

* Install WooCommerce Subscriptions. ([WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions))
* In CityPay Paylink WooCommerce Plugin settings enable Subscriptions and provide:
  * ```Client ID``` - CityPay Client ID
  * ```Subscriptions Merchant ID``` - If you want to process recurring payments with a different Merchant ID
  * ```Subscriptions Prefix``` - Subscription Prefix for the store. If you have others stores using the same Client ID, use a different prefix for each. (maxLength: 8)
* Optional synchronized-renewal settings in the CityPay gateway:
  * ```CityPay Zero-Amount Setup For Synchronized Renewals``` - Enables CityPay cutoff-day logic for synchronized monthly subscriptions.
  * ```CityPay Cutoff Day``` - Orders placed after this day use a zero-amount CityPay setup and first pay on the next synchronized renewal date.

## Creating a subscription product
Please follow the [Subscriptions Store Manager Guide](https://woocommerce.com/document/subscriptions/store-manager-guide/) and take in consideration our Supported/Unsupported Features.
For the cutoff-day setup, WooCommerce Subscriptions should be configured to use synchronized renewals and `Prorate First Renewal` should be set to `Never (do not charge any recurring amount)`.


## WooCommerce Subscriptions - Supported Features

* Sign-Up Fees - Charge an initial amount to account for customer setup costs.
* Free Trials with subsequent recurring payments.
* Variable Subscriptions - Create variable subscription products and allow your customers to choose a subscription that suits their needs.
* Subscription Management - Store owners get full-featured subscription management.
* Subscriber Account Management - Your customers can also manage their own subscriptions. With the My Account > View Subscription page.
* Flexible Product Options - When creating a subscription product, you can make the product downloadable, virtual or physical, charge renewal payments weekly, monthly or annually.
* Customer Emails - Automatically notify customers when a subscription renewal payment is processed, a subscription is cancelled or when a subscription has expired with the built-in subscription emails.
* Zero-Amount Setup for Synchronized Renewals - For monthly subscriptions, optionally configure a CityPay gateway cutoff day so customers on or before the cutoff are charged the first recurring amount at sign-up, while customers after the cutoff use a zero-amount setup checkout and pay on the next synchronized renewal date.


##  WooCommerce Subscriptions - Unsupported Features

* Synchronise renewals - Align subscription renewal to a specific day of the week, month or year.
* Upgrades/Downgrades - Allow subscribers to switch (upgrade or downgrade) between different subscriptions.
* Limit the product to one-per-customer and even charge shipping only on the initial order.
* Multiple Subscriptions - Purchase different subscription products in the same transaction.
* Subscription Coupons

## WooCommerce Subscriptions Documentation
* [Introduction to WooCommerce Subscriptions](https://woocommerce.com/document/subscriptions/)
* [Subscriptions Requirements](https://woocommerce.com/document/subscriptions/requirements/)
* [Subscriptions Developer Docs](https://woocommerce.com/documentation/plugins/woocommerce/woocommerce-extensions/woocommerce-subscriptions/developer-docs/)
