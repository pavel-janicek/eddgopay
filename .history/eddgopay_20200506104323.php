<?php
/*
Plugin Name: Easy Digital Downloads - Gopay 2.0
Plugin URL: https://cleverstart.cz
Description: REST API Platební brána Gopay pro Easy Digital Downloads
Version: 2.1.32
Author: Pavel Janíček
Author URI: https://cleverstart.cz
*/

use GoPay\Definition\Language;
use GoPay\Definition\Payment\Currency;
use GoPay\Definition\Payment\PaymentInstrument;
use GoPay\Definition\Payment\BankSwiftCode;
use GoPay\Definition\Payment\Recurrence;
use GoPay\Definition\TokenScope;
use GoPay\Definition\Response\PaymentStatus;
require __DIR__ . '/vendor/autoload.php';

$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://plugins.cleverstart.cz/?action=get_metadata&slug=eddgopay',
	__FILE__, //Full path to the main plugin file or functions.php.
	'eddgopay'
);

function eddgopay_register_gateway( $gateways ) {
	$gateways['eddgopay'] = array( 'admin_label' => 'GoPay', 'checkout_label' => __( 'Online platební karta nebo převod (ihned)', 'gopay' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'eddgopay_register_gateway' );

function eddgopay_cc_form() {
	return;
}
add_action('edd_eddgopay_cc_form', 'eddgopay_cc_form');


function eddgopay_listener_url($payment_id=0){

  if($payment_id==0){
    return get_home_url() . "?edd-listener=eddgopay";
  }else {
    return get_home_url() . "?edd-listener=eddgopay&payment_id=" .$payment_id;
  }

}

function eddgopay_process_payment( $purchase_data ) {
  $payment = edd_insert_payment( $purchase_data );
  update_post_meta($payment,'eddgopay_stav_platby','Nezaplaceno');
	$payment_meta = get_post_meta( $payment, '_edd_payment_meta', true );
	global $edd_options;
	$after_purchase_notify = (isset($edd_options['eddgopay_send_notification'])) AND (!empty($edd_options['eddgopay_send_notification']));
	if ($after_purchase_notify){
		$admin_to = eddgopay_get_admin_notice_emails();
		$admin_subject = edd_get_option('eddgopay_admin_mail_subject', 'Nová objednávka #{payment_id}');
		$admin_subject = apply_filters( 'eddgopay_admin_mail_subject', wp_strip_all_tags( $admin_subject ), $payment );
		$admin_subject = edd_do_email_tags( $admin_subject, $payment );
		$admin_message = edd_get_option( 'eddgopay_admin_mail_text', edd_get_default_eddgopay_admin_notification_email() );
		$admin_message = edd_do_email_tags( $admin_message, $payment);
		EDD()->emails->send( $admin_to, $admin_subject, $admin_message );
	}

  $iso_code = explode('_', get_locale());
  $language =strtoupper($iso_code[0]);

    // record the pending payment


    if ( !edd_is_test_mode() ) {
          $gopay = GoPay\payments([
              'goid' => $edd_options['eddgopay_prod_goid'],
              'clientId' => $edd_options['eddgopay_prod_client_id'],
              'clientSecret' => $edd_options['eddgopay_prod_client_secret'],
              'isProductionMode' => true,
              'language' => $language
            ]);
				}else {
          $gopay = GoPay\payments([
            'goid' => $edd_options['eddgopay_test_goid'],
            'clientId' => $edd_options['eddgopay_test_client_id'],
            'clientSecret' => $edd_options['eddgopay_test_client_secret'],
            'isProductionMode' => false,
            'language' => $language
            ]);
		}
    $customerData = eddgopay_getCustomerData($purchase_data);
    $contact =array(
          'first_name' => $customerData['firstName'],
          'last_name'=> $customerData['lastName'],
          'email'=> $customerData['email'],
          'phone_number'=>$customerData['phoneNumber'],
          'city'=>$customerData['city'],
          'street'=>$customerData['street'],
          'postal_code' => $customerData['postalCode'],
          'country_code' => $customerData['countryCode']
        );
    $totalInCents = $purchase_data['price'] * 100;
    $redirect_url = eddgopay_listener_url($payment);
    $response = $gopay->createPayment([
          'payer' => [
            'default_payment_instrument' => PaymentInstrument::PAYMENT_CARD,
            'allowed_payment_instruments' => [PaymentInstrument::PAYMENT_CARD,PaymentInstrument::BANK_ACCOUNT, PaymentInstrument::PAYPAL],
            'default_swift' => BankSwiftCode::FIO_BANKA,
            'allowed_swifts' => ['GIBACZPX','KOMBCZPP','RZBCCZPP','BREXCZPP','FIOBCZPP','CEKOCZPP','CEKOCZPP-ERA','BACXCZPP',
			        'SUBASKBX', 'TATRSKBX','UNCRSKBX','GIBASKBX','POBNSKBA','CEKOSKBX','LUBASKBX','OTHERS','BREXPLPW','CITIPLPX',
					    'BPKOPLPW-IKO','BPKOPLPW-INTELIGO','IVSEPLPP','BPHKPLPK','TOBAPLPW','VOWAPLP1','GBWCPLPP','POCZPLP4','GOPZPLPW',
					    'IEEAPLPA','POLUPLPR','GBGCPLPK-GIO','GBGCPLPK-BLIK','GBGCPLPK-NOB','BREXPLPW-OMB','WBKPPLPP','RCBWPLPW','BPKOPLPW',
					    'ALBPPLPW','INGBPLPW','PKOPPLPW','GBGCPLPK','BIGBPLPW','EBOSPLPW','PPABPLPK','AGRIPLPR','DEUTPLPX','NBPLPLPW',
					    'SOGEPLPW','PBPBPLPW'],
            'contact' => $contact
          ],
          'amount' => $totalInCents,
          'currency' => $payment_meta['currency'],
          'order_number' => strval($payment),
          'order_description' => 'Order #'.$payment,
          'items' => eddgopay_prepare_items($purchase_data['cart_details']),
          'callback' => [
            'return_url' => $redirect_url,
            'notification_url' => $redirect_url
          ],
          'lang' => $language, // if lang is not specified, then default lang is used
        ]);
        if ($response->hasSucceed()) {
          edd_empty_cart();
          wp_redirect($response->json['gw_url']);
					exit;
        }else{
          //print_r($response);
					//exit;
          $location = get_permalink($edd_options['failure_page']);
          header('Location: ' . $location);
        }
}
add_action( 'edd_gateway_eddgopay', 'eddgopay_process_payment' );

function eddgopay_add_settings( $settings ) {

	$gopay_settings = array(
		array(
			'id' => 'eddgopay_settings',
			'name' => '<strong>' . __( 'Nastavení GoPay', 'eddgopay' ) . '</strong>',
			'desc' => __( 'Configure the gateway settings', 'eddgopay' ),
			'type' => 'header'
		),
		array(
			'id' => 'eddgopay_test_goid',
			'name' => '<strong> ' . __( 'Test GoID', 'eddgopay' ) . '</strong>',
			'desc' => __( 'Test GoID:', 'gopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddgopay_test_client_id',
			'name' => '<strong> ' . __( 'Test Client ID', 'eddgopay' ) . '</strong>',
			'desc' => __( 'Test Client ID:', 'eddgopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
    array(
			'id' => 'eddgopay_test_client_secret',
			'name' => '<strong> ' . __( 'Test Client Secret', 'eddgopay' ) . '</strong>',
			'desc' => __( 'Test Client Secret:', 'eddgopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddgopay_test_username',
			'name' => '<strong> ' . __( 'Test uživatelské jméno', 'gopay' ) . '</strong>',
			'desc' => __( 'Test uživatelské jméno:', 'gopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddgopay_test_password',
			'name' => '<strong> ' . __( 'Test heslo', 'gopay' ) . '</strong>',
			'desc' => __( 'Test heslo:', 'gopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddgopay_prod_goid',
			'name' => '<strong> ' . __( 'Vaše GoID', 'gopay' ) . '</strong>',
			'desc' => __( 'Vyplňte GoID, které jste dostali při založení platební brány', 'gopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddgopay_prod_client_id',
			'name' => '<strong> ' . __( 'Vaše Client ID', 'gopay' ) . '</strong>',
			'desc' => __( 'Vyplňte produkční Client ID, který jste dostali při založení platební brány', 'gopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
    array(
			'id' => 'eddgopay_prod_client_secret',
			'name' => '<strong> ' . __( 'Váš Client Secret', 'eddgopay' ) . '</strong>',
			'desc' => __( 'Vyplňte produkční Client Secret:', 'eddgopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'prod_username',
			'name' => '<strong> ' . __( 'Uživatelské jméno', 'gopay' ) . '</strong>',
			'desc' => __( 'Vyplňte uživatelské jméno, které jste dostali při založení platební brány', 'gopay' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'prod_password',
			'name' => '<strong> ' . __( 'Heslo', 'gopay' ) . '</strong>',
			'desc' => __( 'Vyplňte heslo, které jste dostali při založení platební brány', 'gopay' ),
			'type' => 'text',
			'size' => 'regular'
		)

	);

	return array_merge( $settings, $gopay_settings );
}
add_filter( 'edd_settings_gateways', 'eddgopay_add_settings' );

function eddgopay_prepare_items($cart){
  $items = array();
  foreach($cart as $cart_item){
    $gopay_item = array(
      'name' => $cart_item['name'],
      'amount' => $cart_item['item_price'] * 100 * $cart_item['quantity'],
      'count' => $cart_item['quantity'],
    );
    array_push($items,$gopay_item);
  }
  return $items;
}

function eddgopay_getCustomerData($purchase_data){
      $gopay_street = $purchase_data ['user_info']['address']['line1'] . $purchase_data ['user_info']['address']['line2'];
      $customerData = array(
			     'firstName' => $purchase_data['user_info']['first_name'],
			     'lastName' => $purchase_data['user_info']['last_name'],
			     'city' => $purchase_data['user_info']['address']['city'],
			     'street' => $gopay_street,
			     'postalCode' => $purchase_data['user_info']['address']['zip'],
			     'countryCode' => "CZE",
			     'email' => $purchase_data ['user_info']['email'],
			     'phoneNumber' => ""
   			    );

      return $customerData;
}

function eddgopay_get_all_swift_codes(){
      return array(
        BankSwiftCode::CESKA_SPORITELNA,
        BankSwiftCode::KOMERCNI_BANKA,
        BankSwiftCode::RAIFFEISENBANK,
        BankSwiftCode::MBANK,
        BankSwiftCode::FIO_BANKA,
        BankSwiftCode::CSOB,
        BankSwiftCode::ERA,
        BankSwiftCode::UNICREDIT_BANK_CZ,
        BankSwiftCode::VSEOBECNA_VEROVA_BANKA_BANKA,
        BankSwiftCode::TATRA_BANKA,
        BankSwiftCode::UNICREDIT_BANK_SK,
        BankSwiftCode::SLOVENSKA_SPORITELNA,
        BankSwiftCode::POSTOVA_BANKA,
        BankSwiftCode::CSOB_SK,
        BankSwiftCode::SBERBANK_SLOVENSKO,
        BankSwiftCode::SPECIAL,
        BankSwiftCode::MBANK1,
        BankSwiftCode::CITI_HANDLOWY,
        BankSwiftCode::IKO,
        BankSwiftCode::INTELIGO,
        BankSwiftCode::PLUS_BANK,
        BankSwiftCode::BANK_BPH_SA,
        BankSwiftCode::TOYOTA_BANK,
        BankSwiftCode::VOLKSWAGEN_BANK,
        BankSwiftCode::SGB,
        BankSwiftCode::POCZTOWY_BANK,
        BankSwiftCode::BGZ_BANK,
        BankSwiftCode::IDEA,
        BankSwiftCode::BPS,
        BankSwiftCode::GETIN_ONLINE,
        BankSwiftCode::BLIK,
        BankSwiftCode::NOBLE_BANK,
        BankSwiftCode::ORANGE,
        BankSwiftCode::BZ_WBK,
        BankSwiftCode::RAIFFEISEN_BANK_POLSKA_SA,
        BankSwiftCode::POWSZECHNA_KASA_OSZCZEDNOSCI_BANK_POLSKI_SA,
        BankSwiftCode::ALIOR_BANK,
        BankSwiftCode::ING_BANK_SLASKI,
        BankSwiftCode::PEKAO_SA,
        BankSwiftCode::GETIN_ONLINE1,
        BankSwiftCode::BANK_MILLENNIUM,
        BankSwiftCode::BANK_OCHRONY_SRODOWISKA,
        BankSwiftCode::BNP_PARIBAS_POLSKA,
        BankSwiftCode::CREDIT_AGRICOLE,
        BankSwiftCode::DEUTSCHE_BANK_POLSKA_SA,
        BankSwiftCode::DNB_NORD,
        BankSwiftCode::E_SKOK,
        BankSwiftCode::EUROBANK,
        BankSwiftCode::POLSKI_BANK_PRZEDSIEBIORCZOSCI_SPOLKA_AKCYJNA
      );
}



function eddgopay_pingback() {
  global $edd_options;
  $payment_id = $_GET['id'];
  $edd_order_id = $_GET['payment_id'];
  if ( !edd_is_test_mode() ) {
        $gopay = GoPay\payments([
            'goid' => $edd_options['eddgopay_prod_goid'],
            'clientId' => $edd_options['eddgopay_prod_client_id'],
            'clientSecret' => $edd_options['eddgopay_prod_client_secret'],
            'isProductionMode' => true,
            'language' => $language
          ]);
      }else {
        $gopay = GoPay\payments([
          'goid' => $edd_options['eddgopay_test_goid'],
          'clientId' => $edd_options['eddgopay_test_client_id'],
          'clientSecret' => $edd_options['eddgopay_test_client_secret'],
          'isProductionMode' => false,
          'language' => $language
          ]);
  }
  $response = $gopay->getStatus($payment_id);
  $location = get_permalink($edd_options['failure_page']);
  if ($response->hasSucceed()) {
        // response format: https://doc.gopay.com/en/?shell#status-of-the-payment
        if ($response->json['state'] == PaymentStatus::PAID){
          update_post_meta($edd_order_id,'eddgopay_stav_platby','Zaplaceno');
          edd_update_payment_status( $edd_order_id, 'publish' );
	        edd_send_to_success_page();
        }
        else{
          update_post_meta($edd_order_id,'eddgopay_stav_platby',$response->json['state']);
					wp_redirect($location);
					exit;
          echo "<script>";
          echo "window.location=\"" .$location. "\"";
          echo "</script>";
        }
      } else {
        update_post_meta($edd_order_id,'eddgopay_stav_platby','Kritická chyba objednávky');
				wp_redirect($location);
				exit;
        echo "<script>";
        echo "window.location=\"" .$location. "\"";
        echo "</script>";
      }
}


function eddgopay_listen_for_gopay_pingback() {
	global $edd_options;

	// Regular GoPay IPN
	if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'eddgopay' ) {
    eddgopay_pingback();
	}
}
add_action( 'init', 'eddgopay_listen_for_gopay_pingback' );

function edd_get_default_eddgopay_admin_notification_email() {
 $default_email_body = "Nová objednávka na webu" . "\n\n" . sprintf( __( 'A %s purchase has been made', 'easy-digital-downloads' ), edd_get_label_plural() ) . ".\n\n";
 $default_email_body .= sprintf( __( '%s sold:', 'easy-digital-downloads' ), edd_get_label_plural() ) . "\n\n";
 $default_email_body .= '{download_list}' . "\n\n";
 $default_email_body .= __( 'Purchased by: ', 'easy-digital-downloads' ) . ' {name}' . "\n";
 $default_email_body .= __( 'Amount: ', 'easy-digital-downloads' ) . '{price}' . "\n";
 $default_email_body .= __( 'Payment Method: ', 'easy-digital-downloads' ) . ' {payment_method}' . "\n\n";
 $default_email_body .= __( 'Thank you', 'easy-digital-downloads' );
 $message = edd_get_option( 'eddgopay_admin_mail_text', false );
 $message = ! empty( $message ) ? $message : $default_email_body;
 return $message;
}

function eddgopay_settings_section( $sections ) {
	$sections['eddgopay-mail'] = __( 'E-Mailové notifikace po nákupu přes Gopay', 'eddgopay' );
	return $sections;
}
add_filter( 'edd_settings_sections_emails', 'eddgopay_settings_section' );

function eddgopay_email_settings($settings){
	$pavel_settings = array(
	          array(
	            'id'   => 'eddgopay_email_settings',
	            'name' => '<strong>' . __( 'Nastavení notifikačních mailů při nákupu přes GoPay bránu', 'eddgopay' ) . '</strong>',
	            'desc' => __( 'Nastavte znění mailů', 'eddpdfi' ),
	            'type' => 'header'
	          ),
						array(
							'id'   => 'eddgopay_admin_mail_subject',
							'name' => __( 'Předmět mailu pro admina', 'eddfio' ),
							'desc' => __( 'Uveďte předmět zprávy, notifikace pro administrátora stránek', 'easy-digital-downloads' ),
							'type' => 'text',
							'std'  => 'Nová objednávka #{payment_id}'
						),
						array(
							'id'   => 'eddgopay_admin_mail_text',
							'name' => __( 'Text mailu pro admina', 'easy-digital-downloads' ),
							'desc' => __( 'Uveďte zprávu, kterou obdrží admin stránek. Dostupné tagy:', 'easy-digital-downloads' ) . '<br/>' . edd_get_emails_tags_list(),
							'type' => 'rich_editor',
							'std'  => edd_get_default_eddgopay_admin_notification_email()
						),
						array(
							'id'   => 'eddgopay_send_notification',
							'name' => __( 'Zasílat e-mailové notifikace', 'easy-digital-downloads' ),
							'desc' => __( 'E-mailové notifikace po nákupu se začnou zasílat po zaškrtnutí tohoto políčka', 'easy-digital-downloads' ),
							'type' => 'checkbox',

            ),
            array(
							'id'   => 'eddgopay_admin_error_subject',
							'name' => __( 'Předmět mailu pro admina při nezpracované platbě', 'eddgopay' ),
							'desc' => __( 'Uveďte předmět zprávy, notifikace pro administrátora stránek', 'eddgopay' ),
							'type' => 'text',
							'std'  => 'Platba #{payment_id} nebyla uhrazena'
            ),
            array(
							'id'   => 'eddgopay_admin_error_text',
							'name' => __( 'Text mailu pro admina', 'easy-digital-downloads' ),
							'desc' => __( 'Uveďte zprávu, kterou obdrží admin stránek. Dostupné tagy:', 'easy-digital-downloads' ) . '<br/>' . edd_get_emails_tags_list(),
							'type' => 'rich_editor',
							'std'  => edd_get_default_eddgopay_admin_error_notification_email()
            ),
            array(
							'id'   => 'eddgopay_send_error_notification',
							'name' => __( 'Zasílat e-mailové notifikace při chybách', 'easy-digital-downloads' ),
							'desc' => __( 'E-mailové notifikace po nákupu se začnou zasílat po zaškrtnutí tohoto políčka', 'easy-digital-downloads' ),
							'type' => 'checkbox',
            ),
            array(
              'id'   => 'eddgopay_admin_error_emails',
              'name' => __( 'Zasílat chyby na jiné e-maily', 'eddgopay' ),
              'desc' => __( 'Enter the email address(es) that should receive a notification anytime a sale is made, one per line.', 'easy-digital-downloads' ),
              'type' => 'textarea',
              'std'  => get_bloginfo( 'admin_email' ),
            ),



	        );
	        if ( version_compare( EDD_VERSION, 2.5, '>=' ) ) {
	          $pavel_settings = array( 'eddgopay-mail' => $pavel_settings );
	        }

	return array_merge( $settings, $pavel_settings );
	}

	add_filter( 'edd_settings_emails', 'eddgopay_email_settings' );

	function eddgopay_get_admin_notice_emails() {

	 	global $edd_options;

	 	$emails = isset( $edd_options['eddgopay_send_notification'] ) && strlen( trim( $edd_options['admin_notice_emails'] ) ) > 0 ? $edd_options['admin_notice_emails'] : get_bloginfo( 'admin_email' );
	 	$emails = array_map( 'trim', explode( "\n", $emails ) );

	 	return apply_filters( 'edd_admin_notice_emails', $emails );
   }
   
   edd_add_email_tag( 'stav_platby', 'Navrácený stav platby z GoPay', 'eddgopay_edd_email_tag_stav_platby' );

   function eddgopay_edd_email_tag_stav_platby($payment_id){
     return get_post_meta($payment_id,'eddgopay_stav_platby',true);
   }
