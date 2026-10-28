<?php
namespace App\Services;

class OperationsService
{
    public function __construct(public Workspace $w) {}

    public function client(array $d): array
    {
        $this->w->require('care');
        $r=$this->w->insert('clients',['agency_id'=>$this->w->agency['id'],'name'=>Workspace::text($d,'name',160),'location'=>Workspace::text($d,'location',240),'contact'=>Workspace::text($d,'contact',160,false),'instructions'=>Workspace::text($d,'instructions',5000)]);
        $this->w->audit('client.created',$r['ref']);return $r;
    }

    public static function instant(string $value,string $timezone): string
    {
        try {
            if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)) throw new \Exception();
            $d=new \DateTimeImmutable($value,new \DateTimeZone($timezone));
            if($d->format('Y-m-d\TH:i')!==$value) throw new \Exception();
            return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch(\Throwable) {throw new DomainError('Enter a valid local date and time.');}
    }

    public function schedule(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $this->w->require('scheduling');$planning=new ShiftPlanningService($this->w);$snapshot=$this->w->record('employments',$d['employment_ref']??'');$planning->lockPerson((int)$snapshot['person_id']);
            $e=$this->w->employment($d['employment_ref']??'');$client=$this->w->record('clients',$d['client_ref']??'');
            $start=self::instant($d['starts_at']??'',$this->w->agency['timezone']);$end=self::instant($d['ends_at']??'',$this->w->agency['timezone']);
            if($end<=$start||strtotime($end.' UTC')-strtotime($start.' UTC')>86400) throw new DomainError('Visit duration must be greater than zero and no longer than 24 hours. Overnight visits are allowed.');
            if(substr($d['starts_at'],0,10)<$e['start_date']) throw new DomainError('Visit falls before employment starts.');
            $overlap=$planning->conflicts((int)$e['person_id'],$start,$end);$leave=$planning->onLeave((int)$e['person_id'],$start,$end);
            if($overlap || $leave) throw new DomainError('The caregiver is unavailable due to another shift or approved leave. Check availability for suggested times, or request caregiver approval to replace eligible future shifts. No shift has changed.',409);
            $qualification=Workspace::text($d,'required_qualification',160,false);
            if($qualification){$valid=$this->w->db->query('SELECT q.id FROM cs_qualification_acceptances a JOIN cs_qualifications q ON q.id=a.qualification_id WHERE a.agency_id=? AND a.employment_id=? AND q.person_id=? AND q.name=? AND (q.expires_on IS NULL OR q.expires_on>=?)',[$e['agency_id'],$e['id'],$e['person_id'],$qualification,substr($end,0,10)])->getRowArray();if(!$valid)throw new DomainError('This assignment requires a current agency-accepted qualification.',409);}
            $r=$this->w->insert('visits',['agency_id'=>$this->w->agency['id'],'client_id'=>$client['id'],'employment_id'=>$e['id'],'starts_at'=>$start,'ends_at'=>$end,'service'=>Workspace::text($d,'service',120),'required_qualification'=>$qualification]);
            $this->w->audit('visit.scheduled',$r['ref']);$this->w->notify('visit.scheduled',$r['ref']);return $r;
        });
    }

    public function visitDecision(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('scheduling');$v=$this->w->record('visits',$d['ref']);$e=$this->w->table('employments')->where('id',$v['employment_id'])->get()->getRowArray();(new ShiftPlanningService($this->w))->lockPerson((int)$e['person_id']);$v=$this->w->record('visits',$d['ref'],true);
            if(!in_array($d['state']??'',['cancelled','no_show'],true)) throw new DomainError('Invalid visit outcome.');
            if(in_array($v['state'],['completed','cancelled','no_show'],true)) throw new DomainError('Visit outcome is already recorded.',409);
            $reason=Workspace::text($d,'reason',300);$this->w->update('visits',$v,['state'=>$d['state']],(int)$d['version']);
            $this->w->audit('visit.'.$d['state'],$v['ref'],null,$reason.'; recorded time remains intact');
        });
    }

    public function clock(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $this->w->require('self');$snapshot=$this->w->record('employments',$d['employment_ref']??'');(new ShiftPlanningService($this->w))->lockPerson((int)$snapshot['person_id']);$e=$this->w->employment($d['employment_ref']??'',true);$v=$this->w->record('visits',$d['visit_ref']??'',true);
            if((int)$v['employment_id']!==(int)$e['id']) throw new DomainError('Assignment unavailable.',404);
            $key=Workspace::text($d,'event_key',64);$type=Workspace::text($d,'type',24);
            $existing=$this->w->table('clock')->where('event_key',$key)->get()->getRowArray();
            if($existing) {
                if((int)$existing['visit_id']!==(int)$v['id'] || $existing['type']!==$type) throw new DomainError('Event key already belongs to another capture.',409);
                return $existing;
            }
            if(in_array($v['state'],['cancelled','completed','no_show'],true)) throw new DomainError('This visit is not open for clock capture.',409);
            $events=$this->w->table('clock')->where('visit_id',$v['id'])->orderBy('id')->get()->getResultArray();
            $last=$events?end($events)['type']:null;
            $allowed=[ 'new'=>['in'], 'in'=>['break_start','out'], 'break_start'=>['break_end'], 'break_end'=>['break_start','out'], 'out'=>[] ];
            if(!in_array($type,$allowed[$last??'new']??[],true)) throw new DomainError('Invalid clock transition. Finish the current session or break first.',409);
            if($type==='in') {
                $open=$this->w->db->query("SELECT v.id FROM cs_visits v JOIN cs_employments e ON e.id=v.employment_id WHERE e.person_id=? AND v.state='in_progress' LIMIT 1 FOR UPDATE",[$e['person_id']])->getRowArray();
                if($open) throw new DomainError('Another work session is active.',409);
            }
            $now=gmdate('Y-m-d H:i:s');
            $r=$this->w->insert('clock',['agency_id'=>$v['agency_id'],'employment_id'=>$e['id'],'visit_id'=>$v['id'],'event_key'=>$key,'type'=>$type,'occurred_at'=>$now]);
            if($type==='in') $this->w->update('visits',$v,['state'=>'in_progress']);
            if($type==='out') {
                $events[]=$r;$seconds=self::elapsed($events);
                $this->w->insert('timesheets',['agency_id'=>$v['agency_id'],'employment_id'=>$e['id'],'visit_id'=>$v['id'],'seconds'=>$seconds]);
                $this->w->update('visits',$v,['state'=>'completed']);
            }
            $this->w->audit('clock.'.$type,$r['ref'],(int)$e['person_id']);return $r;
        });
    }

    public static function elapsed(array $events): int
    {
        $total=0;$open=null;
        foreach($events as $event){
            $time=strtotime($event['occurred_at'].' UTC');
            if(in_array($event['type'],['in','break_end'],true)) $open=$time;
            elseif($open!==null && in_array($event['type'],['break_start','out'],true)){if($time<$open) throw new DomainError('Clock events are out of order.',409);$total+=$time-$open;$open=null;}
        }
        return $total;
    }

    public function approveTime(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('time');$t=$this->w->record('timesheets',$d['ref'],true);
            $e=$this->w->table('employments')->where('id',$t['employment_id'])->get()->getRowArray();
            if((int)$e['person_id']===(int)$this->w->ownPerson()['id']) throw new DomainError('You cannot approve your own time.',403);
            if(!in_array($t['state'],['submitted','returned'],true)) throw new DomainError('Time is already approved or locked.',409);
            $state=$d['state']??'approved';if(!in_array($state,['approved','returned'],true)) throw new DomainError('Choose approve or return.');
            $this->w->update('timesheets',$t,['state'=>$state,'approved_by'=>$this->w->userId],(int)$d['version']);$this->w->audit('time.'.$state,$t['ref']);
        });
    }

    public function leave(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $this->w->require('self');$e=$this->w->employment($d['employment_ref']??'',true);
            $start=self::instant($d['starts_at']??'',$this->w->agency['timezone']);$end=self::instant($d['ends_at']??'',$this->w->agency['timezone']);$minutes=(int)($d['minutes']??0);
            if($end<=$start || $minutes<1 || $minutes>(strtotime($end.' UTC')-strtotime($start.' UTC'))/60) throw new DomainError('Check leave interval and requested minutes.');
            if($this->w->table('leave')->where('employment_id',$e['id'])->whereIn('state',['pending','approved','cancellation_requested'])->where('starts_at <',$end)->where('ends_at >',$start)->countAllResults()) throw new DomainError('A leave request already overlaps this interval.',409);
            $r=$this->w->insert('leave',['agency_id'=>$e['agency_id'],'employment_id'=>$e['id'],'starts_at'=>$start,'ends_at'=>$end,'minutes'=>$minutes,'type'=>Workspace::text($d,'type',40),'requested_by'=>$this->w->userId]);
            $this->w->audit('leave.requested',$r['ref'],(int)$e['person_id']);return $r;
        });
    }

    public function leaveDecision(array $d): void
    {
        $this->w->transaction(function()use($d){
            $r=$this->w->record('leave',$d['ref']);
            $e=$this->w->table('employments')->where('id',$r['employment_id'])->get()->getRowArray();
            (new ShiftPlanningService($this->w))->lockPerson((int)$e['person_id']);
            $this->w->db->query('SELECT id FROM cs_employments WHERE id=? FOR UPDATE',[$e['id']]);
            $r=$this->w->record('leave',$d['ref'],true);$decision=$d['decision']??'';
            if($decision==='cancel_request') {
                $this->w->require('self');if((int)$r['requested_by']!==$this->w->userId) throw new DomainError('Request unavailable.',404);
                if(!in_array($r['state'],['pending','approved'],true)) throw new DomainError('Leave cannot be withdrawn in its current state.',409);
                $this->w->update('leave',$r,['state'=>$r['state']==='approved'?'cancellation_requested':'cancelled'],(int)$d['version']);return;
            }
            $this->w->require('leave');if((int)$r['requested_by']===$this->w->userId || (int)$e['person_id']===(int)$this->w->ownPerson()['id']) throw new DomainError('An alternate reviewer must approve your own request.',403);
            $balance=$this->w->db->query('SELECT * FROM cs_leave_balances WHERE agency_id=? AND employment_id=? FOR UPDATE',[$e['agency_id'],$e['id']])->getRowArray();
            if(!$balance) throw new DomainError('A leave policy and opening balance are required.',409);
            if($decision==='approve' && $r['state']==='pending') {
                if($e['state']!=='active' || $balance['minutes']<$r['minutes']) throw new DomainError('Insufficient leave balance or inactive employment.',409);
                $this->w->update('leave_balances',$balance,['minutes'=>$balance['minutes']-$r['minutes']]);
                $this->w->insert('leave_ledger',['agency_id'=>$r['agency_id'],'leave_id'=>$r['id'],'action'=>'approval','minutes'=>-$r['minutes']]);$state='approved';
                $this->w->notify('leave.coverage_review',$r['ref']);
            } elseif($decision==='cancel' && $r['state']==='cancellation_requested') {
                $this->w->update('leave_balances',$balance,['minutes'=>$balance['minutes']+$r['minutes']]);
                $this->w->insert('leave_ledger',['agency_id'=>$r['agency_id'],'leave_id'=>$r['id'],'action'=>'cancellation','minutes'=>$r['minutes']]);$state='cancelled';
            } elseif($decision==='reject' && $r['state']==='pending') {$state='rejected';}
            else throw new DomainError('This leave decision is not available.',409);
            $this->w->update('leave',$r,['state'=>$state,'reviewed_by'=>$this->w->userId],(int)$d['version']);$this->w->audit('leave.'.$state,$r['ref']);
        });
    }

    public function note(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $v=$this->w->record('visits',$d['visit_ref']??'');
            if(!$this->w->can('care')) {
                $this->w->require('self');$e=$this->w->table('employments')->where('id',$v['employment_id'])->get()->getRowArray();
                if($e['state']!=='active' || (int)$e['person_id']!==(int)$this->w->ownPerson()['id']) throw new DomainError('Visit unavailable.',404);
            }
            $kind=$d['kind']??'care_note';if(!in_array($kind,['care_note','incident','amendment'],true)) throw new DomainError('Invalid note type.');
            $severity=$d['severity']??'routine';if(!in_array($severity,['routine','low','medium','high'],true)) throw new DomainError('Invalid severity.');
            $r=$this->w->insert('notes',['agency_id'=>$v['agency_id'],'visit_id'=>$v['id'],'author_id'=>$this->w->userId,'body'=>Workspace::text($d,'body',8000),'kind'=>$kind,'severity'=>$severity]);
            $this->w->audit('care.'.$kind,$r['ref']);if($kind==='incident') $this->w->notify('incident.review_required',$r['ref']);return $r;
        });
    }

    public function asset(array $d): array
    {
        $this->w->require('assets');$r=$this->w->insert('assets',['agency_id'=>$this->w->agency['id'],'tag'=>Workspace::text($d,'tag',60),'name'=>Workspace::text($d,'name',120)]);$this->w->audit('asset.created',$r['ref']);return $r;
    }

    public function custody(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('assets');$a=$this->w->record('assets',$d['ref'],true);$note=Workspace::text($d,'condition_note',300);
            if(($d['decision']??'')==='assign') {
                if($a['state']!=='available' || $a['employment_id']) throw new DomainError('This asset already has custody or is unavailable.',409);
                $e=$this->w->employment($d['employment_ref']??'');
                $this->w->insert('custody',['agency_id'=>$a['agency_id'],'asset_id'=>$a['id'],'employment_id'=>$e['id'],'condition_note'=>$note]);$values=['state'=>'assigned','employment_id'=>$e['id']];
            } elseif(($d['decision']??'')==='return') {
                if($a['state']!=='assigned') throw new DomainError('This asset is not assigned.',409);
                $c=$this->w->table('custody')->where('asset_id',$a['id'])->where('returned_at',null)->get()->getRowArray();
                if(!$c) throw new DomainError('Custody requires reconciliation.',409);
                $this->w->update('custody',$c,['returned_at'=>gmdate('Y-m-d H:i:s'),'condition_note'=>$c['condition_note'].' | Returned: '.$note]);$values=['state'=>($d['maintenance']??'')==='yes'?'maintenance':'available','employment_id'=>null];
            } else throw new DomainError('Choose assign or return.');
            $this->w->update('assets',$a,$values,(int)$d['version']);$this->w->audit('asset.'.$d['decision'],$a['ref']);
        });
    }

    public function expense(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $this->w->require('self');$e=$this->w->employment($d['employment_ref']??'',true);$amount=Workspace::text($d,'amount',20);
            if(!preg_match('/^\d{1,10}(\.\d{1,2})?$/D',$amount) || bccomp($amount,'0',2)<=0) throw new DomainError('Enter a positive amount with up to two decimal places.');
            $r=$this->w->insert('expenses',['agency_id'=>$e['agency_id'],'employment_id'=>$e['id'],'category'=>Workspace::text($d,'category',60),'amount'=>$amount,'currency'=>$this->w->agency['currency'],'incurred_on'=>Workspace::date($d['incurred_on']??''),'description'=>Workspace::text($d,'description',500),'requested_by'=>$this->w->userId]);$this->w->audit('expense.submitted',$r['ref']);return $r;
        });
    }

    public function expenseDecision(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('expenses');$r=$this->w->record('expenses',$d['ref'],true);
            if((int)$r['requested_by']===$this->w->userId) throw new DomainError('You cannot approve your own expense.',403);
            $decision=$d['decision']??'';$values=[];
            if($r['state']==='submitted' && in_array($decision,['approved','returned'],true)) $values=['state'=>$decision,'reviewed_by'=>$this->w->userId];
            elseif($r['state']==='approved' && $decision==='reimbursed') $values=['state'=>'reimbursed','payment_evidence'=>Workspace::text($d,'payment_evidence',160)];
            else throw new DomainError('This expense decision is unavailable.',409);
            $this->w->update('expenses',$r,$values,(int)$d['version']);$this->w->audit('expense.'.$decision,$r['ref']);
        });
    }
}
