<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_Cecabank_Blocks_Support extends AbstractPaymentMethodType {
	protected $name = 'cecabank_gateway';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_cecabank_gateway_settings', [] );
	}

	public function get_payment_method_script_handles() {
		$asset_path   = WC_GATEWAY_CECABANK_PATH . '/build/zru-blocks/index.asset.php';
		$version      = '0.3.3';
		$dependencies = [];
		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] )
				? $asset['version']
				: $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
				? $asset['dependencies']
				: $dependencies;
		}
		wp_register_script(
			'wc-cecabank-blocks-integration',
			WC_GATEWAY_CECABANK_URL . '/build/cecabank-blocks/index.js',
			$dependencies,
			$version,
			true
		);
		return [ 'wc-cecabank-blocks-integration'];
	}

	public function get_payment_method_data() {
		return [
			'title' => $this->get_setting('title'),
			'description' => $this->get_setting('description'),
			'icon' => $this->get_setting('icon'),
			'currency' => get_woocommerce_currency(),
			'supports' => $this->get_supported_features()
		];
	}

	public function get_supported_features() {
		$features = [];
		$payment_gateways = WC()->payment_gateways->payment_gateways();
		if (array_key_exists('cecabank_gateway', $payment_gateways)) {
			$features = $payment_gateways['cecabank_gateway']->supports;
		}
		return $features;
	}
}
