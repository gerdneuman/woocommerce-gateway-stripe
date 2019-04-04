<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Stripe class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Stripe extends WC_Stripe_Payment_Gateway {
	/**
	 * The delay between retries.
	 *
	 * @var int
	 */
	public $retry_interval;

	/**
	 * Should we capture Credit cards
	 *
	 * @var bool
	 */
	public $capture;

	/**
	 * Alternate credit card statement name
	 *
	 * @var bool
	 */
	public $statement_descriptor;

	/**
	 * Checkout enabled
	 *
	 * @var bool
	 */
	public $stripe_checkout;

	/**
	 * Stripe Checkout description.
	 *
	 * @var string
	 */
	public $stripe_checkout_description;

	/**
	 * Credit card image
	 *
	 * @var string
	 */
	public $stripe_checkout_image;

	/**
	 * Should we store the users credit cards?
	 *
	 * @var bool
	 */
	public $saved_cards;

	/**
	 * API access secret key
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Api access publishable key
	 *
	 * @var string
	 */
	public $publishable_key;

	/**
	 * Do we accept Payment Request?
	 *
	 * @var bool
	 */
	public $payment_request;

	/**
	 * Is test mode active?
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * Inline CC form styling
	 *
	 * @var string
	 */
	public $inline_cc_form;

	/**
	 * Pre Orders Object
	 *
	 * @var object
	 */
	public $pre_orders;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->retry_interval = 1;
		$this->id             = 'stripe';
		$this->method_title   = __( 'Stripe', 'woocommerce-gateway-stripe' );
		/* translators: 1) link to Stripe register page 2) link to Stripe api keys page */
		$this->method_description = sprintf( __( 'Stripe works by adding payment fields on the checkout and then sending the details to Stripe for verification. <a href="%1$s" target="_blank">Sign up</a> for a Stripe account, and <a href="%2$s" target="_blank">get your Stripe account keys</a>.', 'woocommerce-gateway-stripe' ), 'https://dashboard.stripe.com/register', 'https://dashboard.stripe.com/account/apikeys' );
		$this->has_fields         = true;
		$this->supports           = array(
			'products',
			'refunds',
			'tokenization',
			'add_payment_method',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
			'pre-orders',
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Get setting values.
		$this->title                       = $this->get_option( 'title' );
		$this->description                 = $this->get_option( 'description' );
		$this->enabled                     = $this->get_option( 'enabled' );
		$this->testmode                    = 'yes' === $this->get_option( 'testmode' );
		$this->inline_cc_form              = 'yes' === $this->get_option( 'inline_cc_form' );
		$this->capture                     = 'yes' === $this->get_option( 'capture', 'yes' );
		$this->statement_descriptor        = WC_Stripe_Helper::clean_statement_descriptor( $this->get_option( 'statement_descriptor' ) );
		$this->stripe_checkout             = 'yes' === $this->get_option( 'stripe_checkout' );
		$this->stripe_checkout_image       = $this->get_option( 'stripe_checkout_image', '' );
		$this->stripe_checkout_description = $this->get_option( 'stripe_checkout_description' );
		$this->saved_cards                 = 'yes' === $this->get_option( 'saved_cards' );
		$this->secret_key                  = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );
		$this->publishable_key             = $this->testmode ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'publishable_key' );
		$this->payment_request             = 'yes' === $this->get_option( 'payment_request', 'yes' );

		if ( $this->stripe_checkout ) {
			$this->order_button_text = __( 'Continue to payment', 'woocommerce-gateway-stripe' );
		}

		WC_Stripe_API::set_secret_key( $this->secret_key );

		// Hooks.
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_fee' ) );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_payout' ), 20 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'show_update_card_notice' ), 10, 2 );
		add_action( 'woocommerce_receipt_stripe', array( $this, 'stripe_checkout_receipt_page' ) );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'stripe_checkout_return_handler' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'prepare_order_pay_page' ) );

		if ( WC_Stripe_Helper::is_pre_orders_exists() ) {
			$this->pre_orders = new WC_Stripe_Pre_Orders_Compat();

			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this->pre_orders, 'process_pre_order_release_payment' ) );
		}
	}

	/**
	 * Checks if keys are set.
	 *
	 * @since 4.0.6
	 * @return bool
	 */
	public function are_keys_set() {
		if ( empty( $this->secret_key ) || empty( $this->publishable_key ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks if gateway should be available to use.
	 *
	 * @since 4.0.2
	 */
	public function is_available() {
		if ( is_add_payment_method_page() && ! $this->saved_cards ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Adds a notice for customer when they update their billing address.
	 *
	 * @since 4.1.0
	 * @param int    $user_id      The ID of the current user.
	 * @param string $load_address The address to load.
	 */
	public function show_update_card_notice( $user_id, $load_address ) {
		if ( ! $this->saved_cards || ! WC_Stripe_Payment_Tokens::customer_has_saved_methods( $user_id ) || 'billing' !== $load_address ) {
			return;
		}

		/* translators: 1) Opening anchor tag 2) closing anchor tag */
		wc_add_notice( sprintf( __( 'If your billing address has been changed for saved payment methods, be sure to remove any %1$ssaved payment methods%2$s on file and re-add them.', 'woocommerce-gateway-stripe' ), '<a href="' . esc_url( wc_get_endpoint_url( 'payment-methods' ) ) . '" class="wc-stripe-update-card-notice" style="text-decoration:underline;">', '</a>' ), 'notice' );
	}

	/**
	 * Get_icon function.
	 *
	 * @since 1.0.0
	 * @version 4.0.0
	 * @return string
	 */
	public function get_icon() {
		$icons = $this->payment_icons();

		$icons_str = '';

		$icons_str .= isset( $icons['visa'] ) ? $icons['visa'] : '';
		$icons_str .= isset( $icons['amex'] ) ? $icons['amex'] : '';
		$icons_str .= isset( $icons['mastercard'] ) ? $icons['mastercard'] : '';

		if ( 'USD' === get_woocommerce_currency() ) {
			$icons_str .= isset( $icons['discover'] ) ? $icons['discover'] : '';
			$icons_str .= isset( $icons['jcb'] ) ? $icons['jcb'] : '';
			$icons_str .= isset( $icons['diners'] ) ? $icons['diners'] : '';
		}

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = require( dirname( __FILE__ ) . '/admin/stripe-settings.php' );

		if ( 'yes' === $this->get_option( 'three_d_secure' ) ) {
			if ( isset( $_REQUEST['stripe_dismiss_3ds'] ) && wp_verify_nonce( $_REQUEST['stripe_dismiss_3ds'], 'no-3ds' ) ) { // wpcs: sanitization ok.
				$this->update_option( '3ds_setting_notice_dismissed', true );
			}

			add_action( 'admin_notices', array( $this, 'display_three_d_secure_notice' ) );
		}
	}

	/**
	 * Payment form on checkout page
	 */
	public function payment_fields() {
		$user                 = wp_get_current_user();
		$display_tokenization = $this->supports( 'tokenization' ) && is_checkout() && $this->saved_cards;
		$total                = WC()->cart->total;
		$user_email           = '';
		$description          = $this->get_description();
		$description          = ! empty( $description ) ? $description : '';
		$firstname            = '';
		$lastname             = '';

		// If paying from order, we need to get total from order not cart.
		if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) { // wpcs: csrf ok.
			$order      = wc_get_order( wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) ) ); // wpcs: csrf ok, sanitization ok.
			$total      = $order->get_total();
			$user_email = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_email : $order->get_billing_email();
		} else {
			if ( $user->ID ) {
				$user_email = get_user_meta( $user->ID, 'billing_email', true );
				$user_email = $user_email ? $user_email : $user->user_email;
			}
		}

		if ( is_add_payment_method_page() ) {
			$pay_button_text = __( 'Add Card', 'woocommerce-gateway-stripe' );
			$total           = '';
			$firstname       = $user->user_firstname;
			$lastname        = $user->user_lastname;

		} elseif ( function_exists( 'wcs_order_contains_subscription' ) && isset( $_GET['change_payment_method'] ) ) { // wpcs: csrf ok.
			$pay_button_text = __( 'Change Payment Method', 'woocommerce-gateway-stripe' );
			$total           = '';
		} else {
			$pay_button_text = '';
		}

		ob_start();

		echo '<div
			id="stripe-payment-data"
			data-panel-label="' . esc_attr( $pay_button_text ) . '"
			data-description="' . esc_attr( wp_strip_all_tags( $this->stripe_checkout_description ) ) . '"
			data-email="' . esc_attr( $user_email ) . '"
			data-verify-zip="' . esc_attr( apply_filters( 'wc_stripe_checkout_verify_zip', false ) ? 'true' : 'false' ) . '"
			data-billing-address="' . esc_attr( apply_filters( 'wc_stripe_checkout_require_billing_address', false ) ? 'true' : 'false' ) . '"
			data-shipping-address="' . esc_attr( apply_filters( 'wc_stripe_checkout_require_shipping_address', false ) ? 'true' : 'false' ) . '"
			data-amount="' . esc_attr( WC_Stripe_Helper::get_stripe_amount( $total ) ) . '"
			data-name="' . esc_attr( $this->statement_descriptor ) . '"
			data-full-name="' . esc_attr( $firstname . ' ' . $lastname ) . '"
			data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '"
			data-image="' . esc_attr( $this->stripe_checkout_image ) . '"
			data-locale="' . esc_attr( apply_filters( 'wc_stripe_checkout_locale', $this->get_locale() ) ) . '"
			data-allow-remember-me="' . esc_attr( apply_filters( 'wc_stripe_allow_remember_me', true ) ? 'true' : 'false' ) . '"
		>';

		if ( $this->testmode ) {
			/* translators: link to Stripe testing page */
			$description .= ' ' . sprintf( __( 'TEST MODE ENABLED. In test mode, you can use the card number 4242424242424242 with any CVC and a valid expiration date or check the <a href="%s" target="_blank">Testing Stripe documentation</a> for more card numbers.', 'woocommerce-gateway-stripe' ), 'https://stripe.com/docs/testing' );
		}

		$description = trim( $description );

		echo apply_filters( 'wc_stripe_description', wpautop( wp_kses_post( $description ) ), $this->id ); // wpcs: xss ok.

		if ( $display_tokenization ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
		}

		if ( ! $this->stripe_checkout ) {
			$this->elements_form();
		}

		if ( apply_filters( 'wc_stripe_display_save_payment_method_checkbox', $display_tokenization ) && ! is_add_payment_method_page() && ! isset( $_GET['change_payment_method'] ) ) { // wpcs: csrf ok.

			if ( ! $this->stripe_checkout ) {
				$this->save_payment_method_checkbox();
			} elseif ( $this->stripe_checkout && isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) { // wpcs: csrf ok.
				$this->save_payment_method_checkbox();
			}
		}

		do_action( 'wc_stripe_cards_payment_fields', $this->id );

		echo '</div>';

		ob_end_flush();
	}

	/**
	 * Renders the Stripe elements form.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function elements_form() {
		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">
			<?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>

			<?php if ( $this->inline_cc_form ) { ?>
				<label for="card-element">
					<?php esc_html_e( 'Credit or debit card', 'woocommerce-gateway-stripe' ); ?>
				</label>

				<div id="stripe-card-element" class="wc-stripe-elements-field">
				<!-- a Stripe Element will be inserted here. -->
				</div>
			<?php } else { ?>
				<div class="form-row form-row-wide">
					<label for="stripe-card-element"><?php esc_html_e( 'Card Number', 'woocommerce-gateway-stripe' ); ?> <span class="required">*</span></label>
					<div class="stripe-card-group">
						<div id="stripe-card-element" class="wc-stripe-elements-field">
						<!-- a Stripe Element will be inserted here. -->
						</div>

						<i class="stripe-credit-card-brand stripe-card-brand" alt="Credit Card"></i>
					</div>
				</div>

				<div class="form-row form-row-first">
					<label for="stripe-exp-element"><?php esc_html_e( 'Expiry Date', 'woocommerce-gateway-stripe' ); ?> <span class="required">*</span></label>

					<div id="stripe-exp-element" class="wc-stripe-elements-field">
					<!-- a Stripe Element will be inserted here. -->
					</div>
				</div>

				<div class="form-row form-row-last">
					<label for="stripe-cvc-element"><?php esc_html_e( 'Card Code (CVC)', 'woocommerce-gateway-stripe' ); ?> <span class="required">*</span></label>
				<div id="stripe-cvc-element" class="wc-stripe-elements-field">
				<!-- a Stripe Element will be inserted here. -->
				</div>
				</div>
				<div class="clear"></div>
			<?php } ?>

			<!-- Used to display form errors -->
			<div class="stripe-source-errors" role="alert"></div>
			<br />
			<?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
			<div class="clear"></div>
		</fieldset>
		<?php
	}

	/**
	 * Load admin scripts.
	 *
	 * @since 3.1.0
	 * @version 3.1.0
	 */
	public function admin_scripts() {
		if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script( 'woocommerce_stripe_admin', plugins_url( 'assets/js/stripe-admin' . $suffix . '.js', WC_STRIPE_MAIN_FILE ), array(), WC_STRIPE_VERSION, true );
	}

	/**
	 * Payment_scripts function.
	 *
	 * Outputs scripts used for stripe payment
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 */
	public function payment_scripts() {
		if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() && ! isset( $_GET['change_payment_method'] ) ) { // wpcs: csrf ok.
			return;
		}

		// If Stripe is not enabled bail.
		if ( 'no' === $this->enabled ) {
			return;
		}

		// If keys are not set bail.
		if ( ! $this->are_keys_set() ) {
			WC_Stripe_Logger::log( 'Keys are not set correctly.' );
			return;
		}

		// If no SSL bail.
		if ( ! $this->testmode && ! is_ssl() ) {
			WC_Stripe_Logger::log( 'Stripe live mode requires SSL.' );
		}

		$current_theme = wp_get_theme();

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_style( 'stripe_styles', plugins_url( 'assets/css/stripe-styles.css', WC_STRIPE_MAIN_FILE ), array(), WC_STRIPE_VERSION );
		wp_enqueue_style( 'stripe_styles' );

		wp_register_script( 'stripe_checkout', 'https://checkout.stripe.com/checkout.js', '', WC_STRIPE_VERSION, true );
		wp_register_script( 'stripe', 'https://js.stripe.com/v3/', '', '3.0', true );
		wp_register_script( 'woocommerce_stripe', plugins_url( 'assets/js/stripe' . $suffix . '.js', WC_STRIPE_MAIN_FILE ), array( 'jquery-payment', 'stripe' ), WC_STRIPE_VERSION, true );

		$stripe_params = array(
			'key'                  => $this->publishable_key,
			'i18n_terms'           => __( 'Please accept the terms and conditions first', 'woocommerce-gateway-stripe' ),
			'i18n_required_fields' => __( 'Please fill in required checkout fields first', 'woocommerce-gateway-stripe' ),
		);

		// If we're on the pay page we need to pass stripe.js the address of the order.
		if ( isset( $_GET['pay_for_order'] ) && 'true' === $_GET['pay_for_order'] ) { // wpcs: csrf ok.
			$order_id = wc_get_order_id_by_order_key( urldecode( $_GET['key'] ) ); // wpcs: csrf ok, sanitization ok, xss ok.
			$order    = wc_get_order( $order_id );

			if ( is_a( $order, 'WC_Order' ) ) {
				$stripe_params['billing_first_name'] = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_first_name : $order->get_billing_first_name();
				$stripe_params['billing_last_name']  = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_last_name : $order->get_billing_last_name();
				$stripe_params['billing_address_1']  = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_address_1 : $order->get_billing_address_1();
				$stripe_params['billing_address_2']  = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_address_2 : $order->get_billing_address_2();
				$stripe_params['billing_state']      = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_state : $order->get_billing_state();
				$stripe_params['billing_city']       = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_city : $order->get_billing_city();
				$stripe_params['billing_postcode']   = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_postcode : $order->get_billing_postcode();
				$stripe_params['billing_country']    = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_country : $order->get_billing_country();
			}
		}

		$stripe_params['no_prepaid_card_msg']                     = __( 'Sorry, we\'re not accepting prepaid cards at this time. Your credit card has not been charged. Please try with alternative payment method.', 'woocommerce-gateway-stripe' );
		$stripe_params['no_sepa_owner_msg']                       = __( 'Please enter your IBAN account name.', 'woocommerce-gateway-stripe' );
		$stripe_params['no_sepa_iban_msg']                        = __( 'Please enter your IBAN account number.', 'woocommerce-gateway-stripe' );
		$stripe_params['payment_intent_error']                    = __( 'We couldn\'t initiate the payment. Please try again.', 'woocommerce-gateway-stripe' );
		$stripe_params['sepa_mandate_notification']               = apply_filters( 'wc_stripe_sepa_mandate_notification', 'email' );
		$stripe_params['allow_prepaid_card']                      = apply_filters( 'wc_stripe_allow_prepaid_card', true ) ? 'yes' : 'no';
		$stripe_params['inline_cc_form']                          = $this->inline_cc_form ? 'yes' : 'no';
		$stripe_params['stripe_checkout_require_billing_address'] = apply_filters( 'wc_stripe_checkout_require_billing_address', false ) ? 'yes' : 'no';
		$stripe_params['is_checkout']                             = ( is_checkout() && empty( $_GET['pay_for_order'] ) ) ? 'yes' : 'no'; // wpcs: csrf ok.
		$stripe_params['return_url']                              = $this->get_stripe_return_url();
		$stripe_params['ajaxurl']                                 = WC_AJAX::get_endpoint( '%%endpoint%%' );
		$stripe_params['stripe_nonce']                            = wp_create_nonce( '_wc_stripe_nonce' );
		$stripe_params['statement_descriptor']                    = $this->statement_descriptor;
		$stripe_params['elements_options']                        = apply_filters( 'wc_stripe_elements_options', array() );
		$stripe_params['sepa_elements_options']                   = apply_filters(
			'wc_stripe_sepa_elements_options',
			array(
				'supportedCountries' => array( 'SEPA' ),
				'placeholderCountry' => WC()->countries->get_base_country(),
				'style'              => array( 'base' => array( 'fontSize' => '15px' ) ),
			)
		);
		$stripe_params['invalid_owner_name']                      = __( 'Billing First Name and Last Name are required.', 'woocommerce-gateway-stripe' );
		$stripe_params['is_stripe_checkout']                      = $this->stripe_checkout ? 'yes' : 'no';
		$stripe_params['is_change_payment_page']                  = isset( $_GET['change_payment_method'] ) ? 'yes' : 'no'; // wpcs: csrf ok.
		$stripe_params['is_add_payment_page']                     = is_wc_endpoint_url( 'add-payment-method' ) ? 'yes' : 'no';
		$stripe_params['is_pay_for_order_page']                   = is_wc_endpoint_url( 'order-pay' ) ? 'yes' : 'no';
		$stripe_params['elements_styling']                        = apply_filters( 'wc_stripe_elements_styling', false );
		$stripe_params['elements_classes']                        = apply_filters( 'wc_stripe_elements_classes', false );

		// Merge localized messages to be use in JS.
		$stripe_params = array_merge( $stripe_params, WC_Stripe_Helper::get_localized_messages() );

		wp_localize_script( 'woocommerce_stripe', 'wc_stripe_params', apply_filters( 'wc_stripe_params', $stripe_params ) );
		wp_localize_script( 'woocommerce_stripe_checkout', 'wc_stripe_params', apply_filters( 'wc_stripe_params', $stripe_params ) );

		if ( $this->stripe_checkout ) {
			wp_enqueue_script( 'stripe_checkout' );
		}

		$this->tokenization_script();
		wp_enqueue_script( 'woocommerce_stripe' );
	}

	/**
	 * Add Stripe Checkout items to receipt page.
	 *
	 * @since 4.1.0
	 * @param int $order_id The ID of the order to show a receipt for.
	 */
	public function stripe_checkout_receipt_page( $order_id ) {
		if ( ! $this->stripe_checkout ) {
			return;
		}

		$user                 = wp_get_current_user();
		$total                = WC()->cart->total;
		$user_email           = '';
		$display_tokenization = $this->supports( 'tokenization' ) && $this->saved_cards;

		// If paying from order, we need to get total from order not cart.
		if ( ! empty( $_GET['key'] ) ) { // wpcs: csrf ok.
			$order      = wc_get_order( wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) ) ); // wpcs: csrf ok, sanitization ok.
			$total      = $order->get_total();
			$user_email = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->billing_email : $order->get_billing_email();
		} else {
			if ( $user->ID ) {
				$user_email = get_user_meta( $user->ID, 'billing_email', true );
				$user_email = $user_email ? $user_email : $user->user_email;
			}
		}

		ob_start();

		do_action( 'wc_stripe_checkout_receipt_page_before_form' );

		echo '<form method="post" class="woocommerce-checkout" action="' . esc_attr( WC()->api_request_url( get_class( $this ) ) ) . '">';
		echo '<div
			id="stripe-payment-data"
			data-panel-label="' . esc_attr( apply_filters( 'wc_stripe_checkout_label', '' ) ) . '"
			data-description="' . esc_attr( wp_strip_all_tags( $this->stripe_checkout_description ) ) . '"
			data-email="' . esc_attr( $user_email ) . '"
			data-verify-zip="' . esc_attr( apply_filters( 'wc_stripe_checkout_verify_zip', false ) ? 'true' : 'false' ) . '"
			data-billing-address="' . esc_attr( apply_filters( 'wc_stripe_checkout_require_billing_address', false ) ? 'true' : 'false' ) . '"
			data-shipping-address="' . esc_attr( apply_filters( 'wc_stripe_checkout_require_shipping_address', false ) ? 'true' : 'false' ) . '"
			data-amount="' . esc_attr( WC_Stripe_Helper::get_stripe_amount( $total ) ) . '"
			data-name="' . esc_attr( $this->statement_descriptor ) . '"
			data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '"
			data-image="' . esc_attr( $this->stripe_checkout_image ) . '"
			data-locale="' . esc_attr( apply_filters( 'wc_stripe_checkout_locale', $this->get_locale() ) ) . '"
			data-allow-remember-me="' . esc_attr( apply_filters( 'wc_stripe_allow_remember_me', true ) ? 'true' : 'false' ) . '">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';
		echo '<input type="hidden" name="stripe_checkout_order" value="yes" />';

		if (
			apply_filters( 'wc_stripe_display_save_payment_method_checkbox', $display_tokenization ) &&
			( ! function_exists( 'wcs_order_contains_subscription' ) || ( function_exists( 'wcs_order_contains_subscription' ) && ! WC_Subscriptions_Cart::cart_contains_subscription() ) ) &&
			( ! WC_Stripe_Helper::is_pre_orders_exists() || ( WC_Stripe_Helper::is_pre_orders_exists() && ! $this->pre_orders->is_pre_order( $order_id ) ) )
		) {
			$this->save_payment_method_checkbox();
		}

		wp_nonce_field( 'stripe-checkout-process', 'stripe_checkout_process_nonce' );

		do_action( 'wc_stripe_checkout_receipt_page_before_form_submit' );

		echo '<button type="submit" class="wc-stripe-checkout-button">' . esc_html( __( 'Place Order', 'woocommerce-gateway-stripe' ) ) . '</button>';

		do_action( 'wc_stripe_checkout_receipt_page_after_form_submit' );

		echo '</form>';

		do_action( 'wc_stripe_checkout_receipt_page_after_form' );

		echo '</div>';

		ob_end_flush();
	}

	/**
	 * Handles the return from processing the payment.
	 *
	 * @since 4.1.0
	 */
	public function stripe_checkout_return_handler() {
		if ( ! $this->stripe_checkout ) {
			return;
		}

		if ( ! isset( $_POST['stripe_checkout_process_nonce'] ) || ! wp_verify_nonce( $_POST['stripe_checkout_process_nonce'], 'stripe-checkout-process' ) || ! isset( $_POST['order_id'] ) ) { // wpcs: sanitization ok.
			return;
		}

		$order_id = wc_clean( $_POST['order_id'] ); // wpcs: sanitization ok.
		$order    = wc_get_order( $order_id );

		do_action( 'wc_stripe_checkout_return_handler', $order );

		if ( WC_Stripe_Helper::is_pre_orders_exists() && $this->pre_orders->is_pre_order( $order_id ) && WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id ) ) {
			$result = $this->pre_orders->process_pre_order( $order_id );
		} else {
			$result = $this->process_payment( $order_id );
		}

		if ( 'success' === $result['result'] ) {
			wp_redirect( $result['redirect'] );
			exit;
		}

		// Redirects back to pay order page.
		wp_safe_redirect( $order->get_checkout_payment_url( true ) );
		exit;
	}

	/**
	 * Checks if we need to redirect for Stripe Checkout.
	 *
	 * @since 4.1.0
	 * @return bool
	 */
	public function should_redirect_to_stripe_checkout() {
		$is_payment_request = ( isset( $_POST ) && isset( $_POST['payment_request_type'] ) ); // wpcs: csrf ok.

		return (
			$this->stripe_checkout &&
			! isset( $_POST['stripe_checkout_order'] ) && // wpcs: csrf ok.
			! $this->is_using_saved_payment_method() &&
			! is_wc_endpoint_url( 'order-pay' ) &&
			! $is_payment_request
		);
	}

	/**
	 * Generates the `process_payment` redirect to Stripe Checkout.
	 *
	 * @since 4.2.0
	 * @param WC_Order $order The order that needs a payment.
	 * @return array
	 */
	public function redirect_to_stripe_checkout( $order ) {
		WC_Stripe_Logger::log( sprintf( 'Redirecting to Stripe Checkout page for order %s', $order->get_id() ) );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Creates a new WC_Stripe_Customer if the visitor chooses to.
	 *
	 * @since 4.2.0
	 * @param WC_Order $order The order that is being created.
	 */
	public function maybe_create_customer( $order ) {
		// This comes from the create account checkbox in the checkout page.
		if ( empty( $_POST['createaccount'] ) ) { // wpcs: csrf ok.
			return;
		}

		$new_customer_id     = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->customer_user : $order->get_customer_id();
		$new_stripe_customer = new WC_Stripe_Customer( $new_customer_id );
		$new_stripe_customer->create_customer();
	}

	/**
	 * Checks if a source object represents a prepaid credit card and
	 * throws an exception if it is one, but that is not allowed.
	 *
	 * @since 4.2.0
	 * @param object $prepared_source The object with source details.
	 * @throws WC_Stripe_Exception An exception if the card is prepaid, but prepaid cards are not allowed.
	 */
	public function maybe_disallow_prepaid_card( $prepared_source ) {
		// Check if we don't allow prepaid credit cards.
		if ( apply_filters( 'wc_stripe_allow_prepaid_card', true ) || ! $this->is_prepaid_card( $prepared_source->source_object ) ) {
			return;
		}

		$localized_message = __( 'Sorry, we\'re not accepting prepaid cards at this time. Your credit card has not been charged. Please try with alternative payment method.', 'woocommerce-gateway-stripe' );
		throw new WC_Stripe_Exception( print_r( $prepared_source->source_object, true ), $localized_message );
	}

	/**
	 * Checks whether a source exists.
	 *
	 * @since 4.2.0
	 * @param  object $prepared_source The source that should be verified.
	 * @throws WC_Stripe_Exception     An exception if the source ID is missing.
	 */
	public function check_source( $prepared_source ) {
		if ( empty( $prepared_source->source ) ) {
			$localized_message = __( 'Payment processing failed. Please retry.', 'woocommerce-gateway-stripe' );
			throw new WC_Stripe_Exception( print_r( $prepared_source, true ), $localized_message );
		}
	}

	/**
	 * Customer param wrong? The user may have been deleted on stripe's end. Remove customer_id. Can be retried without.
	 *
	 * @since 4.2.0
	 * @param object   $error The error that was returned from Stripe's API.
	 * @param WC_Order $order The order those payment is being processed.
	 * @return bool           A flag that indicates that the customer does not exist and should be removed.
	 */
	public function maybe_remove_non_existent_customer( $error, $order ) {
		if ( ! $this->is_no_such_customer_error( $error ) ) {
			return false;
		}

		if ( WC_Stripe_Helper::is_wc_lt( '3.0' ) ) {
			delete_user_meta( $order->customer_user, '_stripe_customer_id' );
			delete_post_meta( $order->get_id(), '_stripe_customer_id' );
		} else {
			delete_user_meta( $order->get_customer_id(), '_stripe_customer_id' );
			$order->delete_meta_data( '_stripe_customer_id' );
			$order->save();
		}

		return true;
	}

	/**
	 * Completes an order without a positive value.
	 *
	 * @since 4.2.0
	 * @param WC_Order $order The order to complete.
	 * @return array          Redirection data for `process_payment`.
	 */
	public function complete_free_order( $order ) {
		$order->payment_complete();

		// Remove cart.
		WC()->cart->empty_cart();

		// Return thank you page redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process the payment
	 *
	 * @since 1.0.0
	 * @since 4.1.0 Add 4th parameter to track previous error.
	 * @param int  $order_id Reference.
	 * @param bool $retry Should we retry on fail.
	 * @param bool $force_save_source Force save the payment source.
	 * @param mix  $previous_error Any error message from previous request.
	 *
	 * @throws Exception If payment will not be accepted.
	 * @return array|void
	 */
	public function process_payment( $order_id, $retry = true, $force_save_source = false, $previous_error = false ) {
		try {
			$order = wc_get_order( $order_id );

			if ( $this->should_redirect_to_stripe_checkout() ) {
				return $this->redirect_to_stripe_checkout( $order );
			}

			// ToDo: `process_pre_order` saves the source to the order for a later payment.
			// This might not work well with PaymentIntents.
			if ( $this->maybe_process_pre_orders( $order_id ) ) {
				return $this->pre_orders->process_pre_order( $order_id );
			}

			$this->maybe_create_customer( $order );

			$prepared_source = $this->prepare_source( get_current_user_id(), $force_save_source );

			$this->maybe_disallow_prepaid_card( $prepared_source );
			$this->check_source( $prepared_source );
			$this->save_source_to_order( $order, $prepared_source );

			if ( 0 >= $order->get_total() ) {
				return $this->complete_free_order( $order );
			}

			// This will throw exception if not valid.
			$this->validate_minimum_order_amount( $order );

			WC_Stripe_Logger::log( "Info: Begin processing payment for order $order_id for the amount of {$order->get_total()}" );

			$intent = null;
			if ( $this->stripe_checkout ) {
				$response = $this->charge_source( $prepared_source, $order, $previous_error );
			} else {
				$intent = $this->get_intent_from_order( $order );

				if ( $intent ) {
					$intent = $this->update_existing_intent( $intent, $order, $prepared_source );
				} else {
					$intent = $this->create_and_confirm_intent( $order, $prepared_source );
					$intent = $response;
				}
			}

			if ( ! empty( $response->error ) ) {
				if ( ! $prepared_source->is_intent ) {
					$this->maybe_remove_non_existent_customer( $response->error, $order );
				}

				// We want to retry.
				if ( $this->is_retryable_error( $response->error ) ) {
					return $this->retry_after_error( $response, $order, $retry, $force_save_source, $previous_error );
				}

				$this->throw_localized_message( $response, $order );
			}

			if ( ! empty( $intent ) ) {
				// Use the last charge within the intent to proceed.
				$response = end( $intent->charges->data );

				// If the intent requires a 3DS flow, redirect to it.
				if ( 'requires_source_action' === $intent->status ) {
					if ( is_wc_endpoint_url( 'order-pay' ) ) {
						$redirect_url = add_query_arg( 'wc-stripe-confirmation', 1, $order->get_checkout_payment_url( false ) );
					} else {
						// Use the format above (with `wc-stripe-confirmation`) to avoid hash-based actions.
						$redirect_url = '#confirm-pi-' . $intent->client_secret . ':' . rawurlencode( $this->get_return_url( $order ) );
					}

					return array(
						'result'   => 'success',
						'redirect' => $redirect_url,
					);
				}
			}

			do_action( 'wc_gateway_stripe_process_payment', $response, $order );

			// Process valid response.
			$this->process_response( $response, $order );

			// Remove cart.
			WC()->cart->empty_cart();

			// Return thank you page redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} catch ( WC_Stripe_Exception $e ) {
			wc_add_notice( $e->getLocalizedMessage(), 'error' );
			WC_Stripe_Logger::log( 'Error: ' . $e->getMessage() );

			do_action( 'wc_gateway_stripe_process_payment_error', $e, $order );

			/* translators: error message */
			$order->update_status( 'failed' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}
	}

	/**
	 * Displays the Stripe fee
	 *
	 * @since 4.1.0
	 *
	 * @param int $order_id The ID of the order.
	 */
	public function display_order_fee( $order_id ) {
		if ( apply_filters( 'wc_stripe_hide_display_order_fee', false, $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		$fee      = WC_Stripe_Helper::get_stripe_fee( $order );
		$currency = WC_Stripe_Helper::get_stripe_currency( $order );

		if ( ! $fee || ! $currency ) {
			return;
		}

		?>

		<tr>
			<td class="label stripe-fee">
				<?php echo wc_help_tip( __( 'This represents the fee Stripe collects for the transaction.', 'woocommerce-gateway-stripe' ) ); // wpcs: xss ok. ?>
				<?php esc_html_e( 'Stripe Fee:', 'woocommerce-gateway-stripe' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				-&nbsp;<?php echo esc_html( wc_price( $fee, array( 'currency' => $currency ) ) ); ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Displays the net total of the transaction without the charges of Stripe.
	 *
	 * @since 4.1.0
	 *
	 * @param int $order_id The ID of the order.
	 */
	public function display_order_payout( $order_id ) {
		if ( apply_filters( 'wc_stripe_hide_display_order_payout', false, $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		$net      = WC_Stripe_Helper::get_stripe_net( $order );
		$currency = WC_Stripe_Helper::get_stripe_currency( $order );

		if ( ! $net || ! $currency ) {
			return;
		}

		?>

		<tr>
			<td class="label stripe-payout">
				<?php echo wc_help_tip( __( 'This represents the net total that will be credited to your Stripe bank account. This may be in the currency that is set in your Stripe account.', 'woocommerce-gateway-stripe' ) ); // wpcs: xss ok. ?>
				<?php esc_html_e( 'Stripe Payout:', 'woocommerce-gateway-stripe' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				<?php echo esc_html( wc_price( $net, array( 'currency' => $currency ) ) ); ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Charges a source based on an order.
	 *
	 * @since 4.2.0
	 * @param  object   $prepared_source An object with everything, related to the source.
	 * @param  WC_Order $order           The order that is being paid for.
	 * @param  mixed    $previous_error  Any error message from a previous request.
	 * @return stdClass                  The response from the Stripe API, a charge on success.
	 */
	public function charge_source( $prepared_source, $order, $previous_error ) {
		/**
		 * If we're doing a retry and source is chargeable, we need to pass
		 * a different idempotency key and retry for success.
		 */
		if ( $this->need_update_idempotency_key( $prepared_source->source_object, $previous_error ) ) {
			add_filter( 'wc_stripe_idempotency_key', array( $this, 'change_idempotency_key' ), 10, 2 );
		}

		return WC_Stripe_API::request( $this->generate_payment_request( $order, $prepared_source ) );
	}

	/**
	 * Displays a notice that 3DS is not a setting anymore.
	 *
	 * @since 4.2.0
	 */
	public function display_three_d_secure_notice() {
		if ( $this->get_option( '3ds_setting_notice_dismissed' ) ) {
			return;
		}
		?>
		<div data-nonce="<?php echo esc_attr( wp_create_nonce( 'no-3ds' ) ); ?>" class="notice notice-warning is-dismissible wc-stripe-3ds-missing">
			<p>
				<?php
				$url = 'https://stripe.com/docs/payments/dynamic-3ds';
				/* translators: 1) A URL that explains Stripe Radar. */
				$message = __( '<strong>WooCommerce Stripe Gateway:</strong> We see that you had the "Require 3D secure when applicable" setting turned on. This setting is not available here anymore, because it is now replaced by Stripe Radar. You can learn more about it <a href="%s">here</a>.', 'woocommerce-gateway-stripe' );

				$allowed_tags = array(
					'strong' => array(),
					'a'      => array(
						'href' => array(),
					),
				);
				printf( wp_kses( $message, $allowed_tags ), esc_url( $url ) );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Generates a localized message for an error, adds it as a note and throws it.
	 *
	 * @since 4.2.0
	 * @param  stdClass $response  The response from the Stripe API.
	 * @param  WC_Order $order     The order to add a note to.
	 * @throws WC_Stripe_Exception An exception with the right message.
	 */
	public function throw_localized_message( $response, $order ) {
		$localized_messages = WC_Stripe_Helper::get_localized_messages();

		if ( 'card_error' === $response->error->type ) {
			$localized_message = isset( $localized_messages[ $response->error->code ] ) ? $localized_messages[ $response->error->code ] : $response->error->message;
		} else {
			$localized_message = isset( $localized_messages[ $response->error->type ] ) ? $localized_messages[ $response->error->type ] : $response->error->message;
		}

		$order->add_order_note( $localized_message );

		throw new WC_Stripe_Exception( print_r( $response, true ), $localized_message );
	}

	/**
	 * Retries the payment process once an error occured.
	 *
	 * @since 4.2.0
	 * @param object   $response          The response from the Stripe API.
	 * @param WC_Order $order             An order that is being paid for.
	 * @param bool     $retry             A flag that indicates whether another retry should be attempted.
	 * @param bool     $force_save_source Force save the payment source.
	 * @param mixed    $previous_error Any error message from previous request.
	 * @throws WC_Stripe_Exception        If the payment is not accepted.
	 * @return array|void
	 */
	public function retry_after_error( $response, $order, $retry, $force_save_source, $previous_error ) {
		if ( ! $retry ) {
			$localized_message = __( 'Sorry, we are unable to process your payment at this time. Please retry later.', 'woocommerce-gateway-stripe' );
			$order->add_order_note( $localized_message );
			throw new WC_Stripe_Exception( print_r( $response, true ), $localized_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.
		}

		// Don't do anymore retries after this.
		if ( 5 <= $this->retry_interval ) {
			return $this->process_payment( $order->get_id(), false, $force_save_source, $response->error, $previous_error );
		}

		sleep( $this->retry_interval );
		$this->retry_interval++;

		return $this->process_payment( $order->get_id(), true, $force_save_source, $response->error, $previous_error );
	}
	/**
	 * Create a new PaymentIntent and attempt to confirm it.
	 *
	 * @param WC_Order $order           The order that is being paid for.
	 * @param object   $prepared_source The source that is used for the payment.
	 * @return object                   An intent (that is either successful or requires an action) or an error.
	 */
	public function create_and_confirm_intent( $order, $prepared_source ) {
		// The request for a charge contains metadata for the intent.
		$full_request = $this->generate_payment_request( $order, $prepared_source );

		$request = array(
			'source'               => $prepared_source->source,
			'amount'               => WC_Stripe_Helper::get_stripe_amount( $order->get_total() ),
			'currency'             => get_woocommerce_currency(),
			'description'          => $full_request['description'],
			'metadata'             => $full_request['metadata'],
			'statement_descriptor' => $full_request['statement_descriptor'],
			'allowed_source_types' => array(
				'card',
			),
		);

		if ( $prepared_source->customer ) {
			$request['customer'] = $prepared_source->customer;
		}

		// Create an intent that awaits an action.
		$intent = WC_Stripe_API::request( $request, 'payment_intents' );
		if ( ! empty( $intent->error ) ) {
			return $intent;
		}

		/* translators: 1) The ID of the PaymentIntent */
		$order->add_order_note( sprintf( __( 'Stripe PaymentIntent initiated (ID: %s)', 'woocommerce-gateway-stripe' ), $intent->id ) );

		// Try to confirm the intent & capture the charge (if 3DS is not required).
		$confirm_request  = array(
			'source' => $request['source'],
		);
		$confirmed_intent = WC_Stripe_API::request( $confirm_request, "payment_intents/$intent->id/confirm" );

		if ( ! empty( $confirmed_intent->error ) ) {
			return $confirmed_intent;
		}

		// Save the intent ID to the order.
		$this->save_intent_to_order( $order, $confirmed_intent );

		// Save a note about the status of the intent.
		if ( 'succeeded' === $confirmed_intent->status ) {
			/* translators: 1) The ID of the PaymentIntent */
			$order->add_order_note( sprintf( __( 'Stripe PaymentIntent succeeded (ID: %s)', 'woocommerce-gateway-stripe' ), $intent->id ) );
		} elseif ( 'requires_source_action' === $confirmed_intent->status ) {
			/* translators: 1) The ID of the PaymentIntent */
			$order->add_order_note( sprintf( __( 'Stripe PaymentIntent requires authentication (ID: %s)', 'woocommerce-gateway-stripe' ), $intent->id ) );
		}

		return $confirmed_intent;
	}

	/**
	 * Updates an existing intent with updated amount, source, and customer.
	 *
	 * @param object   $intent          The existing intent object.
	 * @param WC_Order $order           The order.
	 * @param object   $prepared_source Currently selected source.
	 * @return object                   An updated intent.
	 */
	public function update_existing_intent( $intent, $order, $prepared_source ) {
		$request = array();

		if ( $prepared_source->source !== $intent->source ) {
			$request['source'] = $prepared_source->source;
		}

		$new_amount = WC_Stripe_Helper::get_stripe_amount( $order->get_total() );
		if ( $intent->amount !== $new_amount ) {
			$request['amount'] = $new_amount;
		}

		if ( $prepared_source->customer && $intent->customer !== $prepared_source->customer ) {
			$request['customer'] = $prepared_source->customer;
		}

		if ( empty( $request ) ) {
			return $intent;
		}

		$updated_intent = WC_Stripe_API::request( $request, "payment_intents/$intent->id" );

		if ( 'requires_confirmation' === $updated_intent->status ) {
			return WC_Stripe_API::request( array(), "payment_intents/$intent->id/confirm" );
		} else {
			return $updated_intent;
		}
	}

	/**
	 * Saves intent to order.
	 *
	 * @since 3.2.0
	 * @param WC_Order $order For to which the source applies.
	 * @param stdClass $intent Payment intent information.
	 */
	public function save_intent_to_order( $order, $intent ) {
		$order_id = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->id : $order->get_id();

		if ( WC_Stripe_Helper::is_wc_lt( '3.0' ) ) {
			update_post_meta( $order_id, '_stripe_intent_id', $intent->id );
		} else {
			$order->update_meta_data( '_stripe_intent_id', $intent->id );
		}

		if ( is_callable( array( $order, 'save' ) ) ) {
			$order->save();
		}
	}

	/**
	 * Retrieves the payment intent, associated with an order.
	 *
	 * @since 4.2
	 * @param WC_Order $order The order to retrieve an intent for.
	 * @return obect|bool     Either the intent object or `false`.
	 */
	public function get_intent_from_order( $order ) {
		$order_id = WC_Stripe_Helper::is_wc_lt( '3.0' ) ? $order->id : $order->get_id();

		if ( WC_Stripe_Helper::is_wc_lt( '3.0' ) ) {
			$intent_id = get_post_meta( $order_id, '_stripe_intent_id', true );
		} else {
			$intent_id = $order->get_meta( '_stripe_intent_id' );
		}

		if ( ! $intent_id ) {
			return false;
		}

		return WC_Stripe_API::request( array(), "payment_intents/$intent_id", 'GET' );
	}

	/**
	 * Adds the necessary hooks to modify the "Pay for order" page in order to clean
	 * it up and prepare it for the Stripe PaymentIntents modal to confirm a payment.
	 *
	 * @since 4.2
	 * @param WC_Payment_Gateway[] $gateways A list of all available gateways.
	 * @return WC_Payment_Gateway[]          Either the same list or an empty one in the right conditions.
	 */
	public function prepare_order_pay_page( $gateways ) {
		if ( ! is_wc_endpoint_url( 'order-pay' ) || ! isset( $_GET['wc-stripe-confirmation'] ) ) { // wpcs: csrf ok.
			return $gateways;
		}

		add_filter( 'woocommerce_checkout_show_terms', '__return_false' );
		add_filter( 'woocommerce_pay_order_button_html', '__return_false' );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, '__return_empty_array' ) );
		add_filter( 'woocommerce_no_available_payment_methods_message', array( $this, 'change_no_available_methods_message' ) );
		add_action( 'woocommerce_pay_order_after_submit', array( $this, 'render_payment_intent_inputs' ) );

		return array();
	}

	/**
	 * Changes the text of the "No available methods" message to one that indicates
	 * the need for a PaymentIntent to be confirmed.
	 *
	 * @since 4.2
	 * @return string the new message.
	 */
	public function change_no_available_methods_message() {
		return __( 'Almost there!<br />Your order has already been created, the only thing that still needs to be done is for you to authorize the payment with your bank.', 'woocommerce-gateway-stripe' );
	}

	/**
	 * Renders hidden inputs on the "Pay for Order" page in order to let Stripe handle PaymentIntents.
	 *
	 * @since 4.2
	 */
	public function render_payment_intent_inputs() {
		$order     = wc_get_order( absint( $GLOBALS['wp']->query_vars['order-pay'] ) );
		$intent    = $this->get_intent_from_order( $order );
		$error_url = $order->get_checkout_order_received_url();

		echo '<input type="hidden" class="stripe-intent-id" value="' . esc_attr( $intent->client_secret ) . '" />';
		echo '<input type="hidden" class="stripe-intent-return" value="' . esc_attr( $this->get_return_url( $order ) ) . '" />';
		echo '<input type="hidden" class="stripe-intent-error" value="' . esc_attr( $error_url ) . '" />';
	}

	/**
	 * Adds an error message wrapper to each saved method.
	 *
	 * @since 4.2.0
	 * @param WC_Payment_Token $token Payment Token.
	 * @return string                 Generated payment method HTML
	 */
	public function get_saved_payment_method_option_html( $token ) {
		$html          = parent::get_saved_payment_method_option_html( $token );
		$error_wrapper = '<div class="stripe-source-errors" role="alert"></div>';

		return preg_replace( '~</(\w+)>\s*$~', "$error_wrapper</$1>", $html );
	}
}
