<?php
/**
 * Pasarela de prueba con el id y los campos del plugin oficial de Wompi: el controlador REST de WooCommerce la recorre como a la verdadera.
 *
 * @package Aralia
 */

class Aralia_Test_Wompi_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id           = 'wompi';
		$this->method_title = 'Wompi';
		$this->init_form_fields();
		$this->init_settings();
	}

	public function init_form_fields() {
		$fields = array(
			'enabled' => array( 'title' => 'On', 'type' => 'checkbox' ),
			'title'   => array( 'title' => 'T', 'type' => 'text' ),
		);
		foreach ( array_keys( \Aralia\Guard\Keys::FIELDS ) as $key ) {
			$fields[ $key ] = array( 'title' => $key, 'type' => 'password' );
		}
		$this->form_fields = $fields;
	}
}
