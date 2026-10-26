<?php
use App\Libraries\SubscriptionQuote;
$saved=(array)session('safe_input');
$retry=($saved['return_page']??'')==='settings' && array_key_exists('service_level',$saved);
$selected=$retry?($saved['modules']??[]):(json_decode($agency['modules']??'[]',true)??[]);
if(!is_array($selected))$selected=[];
$level=$retry && is_string($saved['service_level']??null)?$saved['service_level']:'custom';
?>
<form method="post" action="<?= esc($base.'/actions/subscription_request','attr') ?>" data-workflow data-form-action="subscription_request" class="form-grid subscription-quote">
<?= csrf_field() ?>
<input type="hidden" name="workspace_nonce" value="<?= esc($nonce,'attr') ?>">
<input type="hidden" name="return_page" value="settings">
<fieldset class="quote-modules wide"><legend>Which modules would you like a quote for?</legend><p id="quote-module-help" class="sub">Select one or more options. Your current modules are selected to get you started.</p><div class="quote-module-options">
<?php foreach(SubscriptionQuote::MODULES as $key=>$label): ?><label class="check quote-module-option"><input type="checkbox" name="modules[]" value="<?= esc($key,'attr') ?>" aria-describedby="quote-module-help" <?= in_array($key,$selected,true)?'checked':'' ?>><span><?= esc($label) ?></span></label><?php endforeach ?>
</div></fieldset>
<div class="field"><label for="quote-service-level">Preferred package / service level</label><select id="quote-service-level" name="service_level" required><?php foreach(SubscriptionQuote::SERVICE_LEVELS as $key=>$label): ?><option value="<?= esc($key,'attr') ?>" <?= $level===$key?'selected':'' ?>><?= esc($label) ?></option><?php endforeach ?></select><small class="sub">Choose a starting point, or let us recommend a package for your selections.</small></div>
<p class="sub wide">This requests a quote only. Module availability, dependencies, prices and service terms will be confirmed before an agreement. Your access does not change when you submit.</p>
<div class="wide"><button type="submit">Request subscription quote</button></div>
</form>
