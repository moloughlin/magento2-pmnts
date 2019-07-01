<?php

namespace PMNTS\Gateway\Model\Config;

use Magento\Checkout\Model\ConfigProviderInterface;

class PmntsConfigProvider implements ConfigProviderInterface
{

    /** @var string */
    protected $methodCode = \PMNTS\Gateway\Helper\Data::METHOD_CODE;

    /** @var \Magento\Payment\Model\MethodInterface */
    protected $method;

    /** @var \Magento\Customer\Helper\Session\CurrentCustomer */
    protected $currentCustomer;
    /**
     * @var \Magento\Vault\Model\PaymentTokenManagement
     */
    protected $paymentTokenManagement;

    /**
     * @param \Magento\Payment\Helper\Data $paymentHelper
     * @param \Magento\Customer\Helper\Session\CurrentCustomer $currentCustomer
     * @param \Magento\Vault\Model\PaymentTokenManagement $paymentTokenManagement
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct(
        \Magento\Payment\Helper\Data $paymentHelper,
        \Magento\Customer\Helper\Session\CurrentCustomer $currentCustomer,
        \Magento\Vault\Model\PaymentTokenManagement $paymentTokenManagement
    ) {
        $this->method = $paymentHelper->getMethodInstance($this->methodCode);
        $this->currentCustomer = $currentCustomer;
        $this->paymentTokenManagement = $paymentTokenManagement;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $config = [
            'payment' => [
                'pmntsGateway' => [
                    'iframeSrc' => $this->getIframeSrc(),
                    'isIframeEnabled' => $this->getIframeEnabled(),
                    'fraudFingerprintSrc' => $this->getFraudFingerprintSource(),
                    'isSandbox' => $this->getIsSandbox(),
                    'canSaveCard' => $this->canSaveCard(),
                    'customerHasSavedCC' => $this->customerHasSavedCC(),
                    'ccVaultCode' => \PMNTS\Gateway\Helper\Data::VAULT_METHOD_CODE
                ]
            ]
        ];
        return $config;
    }

    private function getConfigValue($key)
    {
        return $this->method->getConfigData($key);
    }

    private function getIframeEnabled()
    {
        return (bool)$this->getConfigValue("iframe_tokenization");
    }

    private function getFraudFingerprintSource()
    {
        $is_sandbox = $this->getConfigValue("sandbox_mode");
        $username = $this->getConfigValue("username");
        if ($is_sandbox) {
            return "https://gateway.pmnts-sandbox.io/fraud/fingerprint/{$username}.js";
        } else {
            return "https://gateway.pmnts.io/fraud/fingerprint/{$username}.js";
        }
    }

    private function getIframeSrc()
    {
        $is_sandbox = $this->getConfigValue("sandbox_mode");
        $username = $this->getConfigValue("username");
        $shared_secret = $this->getConfigValue("shared_secret");
        $nonce = substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", 5)), 0, 5);
        $hash_payload = "{$nonce}:1.0:AUD";
        $hash = hash_hmac("md5", $hash_payload, $shared_secret);

        $base_url = "https://paynow.pmnts.io";
        if ($is_sandbox) {
            $base_url = "https://paynow.pmnts-sandbox.io";
        }

        $url = "{$base_url}/v2/{$username}/{$nonce}/AUD/1.0/{$hash}?show_extras=false&show_email=false&iframe=true&paypal=false&tokenize_only=true&masterpass=false&visacheckout=false&hide_button=true&postmessage=true&return_target=_self&ajax=true";

        // If CSS URL is set, generate signature, add to iframe URL
        $css_url = $this->getConfigValue("iframe_css");
        if (strlen($css_url) > 0) {
            $css_signature = hash_hmac("md5", $css_url, $shared_secret);
            $url = $url . "&css={$css_url}&css_signature={$css_signature}";
        }

        return $url;
    }

    private function getIsSandbox()
    {
        $is_sandbox = $this->getConfigValue("sandbox_mode");

        return $is_sandbox;
    }

    private function canSaveCard()
    {
        $customer = $this->currentCustomer->getCustomerId();
        return !is_null($customer) && $this->getConfigValue('customer_save_credit_card');
    }

    private function customerHasSavedCC()
    {
        $customerId = $this->currentCustomer->getCustomerId();
        if (!isset($customerId)) {
            return false;
        }

        $customer = $this->currentCustomer->getCustomer();
        if (is_null($customer)) {
            return false;
        }
        $tokens = $this->paymentTokenManagement->getVisibleAvailableTokens($customerId);
        foreach ($tokens as $token) {
            if ($token->getPaymentMethodCode() === \PMNTS\Gateway\Helper\Data::METHOD_CODE) {
                return true;
            }
        }

        return false;
    }
}
