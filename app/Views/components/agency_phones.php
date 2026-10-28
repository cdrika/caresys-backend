<?php
$safe=$safe??[];$chosen=static fn($key)=>($safe[$key]??'')==='yes';
$business=$chosen('include_business_phone');$sameMobile=$business&&$chosen('business_is_mobile');$sameContact=$business&&$chosen('business_is_contact');
$phoneInput=static function(string $name,string $label)use($safe){$value=is_scalar($safe[$name]??'')?(string)($safe[$name]??''):''; ?>
<div class="field"><label for="agency-<?= esc($name,'attr') ?>"><?= esc($label) ?><span class="required-mark" aria-hidden="true"> *</span></label><input type="tel" id="agency-<?= esc($name,'attr') ?>" name="<?= esc($name,'attr') ?>" value="<?= esc($value,'attr') ?>" maxlength="80" required></div>
<?php }; ?>
<div class="wide agency-phone-section">
<h3>Phone numbers <span class="optional-label">Optional</span></h3>
<p class="field-hint">Add the numbers you want shown on invoices and correspondence. One business number can serve several purposes.</p>
<label class="check"><input type="checkbox" name="include_business_phone" value="yes" data-phone-choice aria-controls="agency-business-phone"<?= $business?' checked':'' ?>> Add a business number</label>
<fieldset id="agency-business-phone" data-phone-panel="business"<?= $business?'':' hidden disabled' ?>><legend>Business number</legend>
<?php $phoneInput('business_phone','Business number'); ?>
<label class="check"><input type="checkbox" name="business_is_mobile" value="yes" data-phone-choice<?= $sameMobile?' checked':'' ?>> This is also my mobile number</label>
<label class="check"><input type="checkbox" name="business_is_contact" value="yes" data-phone-choice<?= $sameContact?' checked':'' ?>> This is also my contact number</label>
</fieldset>
<div data-phone-separate="mobile"<?= $sameMobile?' hidden':'' ?>>
<label class="check"><input type="checkbox" name="include_mobile_number" value="yes" data-phone-choice aria-controls="agency-mobile-phone"<?= $chosen('include_mobile_number')?' checked':'' ?>><span data-phone-add-label="mobile">Add a mobile number</span></label>
<fieldset id="agency-mobile-phone" data-phone-panel="mobile"<?= !$sameMobile&&$chosen('include_mobile_number')?'':' hidden disabled' ?>><legend>Mobile number</legend><?php $phoneInput('mobile_number','Mobile number'); ?></fieldset></div>
<div data-phone-separate="contact"<?= $sameContact?' hidden':'' ?>>
<label class="check"><input type="checkbox" name="include_contact_number" value="yes" data-phone-choice aria-controls="agency-contact-phone"<?= $chosen('include_contact_number')?' checked':'' ?>><span data-phone-add-label="contact">Add a contact number</span></label>
<fieldset id="agency-contact-phone" data-phone-panel="contact"<?= !$sameContact&&$chosen('include_contact_number')?'':' hidden disabled' ?>><legend>Contact number</legend><?php $phoneInput('contact_number','Contact number'); ?></fieldset></div>
<p class="field-hint" data-phone-summary role="status"></p>
</div>
