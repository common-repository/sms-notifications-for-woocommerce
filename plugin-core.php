<?php

if (!defined('ABSPATH')) exit;  

//Check whether WPML is active
$wpml_active = function_exists('icl_object_id');
$wpml_regstr = function_exists('icl_register_string');
$wpml_trnslt = function_exists('icl_translate');

//Obtain the settings
$suwcsms_settings = get_option('suwcsms_settings');
global $suwcsms_logger;

function suwcsms_field($var)
{
    global $suwcsms_settings;
    return isset($suwcsms_settings[$var]) ? $suwcsms_settings[$var] : '';
}

//Utility function for registering string to WPML
function suwcsms_register_string($str)
{
    global $suwcsms_settings, $wpml_active, $wpml_regstr;
    if ($wpml_active) {
        ($wpml_regstr) ?
            icl_register_string('suwcsms', $str, $suwcsms_settings[$str]) :
            do_action('wpml_register_single_string', 'suwcsms', $str, $suwcsms_settings[$str]);
    }
}

//Utility function to fetch string from WPML
function suwcsms_fetch_string($str)
{
    global $suwcsms_settings, $wpml_active, $wpml_trnslt;
    if ($wpml_active) {
        return ($wpml_trnslt) ?
            icl_translate('suwcsms', $str, $suwcsms_settings[$str]) :
            apply_filters('wpml_translate_single_string', $suwcsms_settings[$str], 'suwcsms', $str);
    }
    return suwcsms_field($str);
}

//Add phone field to Shipping Address
add_filter('woocommerce_checkout_fields', 'suwcsms_add_shipping_phone_field');
function suwcsms_add_shipping_phone_field($fields)
{
    if (!isset($fields['shipping']['shipping_phone'])) {
        $fields['shipping']['shipping_phone'] = array(
            'label' => __('Mobile Phone', 'woocommerce'),
            'placeholder' => _x('Mobile Phone', 'placeholder', 'woocommerce'),
            'required' => false,
            'class' => array('form-row-wide'),
            'clear' => true
        );
    }
    return $fields;
}

//Display shipping phone field on order edit page
add_action('woocommerce_admin_order_data_after_shipping_address', 'suwcsms_display_shipping_phone_field', 10, 1);
function suwcsms_display_shipping_phone_field($order)
{
    echo '<p><strong>' . __('Shipping Phone') . ':</strong> ' . get_post_meta($order->get_id(), '_shipping_phone', true) . '</p>';
}

//Change label of billing phone field
add_filter('woocommerce_checkout_fields', 'suwcsms_phone_field_label');
function suwcsms_phone_field_label($fields)
{
    $fields['billing']['billing_phone']['label'] = 'Mobile Phone';
    return $fields;
}

//Initialize the plugin
add_action('init', 'suwcsms_initialize');
function suwcsms_initialize()
{
    suwcsms_register_string('msg_new_order');
    suwcsms_register_string('msg_pending');
    suwcsms_register_string('msg_on_hold');
    suwcsms_register_string('msg_processing');
    suwcsms_register_string('msg_completed');
    suwcsms_register_string('msg_cancelled');
    suwcsms_register_string('msg_refunded');
    suwcsms_register_string('msg_failure');
    suwcsms_register_string('msg_custom');
}

//Add settings page to woocommerce admin menu 
add_action('admin_menu', 'suwcsms_admin_menu', 20);
function suwcsms_admin_menu()
{
    
    add_submenu_page('woocommerce', __('WooCommerce SMS Notification Settings', 'suwcsms'), __('SMS Notifications for WooCommerce', 'suwcsms'), 'manage_woocommerce', 'suwcsms', 'suwcsms_tab');
    function suwcsms_tab()
    {
        include('settings-page.php');
    }
}

//Add screen id for enqueuing WooCommerce scripts
add_filter('woocommerce_screen_ids', 'suwcsms_screen_id');
function suwcsms_screen_id($screen)
{
    
    $screen[] = 'woocommerce_page_suwcsms';
    return $screen;
}

//Set the options
add_action('admin_init', 'suwcsms_regiser_settings');
function suwcsms_regiser_settings()
{
    register_setting('suwcsms_settings_group', 'suwcsms_settings');
}

//Schedule notifications for new order
if (suwcsms_field('use_msg_new_order') == 1)
    add_action('woocommerce_new_order', 'suwcsms_owner_notification', 20);
function suwcsms_owner_notification($order_id)
{
    if (suwcsms_field('mnumber') == '')
        return;
    $order = new WC_Order($order_id);
    $template = apply_filters('suwcsms_new_order_template', suwcsms_fetch_string('msg_new_order'), $order_id);
    $message = suwcsms_process_variables($template, $order);
    if (empty($message))
        return;
    $owners_phone = suwcsms_process_phone($order, suwcsms_field('mnumber'), false, true);
    suwcsms_send_sms($owners_phone, $message);
    $additional_numbers = apply_filters('suwcsms_additional_numbers', suwcsms_field('addnumber'), $order_id);
    if (!empty($additional_numbers)) {
        $numbers = array_filter(explode(",", $additional_numbers));
        foreach ($numbers as $number) {
            $phone = suwcsms_process_phone($order, trim($number), false, true);
            suwcsms_send_sms($phone, $message);
        }
    }
}

//Schedule notification for abandoned cart
if (suwcsms_field('use_msg_abandon') == 1) {
    if (suwcsms_field('abandon_checkout') == 1) {
        add_action( 'woocommerce_after_checkout_form', 'suwcsms_checkout_page_js' );   
    }
    if (suwcsms_field('abandon_place_order') == 1) {
        add_action( 'woocommerce_new_order', 'suwcsms_new_order_abandon', 1000 );
    }
    add_action( 'woocommerce_thankyou', 'suwcsms_remove_abandon_entry' );
    add_action( 'suwcsms_cron_hook', 'suwcsms_send_abandon_notifications' );
}

function suwcsms_checkout_page_js() {
?><script>jQuery(function($){
var prev_phone = false;
function validate_suwcsms_data() {
    var name = $('#billing_first_name').val(),
        phone = $('#billing_phone').val(),
        country = $('#billing_country').val();
    if (name.length && phone.length && country.length) {
        const url = '<?php echo esc_url(admin_url("admin-ajax.php")); ?>';
        if (prev_phone) {
            $.post(url, {'action': 'suwcsms_del_checkout', 'phone': prev_phone});
        }
        var data = {
            'action' : 'suwcsms_reg_checkout',
            'country' : country,
            'phone' : phone,
            'name' : name,
        };
        $.post(url, data, function(res) {
            prev_phone = res.data.billing_phone || prev_phone;
        });
    }
}
validate_suwcsms_data();
$('#billing_first_name,#billing_phone,#billing_country').change(validate_suwcsms_data);
});</script><?php        
}

function suwcsms_new_order_abandon( $order_id ) {
    global $wpdb, $suwcsms_db_table;
    $order = wc_get_order( $order_id );
    $country = $order->get_billing_country();
    $phone = $order->get_billing_phone();
    $name = $order->get_billing_first_name();
    $billing_phone = suwcsms_sanitize_phone_number( $country, $phone );
    if ( empty( $billing_phone ) ) return;
    $wpdb->replace( $suwcsms_db_table, ['billing_phone' => $billing_phone, 'first_name' => $name, 'order_id' => $order_id], ['%s', '%s', '%d'] );
    suwcsms_log_message( 'Updated suwcsms_db for billing phone ' . $billing_phone );
}

function suwcsms_remove_abandon_entry( $order_id ) {
    global $wpdb, $suwcsms_db_table;
    $order = wc_get_order( $order_id );
    $country = $order->get_billing_country();
    $phone = $order->get_billing_phone();
    $billing_phone = suwcsms_sanitize_phone_number( $country, $phone );
    if ( empty( $billing_phone ) ) return;
    $wpdb->delete( $suwcsms_db_table, ['billing_phone' => $billing_phone] );
    suwcsms_log_message( 'Deleted suwcsms_db for billing phone ' . $billing_phone );
}

add_action('wp_ajax_suwcsms_reg_checkout', 'suwcsms_reg_checkout_callback');
add_action('wp_ajax_nopriv_suwcsms_reg_checkout', 'suwcsms_reg_checkout_callback');
function suwcsms_reg_checkout_callback() {
    global $wpdb, $suwcsms_db_table;
    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'suwcsms_reg_checkout') {
        $country = sanitize_text_field( $_REQUEST['country'] );
        $phone = sanitize_text_field( $_REQUEST['phone'] );
        $name = sanitize_text_field( $_REQUEST['name'] );
        $billing_phone = suwcsms_sanitize_phone_number( $country, $phone );
        if ( empty( $billing_phone ) ) die();
        $wpdb->replace( $suwcsms_db_table, ['billing_phone' => $billing_phone, 'first_name' => $name] );
        suwcsms_log_message( 'Updated suwcsms_db for billing phone ' . $billing_phone );
        wp_send_json_success( ['billing_phone' => $billing_phone] );
    }
    die();
}

add_action('wp_ajax_suwcsms_del_checkout', 'suwcsms_del_checkout_callback');
add_action('wp_ajax_nopriv_suwcsms_del_checkout', 'suwcsms_del_checkout_callback');
function suwcsms_del_checkout_callback() {
    global $wpdb, $suwcsms_db_table;
    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'suwcsms_del_checkout') {
        $billing_phone = sanitize_text_field( $_REQUEST['phone'] );
        if ( empty( $billing_phone ) ) die();
        $wpdb->delete( $suwcsms_db_table, ['billing_phone' => $billing_phone] );
        suwcsms_log_message( 'Deleted suwcsms_db for billing phone ' . $billing_phone );
    }
    die();
}
function suwcsms_send_abandon_notifications() {
    suwcsms_log_message( 'Initiated scheduled event: suwcsms_send_abandon_notifications' );
    suwcsms_send_bulk_notifications( 'msg_abandon', suwcsms_field('abandon_delay') );
    $n = suwcsms_field('abandon_reminders_count');
    for ($i=0; $i<$n; $i++) {
        $c = "abandon_reminder_" . $i;
        $t = $c . '_template';
        $d = suwcsms_field($c . '_duration') ?: 0;
        $k = suwcsms_field($c . '_time_unit') ?: 1;
        suwcsms_send_bulk_notifications( $t, $d * $k, $i+1 );
    }
}

function suwcsms_send_bulk_notifications( $template_id, $delay_mins, $reminder_id=0 ) {
    global $wpdb, $suwcsms_db_table;
    if ( empty( $template_id ) || empty( $delay_mins ) ) return;
    $template = suwcsms_fetch_string( $template_id );
    if ( empty( $template ) ) return;
    $flag = $reminder_id ? "reminder_{$reminder_id}_sent" : 'msg_sent';
    $rows = $wpdb->get_results( "SELECT billing_phone, first_name, order_id FROM $suwcsms_db_table WHERE register_ts <= CURRENT_TIMESTAMP - INTERVAL $delay_mins MINUTE AND $flag = 0 ORDER BY register_ts" );
    if ( empty( $rows ) ) return;
    foreach ( $rows as $row ) {
        $billing_phone = $row->billing_phone;
        if ( empty($billing_phone) ) continue;
        $order = null;
        $additional_data = ['first_name' => $row->first_name, 'cart_link' => wc_get_cart_url()];
        if ( $row->order_id ) {
            $order = wc_get_order( $row->order_id );
            if ( $order ) {
                $additional_data['cart_link'] = $order->get_checkout_payment_url();
            }
        }
        $message = suwcsms_process_variables($template, $order, $additional_data);
        suwcsms_send_sms($billing_phone, $message);
        suwcsms_log_message( "$flag for billing phone $billing_phone" );
        $wpdb->update( $suwcsms_db_table, [$flag => 1], ['billing_phone' => $billing_phone], ['%d'] );
    }
}

add_filter('woocommerce_cod_process_payment_order_status', 'suwcsms_cod_order_status', 1);
function suwcsms_cod_order_status($status)
{
    return suwcsms_field('otp_pre_status');
}

add_action('woocommerce_thankyou', 'suwcsms_otp_verify_order', 1);
add_action('woocommerce_view_order', 'suwcsms_otp_verify_order', 1);
function suwcsms_otp_verify_order($order_id)
{
    $otp_cod = suwcsms_field('otp_cod');
    $otp_bacs = suwcsms_field('otp_bacs');
    $otp_cheque = suwcsms_field('otp_cheque');
    $payment_method = get_post_meta($order_id, '_payment_method', true);
    $otp_verified = get_post_meta($order_id, 'otp_verified', true);
    if ((($otp_cod && ($payment_method == 'cod')) || ($otp_bacs && ($payment_method == 'bacs')) || ($otp_cheque && ($payment_method == 'cheque'))) && ('Yes' != $otp_verified)) {
        $phone = get_post_meta($order_id, '_billing_phone', true);
        update_post_meta($order_id, 'otp_verified', 'No');
        suwcsms_send_new_order_otp($order_id, $phone);
        suwcsms_display_otp_verification($order_id, $phone);
    }
}

//Verify OTP via AJAX
add_action('wp_ajax_suwcsms_verify_otp', 'suwcsms_verify_otp_callback');
add_action('wp_ajax_nopriv_suwcsms_verify_otp', 'suwcsms_verify_otp_callback');
function suwcsms_verify_otp_callback()
{
    if (isset($_POST['action']) && $_POST['action'] == 'suwcsms_verify_otp') {
        $data = ['error' => true, 'message' => 'OTP could not be verified', 'verification_failure' => true];
        if (isset($_POST['order_id'])) {
            $order_id = sanitize_text_field($_POST['order_id']);
            $otp_submitted = sanitize_text_field($_POST['otp'] ?? '');
            $otp_stored = get_post_meta($order_id, 'otp_value', true);
            if ($otp_stored == $otp_submitted) {
                update_post_meta($order_id, 'otp_verified', 'Yes');
                $pre_status = suwcsms_field('otp_pre_status');
                $post_status = suwcsms_field('otp_post_status');
                $order = wc_get_order($order_id);
                $order->update_status($post_status);
                $data = ['success' => true, 'message' => "Thank You! Your order #$order_id has been confirmed.", 'otp_verified' => true];
            }
        }
        wp_send_json($data);
    }
    die();
}

function suwcsms_sanitize_phone_number($country, $number) {
    $intl_prefix = suwcsms_country_prefix($country);
    $phone = str_replace(array('+', '-'), '', filter_var($number, FILTER_SANITIZE_NUMBER_INT));
    $phone = ltrim($phone, '0');
    preg_match("/(\d{1,4})[0-9.\- ]+/", $phone, $prefix);
    if (strpos($prefix[1], $intl_prefix) !== 0) {
        $phone = $intl_prefix . $phone;
    }
    /* if (strpos($prefix[1], "+") !== 0 ) {
        $phone = "+" . $phone;
    } */
    return $phone;
}

//Request OTP resend via AJAX
add_action('wp_ajax_suwcsms_resend_otp', 'suwcsms_resend_otp_callback');
add_action('wp_ajax_nopriv_suwcsms_resend_otp', 'suwcsms_resend_otp_callback');
function suwcsms_resend_otp_callback()
{
    if (isset($_POST['action']) && $_POST['action'] == 'suwcsms_resend_otp') {
        $data = ['error' => true, 'message' => 'Failed to send OTP'];
        if (isset($_POST['order_id'])) {
            $order_id = sanitize_text_field($_POST['order_id']);
            $otp_verified = get_post_meta($order_id, 'otp_verified', true);
            if ($otp_verified != 'Yes') {
                $phone = get_post_meta($order_id, '_billing_phone', true);
                suwcsms_send_new_order_otp($order_id, $phone);
                $data = ['success' => true, 'message' => "OTP Sent to $phone for order #$order_id"];
            }
        }
        wp_send_json($data);
    }
    die();
}

//Request OTP send via AJAX
add_action('wp_ajax_suwcsms_send_otp', 'suwcsms_send_otp_callback');
add_action('wp_ajax_nopriv_suwcsms_send_otp', 'suwcsms_send_otp_callback');
function suwcsms_send_otp_callback()
{
    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'suwcsms_send_otp') {
        $data = ['error' => true, 'message' => 'Failed to generate OTP. Ensure that you have entered the correct number.', 'number' => NULL];
        $country_code = sanitize_text_field($_REQUEST['country']);
        $billing_phone = sanitize_text_field($_REQUEST['phone']);
        if (!empty($country_code) && !empty($billing_phone)) {
            $user_phone = suwcsms_sanitize_phone_number( $country_code, $billing_phone );
            $transient_id = 'OTP_REG_' . $user_phone;
            $otp_number = get_transient( $transient_id ) ?: suwcsms_generate_otp();
            set_transient( $transient_id, $otp_number, 600 );
            $message = suwcsms_process_variables(suwcsms_fetch_string('msg_otp_checkout'), $order, ['otp' => $otp_number]);
            suwcsms_send_otp($user_phone, $message);
            $data = ['success' => true, 'message' => "OTP sent successfully to $user_phone", 'number' => $user_phone];
        }
        wp_send_json($data);
    }
    die();
}


function suwcsms_generate_otp()
{
    return mt_rand(100000, 999999);
}

function suwcsms_send_new_order_otp($order_id, $phone)
{
    $order = wc_get_order($order_id);
    $phone = suwcsms_process_phone($order, $phone);
    $otp_number = suwcsms_generate_otp();
    $template = apply_filters('suwcsms_new_order_otp_template', suwcsms_fetch_string('msg_otp_new_order'), $order_id);
    $message = suwcsms_process_variables($template, $order, ['otp' => $otp_number]);
    suwcsms_send_otp($phone, $message);
    update_post_meta($order_id, 'otp_value', $otp_number);
}

add_action('woocommerce_before_order_notes', 'suwcsms_otp_order_checkout');
function suwcsms_otp_order_checkout() {
    if (suwcsms_field('require_checkout_otp')) { ?>
    <h3>OTP Verification</h3>
    <div id='su-otp-verification-block' style='background:#EEE;padding:10px;border-radius:5px'>
        <div class='suwcsms-notifications'>
            <div class="woocommerce-info">
            An OTP has been sent to your Billing Phone. You need to enter the OTP below before you can place your order.
            </div>
        </div>
        <center>
        <label style='font-weight:bold;color:#000'>OTP </label>
        <input id='suwcsms-otp-field' size='6' style='letter-spacing:5px;font-weight:bold;padding:10px' name='suwcsms_order_otp'/>
        <input id='suwcsms_resend_otp_btn' type='button' class='button alt' value='Resend OTP'/>
        </center>
        <p>Please make sure you are in a good mobile signal zone. Resend button will get activated in 30 seconds. Please request again if you have not received the OTP in next 30 seconds.</p>
    </div>
    <script>
    jQuery(function($){
        var otp_failure_count = 0,
            otp_resend_count = 0,
            country = '',
            phone = '',
            url = '<?php echo esc_url(admin_url("admin-ajax.php")); ?>';
        function suwcsms_resend_otp() {
            if (country == '' || phone == '') return;
            var data = {
                'action' : 'suwcsms_send_otp',
                'country' : country,
                'phone' : phone
            };
            $.get(url, data, function(res){
                $('#su-otp-verification-block').show();
                if (res.success) {
                    disableResendOTP();
                    otp_resend_count++;
                } else {
                    otp_failure_count++;
                }
                $('.suwcsms-notifications > .woocommerce-info').text(res.message);
            });
        }
        function enableResendOTP() {
            if (otp_resend_count < 3) {
                $('#suwcsms_resend_otp_btn').prop('disabled', false);
            }
        }
        function disableResendOTP() {
            $('#suwcsms_resend_otp_btn').prop('disabled', true);
            setTimeout(enableResendOTP, 30000);
        }
        $('#suwcsms_resend_otp_btn').click(suwcsms_resend_otp);
        $('input[name="billing_phone"]').change(function(){
            phone = $(this).val().trim();
            if (phone != '') suwcsms_resend_otp();
        }).change();
        $('select[name="billing_country"]').change(function(){
            country = $(this).val().trim();
            if (country != '') suwcsms_resend_otp();
        }).change();
    });
    </script>
    <?php }
}

add_action('woocommerce_checkout_process','suwcsms_validate_order_otp');
function suwcsms_validate_order_otp() {
    if (suwcsms_field('require_checkout_otp')) {
        $country_code = sanitize_text_field($_POST['billing_country'] ?? '');
        $billing_phone = sanitize_text_field($_REQUEST['billing_phone'] ?? '');
        if (!empty($country_code) && !empty($billing_phone)) {
            $otp = sanitize_text_field($_POST['suwcsms_order_otp'] ?? '');
            if (!$otp) {
                wc_add_notice( __( 'OTP Verification is required.' ), 'error' );
                return;
            }
            $user_phone = suwcsms_sanitize_phone_number( $country_code, $billing_phone );
            $transient_id = 'OTP_REG_' . $user_phone;
            $otp_number = get_transient($transient_id);
            if ($otp_number && $otp_number == $otp) {
                return;
            } else {
                wc_add_notice( __( 'OTP Verification failed. Please enter the correct OTP.' ), 'error' );
            }
        }
    }
}

function suwcsms_display_otp_verification($order_id, $phone)
{
    ?>
    <script type='text/javascript'>
    jQuery(function($){
        var otp_failure_count = 0,
            otp_resend_count = 0;
        function showSpinner() {
            $('.suwcsms-notifications').html('<center><img src="<?php echo esc_url( admin_url("images/spinner-2x.gif") ) ?>"/></center>');
        }
        function process_json_response(response) {
            var jsonobj = JSON.parse(JSON.stringify(response));
            if (jsonobj.error) {
                $('.suwcsms-notifications').html('<div class="woocommerce-error">'+jsonobj.message+'</div>');
                if (jsonobj.verification_failure) {
                    otp_failure_count++;
                    if (otp_failure_count > 3) {
                        $('.suwcsms-notifications').append('<br/><h3>It seems that there is a difficulty in verifying your order. Please call our support number to verify your order.</h3>');
                    }
                }
            } else {
                if (jsonobj.otp_verified) {
                    $('#su-otp-verification-block').html('<h3>'+jsonobj.message+'</h3>');
                } else {
                    $('.suwcsms-notifications').html('<div class="woocommerce-message">'+jsonobj.message+'</div>');
                    otp_resend_count++;
                }
            }
        }
        function suwcsms_verify_otp() {
            showSpinner();
            var data = {
                'action' : 'suwcsms_verify_otp',
                'order_id' : <?php echo esc_attr( $order_id ) ?>,
                'otp' : document.getElementById('suwcsms-otp-field').value
            };
            $.post(
                "<?php echo esc_url(admin_url("admin-ajax.php")); ?>",
                data,
                process_json_response
            );
        }
        function suwcsms_resend_otp() {
            showSpinner();
            var data = {
                'action' : 'suwcsms_resend_otp',
                'order_id' : <?php echo esc_attr( $order_id ) ?>
            };
            $.post(
                "<?php echo esc_url(admin_url("admin-ajax.php")); ?>",
                data,
                process_json_response
            );
            disableResendOTP();
        }
        function enableResendOTP() {
            if (otp_resend_count < 3) {
                $('#suwcsms_resend_otp_btn').prop('disabled', false);
            }
        }
        function disableResendOTP() {
            $('#suwcsms_resend_otp_btn').prop('disabled', true);
            setTimeout(enableResendOTP, 30000);
        }
        $('p.woocommerce-thankyou-order-received, ul.woocommerce-thankyou-order-details').hide();
        $('#suwcsms_verify_otp_btn').click(suwcsms_verify_otp);
        $('#suwcsms_resend_otp_btn').click(suwcsms_resend_otp);
        disableResendOTP();
    });
    </script>
    <div id='su-otp-verification-block' style='background:#EEE;padding:10px;border-radius:5px'>
        <h3>OTP Verification</h3>
        <div class='suwcsms-notifications'>
            <div class="woocommerce-info">
            OTP sent to mobile no: <?php echo esc_html( $phone ) ?> for order #<?php echo esc_attr( $order_id ) ?>. Your order will be confirmed upon completion of OTP verification.
            </div>
        </div>
        <center>
        <label style='font-weight:bold;color:#000'>OTP </label>
        <input id='suwcsms-otp-field' size='6' style='letter-spacing:5px;font-weight:bold;padding:10px'/>
        <input id='suwcsms_verify_otp_btn' type='button' class='button' value='Verify'/>
        <input id='suwcsms_resend_otp_btn' type='button' class='button alt' value='Resend OTP'/>
        </center>
        <p>Please make sure you are in a good mobile signal zone. Resend button will get activated in 30 seconds. Please request again if you have not received the OTP in next 30 seconds.</p>
    </div>
    <?php
}
    
//Schedule notifications for order status change
add_action('woocommerce_order_status_changed', 'suwcsms_process_status', 10, 3);
function suwcsms_process_status($order_id, $old_status, $status)
{
    $order = new WC_Order($order_id);
    $shipping_phone = false;
    $phone = $order->get_billing_phone();

    //If have to send messages to shipping phone
    if (suwcsms_field('alt_phone') == 1) {
        $phone = get_post_meta($order->get_id(), '_shipping_phone', true);
        $shipping_phone = true;
    }
    
    //Remove old 'wc-' prefix from the order status
    $status = str_replace('wc-', '', $status);
    
    //Sanitize the phone number
    $phone = suwcsms_process_phone($order, $phone, $shipping_phone);
    
    //Get the message corresponding to order status
    $template = "";
    switch ($status) {
        case 'pending':
            if (suwcsms_field('use_msg_pending') == 1)
                $template = apply_filters('suwcsms_new_order_template', suwcsms_fetch_string('msg_new_order'), $order_id);
            break;
        case 'on-hold':
            if (suwcsms_field('use_msg_on_hold') == 1)
                $template = apply_filters('suwcsms_on_hold_template', suwcsms_fetch_string('msg_on_hold'), $order_id);
            break;
        case 'processing':
            if (suwcsms_field('use_msg_processing') == 1)
                $template = apply_filters('suwcsms_processing_template', suwcsms_fetch_string('msg_processing'), $order_id);
            break;
        case 'completed':
            if (suwcsms_field('use_msg_completed') == 1)
                $template = apply_filters('suwcsms_completed_template', suwcsms_fetch_string('msg_completed'), $order_id);
            break;
        case 'cancelled':
            if (suwcsms_field('use_msg_cancelled') == 1)
                $template = apply_filters('suwcsms_cancelled_template', suwcsms_fetch_string('msg_cancelled'), $order_id);
            break;
        case 'refunded':
            if (suwcsms_field('use_msg_refunded') == 1)
                $template = apply_filters('suwcsms_refunded_template', suwcsms_fetch_string('msg_refunded'), $order_id);
            break;
        case 'failed':
            if (suwcsms_field('use_msg_failure') == 1)
                $template = apply_filters('suwcsms_failure_template', suwcsms_fetch_string('msg_failure'), $order_id);
            break;
        default:
            if (suwcsms_field('use_msg_custom') == 1)
                $template = apply_filters('suwcsms_custom_template', suwcsms_fetch_string('msg_custom'), $order_id);
    }

    //Process the template
    $message = empty($template) ? false : suwcsms_process_variables($template, $order);
    
    //Send the SMS
    if (!empty($message))
        suwcsms_send_sms($phone, $message);
}

function suwcsms_message_encode($message)
{
    return urlencode(html_entity_decode($message, ENT_QUOTES, "UTF-8"));
}

function suwcsms_process_phone($order, $phone, $shipping = false, $owners_phone = false)
{
    //Sanitize phone number
    $phone = str_replace(array('+', '-'), '', filter_var($phone, FILTER_SANITIZE_NUMBER_INT));
    $phone = ltrim($phone, '0');
     
    //Obtain country code prefix
    $country = WC()->countries->get_base_country();
    if (!$owners_phone) {
        $country = $shipping ? $order->get_shipping_country() : $order->get_billing_country();
    }
    $intl_prefix = suwcsms_country_prefix($country);

    //Check for already included prefix
    preg_match("/(\d{1,4})[0-9.\- ]+/", $phone, $prefix);
    
    //If prefix hasn't been added already, add it
    if (strpos($prefix[1], $intl_prefix) !== 0) {
        $phone = $intl_prefix . $phone;
    }
    
    /* //Prefix '+' as required
    if ( strpos( $prefix[1], "+" ) !== 0 ) {
        $phone = "+" . $phone;
    } */

    return $phone;
}


function suwcsms_process_variables($message, $order=null, $additional_data=[])
{
    $sms_strings = array("id", "status", "prices_include_tax", "tax_display_cart", "display_totals_ex_tax", "display_cart_ex_tax", "order_date", "modified_date", "customer_message", "customer_note", "post_status", "shop_name", "note", "order_product");
    $suwcsms_variables = array("order_key", "billing_first_name", "billing_last_name", "billing_company", "billing_address_1", "billing_address_2", "billing_city", "billing_postcode", "billing_country", "billing_state", "billing_email", "billing_phone", "shipping_first_name", "shipping_last_name", "shipping_company", "shipping_address_1", "shipping_address_2", "shipping_city", "shipping_postcode", "shipping_country", "shipping_state", "shipping_method", "shipping_method_title", "payment_method", "payment_method_title", "order_discount", "cart_discount", "order_tax", "order_shipping", "order_shipping_tax", "order_total", "order_currency");
    $specials = array("order_date", "modified_date", "shop_name", "id", "order_product", 'signature');
    $order_variables = $order ? get_post_custom($order->get_id()) : []; //WooCommerce 2.1
    $custom_variables = explode("\n", str_replace(array("\r\n", "\r"), "\n", suwcsms_field('variables')));
    $additional_variables = array_keys($additional_data);
    $new_line = 'nl';

    if (empty($order)) {
        $order = new WC_Order();
    }

    preg_match_all("/%(.*?)%/", $message, $search);
    foreach ($search[1] as $variable) {
        $variable = strtolower($variable);

        if ($variable == $new_line) {
            $message = str_replace("%" . $variable . "%", PHP_EOL, $message);
        }

        if (!in_array($variable, $sms_strings) && !in_array($variable, $suwcsms_variables) && !in_array($variable, $specials) && !in_array($variable, $custom_variables) && !in_array($variable, $additional_variables)) {
            continue;
        }

        if (!in_array($variable, $specials)) {
            if (in_array($variable, $sms_strings)) {
                $message = str_replace("%" . $variable . "%", $order->$variable, $message); //Standard fields
            } else if (in_array($variable, $suwcsms_variables) && isset($order_variables["_" . $variable])) {
                $message = str_replace("%" . $variable . "%", $order_variables["_" . $variable][0], $message); //Meta fields
            } else if (in_array($variable, $custom_variables) && isset($order_variables[$variable])) {
                $message = str_replace("%" . $variable . "%", $order_variables[$variable][0], $message);
            }
            if (in_array($variable, $additional_variables) && isset($additional_data[$variable])) {
                $message = str_replace("%" . $variable . "%", $additional_data[$variable], $message);
            }
        } else if ($variable == "order_date" || $variable == "modified_date") {
            $message = str_replace("%" . $variable . "%", date_i18n(woocommerce_date_format(), strtotime($order->$variable)), $message);
        } else if ($variable == "shop_name") {
            $message = str_replace("%" . $variable . "%", get_bloginfo('name'), $message);
        } else if ($variable == "id") {
            $message = str_replace("%" . $variable . "%", $order->get_order_number(), $message);
        } else if ($variable == "order_product") {
            $products = $order->get_items();
            $quantity = $products[key($products)]['name'];
            if (strlen($quantity) > 10) {
                $quantity = substr($quantity, 0, 10) . "...";
            }
            if (count($products) > 1) {
                $quantity .= " (+" . (count($products) - 1) . ")";
            }
            $message = str_replace("%" . $variable . "%", $quantity, $message);
        } else if ($variable == "signature") {
            $message = str_replace("%" . $variable . "%", suwcsms_field('signature'), $message);
        }
    }
    return $message;
}

function suwcsms_country_prefix($country = '')
{
    $countries = array(
        'AC' => '247',
        'AD' => '376',
        'AE' => '971',
        'AF' => '93',
        'AG' => '1268',
        'AI' => '1264',
        'AL' => '355',
        'AM' => '374',
        'AO' => '244',
        'AQ' => '672',
        'AR' => '54',
        'AS' => '1684',
        'AT' => '43',
        'AU' => '61',
        'AW' => '297',
        'AX' => '358',
        'AZ' => '994',
        'BA' => '387',
        'BB' => '1246',
        'BD' => '880',
        'BE' => '32',
        'BF' => '226',
        'BG' => '359',
        'BH' => '973',
        'BI' => '257',
        'BJ' => '229',
        'BL' => '590',
        'BM' => '1441',
        'BN' => '673',
        'BO' => '591',
        'BQ' => '599',
        'BR' => '55',
        'BS' => '1242',
        'BT' => '975',
        'BW' => '267',
        'BY' => '375',
        'BZ' => '501',
        'CA' => '1',
        'CC' => '61',
        'CD' => '243',
        'CF' => '236',
        'CG' => '242',
        'CH' => '41',
        'CI' => '225',
        'CK' => '682',
        'CL' => '56',
        'CM' => '237',
        'CN' => '86',
        'CO' => '57',
        'CR' => '506',
        'CU' => '53',
        'CV' => '238',
        'CW' => '599',
        'CX' => '61',
        'CY' => '357',
        'CZ' => '420',
        'DE' => '49',
        'DJ' => '253',
        'DK' => '45',
        'DM' => '1767',
        'DO' => '1809',
        'DO' => '1829',
        'DO' => '1849',
        'DZ' => '213',
        'EC' => '593',
        'EE' => '372',
        'EG' => '20',
        'EH' => '212',
        'ER' => '291',
        'ES' => '34',
        'ET' => '251',
        'EU' => '388',
        'FI' => '358',
        'FJ' => '679',
        'FK' => '500',
        'FM' => '691',
        'FO' => '298',
        'FR' => '33',
        'GA' => '241',
        'GB' => '44',
        'GD' => '1473',
        'GE' => '995',
        'GF' => '594',
        'GG' => '44',
        'GH' => '233',
        'GI' => '350',
        'GL' => '299',
        'GM' => '220',
        'GN' => '224',
        'GP' => '590',
        'GQ' => '240',
        'GR' => '30',
        'GT' => '502',
        'GU' => '1671',
        'GW' => '245',
        'GY' => '592',
        'HK' => '852',
        'HN' => '504',
        'HR' => '385',
        'HT' => '509',
        'HU' => '36',
        'ID' => '62',
        'IE' => '353',
        'IL' => '972',
        'IM' => '44',
        'IN' => '91',
        'IO' => '246',
        'IQ' => '964',
        'IR' => '98',
        'IS' => '354',
        'IT' => '39',
        'JE' => '44',
        'JM' => '1876',
        'JO' => '962',
        'JP' => '81',
        'KE' => '254',
        'KG' => '996',
        'KH' => '855',
        'KI' => '686',
        'KM' => '269',
        'KN' => '1869',
        'KP' => '850',
        'KR' => '82',
        'KW' => '965',
        'KY' => '1345',
        'KZ' => '7',
        'LA' => '856',
        'LB' => '961',
        'LC' => '1758',
        'LI' => '423',
        'LK' => '94',
        'LR' => '231',
        'LS' => '266',
        'LT' => '370',
        'LU' => '352',
        'LV' => '371',
        'LY' => '218',
        'MA' => '212',
        'MC' => '377',
        'MD' => '373',
        'ME' => '382',
        'MF' => '590',
        'MG' => '261',
        'MH' => '692',
        'MK' => '389',
        'ML' => '223',
        'MM' => '95',
        'MN' => '976',
        'MO' => '853',
        'MP' => '1670',
        'MQ' => '596',
        'MR' => '222',
        'MS' => '1664',
        'MT' => '356',
        'MU' => '230',
        'MV' => '960',
        'MW' => '265',
        'MX' => '52',
        'MY' => '60',
        'MZ' => '258',
        'NA' => '264',
        'NC' => '687',
        'NE' => '227',
        'NF' => '672',
        'NG' => '234',
        'NI' => '505',
        'NL' => '31',
        'NO' => '47',
        'NP' => '977',
        'NR' => '674',
        'NU' => '683',
        'NZ' => '64',
        'OM' => '968',
        'PA' => '507',
        'PE' => '51',
        'PF' => '689',
        'PG' => '675',
        'PH' => '63',
        'PK' => '92',
        'PL' => '48',
        'PM' => '508',
        'PR' => '1787',
        'PR' => '1939',
        'PS' => '970',
        'PT' => '351',
        'PW' => '680',
        'PY' => '595',
        'QA' => '974',
        'QN' => '374',
        'QS' => '252',
        'QY' => '90',
        'RE' => '262',
        'RO' => '40',
        'RS' => '381',
        'RU' => '7',
        'RW' => '250',
        'SA' => '966',
        'SB' => '677',
        'SC' => '248',
        'SD' => '249',
        'SE' => '46',
        'SG' => '65',
        'SH' => '290',
        'SI' => '386',
        'SJ' => '47',
        'SK' => '421',
        'SL' => '232',
        'SM' => '378',
        'SN' => '221',
        'SO' => '252',
        'SR' => '597',
        'SS' => '211',
        'ST' => '239',
        'SV' => '503',
        'SX' => '1721',
        'SY' => '963',
        'SZ' => '268',
        'TA' => '290',
        'TC' => '1649',
        'TD' => '235',
        'TG' => '228',
        'TH' => '66',
        'TJ' => '992',
        'TK' => '690',
        'TL' => '670',
        'TM' => '993',
        'TN' => '216',
        'TO' => '676',
        'TR' => '90',
        'TT' => '1868',
        'TV' => '688',
        'TW' => '886',
        'TZ' => '255',
        'UA' => '380',
        'UG' => '256',
        'UK' => '44',
        'US' => '1',
        'UY' => '598',
        'UZ' => '998',
        'VA' => '379',
        'VA' => '39',
        'VC' => '1784',
        'VE' => '58',
        'VG' => '1284',
        'VI' => '1340',
        'VN' => '84',
        'VU' => '678',
        'WF' => '681',
        'WS' => '685',
        'XC' => '991',
        'XD' => '888',
        'XG' => '881',
        'XL' => '883',
        'XN' => '857',
        'XN' => '858',
        'XN' => '870',
        'XP' => '878',
        'XR' => '979',
        'XS' => '808',
        'XT' => '800',
        'XV' => '882',
        'YE' => '967',
        'YT' => '262',
        'ZA' => '27',
        'ZM' => '260',
        'ZW' => '263'
    );

    return ($country == '') ? $countries : (isset($countries[$country]) ? $countries[$country] : '');
}

function suwcsms_remote_get($url)
{
    $response = wp_remote_get($url, array('timeout' => 15));
    if (is_wp_error($response)) {
        $response = $response->get_error_message();
    } elseif (is_array($response)) {
        $response = $response['body'];
    }
    return $response;
}

function suwcsms_send_sms($phone, $message)
{
    $aid = suwcsms_field('aid');
    $pin = suwcsms_field('pin');
    $sender = suwcsms_field('sender');
    suwcsms_send_sms_text($phone, $message, $aid, $pin, $sender);
}

function suwcsms_send_otp($phone, $message)
{
    $aid = suwcsms_field('otp_aid');
    $pin = suwcsms_field('otp_pin');
    $sender = suwcsms_field('otp_sender');

    //Send transactional SMS if required fields are missing
    if (empty($aid) || empty($pin) || empty($sender)) {
        suwcsms_send_sms($phone, $message);
    } else {
        suwcsms_send_sms_text($phone, $message, $aid, $pin, $sender);
    }
}

function suwcsms_send_sms_text($phone, $message, $aid, $pin, $sender)
{
    global $woocommerce;

    //Don't send the SMS if required fields are missing
    if (empty($phone) || empty($message) || empty($aid) || empty($pin) || empty($sender))
        return;
    
    //Send the SMS by calling the API
    $message = suwcsms_message_encode($message);
    switch(suwcsms_field('api')) {
        // case 1:
            // $fetchurl = "http://121.241.247.195/failsafe/HttpLink?aid=$aid&pin=$pin&signature=$sender&mnumber=$phone&message=$message"; break;
        case 2:
            $fetchurl = "http://www.mgage.solutions/SendSMS/sendmsg.php?uname=$aid&pass=$pin&send=$sender&dest=$phone&msg=$message&concat=1&intl=1"; break;
        case 3:
            $fetchurl = "http://msg.mtalkz.com/V2/http-api.php?apikey=$pin&senderid=$sender&number=$phone&message=$message"; break;
        default:
            $fetchurl = "http://msg2.mtalkz.com/V2/http-api.php?apikey=$pin&senderid=$sender&number=$phone&message=$message";
    }
    $response = suwcsms_remote_get($fetchurl);
    
    //Log the response
    if (1 == suwcsms_field('log_sms')) {
        $log_txt = __('Mobile number: ', 'suwcsms') . $phone . PHP_EOL;
        $log_txt .= __('Message: ', 'suwcsms') . $message . PHP_EOL;
        $log_txt .= __('Gateway response: ', 'suwcsms') . $response . PHP_EOL;
        suwcsms_log_message($log_txt);
    }
}

function suwcsms_log_message( $message ) {
    global $suwcsms_logger;
    if ($suwcsms_logger == NULL)
        $suwcsms_logger = class_exists( 'WC_Logger' ) ? new WC_Logger() : $woocommerce->logger();
    $suwcsms_logger->add('suwcsms', $message);
}

/**
 * User registration OTP mechanism
 */

if (suwcsms_field('otp_user_reg') == 1) {
    add_action('register_form', 'suwcsms_register_form');
    // add_action('woocommerce_register_form_start', 'suwcsms_register_form');
    add_filter('registration_errors', 'suwcsms_registration_errors', 10, 3);
    add_action('woocommerce_register_post', 'suwcsms_wc_registration_errors', 10, 3);
    add_action('user_register', 'suwcsms_user_register');
    add_action('woocommerce_created_customer', 'suwcsms_user_register');
    if (suwcsms_field('otp_user_reg_wc') == 1)
        add_action('woocommerce_register_form', 'suwcsms_register_form');
}

function suwcsms_sanitize_data($data)
{
    $data = (!empty($data)) ? sanitize_text_field($data) : '';
    $data = preg_replace('/[^0-9]/', '', $data);
    return ltrim($data, '0');
}

function suwcsms_country_name($country='') {
    $countries = array(
		"AL" => 'Albania',
		"DZ" => 'Algeria',
		"AS" => 'American Samoa',
		"AD" => 'Andorra',
		"AO" => 'Angola',
		"AI" => 'Anguilla',
		"AQ" => 'Antarctica',
		"AG" => 'Antigua and Barbuda',
		"AR" => 'Argentina',
		"AM" => 'Armenia',
		"AW" => 'Aruba',
		"AU" => 'Australia',
		"AT" => 'Austria',
		"AZ" => 'Azerbaijan',
		"BS" => 'Bahamas',
		"BH" => 'Bahrain',
		"BD" => 'Bangladesh',
		"BB" => 'Barbados',
		"BY" => 'Belarus',
		"BE" => 'Belgium',
		"BZ" => 'Belize',
		"BJ" => 'Benin',
		"BM" => 'Bermuda',
		"BT" => 'Bhutan',
		"BO" => 'Bolivia',
		"BA" => 'Bosnia and Herzegovina',
		"BW" => 'Botswana',
		"BV" => 'Bouvet Island',
		"BR" => 'Brazil',
		"BQ" => 'British Antarctic Territory',
		"IO" => 'British Indian Ocean Territory',
		"VG" => 'British Virgin Islands',
		"BN" => 'Brunei',
		"BG" => 'Bulgaria',
		"BF" => 'Burkina Faso',
		"BI" => 'Burundi',
		"KH" => 'Cambodia',
		"CM" => 'Cameroon',
		"CA" => 'Canada',
		"CT" => 'Canton and Enderbury Islands',
		"CV" => 'Cape Verde',
		"KY" => 'Cayman Islands',
		"CF" => 'Central African Republic',
		"TD" => 'Chad',
		"CL" => 'Chile',
		"CN" => 'China',
		"CX" => 'Christmas Island',
		"CC" => 'Cocos [Keeling] Islands',
		"CO" => 'Colombia',
		"KM" => 'Comoros',
		"CG" => 'Congo - Brazzaville',
		"CD" => 'Congo - Kinshasa',
		"CK" => 'Cook Islands',
		"CR" => 'Costa Rica',
		"HR" => 'Croatia',
		"CU" => 'Cuba',
		"CY" => 'Cyprus',
		"CZ" => 'Czech Republic',
		"CI" => 'Côte d’Ivoire',
		"DK" => 'Denmark',
		"DJ" => 'Djibouti',
		"DM" => 'Dominica',
		"DO" => 'Dominican Republic',
		"NQ" => 'Dronning Maud Land',
		"DD" => 'East Germany',
		"EC" => 'Ecuador',
		"EG" => 'Egypt',
		"SV" => 'El Salvador',
		"GQ" => 'Equatorial Guinea',
		"ER" => 'Eritrea',
		"EE" => 'Estonia',
		"ET" => 'Ethiopia',
		"FK" => 'Falkland Islands',
		"FO" => 'Faroe Islands',
		"FJ" => 'Fiji',
		"FI" => 'Finland',
		"FR" => 'France',
		"GF" => 'French Guiana',
		"PF" => 'French Polynesia',
		"TF" => 'French Southern Territories',
		"FQ" => 'French Southern and Antarctic Territories',
		"GA" => 'Gabon',
		"GM" => 'Gambia',
		"GE" => 'Georgia',
		"DE" => 'Germany',
		"GH" => 'Ghana',
		"GI" => 'Gibraltar',
		"GR" => 'Greece',
		"GL" => 'Greenland',
		"GD" => 'Grenada',
		"GP" => 'Guadeloupe',
		"GU" => 'Guam',
		"GT" => 'Guatemala',
		"GG" => 'Guernsey',
		"GN" => 'Guinea',
		"GW" => 'Guinea-Bissau',
		"GY" => 'Guyana',
		"HT" => 'Haiti',
		"HM" => 'Heard Island and McDonald Islands',
		"HN" => 'Honduras',
		"HK" => 'Hong Kong SAR China',
		"HU" => 'Hungary',
		"IS" => 'Iceland',
		"IN" => 'India',
		"ID" => 'Indonesia',
		"IR" => 'Iran',
		"IQ" => 'Iraq',
		"IE" => 'Ireland',
		"IM" => 'Isle of Man',
		"IL" => 'Israel',
		"IT" => 'Italy',
		"JM" => 'Jamaica',
		"JP" => 'Japan',
		"JE" => 'Jersey',
		"JT" => 'Johnston Island',
		"JO" => 'Jordan',
		"KZ" => 'Kazakhstan',
		"KE" => 'Kenya',
		"KI" => 'Kiribati',
		"KW" => 'Kuwait',
		"KG" => 'Kyrgyzstan',
		"LA" => 'Laos',
		"LV" => 'Latvia',
		"LB" => 'Lebanon',
		"LS" => 'Lesotho',
		"LR" => 'Liberia',
		"LY" => 'Libya',
		"LI" => 'Liechtenstein',
		"LT" => 'Lithuania',
		"LU" => 'Luxembourg',
		"MO" => 'Macau SAR China',
		"MK" => 'Macedonia',
		"MG" => 'Madagascar',
		"MW" => 'Malawi',
		"MY" => 'Malaysia',
		"MV" => 'Maldives',
		"ML" => 'Mali',
		"MT" => 'Malta',
		"MH" => 'Marshall Islands',
		"MQ" => 'Martinique',
		"MR" => 'Mauritania',
		"MU" => 'Mauritius',
		"YT" => 'Mayotte',
		"FX" => 'Metropolitan France',
		"MX" => 'Mexico',
		"FM" => 'Micronesia',
		"MI" => 'Midway Islands',
		"MD" => 'Moldova',
		"MC" => 'Monaco',
		"MN" => 'Mongolia',
		"ME" => 'Montenegro',
		"MS" => 'Montserrat',
		"MA" => 'Morocco',
		"MZ" => 'Mozambique',
		"MM" => 'Myanmar [Burma]',
		"NA" => 'Namibia',
		"NR" => 'Nauru',
		"NP" => 'Nepal',
		"NL" => 'Netherlands',
		"AN" => 'Netherlands Antilles',
		"NT" => 'Neutral Zone',
		"NC" => 'New Caledonia',
		"NZ" => 'New Zealand',
		"NI" => 'Nicaragua',
		"NE" => 'Niger',
		"NG" => 'Nigeria',
		"NU" => 'Niue',
		"NF" => 'Norfolk Island',
		"KP" => 'North Korea',
		"VD" => 'North Vietnam',
		"MP" => 'Northern Mariana Islands',
		"NO" => 'Norway',
		"OM" => 'Oman',
		"PC" => 'Pacific Islands Trust Territory',
		"PK" => 'Pakistan',
		"PW" => 'Palau',
		"PS" => 'Palestinian Territories',
		"PA" => 'Panama',
		"PZ" => 'Panama Canal Zone',
		"PG" => 'Papua New Guinea',
		"PY" => 'Paraguay',
		"YD" => 'People\'s Democratic Republic of Yemen',
		"PE" => 'Peru',
		"PH" => 'Philippines',
		"PN" => 'Pitcairn Islands',
		"PL" => 'Poland',
		"PT" => 'Portugal',
		"PR" => 'Puerto Rico',
		"QA" => 'Qatar',
		"RO" => 'Romania',
		"RU" => 'Russia',
		"RW" => 'Rwanda',
		"RE" => 'Réunion',
		"BL" => 'Saint Barthélemy',
		"SH" => 'Saint Helena',
		"KN" => 'Saint Kitts and Nevis',
		"LC" => 'Saint Lucia',
		"MF" => 'Saint Martin',
		"PM" => 'Saint Pierre and Miquelon',
		"VC" => 'Saint Vincent and the Grenadines',
		"WS" => 'Samoa',
		"SM" => 'San Marino',
		"SA" => 'Saudi Arabia',
		"SN" => 'Senegal',
		"RS" => 'Serbia',
		"CS" => 'Serbia and Montenegro',
		"SC" => 'Seychelles',
		"SL" => 'Sierra Leone',
		"SG" => 'Singapore',
		"SK" => 'Slovakia',
		"SI" => 'Slovenia',
		"SB" => 'Solomon Islands',
		"SO" => 'Somalia',
		"ZA" => 'South Africa',
		"GS" => 'South Georgia and the South Sandwich Islands',
		"KR" => 'South Korea',
		"ES" => 'Spain',
		"LK" => 'Sri Lanka',
		"SD" => 'Sudan',
		"SR" => 'Suriname',
		"SJ" => 'Svalbard and Jan Mayen',
		"SZ" => 'Swaziland',
		"SE" => 'Sweden',
		"CH" => 'Switzerland',
		"SY" => 'Syria',
		"ST" => 'São Tomé and Príncipe',
		"TW" => 'Taiwan',
		"TJ" => 'Tajikistan',
		"TZ" => 'Tanzania',
		"TH" => 'Thailand',
		"TL" => 'Timor-Leste',
		"TG" => 'Togo',
		"TK" => 'Tokelau',
		"TO" => 'Tonga',
		"TT" => 'Trinidad and Tobago',
		"TN" => 'Tunisia',
		"TR" => 'Turkey',
		"TM" => 'Turkmenistan',
		"TC" => 'Turks and Caicos Islands',
		"TV" => 'Tuvalu',
		"UM" => 'U.S. Minor Outlying Islands',
		"PU" => 'U.S. Miscellaneous Pacific Islands',
		"VI" => 'U.S. Virgin Islands',
		"UG" => 'Uganda',
		"UA" => 'Ukraine',
		"SU" => 'Union of Soviet Socialist Republics',
		"AE" => 'United Arab Emirates',
		"GB" => 'United Kingdom',
		"US" => 'United States',
		"ZZ" => 'Unknown or Invalid Region',
		"UY" => 'Uruguay',
		"UZ" => 'Uzbekistan',
		"VU" => 'Vanuatu',
		"VA" => 'Vatican City',
		"VE" => 'Venezuela',
		"VN" => 'Vietnam',
		"WK" => 'Wake Island',
		"WF" => 'Wallis and Futuna',
		"EH" => 'Western Sahara',
		"YE" => 'Yemen',
		"ZM" => 'Zambia',
		"ZW" => 'Zimbabwe',
		"AX" => 'Åland Islands',
	);

    return ($country == '') ? $countries : (isset($countries[$country]) ? $countries[$country] : '');
}

function suwcsms_register_form()
{
    global $woocommerce;
    $country_code = sanitize_text_field($_POST['country_code'] ?? '') ?: (class_exists('WC_Countries') ? (new WC_Countries())->get_base_country() : 'IN');
    $phone_number = suwcsms_sanitize_data($_POST['phone_number'] ?? '');
    $registration_otp = suwcsms_sanitize_data($_POST['registration_otp'] ?? '');
    ?>
        <style>#su_send_otp_link{float:right;display:none;font-size:large;font-weight:bold}#su_send_otp_link::placeholder{text-align:right}</style>
        <p class="message" id="su_register_msg">OTP will be sent to your phone number.</p>
        <p>
            <label for="country_code"><?php _e('Country', 'suwcsms') ?><br />
                <select name="country_code" id="country_code" class="input">
                <?php foreach(suwcsms_country_name() as $code => $name) {
                    echo "<option value='$code' ", selected($country_code, $code), ">$name</option>";
                } ?>
                </select>
            </label>
        </p>
        <p>
            <label for="phone_number"><?php _e('Phone Number', 'suwcsms') ?><br />
                <input type="text" name="phone_number" id="phone_number" class="input" value="<?php echo esc_attr($phone_number); ?>" size="20" placeholder="Phone Number"/>
            </label>
        </p>
        <p>
            <label for="registration_otp"><?php _e('Registration OTP', 'suwcsms') ?> <a id="su_send_otp_link"><?php _e('Send OTP', 'suwcsms')?></a><br />
                <input type="text" name="registration_otp" id="registration_otp" class="input" value="<?php echo esc_attr($registration_otp); ?>" size="25" placeholder="Click on Send OTP link &uarr;"/>
            </label>
        </p>
        <script>
        document.querySelector("input#phone_number").onchange = function(){
            document.querySelector("a#su_send_otp_link").style.display = this.value.trim() == "" ? 'none' : 'inline';
        };
        document.querySelector("a#su_send_otp_link").onclick = function(){
            var request = new XMLHttpRequest(),
                url = '<?php echo esc_url(admin_url("admin-ajax.php?action=suwcsms_reg_otp")); ?>' + '&country=' + document.querySelector("select#country_code").value + '&phone=' + document.querySelector("input#phone_number").value;
            this.innerHTML = 'Re-send OTP';
            this.style.display = 'none';
            setTimeout(function() {
                document.querySelector("a#su_send_otp_link").style.display = 'inline';
            }, 30000);
            request.open('POST', url, true);
            request.onload = function() {
                if (request.status == 200) {
                    var response = request.responseText;
                    console.log('Response', response);
                    var jsonobj = JSON.parse(response);
                    document.querySelector("p#su_register_msg").innerHTML = jsonobj.message;
                }
            };
            request.send();
        };
        </script>
    <?php
}

//OTP Ajax
add_action('wp_ajax_nopriv_suwcsms_reg_otp', 'suwcsms_reg_otp_callback');
function suwcsms_reg_otp_callback()
{
    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'suwcsms_reg_otp') {
        $data = ['error' => true, 'message' => 'Failed to send OTP. Ensure that you have included the ISD code in the number.'];
        $country_code = sanitize_text_field($_REQUEST['country']);
        $billing_phone = suwcsms_sanitize_data($_REQUEST['phone']);
        if (!empty($country_code) && !empty($billing_phone)) {
            $user_phone = suwcsms_country_prefix($country_code) . $billing_phone;
            $user_id = suwcsms_get_user_by_phone($user_phone);
            if (!empty($user_id)) {
                $data['message'] = 'This phone number is linked to an already registered user account.';
            } else {
                $transient_id = 'OTP_REG_' . $country_code . '_' . $billing_phone;
                $otp_number = get_transient($transient_id);
                if ($otp_number == false) {
                    $otp_number = suwcsms_generate_otp();
                    set_transient($transient_id, $otp_number, 120);
                }
                $message = suwcsms_process_variables(suwcsms_fetch_string('msg_otp_register'), null, ['otp' => $otp_number]);
                suwcsms_send_otp($user_phone, $message);
                $data = ['success' => true, 'message' => "Registraion OTP has been sent to $user_phone"];
            }
        }
        wp_send_json($data);
    }
    die();
}


function suwcsms_registration_errors($errors, $username, $user_email)
{
    
    $country_code = sanitize_text_field($_POST['country_code'] ?? '');
    $phone_number = suwcsms_sanitize_data($_POST['phone_number'] ?? '');
    $registration_otp = suwcsms_sanitize_data($_POST['registration_otp'] ?? '');

    if (empty($country_code)) {
        $errors->add('country_code_error', __('Country name is required.', 'suwcsms'));
    }

    if (empty($phone_number)) {
        $errors->add('phone_number_error', __('Numeric Phone Number is required.', 'suwcsms'));
    }

    if (!empty($country_code) && !empty($phone_number)) {
        $billing_phone_otp = 'OTP_REG_' . $country_code . '_' . $phone_number;
        $stored_phone_otp = get_transient($billing_phone_otp);
        if (empty($registration_otp)) {
            $errors->add('registration_otp_error', __('Registration OTP is required.', 'suwcsms'));
        } elseif ($registration_otp !== $stored_phone_otp) {
            $errors->add('registration_otp_error', __('Registration OTP is invalid.', 'suwcsms'));
        }
    }

    return $errors;
}

function suwcsms_wc_registration_errors($username, $user_email, $errors)
{
    suwcsms_registration_errors($errors, $username, $user_email);
}

function suwcsms_user_register($user_id)
{
    $country_code = sanitize_text_field($_POST['country_code'] ?? '');
    $phone_number = suwcsms_sanitize_data($_POST['phone_number'] ?? '');
    if (!empty($country_code) && !empty($phone_number)) {
        $billing_phone = suwcsms_country_prefix($country_code) . $phone_number;
        $billing_phone_otp = 'OTP_REG_' . $country_code . '_' . $phone_number;
        delete_transient($billing_phone_otp);
        update_user_meta($user_id, 'billing_phone', $billing_phone);
        update_user_meta($user_id, 'billing_country', $country_code);
    }
}

/**
 * User login through OTP
 */

add_shortcode('suwcsms_otp_login', 'suwcsms_otp_login');
function suwcsms_otp_login($atts, $content = null)
{
    ob_start();
    $country_code = sanitize_text_field($_POST['country_code'] ?? '') ?: (class_exists('WC_Countries') ? (new WC_Countries())->get_base_country() : 'IN');
    $phone_number = suwcsms_sanitize_data($_POST['phone_number'] ?? '');
    $login_otp = suwcsms_sanitize_data($_POST['login_otp'] ?? '');
    ?>
<div id="suwcsms-otp-login-form">
    <div class='suwcsms-notifications'>
        <div class="woocommerce-info">
        An OTP will be sent to your registered mobile no. You will be logged-in upon completion of OTP verification.
        </div>
    </div>
    <div class="woocommerce-form">
        <p>
            <label for="suwcsms-phone-number"><?php _e('Phone Number', 'suwcsms') ?>
                <select name="country_code" id="suwcsms-country-code" class="input">
                <?php foreach(suwcsms_country_name() as $code => $name) {
                    echo "<option value='$code' ", selected($country_code, $code), ">$name</option>";
                } ?>
                </select>
                <input type="text" id="suwcsms-phone-number" class="input" value="<?php echo esc_attr($phone_number); ?>" size="25"/>
                <a class="button" id="suwcsms_resend_otp_btn">Send OTP</a>
            </label>
        </p>
        <p class="otp_block">
            <label for="suwcsms-otp-field"><?php _e('OTP', 'suwcsms') ?>
                <input type="text" id="suwcsms-otp-field" class="input" value="<?php echo esc_attr($login_otp); ?>" size="25"/>
                <a class="button" id="suwcsms_verify_otp_btn">Verify & Login</a>
            </label>
        </p>
    </div>
</div>
<script type="text/javascript">
    var otp_failure_count = 0,
        otp_resend_count = 0;
    function showSpinner() {
        document.querySelector('.suwcsms-notifications').innerHTML = '<center><img src="<?php echo esc_url( admin_url("images/spinner-2x.gif") ) ?>"/></center>';
    }
    function process_json_response(response) {
        var jsonobj = JSON.parse(response);
        if (jsonobj.error) {
            document.querySelector('.suwcsms-notifications').innerHTML = '<div class="woocommerce-error">'+jsonobj.message+'</div>';
            if (jsonobj.verification_failure) {
                otp_failure_count++;
                if (otp_failure_count > 3) {
                    document.querySelector('.suwcsms-notifications').innerHTML += '<br/><h3>It seems that there is some difficulty in logging you in. Please try again later.</h3>';
                }
            }
        } else {
            if (jsonobj.otp_verified) {
                // window.location.reload();
                window.location = '<?php echo esc_url(home_url("/")) ?>';
            } else {
                document.querySelector('.suwcsms-notifications').innerHTML = '<div class="woocommerce-message">'+jsonobj.message+'</div>';
                otp_resend_count++;
            }
        }
    }
    function suwcsms_make_ajax_post(data) {
        var request = new XMLHttpRequest();
        request.open('POST', '<?php echo esc_url(admin_url("admin-ajax.php")); ?>', true);
        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
        request.onload = function() {
            if (request.status == 200) {
                process_json_response(request.responseText);
            }
        };
        request.send(data);
    }
    function suwcsms_verify_otp() {
        var country = document.getElementById('suwcsms-country-code').value;
        var phone = document.getElementById('suwcsms-phone-number').value;
        var otp = document.getElementById('suwcsms-otp-field').value;
        if (country.trim() == '') {
            document.querySelector('.suwcsms-notifications').innerHTML = 'Please select your country.';
            return;
        }
        if (phone.trim() == '') {
            document.querySelector('.suwcsms-notifications').innerHTML = 'Please enter the registered phone number.';
            return;
        }
        if (otp.trim() == '') {
            document.querySelector('.suwcsms-notifications').innerHTML = 'Please enter a valid OTP.';
            return;
        }
        showSpinner();
        suwcsms_make_ajax_post("action=suwcsms_verify_otp_login&country="+country+"&phone="+phone+"&otp="+otp);
    }
    function suwcsms_resend_otp() {
        var country = document.getElementById('suwcsms-country-code').value;
        var phone = document.getElementById('suwcsms-phone-number').value;
        if (country.trim() == '') {
            document.querySelector('.suwcsms-notifications').innerHTML = 'Please select your country.';
            return;
        }
        if (phone.trim() == '') {
            document.querySelector('.suwcsms-notifications').innerHTML = 'Please enter the registered phone number.';
            return;
        }
        disableResendOTP();
        showSpinner();
        suwcsms_make_ajax_post("action=suwcsms_send_otp_login&country="+country+"&phone="+phone)
    }
    function enableResendOTP() {
        if (otp_resend_count < 3) {
            document.querySelector('#suwcsms_resend_otp_btn').text = 'Resend OTP';
            document.querySelector('#suwcsms_resend_otp_btn').style.visibility = 'visible';
        }
    }
    function disableResendOTP() {
        document.querySelector('#suwcsms_resend_otp_btn').style.visibility = 'hidden';
        setTimeout(enableResendOTP, 30000);
    }
    document.querySelector('#suwcsms_resend_otp_btn').addEventListener('click', suwcsms_resend_otp);
    document.querySelector('#suwcsms_verify_otp_btn').addEventListener('click', suwcsms_verify_otp);
</script>
<?php
return ob_get_clean();
}

function suwcsms_get_user_by_phone($phone_number)
{
    return reset(
        get_users(
            array(
                'meta_key' => 'billing_phone',
                'meta_value' => $phone_number,
                'number' => 1,
                'fields' => 'ids',
                'count_total' => false
            )
        )
    );
}

//Request OTP via AJAX
add_action('wp_ajax_nopriv_suwcsms_send_otp_login', 'suwcsms_send_otp_login_callback');
function suwcsms_send_otp_login_callback()
{
    if (isset($_POST['action']) && $_POST['action'] == 'suwcsms_send_otp_login') {
        $data = ['error' => true, 'message' => 'Failed to send OTP. Ensure that you have included the ISD code in the number.'];
        $country_code = sanitize_text_field($_POST['country'] ?? '');
        $billing_phone = suwcsms_sanitize_data($_POST['phone'] ?? '');
        if (!empty($country_code) && !empty($billing_phone)) {
            $billing_phone = suwcsms_country_prefix($country_code) . $billing_phone;
            $user_id = suwcsms_get_user_by_phone($billing_phone);
            if (!empty($user_id)) {
                $transient_id = 'OTP_LOGIN_' . $user_id;
                $otp_number = get_transient($transient_id);
                if ($otp_number == false) {
                    $otp_number = suwcsms_generate_otp();
                    set_transient($transient_id, $otp_number, 120);
                }
                $message = suwcsms_process_variables(suwcsms_fetch_string('msg_otp_login'), null, ['otp' => $otp_number]);
                suwcsms_send_otp($billing_phone, $message);
                $data = ['success' => true, 'message' => "OTP Sent to $billing_phone for login"];
            } else {
                $data['message'] = "Couldn't locate a user with phone: $billing_phone";
            }
        }
        wp_send_json($data);
    }
    die();
}

add_action('wp_ajax_nopriv_suwcsms_verify_otp_login', 'suwcsms_verify_otp_login_callback');
function suwcsms_verify_otp_login_callback()
{
    if (isset($_POST['action']) && $_POST['action'] == 'suwcsms_verify_otp_login') {
        $data = ['error' => true, 'message' => 'OTP could not be verified', 'verification_failure' => true];
        $country_code = sanitize_text_field($_POST['country'] ?? '');
        $billing_phone = suwcsms_sanitize_data($_POST['phone'] ?? '');
        $user_otp = suwcsms_sanitize_data($_POST['otp'] ?? '');
        if (!empty($country_code) && !empty($billing_phone) && !empty($user_otp)) {
            $billing_phone = suwcsms_country_prefix($country_code) . $billing_phone;
            $user_id = suwcsms_get_user_by_phone($billing_phone);
            if (!empty($user_id)) {
                $transient_id = 'OTP_LOGIN_' . $user_id;
                $otp_number = get_transient($transient_id);
                if ($otp_number == $user_otp) {
                    delete_transient($transient_id);
                    wp_clear_auth_cookie();
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id);
                    $data = ['success' => true, 'message' => "Congrats! Your login is successful.", 'otp_verified' => true];
                }
            }
        }
        wp_send_json($data);
    }
    die();
}

// Add link on default login form
if (suwcsms_field('otp_user_log') == 1) {
    add_action('login_form', 'suwcsms_disply_otp_login_option');
    add_action('woocommerce_login_form_end', 'suwcsms_disply_otp_login_option');
}
function suwcsms_disply_otp_login_option()
{
    ?>
    <p><a href="#suwcsms-login-form-popup">Login with OTP</a></p>
    <style>#suwcsms-login-form-popup{background:rgba(0,0,0,.5);position:absolute;top:0;left:0;width:100vw;height:100vh;overflow:hidden;display:none}#suwcsms-login-form-popup:target{display:flex;justify-content:center;align-items:center}#suwcsms-login-form-popup .close_btn{position:absolute;text-decoration:none;top:1vh;right:1vw;color:#fff;font-size:3em}#suwcsms-otp-login-form{background:#fff;min-width:50%;max-width:90%;padding:5%}</style>
    <div id="suwcsms-login-form-popup">
        <?php echo do_shortcode('[suwcsms_otp_login]') ?>
        <a href="#" class="close_btn">&times;</a>
    </div>
<?php
}