<?php
/**
 * Plugin Name: Cecabank WooCommerce Plugin
 * Plugin URI: https://github.com/cecabank/cecabank-woocommerce
 * Description: Plugin de WooCommerce para conectar con la pasarela de Cecabank.
 * Author: Cecabank, S.A.
 * Author URI: https://www.cecabank.es/
 * Version: 0.3.3
 * Text Domain: wc_cecabank
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2019 Cecabank, S.A. (tpv@cecabank.es) y WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Gateway-Cecabank
 * @author    Cecabank, S.A.
 * @category  Admin
 * @copyright Copyright (c) 2019 Cecabank, S.A. (tpv@cecabank.es) y WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;

defined( 'ABSPATH' ) or exit;
// WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

define( 'WC_GATEWAY_CECABANK_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_GATEWAY_CECABANK_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + cecabank gateway
 */
function wc_cecabank_add_to_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_Cecabank';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_cecabank_add_to_gateways' );
/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_cecabank_gateway_plugin_links( $links ) {
    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=cecabank_gateway' ) . '">' . __( 'Configurar', 'wc-gateway-cecabank' ) . '</a>'
    );
    return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_cecabank_gateway_plugin_links' );

$autoloader_param = __DIR__ . '/lib/Cecabank/Client.php';
try {
    require_once $autoloader_param;
} catch (\Exception $e) {
    throw new \Exception('Error en el plugin de Cecabank al cargar la librería.');
}

/**
 * Cecabank Payment Gateway
 *
 * Provides an Cecabank Payment Gateway.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_Cecabank
 * @extends		WC_Payment_Gateway
 * @version		0.3.3
 * @package		WooCommerce/Classes/Payment
 * @author 		Cecabank, S.A.
 */

add_action( 'plugins_loaded', 'wc_cecabank_gateway_init', 11 );
function wc_cecabank_gateway_init() {
    class WC_Gateway_Cecabank extends WC_Payment_Gateway {
        /**
         * Constructor for the gateway.
         */
        public function __construct() {

            $this->id                 = 'cecabank_gateway';
            $this->icon               = "https://pgw.ceca.es/TPVvirtual/images/logo".$this->get_option( 'acquirer', '0000554000' ).".gif";
            $this->has_fields         = false;
            $this->method_title       = __( 'Cecabank', 'wc-gateway-cecabank' );
            $this->method_description = __( 'Permite utilizar la pasarela de Cecabank en tu sitio web.', 'wc-gateway-cecabank' );
            $this->supports           = array(
                'products',
                'subscriptions',
                'refunds'
            );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option( 'title' );
            $this->merchant     = $this->get_option( 'merchant' );
            $this->acquirer     = $this->get_option( 'acquirer' );
            $this->secret_key   = $this->get_option( 'secret_key' );
            $this->terminal     = $this->get_option( 'terminal' );
            $this->description  = $this->get_option( 'description' );
            $this->thank_you_text = $this->get_option( 'thank_you_text', '' );
            $this->set_completed = $this->get_option( 'set_completed', 'N' );
            $this->environment = $this->get_option( 'environment', 'test' );

            $icon = $this->get_option( 'icon', $this->icon );
            if (strpos($icon, 'assets/images/icons/cecabank.png') !== false || 
                ($this->acquirer !== '0000554000' && $icon === "https://pgw.ceca.es/TPVvirtual/images/logo0000554000.gif") ) {
                $this->update_option( 'icon', $this->icon );
            } else {
                $this->icon = $icon;
            }
            

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

            $this->notify_url = add_query_arg( 'wc-api', 'WC_Gateway_Cecabank', home_url( '/' ) );

            // Actions
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
                // Check for gateway messages using WC 1.X format
                add_action( 'init', array( $this, 'check_notification' ) );
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
            } else {
                // Payment listener/API hook (WC 2.X)
                add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_notification' ) );
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            add_action( 'woocommerce_receipt_cecabank_gateway', array( $this, 'receipt_page' ) );
        }

        function get_client_config() {$lang = '1';
            $locale = get_locale();
            if ($locale) {
                $locale = substr($locale, 0, 2);
            }
            switch ($locale) {
                case 'en':
                    $lang = '6';
                    break;
                case 'fr':
                    $lang = '7';
                    break;
                case 'de':
                    $lang = '8';
                    break;
                case 'pt':
                    $lang = '9';
                    break;
                case 'it':
                    $lang = '10';
                    break;
                case 'ru':
                    $lang = '14';
                    break;
                case 'no':
                    $lang = '15';
                    break;
                case 'ca':
                    $lang = '2';
                    break;
                case 'eu':
                    $lang = '3';
                    break;
                case 'gl':
                    $lang = '4';
                    break;
                default:
                    $lang = '1';
                    break;
            }
            return array(
                'Environment' => $this->environment,
                'MerchantID' => $this->merchant,
                'AcquirerBIN' => $this->acquirer,
                'TerminalID' => $this->terminal,
                'ClaveCifrado' => $this->secret_key,
                'Exponente' => '2',
                'Cifrado' => 'SHA2',
                'Idioma' => $lang,
                'Pago_soportado' => 'SSL',
                'versionMod' => 'W-0.3.3'
            );
        }

        function date_diff ($d1, $d2) {
            return round(abs(strtotime($d1)-strtotime($d2))/86400);
        }

        function wc_get_user_orders_count($user_id, $status, $days) {
            $query = array(
                'user_id' => $user_id,
            );

            if ( $status ) {
                $query['status'] = $status;
            }

            if ( $days ) {

                $query['date_created'] = '>' . ( time() - DAY_IN_SECONDS * $days );
            }

            $orders = wc_get_orders( $query );

            return count ( $orders );
        }



        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {

            $this->form_fields = apply_filters( 'wc_cecabank_form_fields', array(

                'enabled' => array(
                    'title'   => __( 'Habilitar', 'wc-gateway-cecabank' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Habilitar método de pago Cecabank', 'wc-gateway-cecabank' ),
                    'default' => 'yes'
                ),

                'merchant' => array(
                    'title'       => __( 'Código de comercio', 'wc-gateway-cecabank' ),
                    'type'        => 'text',
                    'description' => __( 'Código de comercio dado por Cecabank.', 'wc-gateway-cecabank' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),

                'acquirer' => array(
                    'title'       => __( 'Adquiriente', 'wc-gateway-cecabank' ),
                    'type'        => 'text',
                    'description' => __( 'Adquiriente dado por Cecabank.', 'wc-gateway-cecabank' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),

                'secret_key' => array(
                    'title'       => __( 'Clave Secreta', 'wc-gateway-cecabank' ),
                    'type'        => 'text',
                    'description' => __( 'Clave secreta dada por Cecabank.', 'wc-gateway-cecabank' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),

                'terminal' => array(
                    'title'       => __( 'Terminal', 'wc-gateway-cecabank' ),
                    'type'        => 'text',
                    'description' => __( 'Terminal dada por Cecabank.', 'wc-gateway-cecabank' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),

                'title' => array(
                    'title'       => __( 'Título', 'wc-gateway-cecabank' ),
                    'type'        => 'text',
                    'description' => __( 'Título mostrado al cliente durante el proceso de compra con este método de pago.', 'wc-gateway-cecabank' ),
                    'default'     => __( 'Tarjeta', 'wc-gateway-cecabank' ),
                    'desc_tip'    => true,
                ),

                'description' => array(
                    'title'       => __( 'Descripción', 'wc-gateway-cecabank' ),
                    'type'        => 'textarea',
                    'description' => __( 'Descripción mostrada al cliente durante el proceso de compra con este método de pago.', 'wc-gateway-cecabank' ),
                    'default'     => __( 'Paga con tu tarjeta', 'wc-gateway-cecabank' ),
                    'desc_tip'    => true,
                ),

                'thank_you_text' => array(
                    'title'       => __( 'Texto de la página de gracias', 'wc-gateway-cecabank' ),
                    'type'        => 'textarea',
                    'description' => __( 'Texto que se agregará a la página de gracias.', 'wc-gateway-cecabank' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'set_completed' => array(
                    'title'       => __( '¿Marcar el pedido como completado después del pago?', 'wc-gateway-cecabank' ),
                    'type'        => 'select',
                    'description' => __( 'Después del pago, ¿debe mostrarse el pedido como completado? Por defecto es "processing".', 'wc-gateway-cecabank' ),
                    'desc_tip'    => false,
                    'options'     => array(
                        'N' => __( 'No', 'wc-gateway-cecabank' ),
                        'Y' => __( 'Si', 'wc-gateway-cecabank' ),
                    ),
                    'default'     => 'N'
                ),
                'environment' => array(
                    'title'       => __( 'Entorno', 'wc-gateway-cecabank' ),
                    'type'        => 'select',
                    'description' => __( 'Entorno que se usará al realizar las transacciones.', 'wc-gateway-cecabank' ),
                    'desc_tip'    => false,
                    'options'     => array(
                        'test' => __( 'Prueba', 'wc-gateway-cecabank' ),
                        'real' => __( 'Real', 'wc-gateway-cecabank' ),
                    ),
                    'default'     => 'test'
                ),
                'icon' => array(
                    'title'   => __( 'Icon', 'wc-gateway-cecabank' ),
                    'type'    => 'text',
                    'label'   => __( 'Url de la imagen a mostrar en la página de pago', 'wc-gateway-cecabank' ),
                    'default' => apply_filters( 'woocommerce_cecabank_icon', $this->icon )
                ),
            ) );
        }


        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ( $this->thank_you_text ) {
                echo wpautop( wptexturize( $this->thank_you_text ) );
            }

            if ( version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
                $woocommerce->cart->empty_cart();
            } else {
                WC()->cart->empty_cart();
            }
        }


        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '<' ) ) {
                $redirect_url = add_query_arg('order', $order->get_id(), add_query_arg('key', $order->get_order_key(), get_permalink(wc_get_page_id('pay'))));
            } else {
                $redirect_url = $order->get_checkout_payment_url(true);
            }

            return array(
                    'result'        => 'success',
                    'redirect'      => $redirect_url
            );
        }

        function receipt_page( $order_id ) {
            echo '<p>'.__( 'Redirigiendo a Cecabank.', 'wc-gateway-cecabank' ).'</p>';

            $order = wc_get_order( $order_id );

            $config = $this-> get_client_config();

            $cecabank_client = new Cecabank\Client($config);

            $result = $this->process_regular_payment( $cecabank_client, $order, $order_id );

            // Mark as on-hold (we're awaiting the payment)
            // $order->update_status( 'on-hold', __( 'Esperando la confirmación del pago por Cecabank', 'wc-gateway-cecabank' ) );

            // if ( version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
            //     $woocommerce->cart->empty_cart();
            // } else {
            //     WC()->cart->empty_cart();
            // }
        }


        /**
         * Process regular payment and return the result
         *
         * @param object $cecabank_client
         * @param object $order
         * @param int $order_id
         * @return array
         */
        public function process_regular_payment( $cecabank_client, $order, $order_id ) {

            $user;
            $user_id;
            $user_data;
            $user_age = 'NO_ACCOUNT';
            $user_info_age = '';
            $registered = '';
            $txn_activity_today = '';
            $txn_activity_year = '';
            $txn_purchase_6 = '';
            $ship_name_indicator = 'DIFFERENT';

            $name = $order->get_formatted_billing_full_name();
			$email = '';
			$ip = '';
            $city = '';
            $country = '';
            $line1 = '';
            $line2 = '';
            $postal_code = '';
            $state = '';
            $phone = '';
            $ship_name = $order->get_formatted_shipping_full_name();
            $ship_city = '';
            $ship_country = '';
            $ship_line1 = '';
            $ship_line2 = '';
            $ship_postal_code = '';
            $ship_state = '';
            $date_created = '';
            if ( version_compare( WOOCOMMERCE_VERSION, '3.0', '<' ) ) {
                $email = $order->billing_email;
                $ip = $order->customer_ip_address;
                $city = $order->billing_city;
                $country = $order->billing_country;
                $line1 = $order->billing_address_1;
                $line2 = $order->billing_address_2;
                $postal_code = $order->billing_postcode;
                $state = $order->billing_state;
                $phone = $order->billing_phone;
                $ship_city = $order->shipping_city;
                $ship_country = $order->shipping_country;
                $ship_line1 = $order->shipping_address_1;
                $ship_line2 = $order->shipping_address_2;
                $ship_postal_code = $order->shipping_postcode;
                $ship_state = $order->shipping_state;
                $date_created = $order->date_created;

                $user = $order->user;
                $user_id = $order->user_id;
            } else {
                $email = $order->get_billing_email();
                $ip = $order->get_customer_ip_address();
                $city = $order->get_billing_city();
                $country = $order->get_billing_country();
                $line1 = $order->get_billing_address_1();
                $line2 = $order->get_billing_address_2();
                $postal_code = $order->get_billing_postcode();
                $state = $order->get_billing_state();
                $phone = $order->get_billing_phone();
                $ship_city = $order->get_shipping_city();
                $ship_country = $order->get_shipping_country();
                $ship_line1 = $order->get_shipping_address_1();
                $ship_line2 = $order->get_shipping_address_2();
                $ship_postal_code = $order->get_shipping_postcode();
                $ship_state = $order->get_shipping_state();
                $date_created = $order->get_date_created();

                $user = $order->get_user();
                $user_id = $order->get_user_id();
            }

            if ( $user ) {
                $user_data = get_userdata( $user->ID );
                $registered = $user_data->user_registered;

                $diff = strtotime('now') - strtotime($registered);
                $days = (int)date('d', $diff);
                if ( $days === 0 ) {
                    $user_age = 'JUST_CHANGED';
                    $user_info_age = 'JUST_CHANGED';
                }  elseif ( $days < 31 ) {
                    $user_age = 'LESS_30';
                    $user_info_age = 'LESS_30';
                }  elseif ( $days < 61 ) {
                    $user_age = 'BETWEEN_30_60';
                    $user_info_age = 'BETWEEN_30_60';
                }  else {
                    $user_age = 'MORE_60';
                    $user_info_age = 'MORE_60';
                }

                $txn_activity_today = $this->wc_get_user_orders_count($user_id, false, 1);
                $txn_activity_year = $this->wc_get_user_orders_count($user_id, false, 365);
                $txn_purchase_6 = $this->wc_get_user_orders_count($user_id, 'completed', 180);

                if ( $user->display_name === $ship_name ) {
                    $ship_name_indicator = 'IDENTICAL';
                }
            }

            $ship_indicator = 'CH_BILLING_ADDRESS';
            $delivery_time_frame = 'TWO_MORE_DAYS';
            $delivery_email = '';
            $reorder_items = 'FIRST_TIME_ORDERED';
            if ( !$order->needs_shipping_address() ) {
                $ship_indicator = 'DIGITAL_GOODS';
                $delivery_time_frame = 'ELECTRONIC_DELIVERY';
                $delivery_email = $email;
            } elseif ($line1 !== $ship_line1) {
                $ship_indicator = 'CH_NOT_BILLING_ADDRESS';
            }

            $utc_offset = intval( get_option( 'gmt_offset', 0 ) );
            $utc_offset *= 60;

            // ACS
            $acs = array();

            // Cardholder
            $cardholder = array();
            $add_cardholder = false;

            // Cardholder bill address
            $bill_address = array();
            $add_bill_address = false;
            if ($city) {
                $bill_address['CITY'] = $city;
                $add_bill_address = true;
            }                
            if ($country) {
                $bill_address['COUNTRY'] = $country;
                $add_bill_address = true;
            }
            if ($line1) {
                $bill_address['LINE1'] = $line1;
                $add_bill_address = true;
            }                
            if ($line2) {
                $bill_address['LINE2'] = $line2;
                $add_bill_address = true;
            }
            if ($postal_code) {
                $bill_address['POST_CODE'] = $postal_code;
                $add_bill_address = true;
            }                
            if ($state) {
                $bill_address['STATE'] = $state;
                $add_bill_address = true;
            }
            if ($add_bill_address) {
                $cardholder['BILL_ADDRESS'] = $bill_address;
                $add_cardholder = true;
            }

            // Cardholder name
            if ($name) {
                $cardholder['NAME'] = $name;
                $add_cardholder = true;
            }

            // Cardholder email
            if ($email) {
                $cardholder['EMAIL'] = $email;
                $add_cardholder = true;
            }

            if ($add_cardholder) {
                $acs['CARDHOLDER'] = $cardholder;
            }

            // Purchase
            $purchase = array();
            $add_purchase = true;

            // Purchase ship address
            $ship_address = array();
            $add_ship_address = false;
            if ($ship_city) {
                $ship_address['CITY'] = $ship_city;
                $add_ship_address = true;
            }                
            if ($ship_country) {
                $ship_address['COUNTRY'] = $ship_country;
                $add_ship_address = true;
            }
            if ($ship_line1) {
                $ship_address['LINE1'] = $ship_line1;
                $add_ship_address = true;
            }                
            if ($ship_line2) {
                $ship_address['LINE2'] = $ship_line2;
                $add_ship_address = true;
            }
            if ($ship_postal_code) {
                $ship_address['POST_CODE'] = $ship_postal_code;
                $add_ship_address = true;
            }                
            if ($ship_state) {
                $ship_address['STATE'] = $ship_state;
                $add_ship_address = true;
            }
            if ($add_ship_address) {
                $purchase['SHIP_ADDRESS'] = $ship_address;
                $add_purchase = true;
            }

            // Purchase mobile phone
            if ($phone) {
                $purchase['MOBILE_PHONE'] = array(
                    'SUBSCRIBER' => $phone
                );
                $add_purchase = true;
            }

            if ($add_purchase) {
                $acs['PURCHASE'] = $purchase;
            }

            // Merchant risk
            $merchant_risk = array(
                'PRE_ORDER_PURCHASE_IND' => 'AVAILABLE'
            );
            if ($ship_indicator) {
                $merchant_risk['SHIP_INDICATOR'] = $ship_indicator;
            }
            if ($delivery_time_frame) {
                $merchant_risk['DELIVERY_TIMEFRAME'] = $delivery_time_frame;
            }
            if ($delivery_email) {
                $merchant_risk['DELIVERY_EMAIL_ADDRESS'] = $delivery_email;
            }
            if ($reorder_items) {
                $merchant_risk['REORDER_ITEMS_IND'] = $reorder_items;
            }
            $acs['MERCHANT_RISK_IND'] = $merchant_risk;

            // Account info
            $account_info = array(
                'SUSPICIOUS_ACC_ACTIVITY' => 'NO_SUSPICIOUS'
            );
            if ($user_age) {
                $account_info['CH_ACC_AGE_IND'] = $user_age;
                $account_info['PAYMENT_ACC_IND'] = $user_age;
            }
            if ($user_info_age) {
                $account_info['CH_ACC_CHANGE_IND'] = $user_info_age;
            }
            if ($registered) {
                $account_info['CH_ACC_CHANGE'] = $registered;
                $account_info['CH_ACC_DATE'] = $registered;
                $account_info['PAYMENT_ACC_AGE'] = $registered;
            }
            if ($txn_activity_today) {
                $account_info['TXN_ACTIVITY_DAY'] = $txn_activity_today;
            }
            if ($txn_activity_year) {
                $account_info['TXN_ACTIVITY_YEAR'] = $txn_activity_year;
            }
            if ($txn_purchase_6) {
                $account_info['NB_PURCHASE_ACCOUNT'] = $txn_purchase_6;
            }
            if ($ship_name_indicator) {
                $account_info['SHIP_NAME_INDICATOR'] = $ship_name_indicator;
            }
            $acs['ACCOUNT_INFO'] = $account_info;
			
			$order_received_url = wc_get_endpoint_url( 'order-received', $order->get_id(), wc_get_page_permalink( 'checkout' ) );
			$order_received_url = add_query_arg( 'key', $order->get_order_key(), $order_received_url );

            $hiddens = array(
                'Num_operacion' => $order_id,
                'Descripcion' => __('Pago del pedido ', 'wc-gateway-cecabank').$order_id,
                'Importe' => $order->get_total(),
                'URL_OK' => $order_received_url,
                'URL_NOK' => $order->get_cancel_order_url(),
                'TipoMoneda' => $cecabank_client->getCurrencyCode(get_woocommerce_currency()),
                'datos_acs_20' => base64_encode( str_replace( '[]', '{}', json_encode( $acs ) ) )
            );

            if( class_exists( 'WC_Subscriptions_Order' ) && WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) {
                $period = WC_Subscriptions_Order::get_subscription_period( $order );
                $duration = WC_Subscriptions_Order::get_subscription_interval( $order );
                $price = WC_Subscriptions_Order::get_recurring_total( $order );
                $number_of_payments = WC_Subscriptions_Order::get_subscription_length( $order );
                if ($period == 'year') {
                    $duration *= 12;
                    $number_of_payments *= 12;
                }
                if ($number_of_payments == 0) {
                    $number_of_payments = 9999;
                }
                $first_payment_date = date_i18n( 'Ymd', strtotime("+".($duration*30)." day") );
                $data = $first_payment_date.sprintf("%10d", $price * 100).sprintf("%4d", $number_of_payments).sprintf("%02d", $duration);
                $hiddens['Descripcion'] = __('Suscripción del pedido ', 'wc-gateway-cecabank').$order_id;
                $hiddens['Tipo_operacion'] = 'D';
                $hiddens['Datos_operaciones'] = $data;
            }

            // Create transaction
            $cecabank_client->setFormHiddens($hiddens);

            echo '<form id="cecabank-form" action="'.$cecabank_client->getPath().'" method="post">'.$cecabank_client->getFormHiddens().'</form>'.'<script>document.getElementById("cecabank-form").submit();</script>';
        }

        /**
         * Process refund
         *
         * Overriding refund method
         *
         * @param       int $order_id
         * @param       float $amount
         * @param       string $reason
         * @return      mixed True or False based on success, or WP_Error
         */
        public function process_refund( $order_id, $amount = null, $reason = '' ) {
            $order = wc_get_order( $order_id );

            $transaction_id = $order->get_transaction_id();
            if ( ! $transaction_id ) {
                return new WP_Error( 'cecabank_gateway_wc_refund_error',
                    sprintf(
                        __( 'Devolución %s falló porque Transaction ID está vacio.', 'wc-gateway-cecabank' ),
                        get_class( $this )
                    )
                );
            }

            try {
                $config = $this-> get_client_config();

                $cecabank_client = new Cecabank\Client($config);

                $refund_data = array(
                    'Num_operacion' => $order_id,
                    'Referencia' => $transaction_id,
                    'TipoMoneda' => $cecabank_client->getCurrencyCode(get_woocommerce_currency())
                );

                // If the amount is set, refund that amount, otherwise the entire amount is refunded
                if ( $amount ) {
                    $refund_data['Importe'] = $amount;
                    if ( $amount < $order->get_total() ) {
                        $refund_data['TIPO_ANU'] = 'P';
                    }
                } else {
                    $refund_data['Importe'] = $order->get_total();
                }

                return $cecabank_client->refund($refund_data);
            } catch ( Exception $e ) {
                $error_message = sprintf(
                    __( 'Devolución %s fallida', 'wc-gateway-cecabank' ),
                    get_class( $this )
                );
                $order->add_order_note($error_message);
                // Something failed somewhere, send a message.
                return new WP_Error( 'cecabank_gateway_wc_refund_error', $error_message );
            }
            return false;
        }

        /**
         * Check for Cecabank notification
         *
         * @access public
         * @return void
         */
        function check_notification() {
            global $woocommerce;

            $config = $this-> get_client_config();

            $cecabank_client = new Cecabank\Client($config);

            try {
                $cecabank_client->checkTransaction($_POST);
            } catch (\Exception $e) {
                $message = __('Ha ocurrido un error con el pago: '.$e->getMessage(), 'wc-gateway-cecabank');
                $order = wc_get_order( $_POST['Num_operacion'] );
                $order->update_status('failed', $message );
                die();
            }

            $order = wc_get_order( $_POST['Num_operacion'] );

            if ( $order->has_status( 'completed' ) ) {
                die();
            }

            // Payment completed
            $order->add_order_note( __('Pago completado por Cecabank con referencia: '.$_POST['Referencia'], 'wc-gateway-cecabank') );
            $order->payment_complete( $_POST['Referencia'] );

            // Set order as completed if user did set up it
            if ( 'Y' == $this->set_completed ) {
                $order->update_status( 'completed' );
            }

            die($cecabank_client->successCode());
        }

    } // end \WC_Gateway_Cecabank class
}

add_action( 'woocommerce_blocks_loaded', 'extend_store_api' );
function extend_store_api() {
    if ( ! function_exists('woocommerce_store_api_register_endpoint_data') ) {
        return;
    }

    woocommerce_store_api_register_endpoint_data([
        'endpoint' => CartItemSchema::IDENTIFIER,
        'namespace' => 'cecabank_gateway',
    ]);
}
add_action( 'woocommerce_blocks_loaded', 'add_woocommerce_blocks_support' );
function add_woocommerce_blocks_support() {
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once dirname(__FILE__) . '/class/WC_Gateway_Cecabank_Blocks_Support.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new WC_Gateway_Cecabank_Blocks_Support );
            }
        );
    }
}
// Declare compatibility with custom order tables for WooCommerce.
add_action( 'before_woocommerce_init', function() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);
