<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XLite\Module\QSL\BraintreeVZ\Core;

/**
 * Braintree client
 */
class BraintreeClient extends \XLite\Base\Singleton
{
    /**
     * Braintree class name in payment methods table
     */
    const BRAINTREE_CLASS = 'Module\QSL\BraintreeVZ\Model\Payment\Processor\BraintreeVZ';

    /**
     * Secret and IV for communication with OAuth intermediate server
     */
    const OAUTH_SECRET = '9z947ghG4Q';
    const OAUTH_IV = 'w4gQXhf46YuokfwE';

    /**
     * Channel identifier (shopping cart provider)
     */
    const CHANNEL = 'XCart_Cart_vzero';

    /**
     * Error log file name
     */
    const ERROR_LOG_FILE = 'braintree-error';

    /**
     * Use test intermediate server or not
     */
    const TEST_SERVER = false;

    /**
     * Fields of Braintree transaction theat should be saved
     */
    protected $transactionFields = array(
        'id'                                => 'Transaction ID',
        'status'                            => 'Status',
        'createdAt'                         => 'Created at',
        'updatedAt'                         => 'Updated at',
        'paymentInstrumentType'             => 'Transaction created via',
        'avsErrorResponseCode'              => 'AVS Error response code',
        'avsPostalCodeResponseCode'         => 'AVS Postal code response code',
        'avsStreetAddressResponseCode'      => 'AVS Street address response code',
        'cvvResponseCode'                   => 'CVV Response code',
        'gatewayRejectionReason'            => 'Gateway rejection code',
        'processorAuthorizationCode'        => 'Processor authorization code',
        'processorResponseCode'             => 'Processor response code',
        'processorResponseText'             => 'Processor response text',
        'processorSettlementResponseCode'   => 'Processor settlement response code',
        'processorSettlementResponseText'   => 'Processor settlement response text',
        'additionalProcessorResponse'       => 'Additional processor response',
        'voiceReferralNumber'               => 'Voice referal number',
        'purchaseOrderNumber'               => 'Purchase order number',
    );

    /**
     * AVS response codes
     */
    protected $avsResponseCodes = array(
        'M' => 'Matches',
        'N' => 'Does not Match',
        'U' => 'Not Verified',
        'I' => 'Not Provided',
        'A' => 'Not Applicable',
    );

    /**
     * CVV response codes
     */
    protected $cvvResponseCodes = array(
        'M' => 'Matches',
        'N' => 'Does not Match',
        'U' => 'Not Verified',
        'I' => 'Not Provided',
        'A' => 'Not Applicable',
    );

    /**
     * AVS error codes 
     */
    protected $avsErrorCodes = array(
        'S' => 'Issuing bank does not support AVS',
        'E' => 'AVS system error',
        'A' => 'Not Applicable',
    );

    /**
     * Hash of fields and codes
     */
    protected $codeHash = array(
        'avsErrorResponseCode'          => 'avsErrorCodes',
        'avsPostalCodeResponseCode'     => 'avsResponseCodes',
        'avsStreetAddressResponseCode'  => 'avsResponseCodes',
        'cvvResponseCode'               => 'cvvResponseCodes',
    );

    /**
     * Credit card information 
     */
    protected $creditCardFields = array(
        'bin'               => 'BIN',
        'last4'             => 'Last four digits',
        'cardType'          => 'Card type',
        'expirationMonth'   => 'Expiration month',
        'expirationYear'    => 'Expiration year',
        'customerLocation'  => 'Customer location',
        'cardholderName'    => 'Cardholder name',
        'imageUrl'          => '',
    );

    /**
     * PayPal details 
     */
    protected $paypalFields = array(
        'token'                  => 'PayPal account token',
        'payerEmail'             => 'Payer e-mail',
        'payerId'                => 'Payer ID',
        'payerStatus'            => 'Payer status',
        'paymentId'              => 'Payment ID',
        'authorizationId'        => 'Authorization ID',
        'sellerProtectionStatus' => 'Seller protection status',
        'imageUrl'               => '',
    );

    /**
     * Braintree gateway instance 
     */
    protected $gateway = null;

    /**
     * Braintree payment method 
     */
    protected $paymentMethod = null;

    /**
     * Get Braintree payment method
     *
     * @return \XLite\Model\Payment\Method
     */
    public function getPaymentMethod()
    {
        if (!$this->paymentMethod) {
        
            $this->paymentMethod = \XLite\Core\Database::getRepo('XLite\Model\Payment\Method')
                ->findOneBy(array('class' => static::BRAINTREE_CLASS));
        }

        return $this->paymentMethod;
    }

    /**
     * Get X-Cart timezone
     *
     * @return \DateTimeZone
     */
    public function getStoreTimeZone() 
    {
        $defaultDateTime = new \DateTime();
        $timeZone = \XLite\Core\Config::getInstance()->Units->time_zone ?: $defaultDateTime->getTimezone()->getName();

        return new \DateTimeZone($timeZone);
    }

    /**
     * Get setting value of the Braintree payment method
     *
     * @param string $setting Setting name
     *
     * @return string 
     */
    public function getSetting($setting)
    {
        return $this->getPaymentMethod()->getSetting($setting);
    }

    /**
     * Configure server
     *
     * @return void 
     */
    protected function configure()
    {
        if (!$this->gateway) {

            $this->gateway = new \Braintree\Gateway(array(
                'accessToken' => $this->getSetting('accessToken'),
            ));
        }
    }

    /**
     * Get Braintree customer ID. If vault is on.
     *
     * @param \XLite\Model\Profile $profile Customer profile. Optional
     *
     * @return string || false 
     */
    protected function getCustomerId($profile = null)
    {
        $customerId = false;

        if (
            $this->isConfigured()
            && $this->getSetting('isUseVault') 
            && $profile
            && $profile->getBraintreeCustomerId()
        ) {
            $customerId = $profile->getBraintreeCustomerId();

            try {

                $this->configure();
                $customer = $this->gateway->customer()->find($customerId);

            } catch (\Exception $e) {

                $customerId = false;
            }
        }

        return $customerId;
    }

    /**
     * Get tranaction token 
     *
     * @return string 
     */
    public function getToken()
    {
        try {

            $this->configure();

            $merchantAccountId = $this->getSetting('merchantAccountId');   
 
            if (!empty($merchantAccountId)) {

                $options = array(
                    'merchantAccountId' => $merchantAccountId,
                );

                $token = $this->gateway->clientToken()->generate($options);

            } else {

                $token = $this->gateway->clientToken()->generate();
            }

        } catch (\Exception $e) {

            $token = '';
            static::processError($e->getMessage());
        }

        return $token;
    }

    /**
     * Is Braintree account correct or not 
     *
     * @return bool
     */
    public function isConfigured()
    {
        $accessToken = $this->getSetting('accessToken');

        return !empty($accessToken);
    }
    
    /**
     * Returns state code for US and state name for other countries
     *
     * @param $address \XLite\Model\Address Address
     *
     * @return string
     */
    public function getStateField(\XLite\Model\Address $address)
    {
        $state = '';

        if ($address->getCountry() && $address->getState()) {
            $state = ('US' == strtoupper($address->getCountry()->getCode()))
                ? $address->getState()->getCode()
                : $address->getState()->getState();
        }

        return $state;
    }

    /**
     * Get initial transaction request 
     *
     * @param XLite\Model\Payment\Transaction $transaction Tranaction
     *
     * @return array
     */
    protected function getInitRequest(\XLite\Model\Payment\Transaction $transaction)
    {
        $profile = $transaction->getOrder()->getProfile();

        $request = array(
            'amount' => round($transaction->getValue(), 2),
            'orderId' => $this->getSetting('prefix') . $transaction->getPublicTxnId(),
            'paymentMethodNonce' => \XLite\Core\Request::getInstance()->payment_method_nonce,
            'options' => array(
                'submitForSettlement'   => (bool)$this->getSetting('isAutoSettle'),
                'storeInVaultOnSuccess' => \XLite\Module\QSL\BraintreeVZ\Core\BraintreeClient::getInstance()->getSetting('isUseVault'),
            ),
            'deviceData' => \XLite\Core\Request::getInstance()->device_data,
            'channel' => static::CHANNEL,
        );

        $merchantAccountId = $this->getSetting('merchantAccountId');

        if (!empty($merchantAccountId)) {

            // Add the specific merchhant account ID if it's defined 
            $request['merchantAccountId'] = $merchantAccountId;
        }

        if (!empty(\XLite\Core\Request::getInstance()->payment_method_nonce)) {

            // Credit card or PayPal
            $request['paymentMethodNonce'] = \XLite\Core\Request::getInstance()->payment_method_nonce;

        } elseif (!empty(\XLite\Core\Request::getInstance()->saved_card_token)) {

            // card saved in vault
            $request['paymentMethodToken'] = \XLite\Core\Request::getInstance()->saved_card_token;
        }

        if ($this->getCustomerId($profile)) {

            // Use existing customer
            $request['customerId'] = $this->getCustomerId($profile);

        } elseif ($profile->getBillingAddress()) {

            // Create new customer
            $request['customer'] = array(
                'firstName' => $profile->getBillingAddress()->getFirstname(),
                'lastName'  => $profile->getBillingAddress()->getLastname(),
                'company'   => $profile->getBillingAddress()->getCompany(),
                'phone'     => $profile->getBillingAddress()->getPhone(),
                'fax'       => $profile->getBillingAddress()->getFax(),
                'email'     => $profile->getLogin(),
            );
        }

        if ($profile->getBillingAddress()) {
            $request['billing'] = array(
                'firstName'         => $profile->getBillingAddress()->getFirstname(),
                'lastName'          => $profile->getBillingAddress()->getLastname(),
                'company'           => $profile->getBillingAddress()->getCompany(),
                'streetAddress'     => $profile->getBillingAddress()->getStreet(),
                'locality'          => $profile->getBillingAddress()->getCity(),
                'region'            => $this->getStateField($profile->getBillingAddress()),
                'postalCode'        => $profile->getBillingAddress()->getZipcode(),
                'countryCodeAlpha2' => strtoupper($profile->getBillingAddress()->getCountry()->getCode()),
            );
        }

        // This checks if the shipping for the order is required
        $modifier = $transaction->getOrder()->getModifier(\XLite\Model\Base\Surcharge::TYPE_SHIPPING, 'SHIPPING');

        if (
            $profile->getShippingAddress() 
            && $modifier 
            && $modifier->canApply()
        ) {
            $request['shipping'] = array(
                'firstName'         => $profile->getShippingAddress()->getFirstname(),
                'lastName'          => $profile->getShippingAddress()->getLastname(),
                'company'           => $profile->getShippingAddress()->getCompany(),
                'streetAddress'     => $profile->getShippingAddress()->getStreet(),
                'locality'          => $profile->getShippingAddress()->getCity(),
                'region'            => $this->getStateField($profile->getShippingAddress()),
                'postalCode'        => $profile->getShippingAddress()->getZipcode(),
                'countryCodeAlpha2' => strtoupper($profile->getShippingAddress()->getCountry()->getCode()),
            );
        }

        return $request;
    }

    /**
     * Process transaction result 
     *
     * @param \XLite\Model\Payment\Transaction $transaction Tranaction
     * @param $result Transaction result 
     *
     * @return boolean 
     */
    protected function processResult(\XLite\Model\Payment\Transaction $transaction, $result, $isSecondary = false)
    {
        $status = $transaction::STATUS_FAILED;

        $profile = $transaction->getOrder()->getProfile();

        if ($result->transaction) {

            if ($result->success) {
                $status = $transaction::STATUS_SUCCESS;
            }

            foreach ($this->transactionFields as $field => $title) {

                if ($result->transaction->$field) {
    
                    $transaction->setBraintreeDataCell($field, $result->transaction->$field, $title);

                    if (isset($this->codeHash[$field])) {
                
                        $codes = $this->{$this->codeHash[$field]};

                        $value = $codes[$result->transaction->$field];

                        $transaction->setBraintreeLiteralDataCell($field, $value, $title);
                    }
                }
            }

            if (!empty($result->transaction->paypal)) {

                foreach ($this->paypalFields as $field => $title) {
                    if ($result->transaction->paypal[$field]) {
                        $transaction->setBraintreePayPalDataCell($field, $result->transaction->paypal[$field], $title);
                    }
                }

            } elseif (!empty($result->transaction->creditCard)) {

                foreach ($this->creditCardFields as $field => $title) {
                    if ($result->transaction->creditCard[$field]) {
                        $transaction->setBraintreeCreditCardDataCell($field, $result->transaction->creditCard[$field], $title);
                    }
                }

                if ($profile) {

                    $isSaveCard = ('Y' == \XLite\Core\Request::getInstance()->save_card);

                    if (
                        !$isSaveCard
                        && !empty($result->transaction->creditCard['token'])
                        && empty(\XLite\Core\Request::getInstance()->saved_card_token)
                    ) {
                        $this->removeCardByToken($profile, $result->transaction->creditCard['token']);
                    }

                    $profile->setSaveCardBoxChecked($isSaveCard);
                }
            }

            if (
                $result->transaction->customerDetails
                && !empty($result->transaction->customerDetails->id)
            ) {

                if ($profile) {
                    $profile->setBraintreeCustomerId($result->transaction->customerDetails->id);
                }

                $transaction->setBraintreeLiteralDataCell('customerId', $result->transaction->customerDetails->id, 'Customer ID');
            }

            if (isset($result->transaction->riskData)) {
                $transaction->setBraintreeLiteralDataCell('riskDataId', $result->transaction->riskData->id, 'Risk data ID');
                $transaction->setBraintreeLiteralDataCell('riskDataDecision', $result->transaction->riskData->decision, 'Risk decision');
            }
        }

        if (!$result->success) {

            $errorList = array(''); // For empty line after "Failure reason"

            // Validation error(s)
            foreach ($result->errors->deepAll() as $key => $error) {

                $field = 'Error' . $key;
                $value = $error->message;
                $title = 'Error #' . $error->code . ' ' . $value;

                $transaction->setBraintreeDataCell($field, $value, $title);

                static::processError($title);

                $errorList[] = $title;
            }

            if (!$isSecondary) {
                $transaction->setDataCell('status', implode('<br />', $errorList), 'Failure reason', 'C');
            }
        }

        if (!$isSecondary) {

            $transaction->setStatus($status);

            \XLite\Core\Database::getEM()->flush();

            $transaction->markBraintreeProcessed();    
        }

        return ($transaction::STATUS_SUCCESS == $status);
    }

    /**
     * Process checkout 
     *
     * @param \XLite\Model\Payment\Transaction $transaction Tranaction
     *
     * @return string
     */
    public function processCheckout(\XLite\Model\Payment\Transaction $transaction)
    {
        if (!$transaction->isBraintreeProcessed()) {

            try {

                $this->configure();

                $result = $this->gateway->transaction()->sale( 
                    $this->getInitRequest($transaction)
                );

                $this->processResult($transaction, $result);

                \XLite\Core\Session::getInstance()->braintree_paypal_nonce = '';

            } catch (\Exception $e) {

                static::processError($e->getMessage());
            }
        }
    }

    /**
     * Process capture 
     *
     * @param \XLite\Model\Payment\Transaction $transaction Tranaction
     * @param float $amount Amount
     *
     * @return string
     */
    public function processCapture(\XLite\Model\Payment\Transaction $transaction, $amount = null)
    {
        $result = false;

        if ($transaction->getBraintreeDataCell('id')) {

            $transactionId = $transaction->getBraintreeDataCell('id')->getValue();

            try {

                $this->configure();

                if ($amount) {
                    $result = $this->gateway->transaction()->submitForSettlement($transactionId, $amount);
                } else {
                    $result = $this->gateway->transaction()->submitForSettlement($transactionId);
                }

                $result = $this->processResult($transaction, $result, true);

            } catch (\Braintree\Exception $e) {

                static::processError($e->getMessage());

                $result = false;
            }
        }

        return $result;
    }

    /**
     * Process refund 
     *
     * @param \XLite\Model\Payment\Transaction $transaction Tranaction
     * @param float $amount Amount
     *
     * @return string
     */
    public function processRefund(\XLite\Model\Payment\Transaction $transaction, $amount = null)
    {
        $result = false;

        if ($transaction->getBraintreeDataCell('id')) {

            $transactionId = $transaction->getBraintreeDataCell('id')->getValue();

            try {

                $this->configure();

                if ($amount) {
                    $result = $this->gateway->transaction()->refund($transactionId, $amount);
                } else {
                    $result = $this->gateway->transaction()->refund($transactionId);
                }

                $result = $this->processResult($transaction, $result, true);

            } catch (\Braintree\Exception $e) {

                static::processError($e->getMessage());

                $result = false;
            }
        }

        return $result;
    }

    /**
     * Process void 
     *
     * @param \XLite\Model\Payment\Transaction $transaction Tranaction
     * @param float $amount Amount
     *
     * @return string
     */
    public function processVoid(\XLite\Model\Payment\Transaction $transaction)
    {
        $result = false;

        if ($transaction->getBraintreeDataCell('id')) {

            $transactionId = $transaction->getBraintreeDataCell('id')->getValue();

            try {

                $this->configure();

                $result = $this->gateway->transaction()->void($transactionId);

                $result = $this->processResult($transaction, $result, true);

            } catch (\Braintree\Exception $e) {

                static::processError($e->getMessage());

                $result = false;
            }
        }

        return $result;
    }

    /**
     * Get nonce for the saved card
     *
     * @param string $token Payment method token (i.e. saved card token)
     *
     * @return string 
     */
    public function getSavedCardNonce($token = '')
    {
        $nonce = '';

        try {

            $this->configure();

            $response = $this->gateway->paymentMethodNonce()->create($token);

            if ($response->success) {
                $nonce = $response->paymentMethodNonce->nonce;
            }

        } catch (\Exception $e) {

            static::processError($e->getMessage());
        }

        return $nonce;
    }

    /**
     * Get credit cards associated with profile
     *
     * @param \XLite\Model\Profile $profile Customer's profile
     *
     * @return array 
     */
    public function getCreditCards(\XLite\Model\Profile $profile)
    {
        $creditCards = array();

        try {

            $this->configure();

            $customerId = $this->getCustomerId($profile);

            if (
                $this->isConfigured()
                && $customerId
            ) {

                $customer = $this->gateway->customer()->find($customerId);

                $creditCards = array_merge($customer->paypalAccounts, $customer->creditCards);

            }

        } catch (\Exception $e) {

            // Do nothing. Merchant account is not configured, or the customer doesn't have credit cards
        }

        return $creditCards;
    }

    /**
     * Remove credit card from profile
     *
     * @param \XLite\Model\Profile $profile Customer's profile
     * @param string $token Credit card token
     *
     * @return array
     */
    public function removeCardByToken(\XLite\Model\Profile $profile, $token)
    {
        $result = false;

        try {

            $this->configure();

            $customerId = $this->getCustomerId($profile);

            if (
                $this->isConfigured()
                && $customerId
            ) {

                $result = $this->gateway->paymentMethod()->delete($token);
            }

        } catch (\Exception $e) {

            static::processError($e->getMessage());
        }

        return $result;
    }

    /**
     * Set default credit card for profile
     *
     * @param \XLite\Model\Profile $profile Customer's profile
     * @param string $token Credit card token
     *
     * @return array
     */
    public function setDefaultCardByToken(\XLite\Model\Profile $profile, $token)
    {
        $result = false;

        try {

            $this->configure();

            $customerId = $this->getCustomerId($profile);

            if (
                $this->isConfigured()
                && $customerId
            ) {

                $result = $this->gateway->paymentMethod()->update(
                    $token,
                    array(
                        'options' => array(
                            'makeDefault' => true
                        )
                    )
                );
            }

        } catch (\Exception $e) {

            static::processError($e->getMessage());

        }

        return $result;
    }

    /**
     * Check if Braintree credit cards tab should be displayed 
     *
     * @return bool 
     */
    public function isDisplayCardsTab()
    {
        try {

            $this->configure();

            $result = $this->getPaymentMethod()
                && $this->getPaymentMethod()->isEnabled()
                && $this->getSetting('isUseVault');

        } catch (\Exception $e) {

            $result = false;
        }

        return $result;
    }

    /**
     * URL of the intermediate server for OAuth
     *
     * @param string $action
     *
     * @return string
     */
    public static function getIntermediateServerUrl($action = '')
    {
        if (static::TEST_SERVER) {
            $url = 'https://braintree.x-cart.com/oauth/connect_test.php';
        } else {
            $url = 'https://braintree.x-cart.com/oauth/connect.php';
        }

        $params = array(); 

        if (!empty($action)) {
            $params['action'] = $action;
        }

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Simple method to encrypt a plain text string
     * 
     * @param string $str string to encrypt or decrypt
     *
     * @return string
     */
    protected static function encrypt($str) 
    {
        $str = openssl_encrypt($str, 'AES-256-CBC', static::OAUTH_SECRET, 0, static::OAUTH_IV);
        $str = base64_encode($str);
        
        return $str;
    }

    /**
     * Get state for OAuth
     *
     * @return string
     */
    public function getOAuthState()
    {
        $url = \XLite\Core\Converter::buildUrl(
            'braintree_account',
            'oauth_return',
            array(),
            \XLite::getAdminScript()
        );

        $url = \XLite\Core\URLManager::getShopURL($url, true, array(), null, false);

        return static::encrypt($url);
    }

    /**
     * Obtain client token, i.e. the data of the merchant account from Braintree
     *
     * @return array
     */
    protected function getMerchantData()
    {
        try {

            $this->configure();

            $merchantAccountId = $this->getSetting('merchantAccountId');

            if (!empty($merchantAccountId)) {

                // Use the specific merchant account ID if it's defined
                $options = array(
                    'merchantAccountId' => $this->getSetting('merchantAccountId'),
                );
                $data = $this->gateway->clientToken()->generate($options);

            } else {

                // Use the default merchant ID
                $data = $this->gateway->clientToken()->generate();
            }

            $data = json_decode(base64_decode($data), true);            

        } catch (Exception $e) {

            $data = array();

            $this->processError($e->getMessage());
        }

        return $data;
    }

    /**
     * Synchronize module settings with the Braintree merchant account
     *
     * @return bool
     */
    public function synchronizeAccount()
    {
        $changed = false;

        $data = $this->getMerchantData();

        $isPayPal = !empty($data['paypalEnabled']) ? '1' : '0';
        $is3dSecure = !empty($data['threeDSecureEnabled']) ? '1' : '0';

        if ($isPayPal != $this->getSetting('isPayPal')) {
            $this->getPaymentMethod()->setSetting('isPayPal', $isPayPal);
            $changed = true;    
        }

        if ($is3dSecure != $this->getSetting('is3dSecure')) {
            $this->getPaymentMethod()->setSetting('is3dSecure', $is3dSecure);
            $changed = true;
        }

        if ($changed) {
            \XLite\Core\Database::getEM()->flush();
        }

        return $changed;
    }

    /**
     * Write error to log and display top message
     *
     * @param $message Error message
     *
     * @return void
     */
    public static function processError($message)
    {
        \XLite\Logger::getInstance()->logCustom(static::ERROR_LOG_FILE, $message);
        \XLite\Core\TopMessage::getInstance()->addError($message);
    }

}
