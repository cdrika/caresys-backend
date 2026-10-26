<?php
namespace App\Libraries;

/** Small, dependency-free PDF renderer using the immutable billing snapshot. */
final class SubscriptionInvoicePdf
{
    public static function render(array $invoice): string
    {
        $s=json_decode($invoice['snapshot'],true,512,JSON_THROW_ON_ERROR);
        $content='';$y=790;
        $write=static function(string $text,int $size=11,bool $bold=false)use(&$content,&$y){
            $text=iconv('UTF-8','Windows-1252//TRANSLIT',$text)?:$text;
            $text=str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$text);
            $content.='BT /'.($bold?'F2':'F1').' '.$size.' Tf 0.08 0.20 0.22 rg 50 '.$y.' Td ('.$text.") Tj ET\n";
            $y-=$size+10;
        };
        $money=static fn(int $c)=>'USD '.number_format($c/100,2,'.',',');
        $write('CareSys',24,true);$write('SUBSCRIPTION INVOICE',18,true);
        $write('PRO FORMA - monthly subscription proposal',11,true);
        $write($invoice['invoice_no']);
        foreach(explode("\n",wordwrap($s['agency']['name'],65,"\n",true)) as $line)$write($line,12,true);
        $write('Issued: '.$s['issued_at'].' UTC | Prices valid through: '.$s['valid_until'],10);
        $write('Workers: '.$s['worker_count'].' | '.$s['package_label'],11);
        $y-=10;$write('Description / quantity / monthly amount',11,true);
        foreach($s['lines'] as $line){
            $write($line['description'],11);
            $write('Quantity '.$line['quantity'].' x '.$money($line['unit_cents']).' = '.$money($line['amount_cents']),10);
        }
        $y-=8;$write('Monthly subtotal: '.$money($s['total_cents']),16,true);
        $write('Tax: not calculated. Final tax and payment terms require confirmation.',10);
        $write('No payment has been collected. Module access is unchanged.',10);
        $write('Catalog: '.$s['catalog_version'],9);
        $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>','<< /Length '.strlen($content).">>\nstream\n".$content.'endstream'];
        $pdf="%PDF-1.4\n";$offsets=[0];
        foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 7\n0000000000 65535 f \n";
        foreach(array_slice($offsets,1) as $offset)$pdf.=sprintf("%010d 00000 n \n",$offset);
        return $pdf."trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
