<?php
/**
 * Payment Modal Component
 * Reusable payment modal for various services
 * 
 * @author School Management System
 * @date July 24, 2025
 */

require_once 'PaymentHandlers.php';
?>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="paymentModalLabel">
                    <i class="fas fa-credit-card mr-2"></i>Complete Payment
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            
            <div class="modal-body">
                <!-- Service Information -->
                <div class="service-info mb-4">
                    <div class="row">
                        <div class="col-md-8">
                            <h6 class="service-name font-weight-bold text-primary" id="serviceName">Service Name</h6>
                            <p class="service-description text-muted mb-2" id="serviceDescription">Service description will appear here</p>
                            <small class="text-muted" id="serviceDetails">Additional service details</small>
                        </div>
                        <div class="col-md-4 text-right">
                            <div class="amount-display">
                                <small class="text-muted d-block">Amount</small>
                                <h4 class="text-success font-weight-bold mb-0" id="paymentAmount">GHS 0.00</h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Information -->
                <div class="payment-info-section">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Secure Payment via Paystack</strong><br>
                        You will be redirected to Paystack's secure payment page where you can:
                        <ul class="mb-0 mt-2">
                            <li>Pay with Visa, Mastercard, or Verve cards</li>
                            <li>Use Mobile Money (MTN, Vodafone, AirtelTigo)</li>
                            <li>All payment details are handled securely by Paystack</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Payment Form Container -->
                <div id="paymentFormContainer" style="display: none;">
                    <hr>
                    <div id="paystackPaymentForm"></div>
                </div>
                
                <!-- Loading State -->
                <div id="paymentLoading" style="display: none;">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Processing...</span>
                        </div>
                        <p class="mt-2 text-muted">Processing your payment...</p>
                    </div>
                </div>
                
                <!-- Error Display -->
                <div id="paymentError" class="alert alert-danger" style="display: none;">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <span id="paymentErrorMessage">An error occurred</span>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="proceedPaymentBtn">
                    <i class="fas fa-credit-card mr-1"></i>Pay Now with Paystack
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Payment Success Modal -->
<div class="modal fade" id="paymentSuccessModal" tabindex="-1" role="dialog" aria-labelledby="paymentSuccessLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="paymentSuccessLabel">
                    <i class="fas fa-check-circle mr-2"></i>Payment Successful
                </h5>
            </div>
            <div class="modal-body text-center">
                <div class="py-4">
                    <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                    <h5>Payment Completed Successfully!</h5>
                    <p class="text-muted mb-3" id="successMessage">Your service has been activated.</p>
                    <div class="alert alert-info">
                        <small id="serviceActivationDetails">Service activation details will appear here</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-success" id="continueAfterPayment">
                    <i class="fas fa-arrow-right mr-1"></i>Continue
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.payment-card {
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
}

.payment-card:hover {
    border-color: #007bff;
    box-shadow: 0 4px 8px rgba(0,123,255,0.1);
}

.payment-option.selected .payment-card {
    border-color: #007bff;
    background-color: #f8f9ff;
}

.amount-display {
    border-left: 3px solid #28a745;
    padding-left: 15px;
}

.service-info {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border-left: 4px solid #007bff;
}

#paymentModal .modal-lg {
    max-width: 600px;
}

.payment-icon {
    width: 60px;
    text-align: center;
}

.spinner-border {
    width: 3rem;
    height: 3rem;
}

.payment-form {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.payment-methods-list {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 15px;
}

.custom-control-label {
    cursor: pointer;
    font-weight: 500;
    display: flex;
    align-items: center;
}

.custom-control-label i {
    color: #007bff;
    width: 20px;
}

.custom-control-input:checked ~ .custom-control-label {
    color: #007bff;
    font-weight: 600;
}

.custom-control-input:checked ~ .custom-control-label i {
    color: #0056b3;
}

#mobileMoneyGroup, #voucherGroup {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
    padding: 15px;
    margin-top: 15px;
}

.form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
}

.input-group-text {
    background-color: #e9ecef;
    border-color: #ced4da;
    font-weight: 600;
}

#mobileNumber {
    font-family: monospace;
    font-size: 1.1em;
    letter-spacing: 1px;
}

#paystackPayBtn {
    background: linear-gradient(45deg, #28a745, #20c997);
    border: none;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 30px;
    border-radius: 25px;
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    transition: all 0.3s ease;
}

#paystackPayBtn:hover {
    background: linear-gradient(45deg, #218838, #1abc9c);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
}
</style>

<script>
class PaymentModal {
    constructor() {
        this.currentPaymentData = null;
        this.selectedMethod = 'paystack';
        this.initializeEventListeners();
    }
    
    initializeEventListeners() {
        // Proceed to payment button - now goes directly to Paystack
        $('#proceedPaymentBtn').on('click', () => {
            this.processPayment();
        });
        
        // Modal reset on close
        $('#paymentModal').on('hidden.bs.modal', () => {
            this.resetModal();
        });
    }
    
    /**
     * Show payment modal for a service
     * @param {Object} paymentData - Payment information
     */
    showPaymentModal(paymentData) {
        this.currentPaymentData = paymentData;
        
        // Update modal content
        $('#serviceName').text(paymentData.service_display_name);
        $('#serviceDescription').text(paymentData.service_description);
        $('#serviceDetails').text(paymentData.service_details || '');
        $('#paymentAmount').text(paymentData.currency + ' ' + parseFloat(paymentData.amount).toFixed(2));
        
        // Reset state
        this.resetModal();
        
        // Show modal
        $('#paymentModal').modal('show');
    }
    
    /**
     * Process payment with selected method
     */
    async processPayment() {
        if (!this.currentPaymentData) {
            this.showError('Payment data not available');
            return;
        }
        
        this.showLoading(true);
        
        try {
            // Create payment request
            const response = await this.createPaymentRequest();
            
            if (response.success) {
                // Go directly to Paystack without showing form
                this.openPaystackDirectly(response.data);
            } else {
                this.showError(response.message || 'Failed to initialize payment');
            }
        } catch (error) {
            this.showError('Network error occurred');
            console.error('Payment error:', error);
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Create payment request on server
     */
    async createPaymentRequest() {
        const formData = new FormData();
        formData.append('service_type', this.currentPaymentData.service_type);
        formData.append('reference_id', this.currentPaymentData.reference_id || '');
        formData.append('action', 'create_payment');
        
        // Add authentication data if available
        if (window.authData) {
            formData.append('auth_user_id', window.authData.user_id);
            formData.append('auth_user_role', window.authData.user_role);
            formData.append('auth_session_token', window.authData.session_token);
        }
        
        const response = await fetch('<?= BASE_URL ?>/api/payment-handler.php', {
            method: 'POST',
            body: formData
        });
        
        // Debug: Log the raw response
        const responseText = await response.text();
        console.log('Raw API Response:', responseText);
        console.log('Response Status:', response.status);
        console.log('Response Headers:', response.headers);
        
        try {
            return JSON.parse(responseText);
        } catch (e) {
            console.error('JSON Parse Error:', e);
            console.error('Response was:', responseText);
            throw new Error('Invalid JSON response from server');
        }
    }
    
    /**
     * Open Paystack payment directly without showing custom form
     */
    openPaystackDirectly(paymentData) {
        // Hide the payment modal
        $('#paymentModal').modal('hide');
        
        // Load Paystack script if not already loaded
        if (typeof PaystackPop === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://js.paystack.co/v1/inline.js';
            script.onload = () => {
                this.launchPaystackPopup(paymentData);
            };
            document.head.appendChild(script);
        } else {
            this.launchPaystackPopup(paymentData);
        }
    }
    
    /**
     * Launch Paystack payment popup
     */
    launchPaystackPopup(paymentData) {
        const paymentConfig = {
            key: paymentData.public_key,
            email: paymentData.email,
            amount: paymentData.amount * 100, // Convert to pesewas
            currency: paymentData.currency,
            ref: paymentData.reference,
            metadata: {
                service_type: this.currentPaymentData.service_type,
                reference_id: this.currentPaymentData.reference_id || null,
                custom_fields: [
                    {
                        display_name: 'Service',
                        variable_name: 'service_type',
                        value: this.currentPaymentData.service_display_name
                    }
                ]
            },
            callback: (response) => {
                this.handlePaymentSuccess(response);
            },
            onClose: () => {
                console.log('Payment cancelled by user');
                // Show the payment modal again if user cancels
                $('#paymentModal').modal('show');
            }
        };
        
        const handler = PaystackPop.setup(paymentConfig);
        handler.openIframe();
    }
    
    /**
     * Initialize Paystack payment (legacy method - kept for compatibility)
     */
    initializePaystackPayment(paymentData) {
        // Hide proceed button and show payment form
        $('#proceedPaymentBtn').hide();
        $('#paymentFormContainer').show();
        
        // Load Paystack script if not already loaded
        if (typeof PaystackPop === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://js.paystack.co/v1/inline.js';
            script.onload = () => {
                this.showPaystackForm(paymentData);
            };
            document.head.appendChild(script);
        } else {
            this.showPaystackForm(paymentData);
        }
    }
    
    /**
     * Show Paystack payment form
     */
    showPaystackForm(paymentData) {
        const formHtml = `
            <div class="payment-form">
                <h6 class="mb-3"><i class="fas fa-credit-card mr-2"></i>Payment Details</h6>
                
                <!-- Payment Method Selection -->
                <div class="form-group">
                    <label class="form-label">Choose Payment Method:</label>
                    <div class="payment-methods-list">
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="method_card" name="payment_method" value="card" class="custom-control-input" checked>
                            <label class="custom-control-label" for="method_card">
                                <i class="fas fa-credit-card mr-2"></i>Debit/Credit Card
                            </label>
                        </div>
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="method_mtn" name="payment_method" value="mobile_money_mtn" class="custom-control-input">
                            <label class="custom-control-label" for="method_mtn">
                                <i class="fas fa-mobile-alt mr-2"></i>MTN Mobile Money
                            </label>
                        </div>
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="method_vodafone" name="payment_method" value="mobile_money_vodafone" class="custom-control-input">
                            <label class="custom-control-label" for="method_vodafone">
                                <i class="fas fa-mobile-alt mr-2"></i>Vodafone Cash
                            </label>
                        </div>
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="method_airteltigo" name="payment_method" value="mobile_money_airteltigo" class="custom-control-input">
                            <label class="custom-control-label" for="method_airteltigo">
                                <i class="fas fa-mobile-alt mr-2"></i>AirtelTigo Money
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Mobile Money Number Input (hidden by default) -->
                <div class="form-group" id="mobileMoneyGroup" style="display: none;">
                    <label for="mobileNumber" class="form-label">
                        <i class="fas fa-phone mr-1"></i>Mobile Money Number
                    </label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">+233</span>
                        </div>
                        <input type="tel" class="form-control" id="mobileNumber" placeholder="xxxxxxxxx" 
                               pattern="[0-9]{9}" maxlength="9" title="Enter 9-digit mobile number without country code">
                    </div>
                    <small class="form-text text-muted">Enter your mobile money number (9 digits, without +233)</small>
                </div>
                
                <!-- Voucher Code Input (for mobile money) -->
                <div class="form-group" id="voucherGroup" style="display: none;">
                    <label for="voucherCode" class="form-label">
                        <i class="fas fa-key mr-1"></i>Voucher Code (Optional)
                    </label>
                    <input type="text" class="form-control" id="voucherCode" placeholder="Enter voucher code if you have one">
                    <small class="form-text text-muted">Leave blank if you don't have a voucher code</small>
                </div>
                
                <div class="text-center mt-4">
                    <button type="button" class="btn btn-primary btn-lg btn-block" id="paystackPayBtn">
                        <i class="fas fa-lock mr-2"></i>
                        Pay ${paymentData.currency} ${parseFloat(paymentData.amount).toFixed(2)}
                    </button>
                    <small class="text-muted mt-2 d-block">
                        <i class="fas fa-shield-alt mr-1"></i>Secure payment powered by Paystack
                    </small>
                </div>
            </div>
        `;
        
        $('#paystackPaymentForm').html(formHtml);
        
        // Add event listeners for payment method selection
        $('input[name="payment_method"]').on('change', function() {
            const selectedMethod = $(this).val();
            const isMobileMoney = selectedMethod.includes('mobile_money');
            
            $('#mobileMoneyGroup').toggle(isMobileMoney);
            $('#voucherGroup').toggle(isMobileMoney);
            
            // Update button text based on method
            const buttonText = isMobileMoney ? 
                `<i class="fas fa-mobile-alt mr-2"></i>Pay ${paymentData.currency} ${parseFloat(paymentData.amount).toFixed(2)}` :
                `<i class="fas fa-credit-card mr-2"></i>Pay ${paymentData.currency} ${parseFloat(paymentData.amount).toFixed(2)}`;
            $('#paystackPayBtn').html(buttonText);
        });
        
        // Initialize Paystack payment
        $('#paystackPayBtn').on('click', () => {
            const selectedMethod = $('input[name="payment_method"]:checked').val();
            const mobileNumber = $('#mobileNumber').val();
            const voucherCode = $('#voucherCode').val();
            
            // Validate mobile money input if required
            if (selectedMethod.includes('mobile_money')) {
                if (!mobileNumber || mobileNumber.length !== 9) {
                    alert('Please enter a valid 9-digit mobile money number');
                    $('#mobileNumber').focus();
                    return;
                }
                
                // Validate mobile number format
                if (!/^[0-9]{9}$/.test(mobileNumber)) {
                    alert('Mobile number should contain only digits');
                    $('#mobileNumber').focus();
                    return;
                }
            }
            
            // Prepare payment configuration
            const paymentConfig = {
                key: paymentData.public_key,
                email: paymentData.email,
                amount: paymentData.amount * 100, // Convert to pesewas
                currency: paymentData.currency,
                ref: paymentData.reference,
                metadata: {
                    service_type: this.currentPaymentData.service_type,
                    reference_id: this.currentPaymentData.reference_id || null,
                    payment_method: selectedMethod,
                    custom_fields: [
                        {
                            display_name: 'Service',
                            variable_name: 'service_type',
                            value: this.currentPaymentData.service_display_name
                        },
                        {
                            display_name: 'Payment Method',
                            variable_name: 'payment_method',
                            value: selectedMethod
                        }
                    ]
                },
                callback: (response) => {
                    this.handlePaymentSuccess(response);
                },
                onClose: () => {
                    console.log('Payment cancelled by user');
                }
            };
            
            // Add mobile money specific data
            if (selectedMethod.includes('mobile_money')) {
                paymentConfig.metadata.mobile_money = {
                    phone: '+233' + mobileNumber,
                    provider: selectedMethod.replace('mobile_money_', ''),
                    voucher: voucherCode || null
                };
                
                // Add to custom fields for display
                paymentConfig.metadata.custom_fields.push({
                    display_name: 'Mobile Number',
                    variable_name: 'mobile_number',
                    value: '+233' + mobileNumber
                });
                
                if (voucherCode) {
                    paymentConfig.metadata.custom_fields.push({
                        display_name: 'Voucher Code',
                        variable_name: 'voucher_code',
                        value: voucherCode
                    });
                }
            }
            
            const handler = PaystackPop.setup(paymentConfig);
            handler.openIframe();
        });
    }
    
    /**
     * Handle successful payment
     */
    async handlePaymentSuccess(response) {
        this.showLoading(true, 'Verifying payment...');
        
        try {
            // Verify payment on server
            const verificationResponse = await this.verifyPayment(response.reference);
            
            if (verificationResponse.success) {
                this.showSuccessModal(verificationResponse.data);
            } else {
                this.showError(verificationResponse.message || 'Payment verification failed');
            }
        } catch (error) {
            this.showError('Payment verification failed');
            console.error('Verification error:', error);
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Verify payment on server
     */
    async verifyPayment(reference) {
        const formData = new FormData();
        formData.append('reference', reference);
        formData.append('action', 'verify_payment');
        
        const response = await fetch('<?= BASE_URL ?>/api/payment-handler.php', {
            method: 'POST',
            body: formData
        });
        
        return await response.json();
    }
    
    /**
     * Show success modal
     */
    showSuccessModal(data) {
        $('#paymentModal').modal('hide');
        
        $('#successMessage').text(data.message || 'Your payment has been processed successfully.');
        $('#serviceActivationDetails').text(data.activation_details || 'Service has been activated for your account.');
        
        $('#paymentSuccessModal').modal('show');
        
        // Set continue button action
        $('#continueAfterPayment').off('click').on('click', () => {
            $('#paymentSuccessModal').modal('hide');
            if (data.redirect_url) {
                window.location.href = data.redirect_url;
            } else {
                location.reload();
            }
        });
    }
    
    /**
     * Show loading state
     */
    showLoading(show, message = 'Processing your payment...') {
        if (show) {
            $('#paymentLoading').show();
            $('#paymentLoading p').text(message);
            $('#proceedPaymentBtn').prop('disabled', true);
        } else {
            $('#paymentLoading').hide();
            $('#proceedPaymentBtn').prop('disabled', false);
        }
    }
    
    /**
     * Show error message
     */
    showError(message) {
        $('#paymentErrorMessage').text(message);
        $('#paymentError').show();
        setTimeout(() => {
            $('#paymentError').fadeOut();
        }, 5000);
    }
    
    /**
     * Reset modal to initial state
     */
    resetModal() {
        $('#paymentFormContainer').hide();
        $('#paymentLoading').hide();
        $('#paymentError').hide();
        $('#proceedPaymentBtn').show().prop('disabled', false);
        this.selectedMethod = 'paystack'; // Always use Paystack
    }
}

// Initialize payment modal when document is ready
function initializePaymentModal() {
    if (typeof $ === 'undefined') {
        console.error('jQuery is required for payment modal');
        return;
    }
    
    $(document).ready(() => {
        window.paymentModal = new PaymentModal();
    });
}

// Helper function to show payment modal (can be called from other scripts)
function showPaymentModal(serviceType, serviceDisplayName, serviceDescription, amount, currency = 'GHS', referenceId = null, serviceDetails = null) {
    const paymentData = {
        service_type: serviceType,
        service_display_name: serviceDisplayName,
        service_description: serviceDescription,
        service_details: serviceDetails,
        amount: amount,
        currency: currency,
        reference_id: referenceId
    };
    
    // Wait for payment modal to be ready
    function tryShowModal() {
        if (window.paymentModal) {
            window.paymentModal.showPaymentModal(paymentData);
        } else if (typeof $ !== 'undefined') {
            // jQuery is loaded, initialize if needed
            if (!window.paymentModal) {
                window.paymentModal = new PaymentModal();
            }
            setTimeout(() => tryShowModal(), 100);
        } else {
            console.error('Payment modal not available - jQuery may not be loaded');
            alert('Payment system is loading. Please try again in a moment.');
        }
    }
    
    tryShowModal();
}

// Auto-initialize when script loads
if (typeof $ !== 'undefined') {
    initializePaymentModal();
} else {
    // Wait for jQuery to load
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(initializePaymentModal, 100);
    });
}
</script>