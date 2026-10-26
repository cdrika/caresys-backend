<?php
use App\Libraries\SubscriptionQuote;
use App\Services\DomainError;
use CodeIgniter\Test\CIUnitTestCase;

final class SubscriptionQuoteTest extends CIUnitTestCase
{
    public function testSelectedOptionsAreNormalized(): void
    {
        $this->assertSame(['modules'=>['people','scheduling'],'service_level'=>'operations'],SubscriptionQuote::selection(['modules'=>['scheduling','people','people'],'service_level'=>'operations']));
    }

    public function testUnlistedAndFreeTextOptionsAreRejected(): void
    {
        foreach ([['modules'=>[],'service_level'=>'custom'],['modules'=>'people, scheduling','service_level'=>'custom'],['modules'=>['unknown'],'service_level'=>'custom'],['modules'=>[['people']],'service_level'=>'custom'],['modules'=>['people'],'service_level'=>'unlimited'],['modules'=>['people'],'service_level'=>['starter']]] as $input) {
            try { SubscriptionQuote::selection($input); $this->fail('Invalid quote selection was accepted.'); }
            catch (DomainError $error) { $this->assertSame(422,$error->status); }
        }
    }
}
