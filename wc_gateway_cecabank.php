<?php
/**
 * Plugin Name: Cecabank WooCommerce Plugin
 * Plugin URI: https://github.com/cecabank/cecabank-woocommerce
 * Description: Plugin de WooCommerce para conectar con la pasarela de Cecabank.
 * Author: Cecabank, S.A.
 * Author URI: https://www.cecabank.es/
 * Version: 0.1.2
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

defined( 'ABSPATH' ) or exit;
// WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
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
 * @since 1.0.0
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
 * @version		0.1.2
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
            $this->icon               = apply_filters( 'woocommerce_cecabank_icon', plugins_url( 'assets/images/icons/cecabank.png' , __FILE__ ) );;
            $this->has_fields         = false;
            $this->method_title       = __( 'Cecabank', 'wc-gateway-cecabank' );
            $this->method_description = __( 'Permite utilizar la pasarela de Cecabank en tu sitio web.', 'wc-gateway-cecabank' );
            $this->supports           = array(
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
            $this->currency = $this->get_option( 'currency', '978' );

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

        function get_client_config() {
            return array(
                'Environment' => $this->environment,
                'MerchantID' => $this->merchant,
                'AcquirerBIN' => $this->acquirer,
                'TerminalID' => $this->terminal,
                'ClaveCifrado' => $this->secret_key,
                'TipoMoneda' => $this->currency,
                'Exponente' => '2',
                'Cifrado' => 'SHA2',
                'Idioma' => '1',
                'Pago_soportado' => 'SSL'
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
                'currency' => array(
                    'title'       => __( 'Moneda', 'wc-gateway-cecabank' ),
                    'type'        => 'select',
                    'description' => __( 'Moneda a utilizar en las transacciones.', 'wc-gateway-cecabank' ),
                    'desc_tip'    => false,
                    'options'     => array(
                        '978' => __( 'EUR', 'wc-gateway-cecabank' ),
                        '840' => __( 'USD', 'wc-gateway-cecabank' ),
                        '826' => __( 'GBP', 'wc-gateway-cecabank' ),
                        '392' => __( 'JPY', 'wc-gateway-cecabank' ),
                        '32'  => __( 'ARS', 'wc-gateway-cecabank' ),
                        '124' => __( 'CAD', 'wc-gateway-cecabank' ),
                        '152' => __( 'CLP', 'wc-gateway-cecabank' ),
                        '170' => __( 'COP', 'wc-gateway-cecabank' ),
                        '356' => __( 'INR', 'wc-gateway-cecabank' ),
                        '484' => __( 'MXN', 'wc-gateway-cecabank' ),
                        '604' => __( 'PEN', 'wc-gateway-cecabank' ),
                        '756' => __( 'CHF', 'wc-gateway-cecabank' ),
                        '986' => __( 'BRL', 'wc-gateway-cecabank' ),
                        '937' => __( 'VEF', 'wc-gateway-cecabank' ),
                        '949' => __( 'TRY', 'wc-gateway-cecabank' ),
                    ),
                    'default'     => '978'
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
        }


        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

			$redirect_url = add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(wc_get_page_id('pay'))));

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

            if ( version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
                $woocommerce->cart->empty_cart();
            } else {
                WC()->cart->empty_cart();
            }
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
            $line3 = '';
            $postal_code = '';
            $state = '';
            $phone = '';
            $ship_name = $order->get_formatted_shipping_full_name();
            $ship_city = '';
            $ship_country = '';
            $ship_line1 = '';
            $ship_line2 = '';
            $ship_line3 = '';
            $ship_postal_code = '';
            $ship_state = '';
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

            $acs = array(
                'CARDHOLDER'        => array(
                    'NAME'          => $name,
                    'EMAIL'         => $email,
                    'BILL_ADDRESS'  => array(
                        'CITY'      => $city,
                        'COUNTRY'   => $country,
                        'LINE1'     => $line1,
                        'LINE2'     => $line2,
                        'LINE3'     => $line3,
                        'POST_CODE' => $postal_code,
                        'STATE'     => $state
                    ),
                ),
                'PURCHASE'          => array(
                    'SHIP_ADDRESS'  => array(
                        'CITY'      => $ship_city,
                        'COUNTRY'   => $ship_country,
                        'LINE1'     => $ship_line1,
                        'LINE2'     => $ship_line2,
                        'LINE3'     => $ship_line3,
                        'POST_CODE' => $ship_postal_code,
                        'STATE'     => $ship_state
                    ),
                    'MOBILE_PHONE'  => array(
                        'CC'        => '',
                        'SUBSCRIBER'=> $phone
                    ),
                    'WORK_PHONE'    => array(
                        'CC'        => '',
                        'SUBSCRIBER'=> ''
                    ),
                    'HOME_PHONE'    => array(
                        'CC'        => '',
                        'SUBSCRIBER'=> ''
                    ),
                ),
                'MERCHANT_RISK_IND' => array(
                    'SHIP_INDICATOR'=> $ship_indicator,
                    'DELIVERY_TIMEFRAME' => $delivery_time_frame,
                    'DELIVERY_EMAIL_ADDRESS' => $delivery_email,
                    'REORDER_ITEMS_IND' => $reorder_items,
                    'PRE_ORDER_PURCHASE_IND' => 'AVAILABLE',
                    'PRE_ORDER_DATE'=> '',
                ),
                'ACCOUNT_INFO'      => array(
                    'CH_ACC_AGE_IND'=> $user_age,
                    'CH_ACC_CHANGE_IND' => $user_info_age,
                    'CH_ACC_CHANGE' => $registered,
                    'CH_ACC_DATE'   => $registered,
                    'TXN_ACTIVITY_DAY' => $txn_activity_today,
                    'TXN_ACTIVITY_YEAR' => $txn_activity_year,
                    'NB_PURCHASE_ACCOUNT' => $txn_purchase_6,
                    'SUSPICIOUS_ACC_ACTIVITY' => 'NO_SUSPICIOUS',
                    'SHIP_NAME_INDICATOR' => $ship_name_indicator,
                    'PAYMENT_ACC_IND' => $user_age,
                    'PAYMENT_ACC_AGE' => $registered
                )
            );
			
			$order_received_url = wc_get_endpoint_url( 'order-received', $order->get_id(), wc_get_page_permalink( 'checkout' ) );
			$order_received_url = add_query_arg( 'key', $order->get_order_key(), $order_received_url );

            // Create transaction
            $cecabank_client->setFormHiddens(array(
                'Num_operacion' => $order_id,
                'Descripcion' => __('Pago del pedido ', 'wc-gateway-cecabank').$order_id,
                'Importe' => $order->get_total(),
                'URL_OK' => $order_received_url,
                'URL_NOK' => $order->get_cancel_order_url(),
                'datos_acs_20' => urlencode( json_encode( $acs ) )
            ));

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
                $refund_data = array(
                    'Num_operacion' => $order_id,
                    'Referencia' => $transaction_id
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

                $config = $this-> get_client_config();

                $cecabank_client = new Cecabank\Client($config);

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
