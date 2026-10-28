<?php
namespace App\Libraries;

/** Paginated invoice layout. Monetary values and new module descriptions are snapshotted. */
final class SubscriptionInvoicePdf
{
    private array $pages=[];
    private string $content='';
    private int $y=0;
    private string $number='';
    private bool $paid=false;

    public static function render(array $invoice): string
    {
        return (new self())->document($invoice);
    }

    private function text(string $text,int $x,int $y,int $size=10,bool $bold=false,string $color='0.12 0.20 0.23'): void
    {
        $text=iconv('UTF-8','Windows-1252//TRANSLIT',$text)?:$text;
        $text=str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$text);
        $this->content.='BT /'.($bold?'F2':'F1').' '.$size.' Tf '.$color.' rg '.$x.' '.$y.' Td ('.$text.") Tj ET\n";
    }

    private function box(int $x,int $y,int $width,int $height,string $color): void
    {
        $this->content.=$color.' rg '.$x.' '.$y.' '.$width.' '.$height." re f\n";
    }

    private function page(): void
    {
        if($this->content!=='')$this->pages[]=$this->content;
        $this->content='';
        $this->box(0,828,595,14,'0.06 0.43 0.39');
        $this->text('CareSys',42,784,25,true,'0.06 0.43 0.39');
        $this->text('SUBSCRIPTION INVOICE',310,790,15,true);
        $this->text($this->paid?'PAID / SUBSCRIPTION SUMMARY':'PRO FORMA / MONTHLY PROPOSAL',310,771,9,false,'0.35 0.43 0.46');
        $this->box(42,749,511,1,'0.82 0.88 0.87');
        $this->text($this->number,42,728,10,true);
        $this->y=700;
    }

    private function ensure(int $height): void
    {
        if($this->y-$height<82)$this->page();
    }

    private function paragraph(string $text,int $size=10,bool $bold=false,int $width=88): void
    {
        foreach(explode("\n",wordwrap($text,$width,"\n",true)) as $line){
            $this->ensure($size+5);$this->text($line,42,$this->y,$size,$bold);$this->y-=$size+5;
        }
    }

    private function document(array $invoice): string
    {
        $s=json_decode($invoice['snapshot'],true,512,JSON_THROW_ON_ERROR);
        $money=static fn(int $c)=>number_format($c/100,2,'.',',');
        $this->paid=($invoice['state']??'')==='paid';
        $this->number=$invoice['invoice_no'];$this->page();
        $this->text('PREPARED FOR',42,$this->y,9,true,'0.35 0.43 0.46');$this->y-=22;
        $this->paragraph($s['agency']['name'],14,true,56);
        $this->paragraph('Country: '.$s['agency']['country']);
        foreach(['official_email'=>'Official email','business_phone'=>'Phone','contact_number'=>'Contact','mobile_number'=>'Mobile','business_registration_number'=>'Business registration'] as $key=>$label){
            $value=$s['agency'][$key]??'';if($value==='')continue;
            if(in_array($key,['business_phone','contact_number','mobile_number'],true))$value=AgencyContact::display($value);
            $this->paragraph($label.': '.$value,9);
        }$this->y-=12;
        $this->paragraph('Issued: '.$s['issued_at'].' UTC    |    Price valid through: '.$s['valid_until']);
        $this->paragraph('Plan: '.$s['package_label'].'    |    Workers covered: '.$s['worker_count']);
        $this->paragraph('Billing basis: monthly    |    Currency: USD');$this->y-=18;
        $this->ensure(65);
        $this->box(42,$this->y-10,511,28,'0.06 0.43 0.39');
        foreach([['MONTHLY CHARGES',52],['QTY',340],['UNIT USD',392],['AMOUNT USD',471]] as [$label,$x])$this->text($label,$x,$this->y,9,true,'1 1 1');
        $this->y-=32;
        foreach($s['lines'] as $line){
            $wrapped=explode("\n",wordwrap($line['description'],43,"\n",true));
            $height=max(30,count($wrapped)*14+12);$this->ensure($height);
            foreach($wrapped as $i=>$label)$this->text($label,52,$this->y-$i*14,10);
            $this->text((string)$line['quantity'],345,$this->y);
            $this->text($money($line['unit_cents']),397,$this->y);
            $this->text($money($line['amount_cents']),480,$this->y,10,true);
            $this->y-=$height;$this->box(42,$this->y+8,511,1,'0.88 0.92 0.91');
        }
        $this->ensure(100);$this->y-=12;
        $this->box(300,$this->y-18,253,42,'0.91 0.96 0.94');
        $this->text('MONTHLY SUBTOTAL',312,$this->y+5,9,true);
        $this->text('USD '.$money($s['total_cents']),427,$this->y-10,15,true,'0.06 0.43 0.39');
        $this->y-=45;$this->paragraph($this->paid?'Tax and the final charged amount are shown on your Stripe payment receipt.':'Tax is confirmed at Stripe checkout before you approve payment.',9);
        $this->paragraph($this->paid?'Payment verified by Stripe. Renewal requires another owner-approved payment.':'No payment collected. Payment after the free trial requires explicit owner approval.',9);
        if(!empty($s['trial_terms']['ends_at'])){
            $this->paragraph('Free trial: '.$s['trial_terms']['days'].' days, ending '.$s['trial_terms']['ends_at'].' UTC.',9,true);
            $this->paragraph('No subscription charge during the trial. Agency access locks at expiry until payment.',9);
        }
        $this->y-=12;
        $this->ensure(100);$this->paragraph('MODULE SCOPE & DESCRIPTIONS',13,true,65);
        $this->paragraph('Descriptions below explain the modules covered by this proposal.',9);$this->y-=8;
        $details=$s['module_details']??array_map(static fn($key)=>['key'=>$key,'label'=>SubscriptionQuote::MODULES[$key]??$key,'description'=>SubscriptionQuote::DESCRIPTIONS[$key]??''], $s['effective_modules']);
        foreach($details as $detail){
            $key=$detail['key'];
            $status=in_array($key,$s['requested_modules'],true)?'Selected':(in_array($key,$s['added_dependencies'],true)?'Required dependency':'Included in plan');
            $description=$detail['description'];
            $this->ensure(35+count(explode("\n",wordwrap($description,88,"\n",true)))*15);
            $this->paragraph($detail['label'].' - '.$status,11,true,75);
            $this->paragraph($description);$this->y-=6;
        }
        $this->ensure(65);$this->y-=8;
        $this->paragraph('TERMS & BILLING NOTES',10,true);
        $this->paragraph($this->paid?'This subscription summary accompanies your Stripe payment receipt; it is not a tax invoice.':'Prices are valid through the date above. This is a pro forma proposal, not a tax invoice.',9);
        $this->paragraph('Catalog version: '.$s['catalog_version'].'. Access remains subject to the agreed subscription.',9);
        $this->pages[]=$this->content;
        $objects=['<< /Type /Catalog /Pages 2 0 R >>','', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];$kids=[];
        foreach($this->pages as $i=>$content){
            $this->content='';$this->box(42,59,511,1,'0.82 0.88 0.87');
            $this->text('CareSys | '.$this->number,42,41,8,false,'0.35 0.43 0.46');
            $this->text('Page '.($i+1).' of '.count($this->pages),490,41,8);
            $content.=$this->content;$pageId=count($objects)+1;$streamId=$pageId+1;$kids[]=$pageId.' 0 R';
            $objects[]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$streamId.' 0 R >>';
            $objects[]='<< /Length '.strlen($content).">>\nstream\n".$content.'endstream';
        }
        $objects[1]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';
        $pdf="%PDF-1.4\n";$offsets=[];
        foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n";}
        $xref=strlen($pdf);$size=count($objects)+1;$pdf.="xref\n0 ".$size."\n0000000000 65535 f \n";
        foreach($offsets as $offset)$pdf.=sprintf("%010d 00000 n \n",$offset);
        return $pdf."trailer\n<< /Size ".$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
