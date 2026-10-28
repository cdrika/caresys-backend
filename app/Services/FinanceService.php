<?php
namespace App\Services;

class FinanceService
{
    public function __construct(public Workspace $w) {}

    public function employeeMapping(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('payroll');$e=$this->w->record('employments',$d['ref'],true);
            $this->w->update('employments',$e,['provider_ref'=>Workspace::text($d,'provider_ref',100)],(int)$d['version']);$this->w->audit('payroll.mapping_changed',$e['ref']);
        });
    }

    public function payroll(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $this->w->require('payroll');$start=Workspace::date($d['period_start']??'');$end=Workspace::date($d['period_end']??'');
            if($end<$start) throw new DomainError('Pay period end must follow start.');
            $rows=$this->w->db->query("SELECT t.*,e.provider_ref,e.employee_no,p.name,v.starts_at,v.ends_at FROM cs_timesheets t JOIN cs_employments e ON e.agency_id=t.agency_id AND e.id=t.employment_id JOIN cs_people p ON p.id=e.person_id JOIN cs_visits v ON v.agency_id=t.agency_id AND v.id=t.visit_id WHERE t.agency_id=? AND DATE(v.starts_at)>=? AND DATE(v.starts_at)<=? AND t.state='approved' ORDER BY t.id FOR UPDATE",[$this->w->agency['id'],$start,$end])->getResultArray();
            if(!$rows) throw new DomainError('No approved, unexported time exists in this UTC period.',409);
            $unfinished=$this->w->table('visits')->whereIn('state',['in_progress'])->where('starts_at >=',$start.' 00:00:00')->where('starts_at <=',$end.' 23:59:59')->countAllResults();
            if($unfinished) throw new DomainError('Open work sessions must be resolved before export.',409);
            $snapshot=[];
            foreach($rows as $r) {
                if(!$r['provider_ref']) throw new DomainError('Missing payroll provider mapping for '.$r['employee_no'].'.');
                $snapshot[]=['employee'=>$r['provider_ref'],'employee_no'=>$r['employee_no'],'earning_code'=>'REGULAR_WORKED','seconds'=>(int)$r['seconds'],'timesheet_ref'=>$r['ref'],'source_version'=>(int)$r['version'],'period_start'=>$start,'period_end'=>$end];
            }
            $json=self::canonical($snapshot);$batch=$this->w->insert('payroll',['agency_id'=>$this->w->agency['id'],'period_start'=>$start,'period_end'=>$end,'source_ids'=>json_encode(array_column($rows,'id')),'snapshot'=>$json,'checksum'=>hash('sha256',$json)]);
            foreach($rows as $r) {$this->w->insert('payroll_items',['agency_id'=>$r['agency_id'],'batch_id'=>$batch['id'],'timesheet_id'=>$r['id']]);$this->w->update('timesheets',$r,['state'=>'locked']);}
            $this->w->audit('payroll.export_finalized',$batch['ref'],null,'Worked-time snapshot only; no payment instruction or statutory calculation');return $batch;
        });
    }

    public function invoice(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $this->w->require('finance');$client=$this->w->record('clients',$d['client_ref']??'',true);
            $rate=Workspace::text($d,'hourly_rate',20);
            if(!preg_match('/^\d{1,10}(\.\d{1,4})?$/D',$rate) || bccomp($rate,'0',4)<=0) throw new DomainError('Enter a positive, agreed hourly charge rate.');
            if(($d['confirm']??'')!=='yes') throw new DomainError('Confirm the charge rate against the client agreement.');
            $rows=$this->w->db->query("SELECT v.id AS visit_id,t.seconds FROM cs_visits v JOIN cs_timesheets t ON t.agency_id=v.agency_id AND t.visit_id=v.id WHERE v.agency_id=? AND v.client_id=? AND v.state='completed' AND t.state IN ('approved','locked') AND NOT EXISTS (SELECT 1 FROM cs_invoice_lines l WHERE l.visit_id=v.id) ORDER BY v.id FOR UPDATE",[$client['agency_id'],$client['id']])->getResultArray();
            if(!$rows) throw new DomainError('No approved, completed, unbilled visits exist for this client.',409);
            $total='0.0000';$lines=[];
            foreach($rows as $r) {$amount=bcdiv(bcmul((string)$r['seconds'],$rate,8),'3600',4);if(bccomp($amount,'0',4)<=0) continue;$lines[]=$r+['amount'=>$amount];$total=bcadd($total,$amount,4);}
            if(!$lines) throw new DomainError('Billable quantities total zero.',409);
            $invoice=$this->w->insert('invoices',['agency_id'=>$client['agency_id'],'client_id'=>$client['id'],'agency_contact'=>json_encode(\App\Libraries\AgencyContact::snapshot($this->w->agency),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'invoice_no'=>Workspace::text($d,'invoice_no',60),'total'=>$total,'currency'=>$this->w->agency['currency'],'due_date'=>Workspace::date($d['due_date']??'')]);
            foreach($lines as $line) $this->w->insert('invoice_lines',['agency_id'=>$client['agency_id'],'invoice_id'=>$invoice['id'],'visit_id'=>$line['visit_id'],'seconds'=>$line['seconds'],'hourly_rate'=>$rate,'amount'=>$line['amount']]);
            $this->w->audit('invoice.issued',$invoice['ref'],null,'Agreed charge-rate snapshot; whole visit quantities');return $invoice;
        });
    }

    public function receipt(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('finance');$i=$this->w->record('invoices',$d['ref'],true);$amount=Workspace::text($d,'amount',20);
            if(!preg_match('/^\d{1,10}(\.\d{1,2})?$/D',$amount) || bccomp($amount,'0',2)<=0) throw new DomainError('Enter a positive receipt amount.');
            $paid=$this->w->table('receipts')->selectSum('amount')->where('invoice_id',$i['id'])->get()->getRow()->amount??'0';
            if(bccomp(bcadd($paid,$amount,4),$i['total'],4)>0) throw new DomainError('Receipt exceeds the outstanding balance.',409);
            $this->w->insert('receipts',['agency_id'=>$i['agency_id'],'invoice_id'=>$i['id'],'amount'=>$amount,'evidence'=>Workspace::text($d,'evidence',160)]);
            $this->w->update('invoices',$i,['state'=>bccomp(bcadd($paid,$amount,4),$i['total'],4)===0?'paid':'part_paid'],(int)$d['version']);$this->w->audit('receipt.recorded',$i['ref']);
        });
    }

    public function csv(string $ref): string
    {
        $this->w->require('payroll');$batch=$this->w->record('payroll',$ref);$snapshot=json_decode($batch['snapshot'],true);
        if(!hash_equals($batch['checksum'],hash('sha256',self::canonical($snapshot)))) throw new DomainError('Export checksum mismatch. Reconciliation required.',409);
        $stream=fopen('php://temp','r+');fputcsv($stream,array_keys($snapshot[0]));
        foreach($snapshot as $r) fputcsv($stream,array_map([self::class,'csvCell'],array_values($r)));
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);$this->w->audit('payroll.downloaded',$batch['ref']);return $csv;
    }

    public static function csvCell(mixed $value): string
    {
        $value=(string)$value;return preg_match('/^[\s]*[=+@\-]/u',$value)?"'".$value:$value;
    }

    public static function canonical(array $value): string
    {
        $normalize=function(array $item)use(&$normalize):array{if(!array_is_list($item))ksort($item);foreach($item as &$v)if(is_array($v))$v=$normalize($v);return $item;};
        return json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
}
