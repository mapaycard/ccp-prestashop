{**
 * Confirmation de commande ChapChapPay
 * Mis à jour pour supporter les statuts success et pending
 *}

{if $order->getCurrentState() == $PS_OS_PAYMENT}
    <div class="alert alert-success">
        <p>{l s='Merci pour votre commande !' mod='chapchappay'}</p>
        <p>
            {l s='Votre paiement via Chap Chap Pay a été traité avec succès.' mod='chapchappay'}<br>
            {l s='Référence de commande :' mod='chapchappay'} <strong>{$order->reference}</strong><br>
            {l s='Montant payé :' mod='chapchappay'} <strong>{$total}</strong>
        </p>
    </div>
{elseif $order->getCurrentState() == $CHAPCHAPPAY_OS_PENDING}
    <div class="alert alert-info">
        <p>{l s='Votre commande a été reçue mais le paiement est en attente.' mod='chapchappay'}</p>
        <p>{l s='Vous recevrez un email de confirmation une fois votre paiement vérifié.' mod='chapchappay'}</p>
    </div>
{else}
    <div class="alert alert-warning">
        <p>{l s='Votre commande a été reçue mais le paiement a rencontré un problème.' mod='chapchappay'}</p>
        <p>{l s='Merci de vérifier le statut de votre paiement ou de contacter le support.' mod='chapchappay'}</p>
    </div>
{/if}
