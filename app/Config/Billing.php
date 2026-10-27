<?php
namespace Config;
use CodeIgniter\Config\BaseConfig;

class Billing extends BaseConfig
{
    public string $stripeSecretKey='';
    public string $stripeWebhookSecret='';
    public string $webBaseURL='https://localhost:8444';
    public bool $automaticTax=false;
}
