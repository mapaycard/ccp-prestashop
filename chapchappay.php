<?php
/**
 * 2007-2025 PrestaShop
 *
 * Module Chap Chap Pay - Simplified configuration (API Key + Encryption Key)
 *
 * @author    CHAP CHAP PAY <contact@chapchappay.com>
 * @copyright 2024-2025 CHAP CHAP PAY
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class ChapChapPay extends PaymentModule
{
    const MINIMUM_AMOUNT = 5000; // Montant minimum (GNF)
    
    public $api_url;

    public function __construct()
    {
        $this->name = 'chapchappay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.2';
        $this->author = 'CHAP CHAP PAY';
        $this->need_instance = 0;
        #$this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('Chap Chap Pay');
        $this->description = $this->l('Acceptez les paiements via PayCard, Orange Money, MTN Mobile, Visa & MasterCard');
        $this->confirmUninstall = $this->l('Êtes-vous sûr de vouloir désinstaller ce module ?');

        $this->api_url = 'https://chapchappay.com/api/ecommerce/create';

        $api_key = Configuration::get('CHAPCHAPPAY_API_KEY');
        $encryption_key = Configuration::get('CHAPCHAPPAY_ENCRYPTION_KEY');
         
        if (empty($api_key) || empty($encryption_key)) {
            $this->warning = $this->l('Veuillez configurer votre clé API et clé de chiffrement');
        }
    }

    public function install()
    {
        if (!parent::install() ||
            !$this->registerHook('paymentOptions') ||
            !$this->registerHook('paymentReturn') ||
            !$this->registerHook('displayHeader') ||
            !$this->registerHook('displayOrderConfirmation') ||
            !$this->registerHook('displayAdminOrder') ||
            !$this->createPendingOrderState()
        ) {
            return false;
        }
        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('CHAPCHAPPAY_API_KEY') ||
            !Configuration::deleteByName('CHAPCHAPPAY_ENCRYPTION_KEY')
        ) {
            return false;
        }
        return true;
    }

    /************ CONFIGURATION ************/
    public function getContent()
    {
        
        $output = '';
        
        if (Tools::isSubmit('submit'.$this->name)) {
        
            $api_key = Tools::getValue('CHAPCHAPPAY_API_KEY');
            $encryption_key = Tools::getValue('CHAPCHAPPAY_ENCRYPTION_KEY');
            
            Configuration::updateValue('CHAPCHAPPAY_API_KEY', $api_key);
            Configuration::updateValue('CHAPCHAPPAY_ENCRYPTION_KEY', $encryption_key);
            
            $check1 = Configuration::get('CHAPCHAPPAY_API_KEY');
            $check2 = Configuration::get('CHAPCHAPPAY_ENCRYPTION_KEY');
            
            $output .= $this->displayConfirmation($this->l('Paramètres sauvegardés !'));
            
            // Rediriger pour éviter le re-submit
            Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=4');
        }
        
        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        // Formulaire HTML SIMPLE
        $current_api = Configuration::get('CHAPCHAPPAY_API_KEY');
        $current_enc = Configuration::get('CHAPCHAPPAY_ENCRYPTION_KEY');
        
        $html = '
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-cogs"></i> ' . $this->l('Configuration Chap Chap Pay') . '
            </div>
            <div class="panel-body">
                <form action="" method="post">
                    <div class="form-group">
                        <label for="api_key">' . $this->l('Clé API ChapChapPay') . '</label>
                        <input type="text" id="api_key" name="CHAPCHAPPAY_API_KEY" 
                            value="' . htmlspecialchars($current_api) . '"
                            class="form-control">
                        <p class="help-block">Valeur actuelle: ' . (empty($current_api) ? 'Non définie' : 'Définie') . '</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="enc_key">' . $this->l('Clé de chiffrement HMAC') . '</label>
                        <input type="text" id="enc_key" name="CHAPCHAPPAY_ENCRYPTION_KEY" 
                            value="' . htmlspecialchars($current_enc) . '"
                            class="form-control">
                        <p class="help-block">Valeur actuelle: ' . (empty($current_enc) ? 'Non définie' : 'Définie') . '</p>
                    </div>
                    
                    <div class="panel-footer">
                        <button type="submit" name="submit' . $this->name . '" class="btn btn-default pull-right">
                            <i class="process-icon-save"></i> ' . $this->l('Enregistrer') . '
                        </button>
                    </div>
                </form>
            </div>
        </div>';
        
        return $html;
    }

    /************ FRONT : PAYMENT OPTIONS ************/
    public function hookPaymentOptions($params)
    {
        if (!$this->active) return [];

        $cart = $params['cart'];
        if (!Validate::isLoadedObject($cart)) return [];

        $currency = new Currency($cart->id_currency);
        if ($currency->iso_code !== 'GNF') return [];

        // Vérification des clés
        $api_key = Configuration::get('CHAPCHAPPAY_API_KEY');
        $encryption_key = Configuration::get('CHAPCHAPPAY_ENCRYPTION_KEY');
        if (empty($api_key) || empty($encryption_key)) {
            return [];
        }

        $cart_total = (float)$cart->getOrderTotal(true, Cart::BOTH);
        if ($cart_total < self::MINIMUM_AMOUNT) {
            $message = $this->l('Le montant minimum pour payer avec Chap Chap Pay est ').self::MINIMUM_AMOUNT.' GNF.';
            $this->context->smarty->assign('chapchappay_minimum_message', $message);
            return [];
        }
        
        // Assigner les variables au template du hook
        $this->context->smarty->assign([
            'module_name' => $this->name,
            'payment_url' => $this->context->link->getModuleLink($this->name, 'payment', [], true),
        ]);

        $paymentOption = new PaymentOption();
        $paymentOption->setCallToActionText($this->l('Payer avec Chap Chap Pay'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true))
            ->setAdditionalInformation($this->context->smarty->fetch('module:chapchappay/views/templates/hook/payment.tpl'));

        return [$paymentOption];
    }

    /************ HOOK PAYMENT RETURN ************/


    /************ HOOK DISPLAY ORDER CONFIRMATION ************/
    public function hookDisplayOrderConfirmation($params)
    {
        if (!$this->active) return;

        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order || $order->module !== $this->name) return;

        $currency = new Currency($order->id_currency);
        $this->context->smarty->assign([
            'order' => $order,
            'total' => Tools::displayPrice($order->getOrdersTotalPaid(), $currency, false),
            'PS_OS_PAYMENT' => Configuration::get('PS_OS_PAYMENT'),
        ]);

        return $this->context->smarty->fetch('module:chapchappay/views/templates/hook/order_confirmation.tpl');
    }

    /************ ADMIN ORDER HOOK ************/
    public function hookDisplayAdminOrder($params)
    {
        $order = new Order($params['id_order']);
        if ($order->module !== $this->name) return;

        $operation_id = $this->getOrderMetaData($order->id, 'chapchap_operation_id');
        $payment_method = $this->getOrderMetaData($order->id, 'chapchap_payment_method');

        if ($operation_id || $payment_method) {
            $this->context->smarty->assign([
                'operation_id' => $operation_id,
                'payment_method' => $payment_method,
                'payment_method_text' => $this->getPaymentMethodText($payment_method),
            ]);

            return $this->display(__FILE__, 'views/templates/admin/order_info.tpl');
        }
    }

    /************ UTILITAIRES ************/
    public function verifyHmac($payload, $signature)
    {
        $encryption_key = Configuration::get('CHAPCHAPPAY_ENCRYPTION_KEY');
        if (empty($encryption_key)) return false;

        $computed = hash_hmac('sha256', $payload, $encryption_key);
        return hash_equals($computed, $signature);
    }

    public function getPaymentMethodText($payment_method)
    {
        $translations = [
            'paycard' => 'PAYCARD',
            'cc' => 'Carte bancaire',
            'orange_money' => 'Orange Money',
            'mtn_momo' => 'MTN Mobile Money',
        ];

        return $translations[$payment_method] ?? $payment_method;
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) return true;
            }
        }
        return false;
    }

    private function getOrderMetaData($order_id, $key)
    {
        return Db::getInstance()->getValue('
            SELECT value FROM `'._DB_PREFIX_.'order_meta`
            WHERE id_order = '.(int)$order_id.' AND meta_key = "'.pSQL($key).'"'
        );
    }

    public function saveOrderMetaData($order_id, $key, $value)
    {
        $exists = Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `'._DB_PREFIX_.'order_meta`
            WHERE id_order = '.(int)$order_id.' AND meta_key = "'.pSQL($key).'"'
        );

        if ($exists) {
            return Db::getInstance()->update('order_meta', [
                'value' => pSQL($value),
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_order = '.(int)$order_id.' AND meta_key = "'.pSQL($key).'"');
        } else {
            return Db::getInstance()->insert('order_meta', [
                'id_order' => (int)$order_id,
                'meta_key' => pSQL($key),
                'value' => pSQL($value),
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /************ STATUT PENDING ************/
    private function createPendingOrderState()
    {
        if (Configuration::get('CHAPCHAPPAY_OS_PENDING')) return true;

        $orderState = new OrderState();
        $orderState->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $orderState->name[$lang['id_lang']] = 'Pending ChapChapPay';
        }
        $orderState->color = '#4169E1';
        $orderState->send_email = false;
        $orderState->module_name = $this->name;
        $orderState->logable = true;
        $orderState->delivery = false;
        $orderState->invoice = false;

        if ($orderState->add()) {
            Configuration::updateValue('CHAPCHAPPAY_OS_PENDING', (int)$orderState->id);
            return true;
        }

        return false;
    }
}
