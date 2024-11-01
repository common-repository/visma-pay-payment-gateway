<?php
/**
 * Plugin Name: Visma Pay Payment Gateway
 * Plugin URI: https://www.vismapay.com/docs
 * Description: Visma Pay Payment Gateway Integration for Woocommerce
 * Version: 1.1.5
 * Author: Visma
 * Author URI: https://www.visma.fi/vismapay/
 * Text Domain: visma-pay-payment-gateway
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 9.1.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action('plugins_loaded', 'init_visma_pay_gateway', 0);

function woocommerce_add_WC_Gateway_Visma_Pay($methods)
{
	$methods[] = 'WC_Gateway_Visma_Pay';
	return $methods;
}

add_filter('woocommerce_payment_gateways', 'woocommerce_add_WC_Gateway_Visma_Pay');

function woocommerce_register_WC_Gateway_Visma_Pay_Blocks_Support()
{
	if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
		require_once 'includes/blocks/visma_pay_blocks_support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $paymentMethodRegistry) {
				$paymentMethodRegistry->register(new WC_Gateway_Visma_Pay_Blocks_Support);
			}
		);
	}
}

add_action('woocommerce_blocks_loaded', 'woocommerce_register_WC_Gateway_Visma_Pay_Blocks_Support');

function init_visma_pay_gateway()
{
	load_plugin_textdomain('visma-pay-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages/' );

	if(!class_exists('WC_Payment_Gateway'))
		return;

	class WC_Gateway_Visma_Pay extends WC_Payment_Gateway
	{
		protected $api_key;
		protected $private_key;
		protected $ordernumber_prefix;
		protected $payment_description;
		protected $banks;
		protected $wallets;
		protected $ccards;
		protected $cinvoices;
		protected $laskuyritykselle;
		protected $send_items;
		protected $send_receipt;
		protected $embed;
		protected $cancel_url;
		protected $limit_currencies;
		protected $logger;
		protected $logcontext;

		function __construct()
		{
			$this->id = 'visma_pay';
			$this->has_fields = true;
			$this->method_title = __( 'Visma Pay', 'visma-pay-payment-gateway' );
			$this->method_description = __( 'Visma Pay Payment API integration for Woocommerce', 'visma-pay-payment-gateway' );

			$this->init_form_fields();
			$this->init_settings();

			$this->enabled = $this->settings['enabled'];
			$this->title = $this->get_option('title');

			$this->api_key = $this->get_option('api_key');
			$this->private_key = $this->get_option('private_key');

			$this->ordernumber_prefix = $this->get_option('ordernumber_prefix');
			$this->description = $this->get_option('visma_pay_description');
			$this->payment_description = $this->get_option('visma_pay_payment_description');

			$this->banks = $this->get_option('banks');
			$this->wallets = $this->get_option('wallets');
			$this->ccards = $this->get_option('ccards');
			$this->cinvoices = $this->get_option('cinvoices');
			$this->laskuyritykselle = $this->get_option('laskuyritykselle');

			$this->send_items = $this->get_option('send_items');
			$this->send_receipt = $this->get_option('send_receipt');
			$this->embed = $this->get_option('embed');

			$this->cancel_url = $this->get_option('cancel_url');
			$this->limit_currencies = $this->get_option('limit_currencies');

			$this->logger = wc_get_logger();
			$this->logcontext = array('source' => 'visma-pay-payment-gateway');

			add_action('wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options' ) );
			add_action('woocommerce_api_wc_gateway_visma_pay', array($this, 'check_visma_pay_response' ) );
			add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'visma_pay_settle_payment'), 1, 1);

			if(!$this->is_valid_currency() && $this->limit_currencies == 'yes')
				$this->enabled = false;
		}

		function is_embed() {
			return $this->embed == 'yes';
		}

		static function plugin_url()
		{
			return untrailingslashit(plugins_url( '/', __FILE__ ));
		}
	
		static function plugin_abspath()
		{
			return trailingslashit(plugin_dir_path( __FILE__ ));
		}

		function is_valid_currency()
		{	
			return in_array(get_woocommerce_currency(), array('EUR'));
		}

		function payment_scripts() {
			if (!is_checkout())
				return;

			// CSS Styles
			wp_enqueue_style( 'woocommerce_visma_pay', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/css/vismapay.css', '', '', 'all');
			// JS SCRIPTS
			wp_enqueue_script( 'woocommerce_visma_pay', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/js/vismapay.js', array( 'jquery' ), '', true );
		}

		function init_form_fields()
		{
			$this->form_fields = array(
				'general' => array(
					'title' => __( 'General options', 'visma-pay-payment-gateway' ),
					'type' => 'title',
					'description' => '',
				),
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Visma Pay', 'visma-pay-payment-gateway' ),					
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'visma-pay-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'visma-pay-payment-gateway' ),
					'default' => __( 'Visma Pay', 'visma-pay-payment-gateway' )
				),
				'visma_pay_description' => array(
					'title' => __( 'Description', 'visma-pay-payment-gateway' ),
					'description' => __( 'This controls the first part of the description which the user sees during checkout.', 'visma-pay-payment-gateway' ),
					'type' => 'textarea',
					'default' => __( 'Pay safely with Finnish internet banking, payment cards, wallet services or credit invoices.', 'visma-pay-payment-gateway')
				),
				'visma_pay_payment_description' => array(
					'title' => __( 'Second part of description', 'visma-pay-payment-gateway' ),
					'description' => __( 'This controls the second part of the description which the user sees during checkout.', 'visma-pay-payment-gateway' ),
					'type' => 'textarea',
					'default' => __( 'Choose your payment method and click Pay for Order', 'visma-pay-payment-gateway' )
				),				
				'private_key' => array(
					'title' => __( 'Private key', 'visma-pay-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'Private key of the sub-merchant', 'visma-pay-payment-gateway' ),
					'default' => ''
				),
				'api_key' => array(
					'title' => __( 'API key', 'visma-pay-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'API key of the sub-merchant', 'visma-pay-payment-gateway' ),
					'default' => ''
				),
				'ordernumber_prefix' => array(
					'title' => __( 'Order number prefix', 'visma-pay-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'Prefix to avoid order number duplication', 'visma-pay-payment-gateway' ),
					'default' => ''
				),
				'send_items' => array(
					'title' => __( 'Send products', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Send product breakdown to Visma Pay.", 'visma-pay-payment-gateway' ),
					'default' => 'yes'
				),
				'send_receipt' => array(
					'title' => __( 'Send payment confirmation', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Send Visma Pay's payment confirmation email to the customer's billing e-mail.", 'visma-pay-payment-gateway' ),
					'default' => 'yes',
				)
			);

			if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>='))
			{
				$this->form_fields['cancel_url'] = array(
					'title' => __( 'Cancel Page', 'visma-pay-payment-gateway' ),
					'type' => 'select',
					'description' => 
						__( 'Choose the page where the customer is redirected after a canceled/failed payment.', 'visma-pay-payment-gateway' ) . '<br>'.
						' - ' . __( 'Order Received: Shows the customer information about their order and a notice that the payment failed. Customer has an opportunity to try payment again.', 'visma-pay-payment-gateway' ) . '<br>'.
						' - ' .__( 'Pay for Order: Returns user to a page where they can try to pay their unpaid order again. ', 'visma-pay-payment-gateway' ) . '<br>'.
						' - ' .__( 'Cart: Customer is redirected back to the shopping cart.' , 'visma-pay-payment-gateway' ) . '<br>'.
						' - ' .__( 'Checkout: Customer is redirected back to the checkout.', 'visma-pay-payment-gateway' ) . '<br>'.
						'<br>' .__( '(When using Cart or Checkout as the return page for failed orders, the customer\'s cart will not be emptied during checkout.)', 'visma-pay-payment-gateway' ),
					'default' => 'order_received',
					'options' => array(
						'order_received' => __('Order Received', 'visma-pay-payment-gateway'),
						'order_pay' => __('Pay for Order', 'visma-pay-payment-gateway'),
						'order_new_cart' => __('Cart', 'visma-pay-payment-gateway'),
						'order_new_checkout' => __('Checkout', 'visma-pay-payment-gateway')
					)
				);
			}

			$this->form_fields = array_merge($this->form_fields, array(
				'limit_currencies' => array(
					'title' => __( 'Only allow payments in EUR', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Enable this option if you want to allow payments only in EUR.", 'visma-pay-payment-gateway' ),
					'default' => 'yes',
				),
				'embed' => array(
					'title' => __( 'Enable payment method embedding', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Enable this if you want to embed the payment methods to the checkout-page.", 'visma-pay-payment-gateway' ),
					'default' => 'yes',
					'checkboxgroup'	=> 'start',
					'show_if_checked' => 'option'
				),
				'limit_options' => array(
					'title' => __( 'Manage payment methods', 'visma-pay-payment-gateway' ),
					'type' => 'title',
					'description' => '',
				),
				'banks' => array(
					'title' => __( 'Banks', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable bank payments in the Visma Pay payment page.', 'visma-pay-payment-gateway' ),
					'default' => 'yes'
				),
				'wallets' => array(
					'title' => __( 'Wallets', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable wallet services in the Visma Pay payment page.', 'visma-pay-payment-gateway' ),
					'default' => 'yes'
				),
				'ccards' => array(
					'title' => __( 'Card payments', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable credit cards in the Visma Pay payment page.', 'visma-pay-payment-gateway' ),
					'default' => 'yes'
				),
				'cinvoices' => array(
					'title' => __( 'Credit invoices', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable credit invoices in the Visma Pay payment page.', 'visma-pay-payment-gateway' ),
					'default' => 'yes'
				),
				'laskuyritykselle' => array(
					'title' => __( 'Alisa Yrityslasku', 'visma-pay-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Alisa Yrityslasku in the Visma Pay payment page.', 'visma-pay-payment-gateway' ),
					'default' => 'no'
				)
			));
		}

		function payment_fields()
		{
			if($this->embed == 'yes')
			{
				$creditcards = $banks = $creditinvoices = $wallets = '';

				// disabled/unavailable methods are filtered at this point
				$payment_methods = $this->visma_pay_get_merchant_payment_methods();

				if(is_array($payment_methods) && count($payment_methods) > 0)
				{
					foreach ($payment_methods as $method)
					{
						if($method->group == 'creditcards')
						{
							$creditcards .= '<div id="visma-pay-button-creditcards" class="bank-button"><img alt="' . $method->name . '" src="' . $method->img_src . '"/></div>';
						}
						else if($method->group == 'wallets')
						{
							$wallets .= '<div id="visma-pay-button-' . $method->selected_value . '" class="bank-button"><img alt="' . $method->name . '" src="' . $method->img_src . '"/></div>';
						}
						else if($method->group == 'banks')
						{
							$banks .= '<div id="visma-pay-button-' . $method->selected_value . '" class="bank-button"><img alt="' . $method->name . '" src="' . $method->img_src . '"/></div>';
						}
						else if($method->group == 'creditinvoices')
						{
							if($method->selected_value == 'laskuyritykselle')
							{
								$creditinvoices .= '<div id="visma-pay-button-' . $method->selected_value . '" class="bank-button"><img alt="' . $method->name . '" src="' . $method->img_src . '"/></div>';
							}
							else
							{
								$creditinvoices .= '<div id="visma-pay-button-' . $method->selected_value . '" class="bank-button"><img alt="' . $method->name . '" src="' . $method->img_src . '"/></div>';
							}
						}
					}
				}

				if(empty($creditcards) && empty($banks) && empty($creditinvoices) && empty($wallets))
				{
					echo '<div class="woocommerce-error"><strong>' . esc_html(__('No payment methods available for the currency: ', 'visma-pay-payment-gateway') . get_woocommerce_currency()) . '</strong></div>';
				}
				else
				{
					if (!empty($this->description))
						echo wpautop(wptexturize($this->description));
					if (!empty($this->payment_description))
						echo wpautop(wptexturize($this->payment_description));
				}

				$clear_both = '<div style="display: block; clear: both;"></div>';
				
				echo '<div id="visma-pay-bank-payments">';
				if($banks != '')
					echo '<div>'.wpautop(wptexturize(__( 'Internet banking', 'visma-pay-payment-gateway' ))) . $banks . '</div>' . $clear_both;

				if($creditcards != '')
					echo '<div>'.wpautop(wptexturize(__( 'Payment card', 'visma-pay-payment-gateway' ))) . $creditcards . '</div>' . $clear_both;

				if($wallets != '')
					echo '<div>'.wpautop(wptexturize(__( 'Wallet services', 'visma-pay-payment-gateway' ))) . $wallets . '</div>' . $clear_both;

				if($creditinvoices != '')
					echo '<div>'.wpautop(wptexturize(__( 'Invoice or part payment', 'visma-pay-payment-gateway' ))) . $creditinvoices . '</div>' . $clear_both;

				echo '</div>';

				echo '<div id="visma_pay_bank_checkout_fields" style="display: none;">';
				echo '<input id="visma_pay_selected_bank" class="input-hidden" type="hidden" name="visma_pay_selected_bank" />';
				echo '</div>';
			}
			else if(!empty($this->description)) //Non embed
				echo wpautop(wptexturize($this->description));
		}

		function visma_pay_get_merchant_payment_methods()
		{
			include(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');
			$visma_pay = new Visma\VismaPay($this->api_key, $this->private_key);

			try
			{
				$response = $visma_pay->getMerchantPaymentMethods(get_woocommerce_currency());

				if($response->result == 0)
				{
					if(count($response->payment_methods) > 0)
					{
						foreach ($response->payment_methods as $method)
						{
							$key = $method->selected_value;
							if($method->group == 'creditcards')
								$key = strtolower($method->name);

							$img = $this->visma_pay_save_img($key, $method->img, $method->img_timestamp);
							$method->img_src = $img;
						}

						$filtered_methods = $this->visma_pay_filter_payment_methods($response->payment_methods);
						return $filtered_methods;
					}
				}
			}
			catch (Visma\VismaPayException $e)
			{
				$this->logger->error('Visma Pay REST::getMerchantPaymentMethods failed, exception: ' . $e->getCode().' '.$e->getMessage(), $this->logcontext);
			}

			return false;
		}

		function visma_pay_filter_payment_methods($payment_methods)
		{
			$filtered_methods = [];
			$plugin_url = untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))) . '/';

			$total = 0;
			
			$wc_cart_total = WC()->cart->total;
			$cart_total = (int)(round($wc_cart_total*100, 0));

			if(get_query_var('order-pay') != '')
			{
				$order = new WC_Order(get_query_var('order-pay'));
				$wc_order_total = $order->get_total();
				$total = (int)(round($wc_order_total*100, 0));
			}

			foreach ($payment_methods as $method)
			{
				if($method->group == 'creditcards'  && $this->ccards == 'no')
				{
					continue;
				}
				else if($method->group == 'wallets' && $this->wallets == 'no')
				{
					continue;
				}
				else if($method->group == 'banks' && $this->banks == 'no')
				{
					continue;
				}
				else if($method->group == 'creditinvoices')
				{
					if (isset($order))
					{
						if ($total < $method->min_amount || $total > $method->max_amount)
							continue;
					}
					else if ($cart_total < $method->min_amount || $cart_total > $method->max_amount)
					{
						continue;
					}

					if($method->selected_value == 'laskuyritykselle')
					{
						if($this->laskuyritykselle == 'no')
						{
							continue;
						}
					}
					else if($this->cinvoices == 'no')
					{
						continue;
					}
				}

				// when using WC Blocks, these will be sent to frontend scripts
				$method->selected_value = esc_attr($method->selected_value);
				$method->name = esc_attr($method->name);
				$method->img_src = esc_url($plugin_url.$method->img_src);
				
				unset($method->min_amount);
				unset($method->max_amount);
				unset($method->img_timestamp);
				unset($method->img);

				array_push($filtered_methods, $method);
			}
			return $filtered_methods;
		}

		function process_payment($order_id)
		{
			if (sanitize_key($_POST['payment_method']) != 'visma_pay')
				return false;

			$return = array(
				'result' => 'failure',
				'redirect' => '' // expected but not used in failure case
			);

			$order = new WC_Order($order_id);
			$wc_order_id = $order->get_id();
			$wc_order_total = $order->get_total();

			$wc_b_first_name = $order->get_billing_first_name();
			$wc_b_last_name = $order->get_billing_last_name();
			$wc_b_email = $order->get_billing_email();
			$wc_b_address_1 = $order->get_billing_address_1();
			$wc_b_address_2 = $order->get_billing_address_2();
			$wc_b_city = $order->get_billing_city();
			$wc_b_postcode = $order->get_billing_postcode();
			$wc_b_country = $order->get_billing_country();
			$wc_s_first_name = $order->get_shipping_first_name();
			$wc_s_last_name = $order->get_shipping_last_name();
			$wc_s_address_1 = $order->get_shipping_address_1();
			$wc_s_address_2 = $order->get_shipping_address_2();
			$wc_s_city = $order->get_shipping_city();
			$wc_s_postcode = $order->get_shipping_postcode();
			$wc_s_country = $order->get_shipping_country();
			$wc_b_phone = $order->get_billing_phone();

			$visma_pay_selected_bank = isset($_POST['visma_pay_selected_bank']) ? sanitize_text_field($_POST['visma_pay_selected_bank']) : '';

			$order_number = (strlen($this->ordernumber_prefix)  > 0) ?  $this->ordernumber_prefix. '_'  .$order_id : $order_id;
			$order_number .=  '-' . str_pad(time().rand(0,9999), 5, "1", STR_PAD_RIGHT);

			include_once(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');

			$redirect_url = $this->get_return_url($order);
			$return_url = add_query_arg( array('wc-api' => get_class( $this ) ,'order_id' => $order_id), $redirect_url );

			$amount =  (int)(round($wc_order_total*100, 0));

			$order->update_meta_data('_visma_pay_selected_bank_', $visma_pay_selected_bank);
			$order->update_meta_data('visma_pay_is_settled', 1);
			$order->update_meta_data('visma_pay_return_code', 99);
			$order->save();

			$finn_langs = array('fi-FI', 'fi', 'fi_FI');
			$sv_langs = array('sv-SE', 'sv', 'sv_SE');
			$current_locale = get_locale();

			if(in_array($current_locale, $finn_langs))
				$lang = 'fi';
			else if (in_array($current_locale, $sv_langs))
				$lang = 'sv';
			else
				$lang = 'en';

			$payment = new Visma\VismaPay($this->api_key, $this->private_key);

			if($this->send_receipt == 'yes')
				$receipt_mail = $wc_b_email;
			else
				$receipt_mail = '';

			$payment->addCharge(
				array(
					'order_number' => $order_number,
					'amount' => $amount,
					'currency' => get_woocommerce_currency(),
					'email' =>  $receipt_mail
				)
			);

			$payment->addCustomer(
				array(
					'firstname' => htmlspecialchars($wc_b_first_name),
					'lastname' => htmlspecialchars($wc_b_last_name),
					'email' => htmlspecialchars($wc_b_email),
					'address_street' => trim(htmlspecialchars($wc_b_address_1.' '.$wc_b_address_2)),
					'address_city' => htmlspecialchars($wc_b_city),
					'address_zip' => htmlspecialchars($wc_b_postcode),
					'address_country' => htmlspecialchars($wc_b_country),
					'shipping_firstname' => htmlspecialchars($wc_s_first_name),
					'shipping_lastname' => htmlspecialchars($wc_s_last_name),
					'shipping_address_street' => trim(htmlspecialchars($wc_s_address_1.' '.$wc_s_address_2)),
					'shipping_address_city' => htmlspecialchars($wc_s_city),
					'shipping_address_zip' => htmlspecialchars($wc_s_postcode),
					'shipping_address_country' => htmlspecialchars($wc_s_country),
					'phone' => preg_replace('/[^0-9+ ]/', '', $wc_b_phone)
				)
			);

			$products = array();
			$total_amount = 0;
			$order_items = $order->get_items();
			foreach($order_items as $item) {
				$tax_rates = WC_Tax::get_rates($item->get_tax_class());
				if(!empty($tax_rates))
				{
					$tax_rate = reset($tax_rates);
					$line_tax = number_format($tax_rate['rate'], 2, '.', '');
				}
				else
				{
					$i_total = $order->get_item_total($item, false, false);
					$i_tax = $order->get_item_tax($item, false);
					$line_tax = ($i_total > 0) ? number_format($i_tax / $i_total * 100, 2, '.', '') : 0;
				}

				$product = array(
					'title' => $item['name'],
					'id' => $item['product_id'],
					'count' => $item['qty'],
					'pretax_price' => (int)(round(($item['line_total']/$item['qty'])*100, 0)),
					'price' => (int)(round((($item['line_total'] + $item['line_tax'] ) / $item['qty'])*100, 0)),
					'tax' => $line_tax,
					'type' => 1
				);

				$total_amount += $product['price'] * $product['count'];
				array_push($products, $product);
		 	}

			$shipping_items = $order->get_items( 'shipping' );
			foreach ($shipping_items as $item_id => $s_item) {
				$s_total = floatval($s_item->get_total());

				if($s_total > 0)
				{
					$tax_data = $s_item->get_taxes();
					$s_total_tax = 0;

					if(!empty($tax_data['total']))
					{
						foreach ($tax_data['total'] as $tax_amount) {
							$s_total_tax += (float) $tax_amount;
						}
					}

					$s_tax_rate = $s_total_tax / $s_total * 100;

					$product = array(
						'title' => $s_item->get_name(),
						'id' => $s_item->get_method_id(),
						'count' => 1,
						'pretax_price' => round($s_total * 100),
						'price' => round(($s_total + $s_total_tax) * 100),
						'tax' => round($s_tax_rate, 1),
						'type' => 2
					);

					$total_amount += $product['price'] * $product['count'];
					array_push($products, $product);
				}
			}

			if($this->send_items == 'yes' && abs($total_amount - $amount) < 9)
			{
				foreach($products as $product)
				{
					$payment->addProduct(
						array(
							'id' => htmlspecialchars($product['id']),
							'title' => htmlspecialchars($product['title']),
							'count' => $product['count'],
							'pretax_price' => $product['pretax_price'],
							'tax' => $product['tax'],
							'price' => $product['price'],
							'type' => $product['type']
						)
					);
				}
			}

			$vp_selected = '';

			if($this->embed == 'yes' && !empty($visma_pay_selected_bank))
				$vp_selected = array($visma_pay_selected_bank);
			else
			{
				$vp_selected = array();
				if($this->is_valid_currency())
				{
					if($this->banks == 'yes')
						$vp_selected[] = 'banks';
					if($this->wallets == 'yes')
						$vp_selected[] = 'wallets';
					if($this->ccards == 'yes')
						$vp_selected[] = 'creditcards';
					if($this->cinvoices == 'yes')
						$vp_selected[] = 'creditinvoices';
					if($this->laskuyritykselle == 'yes')
						$vp_selected[] = 'laskuyritykselle';
				}
				else if($this->limit_currencies == 'no')
				{
					include(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');
					$payment_methods = new Visma\VismaPay($this->api_key, $this->private_key);
					try
					{
						$response = $payment_methods->getMerchantPaymentMethods(get_woocommerce_currency());

						if($response->result == 0)
						{
							if(count($response->payment_methods) > 0)
							{
								foreach ($response->payment_methods as $method)
								{
									$key = $method->selected_value;
									if($method->group == 'creditcards')
										$key = strtolower($method->name);

									if($method->group == 'creditcards'  && $this->ccards == 'yes')
									{
										$vp_selected[] = $method->group; //creditcards
									}
									else if($method->group == 'wallets' && $this->wallets == 'yes')
									{
										$vp_selected[] = $method->selected_value;
									}
									else if($method->group == 'banks' && $this->banks == 'yes')
									{
										$vp_selected[] = $method->selected_value;
									}
									else if($method->group == 'creditinvoices')
									{
										if($method->selected_value == 'laskuyritykselle' && ((!isset($order) && $cart_total >= $method->min_amount && $cart_total <= $method->max_amount) || ($total >= $method->min_amount && $total <= $method->max_amount)))
										{
											if($this->laskuyritykselle == 'yes')
											{
												$vp_selected[] = $method->selected_value;
											}
										} 
										else if($this->cinvoices == 'yes' && ((!isset($order) && $cart_total >= $method->min_amount && $cart_total <= $method->max_amount) || ($total >= $method->min_amount && $total <= $method->max_amount)))
										{
											$vp_selected[] = $method->selected_value;
										}
									}
								}
							}

							if(empty($vp_selected))
							{
								$this->logger->info('Visma Pay no payment methods available for order: ' . $order_number . ', currency: ' . get_woocommerce_currency(), $this->logcontext);
								wc_add_notice(__('Visma Pay: No payment methods available for the currency: ', 'visma-pay-payment-gateway') . get_woocommerce_currency(), 'error');
								$order_number_text = __('Visma Pay: No payment methods available for the currency: ', 'visma-pay-payment-gateway') .  get_woocommerce_currency();
								$order->add_order_note($order_number_text);
								return $return;
							}
						}
					}
					catch (Visma\VismaPayException $e) 
					{
						$this->logger->error('Visma Pay getMerchantPaymentMethods failed for order: ' . $order_number . ', exception: ' . $e->getCode().' '.$e->getMessage(), $this->logcontext);
					}
				}
				else
				{
					$error_text = __('Visma Pay: "Only allow payments in EUR" is enabled and currency was not EUR for order: ', 'visma-pay-payment-gateway');
					$this->logger->info($error_text, $this->logcontext);
					wc_add_notice(__('Visma Pay: No payment methods available for the currency: ', 'visma-pay-payment-gateway') . get_woocommerce_currency(), 'notice');
					$order->add_order_note($error_text . $order_number);
					return $return;
				}
			}

			$payment->addPaymentMethod(
				array(
					'type' => 'e-payment', 
					'return_url' => $return_url,
					'notify_url' => $return_url,
					'lang' => $lang,
					'selected' => $vp_selected,
					'token_valid_until' => strtotime('+1 hour')
				)
			);

			try
			{
				$response = $payment->createCharge();

				if($response->result == 0)
				{
					$order_number_text = __('Visma Pay order', 'visma-pay-payment-gateway') . ": " . $order_number . "<br>-<br>" . __('Payment pending. Waiting for result.', 'visma-pay-payment-gateway');
					$order->add_order_note($order_number_text);

					$order->update_meta_data('visma_pay_order_number', $order_number);
					$order_numbers = $order->get_meta('visma_pay_order_numbers', true, 'edit');
					$order_numbers = ($order_numbers) ? array_values($order_numbers) : array();
					$order_numbers[] = $order_number;
					$order->update_meta_data('visma_pay_order_numbers', $order_numbers);
					$order->save();

					$url = Visma\VismaPay::API_URL."/token/".$response->token;
					
					if(!in_array($this->cancel_url, array('order_new_cart', 'order_new_checkout')))
						WC()->cart->empty_cart();

					return array(
						'result'   => 'success',
						'redirect'  => $url
					);
				}
				else if($response->result == 10)
				{
					$errors = '';
					wc_add_notice(__('Visma Pay system is currently in maintenance. Please try again in a few minutes.', 'visma-pay-payment-gateway'), 'notice');
					$this->logger->info('Visma Pay REST::CreateCharge. Visma Pay system maintenance in progress.', $this->logcontext);
					return $return;
				}
				else
				{
					$errors = '';
					wc_add_notice(__('Payment failed due to an error.', 'visma-pay-payment-gateway'), 'error');
					if(isset($response->errors))
					{
						foreach ($response->errors as $error) 
						{
							$errors .= ' '.$error;
						}
					}
					$this->logger->error('Visma Pay REST::CreateCharge failed, response: ' . $response->result . ' - Errors:'.$errors, $this->logcontext);
					return $return;
				}
			}
			catch (Visma\VismaPayException $e) 
			{
				wc_add_notice(__('Payment failed due to an error.', 'visma-pay-payment-gateway'), 'error');
				$this->logger->error('Visma Pay REST::CreateCharge failed, exception: ' . $e->getCode().' '.$e->getMessage(), $this->logcontext);
				return $return;
			}
		}

		function get_order_by_id_and_order_number($order_id, $order_number)
		{
			$order = New WC_Order($order_id);
			$order_numbers = $order->get_meta('visma_pay_order_numbers', true, 'edit');

			if(!$order_numbers)
			{
				$current_order_number = $order->get_meta('visma_pay_order_number', true, 'edit');
				$order_numbers = array($current_order_number);
			}

			if(in_array($order_number, $order_numbers, true));
				return $order;

			return null;
		}

		protected function sanitize_visma_pay_order_number($order_number)
		{
			if (function_exists('mb_ereg_replace')) {
				return mb_ereg_replace('/[^\-\p{L}\p{N}_\s@&\/\\()?!=+£$€.,;:*%]/', '', $order_number);
			}
			return preg_replace('/[^\-\p{L}\p{N}_\s@&\/\\()?!=+£$€.,;:*%]/', '', $order_number);
		}

		function check_visma_pay_response()
		{
			if(count($_GET))
			{
				$return_code = isset($_GET['RETURN_CODE']) ? sanitize_text_field($_GET['RETURN_CODE']) : -999;
				$incident_id = isset($_GET['INCIDENT_ID']) ? sanitize_text_field($_GET['INCIDENT_ID']) : null;
				$settled = isset($_GET['SETTLED']) ? sanitize_text_field($_GET['SETTLED']) : null;
				$authcode = isset($_GET['AUTHCODE']) ? sanitize_text_field($_GET['AUTHCODE']) : null;
				$contact_id = isset($_GET['CONTACT_ID']) ? sanitize_text_field($_GET['CONTACT_ID']) : null;
				$order_number = isset($_GET['ORDER_NUMBER']) ? $this->sanitize_visma_pay_order_number($_GET['ORDER_NUMBER']) : null;

				$authcode_confirm = $return_code .'|'. $order_number;

				if(isset($return_code) && $return_code == 0)
				{
					$authcode_confirm .= '|' . $settled;
					if(isset($contact_id) && !empty($contact_id))
						$authcode_confirm .= '|' . $contact_id;
				}
				else if(isset($incident_id) && !empty($incident_id))
					$authcode_confirm .= '|' . $incident_id;

				$authcode_confirm = strtoupper(hash_hmac('sha256', $authcode_confirm, $this->private_key));

				$order_id = isset($_GET['order_id']) ? sanitize_text_field($_GET['order_id']) : null;

				if($order_id === null || $order_number === null)
					$this->visma_pay_die("No order_id nor order_number given.");

				$order = $this->get_order_by_id_and_order_number($order_id, $order_number);
				
				if($order === null)
					$this->visma_pay_die("Order not found.");

				$wc_order_id = $order->get_id();
				$wc_order_status = $order->get_status();

				if($authcode_confirm === $authcode && $order)
				{
					$current_return_code = $order->get_meta('visma_pay_return_code', true, 'edit');

					if(!$order->is_paid() && $current_return_code != 0)
					{
						$pbw_extra_info = '';

						include_once(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');
						$payment = new Visma\VismaPay($this->api_key, $this->private_key);
						try
						{
							$result = $payment->checkStatusWithOrderNumber($order_number);
							if(isset($result->source->object) && $result->source->object === 'card')
							{
								$pbw_extra_info .=  "<br>-<br>" . __('Payment method: Card payment', 'visma-pay-payment-gateway') . "<br>";
								$pbw_extra_info .=  "<br>-<br>" . __('Card payment info: ', 'visma-pay-payment-gateway') . "<br>";

								if(isset($result->source->card_verified))
								{
									$pbw_verified = $this->visma_pay_translate_verified_code($result->source->card_verified);
									$pbw_extra_info .= isset($pbw_verified) ? __('Verified: ', 'visma-pay-payment-gateway') . $pbw_verified . "<br>" : '';
								}

								$pbw_extra_info .= isset($result->source->card_country) ? __('Card country: ', 'visma-pay-payment-gateway') . $result->source->card_country . "<br>" : '';
								$pbw_extra_info .= isset($result->source->client_ip_country) ? __('Client IP country: ', 'visma-pay-payment-gateway') . $result->source->client_ip_country . "<br>" : '';

								if(isset($result->source->error_code))
								{
									$pbw_error = $this->visma_pay_translate_error_code($result->source->error_code);
									$pbw_extra_info .= isset($pbw_error) ? __('Error: ', 'visma-pay-payment-gateway') . $pbw_error . "<br>" : '';
								}								
							}
							elseif (isset($result->source->brand))
								$pbw_extra_info .=  "<br>-<br>" . __('Payment method: ', 'visma-pay-payment-gateway') . ' ' . $result->source->brand . "<br>";
						}
						catch(Visma\VismaPayException $e)
						{
							$message = $e->getMessage();
							$this->logger->error('Visma Pay REST::checkStatusWithOrderNumber failed, message: ' . $message, $this->logcontext );
						}

						switch($return_code)
						{							
							case 0:
								if($settled == 0)
								{
									$is_settled = 0;
									$order->update_meta_data('visma_pay_is_settled', $is_settled);
									$order->save();
									$pbw_note = __('Visma Pay order', 'visma-pay-payment-gateway') . ' ' . $order_number . "<br>-<br>" . __('Payment is authorized. Use settle option to capture funds.', 'visma-pay-payment-gateway') . "<br>";
								}
								else
									$pbw_note = __('Visma Pay order', 'visma-pay-payment-gateway') . ' ' . $order_number . "<br>-<br>" . __('Payment accepted.', 'visma-pay-payment-gateway') . "<br>";


								$order->update_meta_data('visma_pay_order_number', $order_number);
								$order->save();

								$order->add_order_note($pbw_note . $pbw_extra_info);
								$order->payment_complete();
								WC()->cart->empty_cart();
								break;

							case 1:
								$pbw_note = __('Payment was not accepted.', 'visma-pay-payment-gateway') . $pbw_extra_info;
								if($wc_order_status == 'failed')
									$order->add_order_note($pbw_note);
								else
									$order->update_status('failed', $pbw_note);
								break;

							case 4:
								$note = __('Transaction status could not be updated after customer returned from the web page of a bank. Please use the merchant UI to resolve the payment status.', 'visma-pay-payment-gateway');
								if($wc_order_status == 'failed')
									$order->add_order_note($note);
								else
									$order->update_status('failed', $note);
								break;

							case 10:
								$note = __('Maintenance break. The transaction is not created and the user has been notified and transferred back to the cancel address.', 'visma-pay-payment-gateway');
								if($wc_order_status == 'failed')
									$order->add_order_note($note);
								else
									$order->update_status('failed', $note);
								break;
						}

						$order->update_meta_data('visma_pay_return_code', $return_code);
						$order->save();
					}
				}
				else
					$this->visma_pay_die("MAC check failed");

				$cancel_url_option = $this->get_option('cancel_url', '');
				$card = (isset($result->source->object) && $result->source->object === 'card') ? true : false;
				$redirect_url = $this->visma_pay_url($return_code, $order, $cancel_url_option, $card);
				wp_redirect($redirect_url);
				exit('Ok');
			}
		}

		function visma_pay_url($return_code, $order, $cancel_url_option = '', $card = false)
		{
			if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>='))
			{
				if($return_code == 0)
					$redirect_url = $this->get_return_url($order);
				else
				{
					if($card)
						$error_msg = __('Card payment failed. Your card has not been charged.', 'visma-pay-payment-gateway');
					else
						$error_msg = __('Payment was canceled or charge was not accepted.', 'visma-pay-payment-gateway');
					switch ($cancel_url_option)
					{
						case 'order_pay':
							do_action( 'woocommerce_set_cart_cookies',  true );
							$redirect_url = $order->get_checkout_payment_url();
							break;
						case 'order_new_cart':
							$redirect_url = wc_get_cart_url();
							break;
						case 'order_new_checkout':
							$redirect_url = wc_get_checkout_url();
							break;
						default:
							do_action( 'woocommerce_set_cart_cookies',  true );
							$redirect_url = $this->get_return_url($order);
							break;
					}
					wc_add_notice($error_msg, 'error');
				}
			}
			else
				$redirect_url = (get_option('woocommerce_thanks_page_id') != '' ) ? get_permalink(get_option('woocommerce_thanks_page_id')): get_site_url().'/' ;
			
			return $redirect_url;
		}

		function visma_pay_settle_payment($order)
		{
			$wc_order_id = $order->get_id();			
			$settle_field = $order->get_meta('visma_pay_is_settled', true, 'edit');
			$settle_check = empty($settle_field) && $settle_field == "0";

			if(!$settle_check)
				return;

			$url = admin_url('post.php?post=' . absint( $wc_order_id ) . '&action=edit');

			if(isset($_GET['visma_pay_settle']))
			{
				$order_number = $order->get_meta('visma_pay_order_number', true, 'edit');
				$settlement_msg = '';

				if($this->process_settlement($order_number, $settlement_msg))
				{
					$order->add_order_note(__('Payment settled.', 'visma-pay-payment-gateway'));
					$order->update_meta_data('visma_pay_is_settled', 1);
					$order->save();
					$settlement_result = '1';
				}
				else
					$settlement_result = '0';

				if(!$settlement_result)
					echo '<div id="message" class="error">' . esc_html($settlement_msg) . ' <p class="form-field"><a href="' . esc_url($url) . '" class="button button-primary">OK</a></p></div>';
				else
				{
					echo '<div id="message" class="updated fade">' . esc_html($settlement_msg) . ' <p class="form-field"><a href="' . esc_url($url) . '" class="button button-primary">OK</a></p></div>';
					return;
				}
			}


			$text = __('Settle payment', 'visma-pay-payment-gateway');
			$url .= '&visma_pay_settle';
			$html = '
				<p class="form-field">
					<a href="' . esc_url($url) . '" class="button button-primary">' . esc_html($text) . '</a>
				</p>';

			echo $html;
		}

		function process_settlement($order_number, &$settlement_msg)
		{
			include(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');
			$successful = false;
			$payment = new Visma\VismaPay($this->api_key, $this->private_key);
			try
			{
				$settlement = $payment->settlePayment($order_number);
				$return_code = $settlement->result;

				switch ($return_code)
				{
					case 0:
						$successful = true;
						$settlement_msg = __('Settlement was successful.', 'visma-pay-payment-gateway');
						break;
					case 1:
						$settlement_msg = __('Settlement failed. Validation failed.', 'visma-pay-payment-gateway');
						break;
					case 2:
						$settlement_msg = __('Settlement failed. Either the payment has already been settled or the payment gateway refused to settle payment for given transaction.', 'visma-pay-payment-gateway');
						break;
					default:
						$settlement_msg = __('Settlement failed. Unknown error.', 'visma-pay-payment-gateway');
						break;
				}
			}
			catch (Visma\VismaPayException $e) 
			{
				$message = $e->getMessage();
				$settlement_msg = __('Exception, error: ', 'visma-pay-payment-gateway') . $message;
			}
			return $successful;
		}

		function visma_pay_save_img($key, $img_url, $img_timestamp)
		{
			$img = 'assets/images/'.$key.'.png';
			$timestamp = file_exists(plugin_dir_path( __FILE__ ) . $img) ? filemtime(plugin_dir_path( __FILE__ ) . $img) : 0;

			try
			{
				if(!file_exists(plugin_dir_path( __FILE__ ) . $img) || $img_timestamp > $timestamp)
				{
					if($file = @fopen($img_url, 'r'))
					{
						if(class_exists('finfo'))
						{
							$finfo = new finfo(FILEINFO_MIME_TYPE);
							if(strpos($finfo->buffer($file_content = stream_get_contents($file)), 'image') !== false)
							{
								@file_put_contents(plugin_dir_path( __FILE__ ) . $img, $file_content);
								touch(plugin_dir_path( __FILE__ ) . $img, $img_timestamp);
							}
						}
						else
						{
							@file_put_contents(plugin_dir_path( __FILE__ ) . $img, $file);
							touch(plugin_dir_path( __FILE__ ) . $img, $img_timestamp);
						}
						@fclose($file);
					}
				}
			}
			catch (Exception $e)
			{
				$this->logger->error('Visma Pay - failed to save new img: ' . $e->getCode().' '.$e->getMessage(), $this->logcontext);
			}

			return $img;
		}

		function visma_pay_translate_error_code($pbw_error_code)
		{
			switch ($pbw_error_code)
			{
				case '04':
					return ' 04 - ' . __('The card is reported lost or stolen.', 'visma-pay-payment-gateway');
				case '05':
					return ' 05 - ' . __('General decline. The card holder should contact the issuer to find out why the payment failed.', 'visma-pay-payment-gateway');
				case '51':
					return ' 51 - ' . __('Insufficient funds. The card holder should verify that there is balance on the account and the online payments are actived.', 'visma-pay-payment-gateway');
				case '54':
					return ' 54 - ' . __('Expired card.', 'visma-pay-payment-gateway');
				case '61':
					return ' 61 - ' . __('Withdrawal amount limit exceeded.', 'visma-pay-payment-gateway');
				case '62':
					return ' 62 - ' . __('Restricted card. The card holder should verify that the online payments are actived.', 'visma-pay-payment-gateway');
				case '1000':
					return ' 1000 - ' . __('Timeout communicating with the acquirer. The payment should be tried again later.', 'visma-pay-payment-gateway');
				default:
					return null;
			}
		}

		function visma_pay_translate_verified_code($pbw_verified_code)
		{
			switch ($pbw_verified_code)
			{
				case 'Y':
					return ' Y - ' . __('3-D Secure was used.', 'visma-pay-payment-gateway');
				case 'N':
					return ' N - ' . __('3-D Secure was not used.', 'visma-pay-payment-gateway');
				case 'A':
					return ' A - ' . __('3-D Secure was attempted but not supported by the card issuer or the card holder is not participating.', 'visma-pay-payment-gateway');
				default:
					return null;
			}
		}

		function visma_pay_die($msg = '')
		{
			$this->logger->error('Visma Pay - return failed. Error: ' . $msg, $this->logcontext );
			status_header(400);
			nocache_headers();
			die($msg);
		}
	}
}
