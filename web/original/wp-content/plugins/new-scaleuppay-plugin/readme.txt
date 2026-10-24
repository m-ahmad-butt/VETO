=== Cleffo ===
Contributors: Cleffo
Tags: WooCommerce, Payment Gateway, Cleffo
Requires at least: 3.5.1
Tested up to: 5.6
Stable tag: 4.3
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Cleffo is payment gateway that supports high risk businesses.

== Description ==

WooCommerce - The most user friendly e-commerce toolkit that helps you sell anything. Beautifully.
[Cleffo](https://payment.Cleffo.com.au "Get your free account") - Integrate it in 29 seconds. Easiest Payment Gateway system.


Visit [cleffo.com](https://cleffo.com?wordpress_plugin_visit=true " WooCommerce") for more info about this plugin.


Part 1 == Installation ==

1. Ensure you have latest version of WooCommerce plugin installed (WooCommerce 2.0+)
2. Unzip and upload contents of the plugin to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. set API keys and configure settings (See Configuration)

Part 2 == Configuration ==

1. Visit the `WooCommerce > Settings > Checkout` tab.
2. Click on *Cleffo* to edit the settings. If you do not see *Cleffo* in the list at the top of the screen make sure you have activated the plugin in the WordPress Plugin Manager.
3. Enable the Payment Method, name it `Credit Card / Debit Card ` (this will show up on the payment page your customer sees).
4. Add your API Keys (Title Description, Client key, Payment Mode, etc).
5. Define *Client key*. It is extremely important because Cleffo will use this to verify the payments on backend. This key is generated when the client is onboarded in the cleffo.com. See Part 3.
6. Select `Redirect url` (URL you want Cleffo to redirect after payment).
7. Click Save.


Part 3 == Configuration of the new client in Payments backend ==
1. Go to Config folder in the root directory. Open DataArray.php file. It contains the active clients. For each client one entry should be added in the $clients array with a unique client-key.
2. Here is the sample entry (same as current my_wp_website)
'my_wp_website' => [
    'test' => [
        'configs' => [
            'origin'=>'https://my_wp_website.com'
        ],
        'stripe' => [
            //MushroomPrints Stripe Account keys
            'pk' => 'pk_test_51.....7ov',
            'sk' => 'sk_test_51N.....wNmp'
        ]
    ],
    'live' => [
        'configs' => [
            'origin'=>'https://my_wp_website.com'
        ],
        'stripe' => [
            //MushroomPrints Stripe Account keys
            'pk' => 'pk_live_51NHH.......uiyu',
            'sk' => 'sk_live_51NHd......WDPJi'
        ]
    ],
    'status' => 'active'
]

3. In the example above, client key is - my_wp_website. The same should be used in the Step 5 of Part 2.
4. "origin" should be the website link where the checkout will happen. Here it is https://my_wp_website.com. in test and live mode both. It can be different if test and live websites are different.
5. "stripe" field of the test and live modes contain both the publishable key (pk) and secret key (sk) of the Stripe account in which the final payments are supposed to go.
6. "suap" keys are not being used currently. Parked for phase 2 where we will do client onboarding and their authenticaton.