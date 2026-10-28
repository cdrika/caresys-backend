<?php
$safe=$safe??[];$enabled=($safe['include_address']??'')==='yes';$addressCountry=is_string($safe['country']??null)?$safe['country']:'US';
try{$schema=\App\Libraries\AgencyAddress::schema($addressCountry);}catch(\App\Services\DomainError){$schema=\App\Libraries\AgencyAddress::schema('US');}
?>
<div class="wide agency-address-section">
<label class="check"><input type="checkbox" name="include_address" value="yes" data-address-opt-in aria-controls="agency-address-fields" aria-expanded="<?= $enabled?'true':'false' ?>"<?= $enabled?' checked':'' ?>> Enter agency address (optional)</label>
<p class="field-hint">Add an address only if you want it included on invoices and correspondence.</p>
<fieldset id="agency-address-fields" data-agency-address<?= $enabled?'':' hidden disabled' ?>><legend>Agency address</legend>
<p class="field-hint">Fields marked * are required only when you choose to enter an address.</p>
<div class="form-grid">
<?php foreach($schema['fields'] as $key=>$field): ?><div class="field" data-address-field="<?= esc($key,'attr') ?>"<?= $field['used']?'':' hidden' ?>>
<label for="agency-<?= esc($key,'attr') ?>"><span data-address-label><?= esc($field['label']) ?></span><span class="required-mark" data-address-required<?= $field['required']?'':' hidden' ?>> *</span></label>
<?php if($key==='address_state'&&$schema['states']): ?><select id="agency-<?= esc($key,'attr') ?>" name="<?= esc($key,'attr') ?>"<?= $field['required']?' required':'' ?>><option value="">Choose an option…</option><?php foreach($schema['states'] as $code=>$label): ?><option value="<?= esc($code,'attr') ?>"<?= ($safe[$key]??'')===(string)$code?' selected':'' ?>><?= esc($label) ?></option><?php endforeach ?></select>
<?php else: ?><input id="agency-<?= esc($key,'attr') ?>" name="<?= esc($key,'attr') ?>" value="<?= esc((string)($safe[$key]??''),'attr') ?>" maxlength="<?= str_starts_with($key,'address_line')?255:($key==='address_postal_code'?32:120) ?>"<?= $field['required']?' required':'' ?><?= $field['used']?'':' disabled' ?>><?php endif ?>
</div><?php endforeach ?></div></fieldset>
<p class="field-hint" data-address-status role="status"></p>
</div>
<script type="application/json" id="agency-address-schema"><?= json_encode($schema,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
