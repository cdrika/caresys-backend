<?php
namespace App\Services;

final class NotificationService
{
    public function __construct(private Workspace $w){}
    private function eligible(): string
    {
        return "n.recipient_id=? AND ((n.event='agency.review_requested' AND EXISTS(SELECT 1 FROM cs_operators o WHERE o.user_id=n.recipient_id)) OR (n.event<>'agency.review_requested' AND EXISTS(SELECT 1 FROM cs_memberships m WHERE m.user_id=n.recipient_id AND m.agency_id=n.agency_id AND m.role='owner' AND m.state='active')))";
    }
    public function inbox(int $limit=10): array
    {
        $limit=max(1,min(100,$limit));$where=$this->eligible();
        $items=$this->w->db->query("SELECT n.ref,n.title,n.body,n.link,n.event,n.created_at,n.read_at FROM cs_notifications n WHERE {$where} ORDER BY n.id DESC LIMIT {$limit}",[$this->w->userId])->getResultArray();
        $count=(int)$this->w->db->query("SELECT COUNT(*) AS n FROM cs_notifications n WHERE {$where} AND n.read_at IS NULL",[$this->w->userId])->getRow()->n;
        return ['items'=>$items,'unread'=>$count];
    }
    public function markRead(string $ref): void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$ref))throw new DomainError('Notification unavailable.',404);
        $this->w->transaction(function()use($ref){
            $where=$this->eligible();$row=$this->w->db->query("SELECT n.* FROM cs_notifications n WHERE {$where} AND n.ref=? FOR UPDATE",[$this->w->userId,$ref])->getRowArray();
            if(!$row)throw new DomainError('Notification unavailable.',404);
            if(!$row['read_at'])$this->w->update('notifications',$row,['read_at'=>gmdate('Y-m-d H:i:s')]);
        });
    }
    private function send(int $recipient,array $agency,string $event,string $key,string $title,string $body,string $link): void
    {
        $hash=hash('sha256',$key);
        if($this->w->db->table('cs_notifications')->where(['recipient_id'=>$recipient,'event_key'=>$hash])->countAllResults())return;
        $now=gmdate('Y-m-d H:i:s');
        $this->w->insert('notifications',['created_at'=>$now,'updated_at'=>$now,'recipient_id'=>$recipient,'agency_id'=>$agency['id'],'event'=>$event,'event_key'=>$hash,'title'=>$title,'body'=>$body,'link'=>$link]);
    }
    public function registered(array $agency): void
    {
        $operators=$this->w->db->query("SELECT o.user_id FROM cs_operators o JOIN users u ON u.id=o.user_id WHERE u.active=1 AND u.deleted_at IS NULL AND (u.status IS NULL OR u.status NOT IN ('suspended','banned','closed'))")->getResultArray();
        foreach($operators as $operator)$this->send((int)$operator['user_id'],$agency,'agency.review_requested','register:'.$agency['ref'],'Agency registration needs review',$agency['name'].' has registered. Review the agency details, then verify and approve, reject or suspend the registration.','/app/platform#agency-'.$agency['ref']);
        $this->owners($agency,'agency.registration_received','received:'.$agency['ref'],'Agency registration received',$agency['name'].' was registered and is awaiting platform verification and approval.');
    }
    public function decision(array $agency,string $basis): void
    {
        $state=$agency['verification'];
        [$title,$body]=match($state){
            'verified'=>['Agency verified and approved',$agency['name'].' registration has been verified and approved. Trial setup, subscription and module permissions still apply.'],
            'rejected'=>['Agency registration not approved',$agency['name'].' registration was rejected.'],
            'suspended'=>['Agency registration suspended',$agency['name'].' is suspended. Agency operations are restricted.'],
            default=>throw new DomainError('Invalid notification status.')
        };
        $this->owners($agency,'agency.'.$state,'decision:'.$agency['ref'].':'.$agency['version'],$title,$body.' Review note: '.$basis);
    }
    private function owners(array $agency,string $event,string $key,string $title,string $body): void
    {
        $owners=$this->w->db->table('cs_memberships')->where(['agency_id'=>$agency['id'],'role'=>'owner','state'=>'active'])->get()->getResultArray();
        foreach($owners as $owner)$this->send((int)$owner['user_id'],$agency,$event,$key,$title,$body,'/app/notifications');
    }
}
