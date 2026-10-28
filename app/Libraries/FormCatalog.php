<?php
namespace App\Libraries;

/** Shared choices keep the same wording across personal and agency forms. */
class FormCatalog
{
    public const ROLES=['Caregiver','Home care aide','Personal care assistant','Home health aide','Companion','Respite caregiver','Live-in caregiver','Disability support worker','Community support worker','Certified nursing assistant','Nursing assistant','Registered nurse','Licensed practical nurse','Care coordinator','Care supervisor'];
    public const SKILLS=['Personal care','Companionship','Mobility support','Bathing and grooming','Dressing assistance','Toileting assistance','Meal preparation','Feeding assistance','Light housekeeping','Laundry','Medication reminders','Dementia support','Alzheimer support','Disability support','Fall prevention','Safe transfers','Hoist assistance','First aid','CPR','Infection prevention','Care documentation','Communication','Client transportation','Shopping and errands','End-of-life support'];
    public const QUALIFICATIONS=['First aid','CPR','Basic life support (BLS)','Certified nursing assistant (CNA)','Home health aide (HHA)','Personal care aide training','Dementia care training','Medication assistance training','Infection prevention training','Safe patient handling training','Safeguarding training','Food safety training','Nursing diploma','Registered nurse license','Licensed practical nurse license','Disability support certificate'];
    public const LANGUAGES=['English','Spanish','Mandarin Chinese','Cantonese','Tagalog','Vietnamese','Korean','Japanese','Hindi','Punjabi','Urdu','Arabic','Persian','Russian','Ukrainian','French','Portuguese','German','Italian','Polish','Romanian','Greek','Turkish','Hebrew','Amharic','Somali','Swahili','Thai','Khmer','Lao','Hmong','Indonesian','Malay','Bengali','Tamil','Telugu','Nepali','Sinhala','Fijian','Samoan','Tongan','American Sign Language','British Sign Language'];
    public const CARE_SETTINGS=['Home care','Residential care home','Assisted living','Skilled nursing facility','Adult day care','Community support','Disability support residence','Hospice care','Respite care','Hospital support'];
    public const AVAILABILITY=['Weekday mornings','Weekday afternoons','Weekday evenings','Weekday nights','Weekend mornings','Weekend afternoons','Weekend evenings','Weekend nights','Live-in work','Flexible hours'];

    public static function choices(string $action,string $key):?array
    {
        if($key==='role' && in_array($action,['profile','career','contract'],true))return ['choice',self::ROLES];
        if($key==='skills')return ['tags',self::SKILLS];
        if($key==='skill')return ['choice',self::SKILLS];
        if($key==='qualifications')return ['tags',self::QUALIFICATIONS];
        if($key==='languages')return ['tags',self::LANGUAGES];
        if($key==='care_settings')return ['tags',self::CARE_SETTINGS];
        if($key==='availability')return ['tags',self::AVAILABILITY];
        if(($key==='name' && $action==='qualification') || $key==='required_qualification')return ['choice',self::QUALIFICATIONS];
        if($key==='travel_radius'){
            $choices=[];foreach(['miles','km'] as $unit)foreach([5,10,15,20,25,30,40,50,75,100,150,200] as $n)$choices[$n.' '.$unit]='Up to '.$n.' '.$unit;
            $choices['Flexible / discuss']='Flexible — discuss with the agency';return ['select',$choices];
        }
        if($key==='category' && $action==='expense')return ['choice',['Mileage','Public transportation','Parking','Meals during approved travel','Care supplies','Training','Other approved expense']];
        if($key==='country')return ['country',[]];
        if($key==='currency')return ['choice',['USD','CAD','GBP','EUR','AUD','NZD','FJD','INR','PHP','SGD','ZAR']];
        if($key==='timezone')return ['choice',\DateTimeZone::listIdentifiers()];
        return null;
    }

    public static function help(string $action,string $key,string $label):array
    {
        $specific=[
            'agency.name'=>['Use the legal business name that should appear on invoices and agency correspondence.','Home Care Agency Ltd'],
            'agency.official_email'=>['Use the official business email shown on invoices and correspondence. This does not change your personal sign-in email.','office@example.com'],
            'agency.business_phone'=>['Enter the main business phone using the selected country format, or include + and an international country code.','Use the country example shown below the field.'],
            'agency.contact_number'=>['Enter the business contact number for agency enquiries. Use an international country code if it belongs to another country.','Use the country example shown below the field.'],
            'agency.mobile_number'=>['Enter a mobile-capable contact number using the selected country format or an international country code.','Use the mobile example shown below the field.'],
            'agency.business_registration_number'=>['Enter the business registration identifier issued to your agency, if applicable. This field is optional.','BR-2026-001234'],
            'profile.role'=>['Your main caregiving job or the kind of role you want. Choose a common role or select Other to enter your own.','Caregiver or Personal care assistant'],
            'profile.summary'=>['A short introduction for recruiting agencies. Describe your relevant experience and the support you can offer. Do not include a client name, private health information or your home address.','I have five years of home care experience and enjoy helping older adults with daily routines.'],
            'profile.skills'=>['Select the tasks you can confidently perform. You can select several skills and add your own. Some tasks require separate training or authorization.','Personal care, Meal preparation, Mobility support'],
            'profile.qualifications'=>['Choose qualifications or training you hold. Add certificates with the issuer and expiry in the Qualifications section below. Selecting an item does not mark it as verified.','First aid and CPR'],
            'profile.care_settings'=>['A care setting is the type of place or service where you want to work. Choose all that suit you, or add another setting.','Home care means supporting someone in their own home. Assisted living means working in a supported residential community.'],
            'profile.availability'=>['Select the days or times you generally prefer. Add specific hours if useful. These are preferences; your contract and agreed schedule determine your work.','Weekend mornings; add Saturday 8 am to 12 pm'],
            'profile.regions'=>['Choose a country, then a state or province. You can narrow it to a county or district and a city. Add each area where you would like to work. Your exact home address is not requested.','United States → California → Sacramento County → Sacramento'],
            'qualification.name'=>['Choose the certificate, license or training course you hold. Select Other if its exact name is not listed.','First aid or Certified nursing assistant (CNA)'],
            'qualification.evidence_ref'=>['An optional reference to supporting evidence already held privately. Do not enter a public link containing identity or medical documents.','Certificate reference FA-2048'],
            'contract.pay_basis'=>['State the amount, currency and how pay is measured. This text becomes part of the contract version both parties review.','USD 25 per hour'],
            'contract.terms'=>['Enter the final employment terms and relevant policy references. A later material change creates a new version that both parties must accept again.','Part-time home support, agreed hours, duties, notice terms and required onboarding conditions.'],
            'schedule.required_qualification'=>['Optional: a qualification that must be current and accepted by your agency before this caregiver can be assigned. Leave blank if this visit has no extra qualification requirement.','First aid'],
            'history_request.days'=>['How many days the named recruiter needs access if the candidate approves. The supported period is 1 to 30 days.','7 days'],
            'profile.visibility'=>['Publish makes the preview visible to eligible verified recruiters. Pause removes it from future directory searches. Editing your profile makes it private until you publish again.','Publish when you are ready to be found; pause when you are not seeking opportunities.'],
            'expense.amount'=>['Enter the actual expense in the agency currency. Use numbers and a decimal point, without a currency symbol.','24.50'],
            'invoice.hourly_rate'=>['The client charge rate from the service agreement. This is separate from caregiver pay.','50.00 per service hour'],
        ];
        if(isset($specific[$action.'.'.$key]))return $specific[$action.'.'.$key];
        $common=[
            'role'=>['The job title or agency role for this record. Agency access roles determine which modules a person may use.','Caregiver for employment; Recruiter for agency access'],
            'work_type'=>['Choose the arrangement that best describes the work.','Part time for regular work with fewer hours than a full-time role'],
            'languages'=>['Choose the languages you can use with clients or coworkers. Add another language if it is not listed.','English and Spanish'],
            'travel_radius'=>['The approximate distance you are willing to travel around a preferred work area. This is a preference, not a promised commute time.','Up to 20 miles'],
            'region'=>['Choose the work area using country, state, county and city. CareSys stores the location reference for you.','Sacramento, California, United States'],
            'skill'=>['Choose a caregiving skill to narrow the candidate search. Select Other to search for an unlisted skill.','Personal care'],
            'entries'=>['Select only the specific career entry versions you permit this named recruiter to view. New or corrected entries are not automatically included.','Share the permitted role and dates from your previous home care employer.'],
            'regions'=>['Add the areas where you prefer to work using the location picker. You do not need to know any location codes.','Sacramento County and Yolo County in California'],
            'email'=>['Enter the email address used for this person’s CareSys account.','candidate@caresys.test in the local demo'],
            'password'=>['Enter your own account password. Never enter another person’s password. Your password is not shown to the agency.','Use the password you sign in with.'],
            'password_confirm'=>['Enter the same password again to check for typing mistakes.','Repeat your new account password.'],
            'username'=>['Choose a short account name that helps you recognize your login.','alex.chen'],
            'purpose'=>['Explain the work-related reason for the invitation, search or history request. The candidate sees the purpose of a sharing request.','Recruiting a weekend home caregiver for Sacramento'],
            'name'=>['Use a clear name so people can recognize this record. Do not use an internal code as the name.','A client’s authorized name, an equipment name or an agency’s legal name'],
            'employer'=>['The organization where you performed this work. External experience remains self-declared until confirmed.','Community Home Support'],
            'start_period'=>['Use a year or a year and month. Leave blank if you do not know. Do not guess a day.','2024 or 2024-06'],
            'end_period'=>['Use a year or year and month. Leave blank if the end date is unknown or the role is ongoing.','2025-09'],
            'summary'=>['Briefly describe the work, responsibilities or information relevant to this record.','Supported daily routines, companionship and safe mobility.'],
            'reason'=>['Explain why this change or review is needed. Be specific and factual.','A 30-minute break was missed in the original time record.'],
            'resolution'=>['Record the review outcome, what evidence was considered and what happens next.','Reviewed with the source employer; a corrected version is required.'],
            'conditions_note'=>['Record the checks your agency requires before employment starts and the evidence reviewed. This is not an automatic background-check result.','Identity confirmed, training evidence reviewed, induction completed.'],
            'basis'=>['Record the approved evidence, agreement or review basis for this administrative decision.','Manual agreement AGR-102, reviewed by the platform owner.'],
            'evidence'=>['Enter the private reference proving this review or transaction. Use a traceable reference, not a vague confirmation.','Review record QA-102 or bank receipt TXN-2048'],
            'evidence_ref'=>['An optional private reference to supporting evidence.','Certificate reference FA-2048'],
            'payment_evidence'=>['Use an actual payment or reconciliation reference. Approval alone does not prove an expense was paid.','Bank transfer reference TXN-2048'],
            'provider_ref'=>['The employee identifier used by your chosen payroll provider. Missing mappings block payroll export.','EMP-0042'],
            'employment_ref'=>['Choose the employee or your own employment episode for this agency.','Jamie Rivera at Willow Care Agency'],
            'application_ref'=>['Choose the candidate application this offer belongs to.','Alex Chen’s weekend caregiver application'],
            'client_ref'=>['Choose the client receiving the service in this agency.','Pat Morgan'],
            'work_area'=>['Describe where the person will work. Avoid a client’s private details in general contract descriptions.','Sacramento County, California'],
            'hours_terms'=>['State the hours or availability being agreed in the contract.','20 hours per week, with agreed weekend shifts'],
            'issued_on'=>['The date printed on the certificate or license as its issue date.','June 1, 2025'],
            'expires_on'=>['The expiry date printed on the credential. Leave blank if it has no expiry.','June 1, 2027'],
            'issuer'=>['The organization that issued the qualification or training certificate.','The training provider named on your certificate'],
            'title'=>['Use the job title candidates should see.','Weekend Home Caregiver'],
            'requirements'=>['List the experience or current credentials required for this role.','Home care experience and current First aid training'],
            'closing_date'=>['The final date candidates may apply for the vacancy.','November 30, 2026'],
            'start_date'=>['The agreed employment start date in the agency’s local timezone. A future date keeps activation pending.','November 2, 2026'],
            'expiry_date'=>['The last date this offer may be accepted.','November 15, 2026'],
            'end_date'=>['The actual last day of this employment. It cannot precede the start date or be in the future.','October 31, 2026'],
            'starts_at'=>['Choose the starting date and time in the agency timezone shown at the top of the page.','November 2, 2026 at 9:00 am'],
            'ends_at'=>['Choose the ending date and time. It must be after the start; overnight work can end the next day.','November 2, 2026 at 1:00 pm'],
            'period_start'=>['The first date of the UTC pay period to include in this export.','November 1, 2026'],
            'period_end'=>['The last date of the UTC pay period. It must be on or after the first date.','November 15, 2026'],
            'incurred_on'=>['The date you paid or incurred this work expense.','October 23, 2026'],
            'valid_until'=>['The last date the approved module agreement remains valid.','December 31, 2026'],
            'due_date'=>['The date the client’s invoice payment is due under the agreement.','November 30, 2026'],
            'minutes'=>['Choose hours or minutes and enter the duration. CareSys converts it for you. For a balance adjustment, a negative amount subtracts time.','8 hours, or 480 minutes'],
            'proposed_seconds'=>['Choose hours or minutes for the corrected total time worked, excluding actual breaks. The original clock events are preserved.','2 hours of work, excluding a 30-minute break'],
            'type'=>['Choose the action or leave type that matches what happened.','Clock in starts work; Start break pauses worked time.'],
            'state'=>['Choose the next valid review or workflow status. Your permission and the current state determine which actions may complete.','Approve after review, or return a record for correction.'],
            'stage'=>['Choose the candidate’s next recruitment stage. This does not create an employment relationship.','Interview or Checks pending'],
            'decision'=>['Choose your response after reviewing the record. Approval only applies to the specific action shown.','Approve the requested record, decline it, or withdraw future permission.'],
            'confirm'=>['Read the statement and check it only if you explicitly agree. This confirmation is specific to this record and action.','Confirm the exact contract version you have reviewed.'],
            'tag'=>['Use your agency’s unique equipment label. The same tag cannot be registered twice in the agency.','KIT-001'],
            'condition_note'=>['Describe the equipment condition at handover or return.','Good condition, charger included, no visible damage.'],
            'maintenance'=>['Select this if returned equipment needs repair or inspection before it is used again.','A damaged charger needs replacement.'],
            'category'=>['Choose the kind of work expense, or add a specific approved category.','Mileage or Care supplies'],
            'description'=>['Explain the work-related purpose and include a private receipt reference if available.','Parking during an approved client visit; receipt R-102.'],
            'amount'=>['Enter the amount in the agency currency without a currency symbol.','24.50'],
            'hourly_rate'=>['Enter the agreed client charge for one service hour. This is separate from employee pay.','50.00'],
            'invoice_no'=>['Use a unique invoice number from your agency’s numbering sequence.','INV-2026-001'],
            'body'=>['Record factual observations, support provided and actions taken. For emergencies follow the agency emergency procedure.','Assisted with the agreed morning routine; reported a mobility concern to the supervisor.'],
            'kind'=>['Choose a routine care note, an incident needing review, or an amendment to clarify a previous record.','Care note for routine support; Incident for an unexpected safety event'],
            'severity'=>['Choose the level that matches the agency’s incident policy. Notifications are not emergency response.','Routine for normal notes; High for a serious safety concern'],
            'location'=>['The authorized location where care will be delivered. This remains an agency-private client record.','The client’s approved service address'],
            'contact'=>['The contact the agency is authorized to use for this client.','Authorized representative and approved contact number'],
            'instructions'=>['Enter the approved care instructions needed for safe service delivery.','Follow the current care plan; use the prescribed mobility aid.'],
            'service'=>['A short name for the service being scheduled.','Home support or Respite care'],
            'modules'=>['Use the module names shown in agency settings. Manual agreements need an approved commercial basis.','people, scheduling, care, time'],
            'country'=>['Select the country where the agency or preferred work area is located.','United States'],
            'currency'=>['The currency used for this agency’s financial records.','USD for United States dollars'],
            'timezone'=>['Choose the timezone used for agency schedules and employment start dates.','America/Los_Angeles for California'],
            'verification'=>['Record the agency review outcome using approved evidence. Payment does not establish verification.','Verified after the documented agency evidence review'],
        ];
        return $common[$key]??['Enter the information described by this field for the current record. Choose a listed option when one is available.','Use a clear, factual value relevant to '.mb_strtolower($label).'.'];
    }
}
