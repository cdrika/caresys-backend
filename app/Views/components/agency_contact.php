<?php
$contact=$contact??[];if(is_string($contact))$contact=json_decode($contact,true)??[];
if(!$contact)return;
?>
<address class="agency-correspondence" style="font-style:normal;margin:12px 0">
<strong><?= esc($contact['name']??'') ?></strong>
<?php foreach(\App\Libraries\AgencyAddress::lines($contact) as $addressLine): ?><div><?= esc($addressLine) ?></div><?php endforeach ?>
<?php foreach(['official_email'=>'Official email','business_phone'=>'Phone','contact_number'=>'Contact','mobile_number'=>'Mobile','business_registration_number'=>'Business registration'] as $key=>$label):$value=$contact[$key]??'';if($value==='')continue;if(in_array($key,['business_phone','contact_number','mobile_number'],true))$value=\App\Libraries\AgencyContact::display($value); ?>
<div><?= esc($label) ?>: <?= esc($value) ?></div>
<?php endforeach ?></address>
