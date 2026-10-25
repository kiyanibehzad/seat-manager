<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\Input;
final class Accounts
{
    public function __construct(private Domain $d) {}
    public function createInTx(int $owner,string $email,?string $alias=null): array
    {
        if ($this->d->app->db->one('SELECT id FROM openai_accounts WHERE email=?',[$email])) { $this->d->conflict('این ایمیل حساب قبلاً ثبت شده است.'); }
        $this->d->app->db->run('INSERT INTO openai_accounts(owner_id,email,alias) VALUES(?,?,?)',[$owner,$email,$alias]);
        return $this->d->account((int)$this->d->app->db->pdo->lastInsertId());
    }
    public function create(array $data,array $actor): array
    {
        Input::only($data,['email','alias','idempotency_key']); $email=Input::email($data); $alias=$this->alias($data);
        return $this->d->tx($actor,'personal.read','account.create',$data,function ($fresh) use ($email,$alias) { $account=$this->createInTx((int)$fresh['id'],$email,$alias); $this->d->app->audit('openai_account.created',(int)$fresh['id'],(int)$fresh['id']); return ['account'=>$this->d->publicAccount($account)]; });
    }
    private function alias(array $data): ?string { if (!isset($data['alias'])||$data['alias']==='') { return null; } return Input::text($data,'alias',120); }
    public function rename(array $data,array $actor): array
    {
        Input::only($data,['account_id','alias','idempotency_key']); $alias=$this->alias($data);
        return $this->d->tx($actor,'personal.read','account.rename',$data,function ($fresh) use ($data,$alias) {
            $account=$this->d->account(Input::id($data,'account_id')); if ((int)$account['owner_id']!==(int)$fresh['id']) { $this->d->missing(); }
            $this->d->app->db->run('UPDATE openai_accounts SET alias=? WHERE id=?',[$alias,$account['id']]);
            return ['account'=>$this->d->publicAccount($this->d->account((int)$account['id']))];
        });
    }
    public function list(array $query,array $actor,bool $admin=false): array
    {
        $params=[]; $where=[];
        if (!$admin) { $where[]='x.owner_id=?'; $params[]=$actor['id']; }
        elseif (isset($query['owner_id'])) { $where[]='x.owner_id=?'; $params[]=Input::id($query,'owner_id'); }
        if ($admin && isset($query['q']) && $query['q']!=='') { $q=Input::text($query,'q',100); $where[]='(x.email LIKE ? OR x.alias LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)'; $params=[...$params,...array_fill(0,4,'%'.$q.'%')]; }
        $before=isset($query['before_id'])?Input::id($query,'before_id'):PHP_INT_MAX; $where[]='x.id<?'; $params[]=$before;
        $limit=$admin?min(100,isset($query['limit'])?Input::id($query,'limit'):50):500;
        $rows=$this->d->app->db->all($this->d->accountSql().' WHERE '.implode(' AND ',$where).' ORDER BY x.id DESC LIMIT '.($limit+1),$params);
        $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        return ['items'=>array_map(fn($r)=>$this->d->publicAccount($r,$admin),$rows),'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
}
