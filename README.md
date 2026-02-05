ChapChapPay PrestaShop Module

1. Installation

Copier le dossier chapchappay dans modules/ de votre PrestaShop.

Connectez-vous à l’admin PrestaShop → Modules → Module Manager.

Recherchez ChapChapPay et cliquez sur Installer.

Configurez le module avec votre clé API et votre HMAC secret.

2. Configuration

Clé API : obtenue depuis votre compte ChapChapPay.

Encryption Password / HMAC secret : utilisé pour signer et vérifier les webhooks.

Statuts PrestaShop :

PS_OS_PAYMENT → paiement réussi

CHAPCHAPPAY_OS_PENDING → paiement en attente

PS_OS_ERROR → paiement échoué

PS_OS_CANCELED → paiement annulé

3. Flux de paiement

Le client clique sur Payer via ChapChapPay.

Le module crée une opération.

Le client est redirigé vers ChapChapPay pour choisir le moyen de paiement.

Une fois le paiement traité, ChapChapPay envoie un webhook vers validation.php.

PrestaShop met à jour la commande selon le statut (success, pending, failed, canceled).

4. Format des IDs

Pour garantir la correspondance entre les commandes ChapChapPay et PrestaShop :

ID de commande ChapChapPay (order_id) :
Format recommandé : PS-{id_cart}-{timestamp}

id_cart : identifiant du panier PrestaShop

timestamp : date/heure en format Unix ou YYYYMMDDHHMMSS

Exemple :

PS-1234-20251211103000


ID de produit / référence produit : doit correspondre à ce qui est défini dans PrestaShop (reference ou id_product).

Le module cherchera la commande en base via :

order_payment.transaction_id = order_id ChapChapPay

Si non trouvé → extraction du id_cart depuis le order_id ChapChapPay

5. Prise en charge des moyens de paiement

PayCard

Orange Money

MTN Mobile Money

Visa & MasterCard
