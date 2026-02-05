<?php
class ChapChapPayValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;

    public function postProcess()
    {
        // Log de début pour debug
        error_log('ChapChapPay Validation: Starting callback processing');
        
        // Lire les headers
        $headers = getallheaders();
        
        $api_key_header = isset($headers['CCP-Api-Key']) ? $headers['CCP-Api-Key'] : 
                         (isset($headers['ccp-api-key']) ? $headers['ccp-api-key'] : '');
        
        $hmac_header = isset($headers['CCP-HMAC-Signature']) ? $headers['CCP-HMAC-Signature'] : 
                      (isset($headers['ccp-hmac-signature']) ? $headers['ccp-hmac-signature'] : '');
        
        // Lire le payload
        $payload = file_get_contents('php://input');
        
        error_log('ChapChapPay Validation: Raw payload: ' . $payload);
        error_log('ChapChapPay Validation: HMAC received: ' . $hmac_header);
        
        // Vérifier la signature HMAC
        $encryption_key = Configuration::get('CHAPCHAPPAY_ENCRYPTION_KEY');
        if (empty($encryption_key)) {
            error_log('ChapChapPay Validation: Encryption key is empty');
            http_response_code(500);
            die('Encryption key not configured');
        }
        
        $calculated_hmac = hash_hmac('sha256', $payload, $encryption_key);
        
        error_log('ChapChapPay Validation: Calculated HMAC: ' . $calculated_hmac);
        
        if (!hash_equals($calculated_hmac, $hmac_header)) {
            http_response_code(401);
            error_log('ChapChapPay: Invalid HMAC signature');
            error_log('ChapChapPay: Expected ' . $calculated_hmac . ', got ' . $hmac_header);
            die('Invalid signature');
        }
        
        // Vérifier l'API Key
        $api_key = Configuration::get('CHAPCHAPPAY_API_KEY');
        if ($api_key_header !== $api_key) {
            http_response_code(401);
            error_log('ChapChapPay: Invalid API key');
            error_log('ChapChapPay: Expected ' . $api_key . ', got ' . $api_key_header);
            die('Invalid API key');
        }
        
        // Parser le JSON
        $data = json_decode($payload, true);
        if (!$data) {
            http_response_code(400);
            error_log('ChapChapPay: Invalid JSON');
            die('Invalid JSON');
        }
        
        // Traiter le callback
        $this->processCallback($data);
    }
    
    private function processCallback($data)
    {
        // Log pour debug
        error_log('ChapChapPay Callback data: ' . print_r($data, true));
        
        // Récupérer l'ID de commande du payload ChapChapPay
        $order_id = null;
        $operation_id = $data['operation_id'] ?? '';
        $status = $data['status']['code'] ?? '';
        $amount = floatval($data['amount'] ?? 0);
        
        // IMPORTANT: Extraire l'ID PrestaShop du order_id ChapChapPay
        // Format attendu: "PS-{order_id}-{timestamp}"
        if (isset($data['order_id']) && preg_match('/^PS-(\d+)-TS\d+$/', $data['order_id'], $matches)) {
            $order_id = (int)$matches[1];
            error_log('ChapChapPay: Extracted order ID: ' . $order_id);
        } else {
            error_log('ChapChapPay: Could not extract order ID from: ' . ($data['order_id'] ?? 'null'));
            
            // Fallback: Chercher par operation_id sauvegardé
            if (!empty($operation_id)) {
                $order_id = $this->findOrderByOperationId($operation_id);
                if ($order_id) {
                    error_log('ChapChapPay: Found order by operation_id: ' . $order_id);
                }
            }
        }
        
        if (!$order_id) {
            http_response_code(400);
            error_log('ChapChapPay: Order not found for data: ' . print_r($data, true));
            die('Order not found');
        }
        
        $order = new Order($order_id);
        if (!Validate::isLoadedObject($order)) {
            http_response_code(400);
            error_log('ChapChapPay: Order object not loaded for ID: ' . $order_id);
            die('Order not valid');
        }
        
        $payment_method = $data['transaction']['payment_method'] ?? '';
        
        // Vérifier si la commande est déjà payée
        $current_state = $order->getCurrentState();
        if ($current_state == Configuration::get('PS_OS_PAYMENT')) {
            error_log('ChapChapPay: Order already paid, skipping update');
            http_response_code(200);
            echo json_encode(['status' => 'already_processed']);
            exit;
        }
        
        error_log('ChapChapPay: Current order state: ' . $current_state);
        error_log('ChapChapPay: New status from ChapChapPay: ' . $status);
        
        // Traiter selon le statut
        switch ($status) {
            case 'success':
                // Vérifier le montant (tolérance de 0.01 pour les arrondis)
                $order_total = (float)$order->getOrdersTotalPaid();
                $difference = abs($order_total - $amount);
                
                error_log("ChapChapPay: Amount check - Order: $order_total, ChapChap: $amount, Diff: $difference");
                
                if ($difference < 1) { // Tolérance de 1 GNF
                    // Changer le statut à PAYMENT
                    $order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
                    
                    // Ajouter le paiement (important pour la comptabilité)
                    if (!OrderPayment::getByOrderId($order_id)) {
                        $order_payment = new OrderPayment();
                        $order_payment->order_reference = $order->reference;
                        $order_payment->id_currency = $order->id_currency;
                        $order_payment->amount = $amount;
                        $order_payment->payment_method = $this->module->getPaymentMethodText($payment_method);
                        $order_payment->transaction_id = $operation_id;
                        $order_payment->date_add = date('Y-m-d H:i:s');
                        $order_payment->add();
                    }
                    
                    // Sauvegarder les métadonnées
                    $this->module->saveOrderMetaData($order_id, 'chapchap_operation_id', $operation_id);
                    $this->module->saveOrderMetaData($order_id, 'chapchap_payment_method', $payment_method);
                    
                    error_log('ChapChapPay: Order marked as PAID - ID: ' . $order_id);
                } else {
                    $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
                    error_log('ChapChapPay: Amount mismatch for order ' . $order_id . 
                              ' (Order: ' . $order_total . ' vs ChapChap: ' . $amount . ')');
                }
                break;
                
            case 'pending':
                // Déjà en pending, pas de changement nécessaire
                error_log('ChapChapPay: Order remains in PENDING - ID: ' . $order_id);
                break;
                
            case 'failed':
            case 'error':
                $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
                error_log('ChapChapPay: Order marked as ERROR - ID: ' . $order_id);
                break;
                
            case 'canceled':
            case 'expired':
                $order->setCurrentState(Configuration::get('PS_OS_CANCELED'));
                error_log('ChapChapPay: Order marked as CANCELED - ID: ' . $order_id);
                break;
                
            default:
                error_log('ChapChapPay: Unknown status: ' . $status);
                break;
        }
        
        // Répondre à ChapChapPay
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'processed']);
        exit;
    }
    
    private function findOrderByOperationId($operation_id)
    {
        $sql = 'SELECT id_order FROM `'._DB_PREFIX_.'order_meta`
                WHERE (meta_key = "chapchap_operation_id_temp" OR meta_key = "chapchap_operation_id")
                AND value = "' . pSQL($operation_id) . '"';
        
        $result = Db::getInstance()->getValue($sql);
        error_log('ChapChapPay: findOrderByOperationId query: ' . $sql . ' - Result: ' . ($result ?: 'null'));
        
        return $result;
    }
}