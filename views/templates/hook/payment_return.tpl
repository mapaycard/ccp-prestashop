{**
 * Page de retour après paiement ChapChapPay
 * Mis à jour pour gérer success et pending
 *}

{if $status == 'ok'}
    <div class="alert alert-success">
        <p>{l s='Votre commande a été payée avec succès via Chap Chap Pay.' mod='chapchappay'}</p>
        <p>
            {l s='Montant payé:' mod='chapchappay'} <strong>{$total}</strong><br>
            {l s='Référence de commande:' mod='chapchappay'} <strong>{$order->reference}</strong><br>
            {l s='Moyen de paiement:' mod='chapchappay'} <strong>{$payment_method|escape:'html':'UTF-8'}</strong>
        </p>
    </div>
{elseif $status == 'pending'}
    <div class="alert alert-info">
        <p>{l s='Votre paiement via Chap Chap Pay est en attente de confirmation.' mod='chapchappay'}</p>
        <p>{l s='Vous recevrez un email une fois le paiement validé.' mod='chapchappay'}</p>
    </div>
{else}
    <div class="alert alert-warning">
        <p>{l s='Nous avons reçu votre commande mais le paiement n\'a pas été confirmé.' mod='chapchappay'}</p>
        <p>{l s='Merci de vérifier le statut de votre paiement ou de contacter le support.' mod='chapchappay'}</p>
    </div>
{/if}
