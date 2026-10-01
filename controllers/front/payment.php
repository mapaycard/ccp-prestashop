<?php

class ChapChapPayPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;

        if (!$cart->id) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        // Vérifier la devise (ChapChapPay supporte uniquement GNF)
        $currency = new Currency($cart->id_currency);
        if ($currency->iso_code !== 'GNF') {
            $this->errors[] = $this->module->l('Cette devise n\'est pas supportée. Seul le GNF est accepté.');
            return $this->redirectWithNotifications(
                $this->context->link->getPageLink('order', true, null, ['step' => 3])
            );
        }

        // Montant de la commande
        $amount = (float)$cart->getOrderTotal(true, Cart::BOTH);

        if ($amount < 5000) {
            $this->errors[] = $this->module->l('Le montant minimum pour payer avec Chap Chap Pay est 5000 GNF.');
            return $this->redirectWithNotifications(
                $this->context->link->getPageLink('order', true, null, ['step' => 3])
            );
        }

        // Clés API
        $api_key = Configuration::get('CHAPCHAPPAY_API_KEY');
        $encryption_key = Configuration::get('CHAPCHAPPAY_ENCRYPTION_KEY');

        if (empty($api_key) || empty($encryption_key)) {
            $this->errors[] = $this->module->l('Le module Chap Chap Pay n\'est pas correctement configuré.');
            return $this->redirectWithNotifications(
                $this->context->link->getPageLink('order', true, null, ['step' => 3])
            );
        }

        // Créer la commande dans PrestaShop AVANT d'appeler ChapChapPay
        // Statut PENDING car le paiement n'est pas encore confirmé
        $customer = new Customer($cart->id_customer);
        $order_id = null;
        
        try {
            // Valider la commande avec statut PENDING
            $this->module->validateOrder(
                (int)$cart->id,                 // id_cart
                (int)Configuration::get('CHAPCHAPPAY_OS_PENDING'), // statut PENDING
                (float)$amount,                 // montant
                $this->module->displayName,     // nom du module
                null,                           // message
                [],                             // extra vars
                (int)$currency->id,             // id_currency
                false,                          // dont_touch_amount
                $customer->secure_key           // secure_key
            );
            
            $order_id = Order::getOrderByCartId($cart->id);
            
            if (!$order_id) {
                throw new Exception('Commande non créée');
            }
            
        } catch (Exception $e) {
            error_log('ChapChapPay - Erreur création commande: ' . $e->getMessage());
            $this->errors[] = $this->module->l('Erreur lors de la création de la commande.');
            return $this->redirectWithNotifications(
                $this->context->link->getPageLink('order', true, null, ['step' => 3])
            );
        }

        // URLs - IMPORTANT: utiliser l'ID de commande PrestaShop
        $notify_url = $this->context->link->getModuleLink('chapchappay', 'validation', [], true);
        $return_url = $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart'   => $cart->id,
            'id_module' => $this->module->id,
            'key'       => $customer->secure_key
        ]);
        $cancel_url = $this->context->link->getPageLink('order', true, null, ['step' => 3]);

        // Payload conforme à ChapChapPay
        // Utiliser l'ID de commande PrestaShop comme référence
        $payload = [
            'amount'      => $amount,
            'order_id'    => 'PS-' . $order_id . '-TS' . time(), // Inclure l'ID PrestaShop
            'description' => 'Commande #' . $order_id . ' - ' . Configuration::get('PS_SHOP_NAME'),
            'cancel_url'  => $cancel_url,
            'return_url'  => $return_url,
            'callback_url' => $notify_url,
            'options'     => [
                'auto-redirect' => true,
            ]
        ];

        // Encoder en JSON propre
        $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Calculer la signature HMAC
        $hmac_signature = hash_hmac('sha256', $payload_json, $encryption_key);

        // Créer la transaction ChapChapPay via cURL (API call côté serveur)
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->module->api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload_json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'CCP-Api-Key: ' . $api_key,
                'CCP-HMAC-Signature: ' . $hmac_signature,
                'Content-Length: ' . strlen($payload_json)
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false, // a mettre sur true en prod
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Analyser la réponse
        if ($response && $httpCode == 201) {
            $result = json_decode($response, true);
            
            if (isset($result['payment_url']) && !empty($result['payment_url'])) {
                // Sauvegarder l'operation_id de ChapChapPay pour référence
                if (isset($result['operation_id'])) {
                    $this->module->saveOrderMetaData($order_id, 'chapchap_operation_id_temp', $result['operation_id']);
                }
                
                // Rediriger directement vers ChapChapPay
                Tools::redirect($result['payment_url']);
                exit;
            } else {
                // Si pas d'URL de paiement, mettre la commande en erreur
                if ($order_id) {
                    $order = new Order($order_id);
                    $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
                }
                $this->errors[] = $this->module->l('Erreur: Pas d\'URL de paiement reçue.');
            }
        } else {           
            // Si erreur API, mettre la commande en erreur
            if ($order_id) {
                $order = new Order($order_id);
                $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
            }
            $this->errors[] = $this->module->l('Erreur lors de la création du paiement. Veuillez réessayer.');
        }

        // Si on arrive ici, il y a eu une erreur
        return $this->redirectWithNotifications(
            $this->context->link->getPageLink('order', true, null, ['step' => 3])
        );
    }
}