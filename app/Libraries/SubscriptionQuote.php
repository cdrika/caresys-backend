<?php
namespace App\Libraries;

use App\Services\DomainError;

/** Quote preferences are requests only; they never change agency entitlements. */
final class SubscriptionQuote
{
    public const MODULES = [
        'recruitment'=>'Recruitment & applications', 'history'=>'Consented career history',
        'employment'=>'Offers & joining', 'people'=>'People administration',
        'scheduling'=>'Scheduling & visits', 'time'=>'Time & attendance', 'leave'=>'Leave management',
        'care'=>'Care notes & incidents', 'assets'=>'Equipment & custody', 'expenses'=>'Expenses',
        'payroll'=>'Payroll preparation & exports', 'finance'=>'Invoices & receipts', 'reporting'=>'Reporting',
    ];

    public const DESCRIPTIONS = [
        'recruitment'=>'Find candidates, publish vacancies, review applications and send invitations.',
        'history'=>'Request selected career history with the caregiver’s consent, expiry and withdrawal controls.',
        'employment'=>'Prepare and review offer versions, record joining decisions and manage employment activation.',
        'people'=>'Manage agency team records, employment relationships and administrative roles.',
        'scheduling'=>'Plan client visits and assign caregivers with overlap, leave and qualification checks.',
        'time'=>'Record online clock events and breaks, review worked time and handle correction requests.',
        'leave'=>'Submit and review leave requests and maintain leave balances.',
        'care'=>'Keep visit-related care notes, incident records and acknowledgements in the agency workspace.',
        'assets'=>'Register equipment and track who has it, returns and custody history.',
        'expenses'=>'Submit and review expenses and record reimbursement evidence.',
        'payroll'=>'Prepare approved worked-time export snapshots for payroll; external payroll submission is not included.',
        'finance'=>'Maintain invoices, receipt records and reviewed credits for agency billing.',
        'reporting'=>'Review available agency summaries and reports within your role and module permissions.',
    ];

    public const SERVICE_LEVELS = [
        'starter'=>'Starter — recruitment & administration',
        'operations'=>'Operations — day-to-day agency workflows',
        'growth'=>'Growth — operations & finance preparation',
        'custom'=>'Tailored quote / help me choose',
    ];

    public static function selection(array $data): array
    {
        $modules=$data['modules']??[];
        if (!is_array($modules) || !array_is_list($modules) || !$modules || count($modules)>count(self::MODULES)) {
            throw new DomainError('Select at least one module from the available options.');
        }
        foreach ($modules as $module) {
            if (!is_string($module) || !array_key_exists($module,self::MODULES)) throw new DomainError('Select only the listed modules.');
        }
        $level=$data['service_level']??null;
        if (!is_string($level) || !array_key_exists($level,self::SERVICE_LEVELS)) throw new DomainError('Choose a preferred package or select help me choose.');
        return ['modules'=>array_values(array_intersect(array_keys(self::MODULES),$modules)),'service_level'=>$level];
    }
}
