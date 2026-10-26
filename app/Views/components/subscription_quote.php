<?php
use App\Libraries\SubscriptionQuote;
use App\Libraries\SubscriptionPricing;
$saved=(array)session('safe_input');
$retry=($saved['return_page']??'')==='settings' && array_key_exists('service_level',$saved);
$selected=$retry?($saved['modules']??[]):(json_decode($agency['modules']??'[]',true)??[]);
if(!is_array($selected))$selected=[];
$level=$retry && is_string($saved['service_level']??null)?$saved['service_level']:'custom';
?>
<form method="post" action="<?= esc($base.'/actions/subscription_request','attr') ?>" data-workflow data-inline-help data-form-action="subscription_request" class="form-grid subscription-quote">
<?= csrf_field() ?>
<input type="hidden" name="workspace_nonce" value="<?= esc($nonce,'attr') ?>">
<input type="hidden" name="return_page" value="settings">
<input type="hidden" name="request_key" value="<?= esc(preg_match('/^[a-f0-9]{32}$/D',(string)($saved['request_key']??''))?$saved['request_key']:bin2hex(random_bytes(16)),'attr') ?>">
<fieldset class="quote-modules wide"><legend>Which modules would you like a quote for?</legend><p id="quote-module-help" class="sub">Select one or more options. Your current modules are selected to get you started.</p><div class="quote-module-options">
<?php foreach(SubscriptionQuote::MODULES as $key=>$label): ?><div class="quote-module-row"><label class="quote-module-choice" for="quote-module-<?= esc($key,'attr') ?>"><input id="quote-module-<?= esc($key,'attr') ?>" type="checkbox" name="modules[]" value="<?= esc($key,'attr') ?>" aria-describedby="quote-description-<?= esc($key,'attr') ?>" <?= in_array($key,$selected,true)?'checked':'' ?>><span><?= esc($label) ?></span></label><p class="quote-module-description" id="quote-description-<?= esc($key,'attr') ?>"><?= esc(SubscriptionQuote::DESCRIPTIONS[$key]) ?></p></div><?php endforeach ?>
</div></fieldset>
<div class="field"><label for="quote-service-level">Preferred package / service level</label><select id="quote-service-level" name="service_level" required><?php foreach(SubscriptionQuote::SERVICE_LEVELS as $key=>$label): ?><option value="<?= esc($key,'attr') ?>" <?= $level===$key?'selected':'' ?>><?= esc($label) ?></option><?php endforeach ?></select><small class="sub">Choose a starting point, or let us recommend a package for your selections.</small></div>
<div class="field"><label for="quote-workers">Active workers to cover</label><select id="quote-workers" name="worker_count" required><?php $workers=(int)($saved['worker_count']??max(10,$active_worker_count??0)); for($i=1;$i<=1000;$i++): ?><option value="<?= $i ?>" <?= $i===$workers?'selected':'' ?>><?= $i ?></option><?php endfor ?></select><small class="sub">Include all current active workers and any planned additional workers.</small></div>
<p class="sub wide">A monthly pro forma invoice is issued immediately. We compare custom pricing and packages with add-ons, include required dependencies, and choose the lowest total. Your package preference breaks ties. Custom starts at USD 29/month with 10 workers; additional workers cost USD 3/month each. Tax and payment terms require confirmation. Your access does not change when you submit.</p>
<p class="wide" id="subscription-estimate" role="status" aria-live="polite"></p>
<script type="application/json" id="subscription-price-catalog"><?= json_encode(SubscriptionPricing::catalog(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="/assets/subscription-pricing.js" defer></script>
<div class="wide"><button type="submit">Request subscription quote</button></div>
</form>
<?php if(!empty($subscription_invoices)): ?>
<section aria-label="Subscription invoices"><h3>Subscription invoices</h3><p class="sub">Pro forma proposals; no payment collected. Prices are fixed on each saved invoice for its validity period.</p>
<?php foreach($subscription_invoices as $invoice): ?><p><strong><?= esc($invoice['invoice_no']) ?></strong> · <?= esc($invoice['created_at']) ?> · USD <?= number_format($invoice['total_cents']/100,2) ?>/month · Pro forma — <a href="<?= esc($base.'/subscription-invoices/'.$invoice['ref'].'/download','attr') ?>">Download PDF</a></p><?php endforeach ?>
</section>
<?php endif ?>
