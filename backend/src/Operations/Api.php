<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\{App,Input};
final class Api
{
    public const ROUTES=[
        '/accounts'=>'GET,POST,PATCH','/requests'=>'GET,POST','/requests/detail'=>'GET','/requests/cancel'=>'POST','/history'=>'GET','/reasons'=>'GET',
        '/admin/requests'=>'GET','/admin/accounts'=>'GET','/admin/queue'=>'GET','/admin/dashboard'=>'GET','/admin/requests/detail'=>'GET','/admin/requests/revalidate'=>'POST','/admin/requests/reject'=>'POST','/admin/requests/start'=>'POST',
        '/admin/operations'=>'GET','/admin/operations/detail'=>'GET','/admin/operations/start'=>'POST','/admin/operations/result'=>'POST','/admin/operations/invitation'=>'POST','/admin/operations/defer'=>'POST','/admin/operations/cancel'=>'POST','/admin/operations/abort'=>'POST','/admin/corrections'=>'POST',
        '/admin/history'=>'GET','/admin/capacity'=>'GET,POST','/admin/finance'=>'GET','/admin/reasons'=>'POST','/admin/users/bulk-preview'=>'POST','/admin/users/bulk-commit'=>'POST',
    ];
    public function __construct(private App $app) {}
    public function handle(string $route,string $method,array $data,array $actor): array
    {
        if ($method==='GET') { return $this->app->db->transaction(fn()=>$this->dispatch($route,$method,$data,$actor)); }
        return $this->dispatch($route,$method,$data,$actor);
    }
    private function dispatch(string $route,string $method,array $data,array $actor): array
    {
        $permission=match ($route) {
            '/accounts','/history','/reasons'=>'personal.read','/requests','/requests/detail','/requests/cancel'=>'personal.request',
            '/admin/users/bulk-preview','/admin/users/bulk-commit'=>'people.manage',
            '/admin/reasons'=>'settings.manage','/admin/finance'=>'finance.read','/admin/capacity'=>$method==='GET'?'capacity.read':'capacity.manage',
            default=>'seats.manage',
        };
        $this->app->auth->requirePermission($actor,$permission); $d=new Domain($this->app); $accounts=new Accounts($d); $requests=new Requests($d); $engine=new Engine($d); $history=new History($d);
        switch ($route) {
            case '/accounts': return [200,match ($method) { 'GET'=>$accounts->list($_GET,$actor),'POST'=>$accounts->create($data,$actor),'PATCH'=>$accounts->rename($data,$actor) }];
            case '/requests': return [200,$method==='GET'?$requests->list($actor):$requests->create($data,$actor)];
            case '/requests/detail': case '/admin/requests/detail':
                $r=$requests->row(Input::id($_GET,'id')); $admin=$route==='/admin/requests/detail'; if (!$admin&&(int)$r['owner_id']!==(int)$actor['id']) { $d->missing(); }
                return [200,['request'=>$requests->publicRequest($r,$admin,(new Queue($d))->estimates()[(int)$r['id']]??null),'history'=>$history->list(['request_id'=>$r['id']],$actor,$admin)['items']]];
            case '/requests/cancel': return [200,$requests->cancel($data,$actor)];
            case '/history': return [200,$history->list($_GET,$actor)];
            case '/reasons': return [200,['items'=>$this->app->db->all('SELECT code,label FROM request_reasons WHERE enabled=1 ORDER BY code'),'other_code'=>'other']];
            case '/admin/requests': return [200,$requests->adminList($_GET)];
            case '/admin/accounts': return [200,$accounts->list($_GET,$actor,true)];
            case '/admin/queue': return [200,(new Queue($d))->view()];
            case '/admin/dashboard': return [200,(new Dashboard($d))->view()];
            case '/admin/requests/revalidate': return [200,$requests->revalidate($data,$actor)];
            case '/admin/requests/reject': return [200,$requests->reject($data,$actor)];
            case '/admin/requests/start': return [200,$engine->startRequest($data,$actor)];
            case '/admin/operations': return [200,$engine->list($_GET)];
            case '/admin/operations/detail': $id=Input::id($_GET,'id'); return [200,['operation'=>$engine->view($id),'history'=>$history->list(['operation_id'=>$id],$actor,true)['items']]];
            case '/admin/operations/start': return [200,$engine->startDirect($data,$actor)];
            case '/admin/operations/result': return [200,$engine->result($data,$actor)];
            case '/admin/operations/invitation': return [200,$engine->invitation($data,$actor)];
            case '/admin/operations/defer': return [200,$engine->defer($data,$actor)];
            case '/admin/operations/abort': return [200,$engine->abort($data,$actor)];
            case '/admin/operations/cancel': return [200,$engine->cancel($data,$actor)];
            case '/admin/corrections': return [200,$engine->correct($data,$actor)];
            case '/admin/capacity': return [200,$method==='GET'?['capacity'=>(new Capacity($d))->snapshot()]:(new Capacity($d))->set($data,$actor)];
            case '/admin/history': return [200,$history->list($_GET,$actor,true)];
            case '/admin/finance': return [200,$history->finance($_GET)];
            case '/admin/users/bulk-preview': return [200,(new BulkPeople($d))->preview($data,$actor)];
            case '/admin/users/bulk-commit': return [200,(new BulkPeople($d))->commit($data,$actor)];
            case '/admin/reasons':
                Input::only($data,['items','idempotency_key']);
                return [200,$d->tx($actor,'settings.manage','reasons.set',$data,function ($fresh) use ($data) {
                    $items=$data['items']??null; if (!is_array($items)||!array_is_list($items)||count($items)>50) { Input::invalid('items'); }
                    $seen=[]; $valid=[]; foreach ($items as $item) { if (!is_array($item)) { Input::invalid('items'); } Input::only($item,['code','label']); $code=$item['code']??''; if (!is_string($code)||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$code)||$code==='other'||isset($seen[$code])) { Input::invalid('code'); } $seen[$code]=true; $valid[]=['code'=>$code,'label'=>Input::text($item,'label',150)]; }
                    $this->app->db->run('UPDATE request_reasons SET enabled=0'); foreach ($valid as $item) { $this->app->db->run('INSERT INTO request_reasons(code,label) VALUES(?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),enabled=1',[$item['code'],$item['label']]); }
                    $this->app->audit('reasons.updated',(int)$fresh['id'],null); return ['items'=>$valid,'other_code'=>'other'];
                })];
        }
        $d->missing();
    }
}
