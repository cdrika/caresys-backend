<?php
namespace Config;

use CodeIgniter\Config\BaseConfig;

class Mobile extends BaseConfig
{
    public string $webBaseURL='';
    public string $apiBaseURL='';

    public function testDeviceUrl(): string
    {
        $base=(string)(getenv('CARESYS_API_URL') ?: $this->apiBaseURL);
        return str_starts_with($base,'https://') ? rtrim($base,'/').'/mobile/v1/pairing/test-device' : '';
    }

    public function pairingUrl(): string
    {
        $base=(string)(getenv('CARESYS_WEB_URL') ?: ($this->webBaseURL ?: config('App')->baseURL));
        return rtrim($base,'/').'/mobile-enrollment';
    }
}
