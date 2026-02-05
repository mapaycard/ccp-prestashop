<div class="chapchap-payment-info" style="padding:10px; border:1px solid #ddd; border-radius:4px; background:#f9f9f9;">
    <p style="font-size: 12px; color: #666; margin-top: 5px;">
        {l s='Redirection vers Chap Chap Pay en cours...' mod='chapchappay'}
    </p>
    
    <div style="text-align:center; padding:20px;">
        <p>{l s='Patientez pendant que nous préparons votre paiement...' mod='chapchappay'}</p>
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">{l s='Chargement...' mod='chapchappay'}</span>
        </div>
    </div>
</div>

<script>
// Redirection automatique après un court délai (fallback)
setTimeout(function() {
    window.location.href = '{$link->getPageLink("order", true, NULL, "step=3")}';
}, 5000);
</script>