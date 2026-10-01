jQuery(document).ready(function($) {
    'use strict';
    
    var solidForm = null;
    var isSolidgateSelected = false;
    var lastCartHash = null;
    var lastEmail = null;
    var isProcessing = false;

    // Add custom popup styles
    $('head').append(`
        <style>
        .custom-popup {
            position: fixed;
            top: 30%;
            left: 40%;
            background: white;
            padding: 20px;
            border: 2px solid #000;
            z-index: 9999;
        }
        #continue {
            margin-top: 15px;
            padding: 8px 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: block;
        }
        #continue:hover {
            background-color: #45a049;
        }
        </style>
    `);

    // Ensure modal popup exists on page load
    if (!$('#solidgate-popup').length) {
        $('body').append(`
            <div id="solidgate-popup" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999999;">
                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:20px; border-radius:5px; min-width:300px; min-height:200px;">
                    <div id="solidgate-payment-form"></div>
                    <div id="solidgate-error-message" style="color:red; margin-top:10px; display:none;"></div>
                    <button id="solidgate-close-modal" style="position:absolute; top:10px; right:10px;">&times;</button>
                </div>
            </div>
        `);
    }

    
    // Show modal and initialize payment form after successful WooCommerce checkout
    $(document.body).on('checkout_place_order_success', function(e, result) {
        const selectedMethod = $('input[name="payment_method"]:checked').val();
        if (selectedMethod !== 'solidgate') return true;

        e.preventDefault();

        // Prevent redirect
        if (result.result === 'success') {
            // Stop default redirect
            delete result.redirect;

            // Show popup
            $('#solidgate-popup').show();
            $('#solidgate-error-message').hide();
            $('#solidgate-payment-form').html('<div style="text-align:center;">Loading payment form...</div>');

            // Get Solidgate form data from server
            $.ajax({
                type: 'POST',
                url: solidgate_params.ajax_url,
                data: {
                    action: 'get_solidgate_form',
                    nonce: solidgate_params.nonce,
                    order_id: result.order_id
                },
                success: function(response) {
                    if (response.success) {
                        const formData = response.data;
                        // Prepare params for SolidgateForm
                        const params = {
                            merchant: formData.merchant_id,
                            signature: formData.signature,
                            paymentIntent: formData.payment_intent
                        };
                        // Initialize Solidgate form from CDN JS
                        if (typeof SolidgateForm !== 'undefined') {
                            if (window.solidForm) {
                                window.solidForm.destroy();
                            }
                            window.solidForm = new SolidgateForm({
                                merchantData: params,
                                container: '#solidgate-payment-form',
                                // Add any additional config here if needed
                            });
                            window.solidForm.mount('#solidgate-payment-form');
                            window.solidForm.on('success', function(e) {
                                // Show custom confirmation popup after successful payment
                                showCustomPopup(result);
                            });
                            window.solidForm.on('fail', function(e) {
                                $('#solidgate-error-message').text(e.data && e.data.message ? e.data.message : 'Payment failed. Please try again.').show();
                            });
                            window.solidForm.on('error', function(e) {
                                $('#solidgate-error-message').text(e.data && e.data.message ? e.data.message : 'An error occurred. Please try again.').show();
                            });
                        } else {
                            $('#solidgate-error-message').text('Solidgate payment form could not be loaded.').show();
                        }
                    } else {
                        $('#solidgate-error-message').text(response.data && response.data.message ? response.data.message : 'Failed to initialize payment form.').show();
                    }
                },
                error: function() {
                    $('#solidgate-error-message').text('Failed to initialize payment form. Please try again.').show();
                }
            });
        }
        
        return false;
    });

    // Function to show custom popup
    function showCustomPopup(result) {
        // Hide Solidgate popup first
        $('#solidgate-popup').hide();
        
        const popup = $('<div class="custom-popup">Order Created! Continue to Thank You page?<br><button id="continue">Continue</button></div>');
        $('body').append(popup);

        $('#continue').on('click', function(){
            $('.custom-popup').remove();

            // Redirect manually to Thank You page
            if (result && result.order_id) {
                window.location.href = '/checkout/order-received/' + result.order_id + '/?key=' + result.order_key;
            }
        });
    }

    // Close modal on close button or outside click
    $(document).on('click', '#solidgate-close-modal', function() {
        $('#solidgate-popup').hide();
        if (window.solidForm) {
            window.solidForm.destroy();
            window.solidForm = null;
        }
    });
    $(document).on('click', '#solidgate-popup', function(e) {
        if (e.target.id === 'solidgate-popup') {
            $('#solidgate-popup').hide();
            if (window.solidForm) {
                window.solidForm.destroy();
                window.solidForm = null;
            }
        }
    });
}); 