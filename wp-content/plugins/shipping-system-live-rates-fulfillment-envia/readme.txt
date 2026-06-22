=== Envia Shipping and Fulfillment ===
Contributors: enrique08carreon, enviapartners
Donate link: 
Tags: shipping, delivery and fulfillment, checkout calculator, labels
Requires PHP: 7.4
Tested up to: 6.9.1
Requires at least: 6.5
WC tested up to: 10.4.3
WC requires at least: 8.9
Stable tag: 5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
With Envia Shipping and Fulfillment plugin for WooCommerce; streamline your shipping logistics worldwide.
== Description ==
Shipping is a challenge for every ecommerce site owner. Install the Envia Shipping and Fulfillment plugin for WooCommerce and streamline your shipping logistics worldwide.
Envia is a leader in ecommerce shipping. Envia partners with 150+ carriers worldwide to provide an extraordinary number of shipping options for store owners. Envia also integrates with 45+ ecommerce platforms for shop owners selling via multiple channels.
Envia is a powerful and easy-to-use professional shipping system.
Easily quote, create, and track shipments with 150+ carriers worldwide. With Envia, you can integrate multiple sales channels and manage your logistics from a single platform.
Envia can also help companies start selling outside their home countries. Envia Fulfillment Services include product warehousing, picking, packing, and shipping to final customers.
== Frequently Asked Questions ==
= How to start with Envia Shipping and Fulfillment plugin? =
1. Create your account at Envia.com
2. Install the Envia Shipping and Fulfillment plugin in your wordpress site.
3. Activate your store from Envia rates and shipping and make your selection of configurations.
= How sync orders with my store? =
You can sync orders with a simple click in Envia Shipping and Fulfillment plugin section. 
= What are the plugin features in the checkout? =
You can use the live shipping calculator in the checkout and view/choose Envia.com carriers options and costs. Combined with WooCommerce shipping zones it is possible to set different shipping locations; you may configure through envia.com to display different carriers depending on the origin zone. 
= What are the plugin features in store administrator? =
Connecting your store with envia.com allows you to generate individual or multiple quotes and shipping labels for your orders. You may also handle orders through envia.com in the Ecommerce Pro section.
== Screenshots ==
==Changelog==
= 5.0 =
* New: Interactive pickup map (Leaflet.js + OpenStreetMap) for block checkout, allowing customers to visually browse and select pickup branches directly on a map.
* New: Pickup display mode selector for block checkout — store owners can now choose between "List only" (WooCommerce standard), "Map only", or "Map and list" via the admin settings.
* New: When "Map only" is selected, the native WooCommerce pickup list is automatically hidden so only the map is shown.
* New: Loading indicator on the pickup map while shipping totals recalculate after a branch or rate selection, hiding automatically when the checkout finishes processing.
* New: Admin settings fields (displayPickUp, displayPickUpBlock, maxPickupRatesPerService) are dynamically disabled in real time when the "Pickup rates options" checkbox is unchecked, without requiring a page save.
* Fix: Pickup options are now correctly displayed when the cart uses block templates and the classic "Dropdown list" display mode is configured — falls back to the standard per-branch list automatically.
* Fix: AJAX URL construction in pickUpDestination.js now uses the server-side admin_url() value, resolving missing pickup options in subfolder WooCommerce installations.
* Fix: Prevented a data mutation bug in pickUpDestination.js where the ajaxurl was overwritten before the AJAX call could use it.
* Fix: useNewBlocks detection logic replaced with a dedicated uses_block_checkout() helper that correctly distinguishes block checkout from block cart across WooCommerce versions (pre/post 8.3).
* Update: "Pick up location format" setting renamed and split into separate controls for classic and block checkout templates.
* Update: Admin settings fields that are incompatible with the current cart/checkout template are visually marked as "* Not available" and grayed out.
* Update: Connection status indicator on the settings page now displays a colored badge — green when connected, red when disconnected.
* New: The plugin now supports translation (es_MX, en_US, pt_BR included).
* Update: The quotation request now sends the customer's locale using determine_locale().
= 4.5.1 =
* Fix: Fixed a fatal error when turning on the pickup option.
= 4.5 =
* New: Support for multiple stores in the same domain
* New: Max pickup options per rate setting to limit displayed pickup locations per shipping rate (1–10, default 5).
* Fix: Resolved "Undefined constant WC_VERSION" error when WooCommerce loads after the plugin (compatibility check).
* New: Pickup location is enabled automatically when the plugin is activated; respects seller preference when disabled during normal operation.
* Update: Pickup location enable logic runs only on plugin activation (deactivate + activate); no longer overrides when seller disables it in settings.
* Update: Tested with WooCommerce 10.4.3 and WordPress 6.9.1.
= 4.3.1 =
* New Enhanced compatibility with the new cart and checkout blocks mode in WC.
* Update Improved and adapted pick-up functionality for WC's new chart and checkout blocks.
* New Implemented Envía Pickup class to handle pickup rates as WC local pickup objects while maintaining classic shipping and local pickup processes in Envía Shipping class.
* Update Separated actioners (type Ajax and type hooks) and functions into distinct PHP files and class structures, including a dedicated class for legacy functions.
* Other Introduced namespaces and trait class declarations in classes, enhancing Object-Oriented Programming (OOP) practices and scalability.
= 4.2.5 =
* Fix: Incompatible file. 
* Fix: Oauth error message.
* Fix: Virtual and downloadable product error in finish order.   
* New: Version request header for Envia calls. 
* Depured notices and warnings.
= 4.2.4 =
* Fix: Validation of the GET request for origin addresses.
* Improvements in views for mobile devices (responsive mode).
* Correction of metadata handling for WooCommerce orders.
* Code optimization.
= 4.2.3 =
* Fix: Success in quoting process when applying discount codes at checkout.
* Fix: Page does not crash upon installing the plugin without having WooCommerce previously installed.
* Improvement in the user experience on the shipping address form.
* Improvement in the communication of pick-up locations (branch code) between the store and Envia.
= 4.2.2 =
* Functionality - 4.2.2: Improvements in user experience.
* Fix - 4.2.2: Adjustment for the checkout view in two-column templates.
= 4.2.1 =
* Fix - 4.2.1: Error in single order page
* Fix - 4.2.1: The iframe css not loading.
= 4.2.0 =
* Fix: The checkout section achieves full compatibility with all WooCommerce versions by providing users with the choice between a custom mode and a standard mode.
* Fix: The checkout process retrieves saved destination addresses.
* Fix: Enhancements to the checkout process for countries offering pick-up locations.
* Fix: Weight and shipping costs are excluded for digital products.
* Fix: The telephone field accepts entries with 7 to 12 digits.
* New feature: Enhancement in order management by directing users to Envia's page.
* New feature: Multi-order management.
* Complete compatibility with WooCommerce's HPOs (High-Performance Options).
* Functionality without the need to be in debug mode.
* Improvement in checkout speed and performance.
* Improved security when accessing Envia.
* User interface enhancement. 
= 4.1.0 =
* Fix in labels option, now is possible disabled show label option without hidden the delivery time text.
* New feature: Now is possible use pickUp option in checkout shipping rates as destination address. 
* New feature: When gets the origin addresses from envia.com, now only get the addresses linked to store. (The addresses that you can see in ecommerce settings from envia.com page).
* New feature: Creates and saves a new origin addresses to envia.com since woocommerce pluging page, you can see the new address saved in ecommece settings from envia.com page.
* Fixes bugs and uncaught errors.
== Upgrade Notice ==
= 4.3.1 =
Introduces enhanced compatibility with new cart and checkout blocks, improved pick-up functionality, implementation of Envía Pickup class for WC local pickup objects, separated actioners and functions into distinct PHP files, and introduced namespaces and trait class declarations for better OOP practices and scalability.