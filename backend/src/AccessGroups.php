<?php
declare(strict_types=1);
namespace AccManager;
use AccManager\Operations\Domain;
final class AccessGroups
{
    public const BASE=['personal.read','personal.request','notifications.read'];
    public const CATALOG=[
        'dashboard.read'=>['label'=>'کارهای روزانه','requires'=>['seats.manage']],
        'seats.manage'=>['label'=>'مدیریت سیت‌ها و درخواست‌ها','requires'=>[]],
        'registrations.review'=>['label'=>'تأیید و رد ثبت‌نام','requires'=>[]],
        'people.manage'=>['label'=>'مدیریت افراد','requires'=>[]],
        'departments.manage'=>['label'=>'مدیریت بخش‌ها و امتیازها','requires'=>['people.manage']],
    ];
    public const CHIEF=['finance.read','capacity.manage','settings.manage','roles.manage','access_groups.manage'];
    private ?bool $schemaReady=null;
    public function __construct(private App $app) {}
    public function ready(): bool
    {
        if ($this->schemaReady!==null) { return $this->schemaReady; }
        $exists=$this->app->db->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'");
        return $this->schemaReady=(bool)$exists['n'] && $this->app->db->one('SELECT version FROM schema_migrations WHERE version=?',['003_access_groups.sql'])!==null;
    }
    public function refreshSchema(): void { $this->schemaReady=null; }
    public function initializeUser(int $id,bool $chief=false): void
    {
        if ($this->ready()) { $this->app->db->run('UPDATE users SET access_group_id=? WHERE id=?',[$chief?4:1,$id]); }
    }
    public function requireReady(): void
    {
        if (!$this->ready()) { throw new ApiError(503,'migration_required','قابلیت گروه‌های دسترسی پس از مهاجرت هماهنگ‌شده فعال می‌شود.'); }
    }
    public function defaultId(): int { $this->requireReady(); return 1; }
    public function legacyGroupId(string $role): int { return match ($role) { 'user'=>1,'hr'=>2,'operator'=>3,'admin'=>4,default=>throw new ApiError(422,'validation_failed','نقش سازگاری معتبر نیست.') }; }
    public function groupForUser(int $id): ?array
    {
        return $this->app->db->one('SELECT g.* FROM users u JOIN access_groups g ON g.id=u.access_group_id WHERE u.id=?',[$id]);
    }
    public function selected(array $user): array
    {
        if (!$this->ready()) {
            return array_values(array_intersect(array_keys(self::CATALOG),self::legacyPermissions($user)));
        }
        $group=$this->groupForUser((int)$user['id']);
        if (!$group||$group['system_key']==='user') { return []; }
        if ($group['is_super_admin']) { return array_keys(self::CATALOG); }
        return array_column($this->app->db->all('SELECT capability FROM access_group_capabilities WHERE group_id=? ORDER BY capability',[$group['id']]),'capability');
    }
    public function permissions(array $user): array
    {
        if (!$this->ready()) { return self::legacyPermissions($user); }
        $group=$this->groupForUser((int)$user['id']);
        if (!$group) { return self::BASE; } // Invalid membership fails closed for administrative actions.
        $selected=$group['is_super_admin']?array_keys(self::CATALOG):$this->selected($user);
        $permissions=[...self::BASE,...$selected];
        if (in_array('seats.manage',$selected,true)) { $permissions=[...$permissions,'requests.manage','capacity.read']; }
        if ($group['is_super_admin']) { $permissions=[...$permissions,...self::CHIEF]; }
        return array_values(array_unique($permissions));
    }
    public static function legacyPermissions(array $user): array
    {
        $permissions=self::BASE;
        if (in_array($user['app_role'],['admin','hr'],true)) { $permissions=[...$permissions,'people.manage','departments.manage','registrations.review']; }
        if (in_array($user['app_role'],['admin','operator'],true)) { $permissions=[...$permissions,'dashboard.read','seats.manage','requests.manage','capacity.read']; }
        if ($user['app_role']==='admin') { $permissions=[...$permissions,'roles.manage','settings.manage','finance.read','capacity.manage']; }
        return $permissions;
    }
    public function recipients(string $permission): array
    {
        if (!$this->ready()) {
            $rows=$this->app->db->all("SELECT id,app_role FROM users WHERE is_active=1 AND approval_status='approved' AND email_verified_at IS NOT NULL");
            return array_column(array_filter($rows,fn($u)=>in_array($permission,self::legacyPermissions($u),true)),'id');
        }
        $capability=match ($permission) { 'requests.manage','capacity.read'=>'seats.manage',default=>$permission };
        return array_column($this->app->db->all("SELECT u.id FROM users u JOIN access_groups g ON g.id=u.access_group_id WHERE u.is_active=1 AND u.approval_status='approved' AND u.email_verified_at IS NOT NULL AND (g.is_super_admin=1 OR ((g.system_key IS NULL OR g.system_key<>'user') AND EXISTS(SELECT 1 FROM access_group_capabilities c WHERE c.group_id=g.id AND c.capability=?))) ORDER BY u.id",[$capability]),'id');
    }
    public function isChief(array $user): bool
    {
        return $this->ready()?(bool)($this->groupForUser((int)$user['id'])['is_super_admin']??false):$user['app_role']==='admin';
    }
    public function protectLastChief(array $target): void
    {
        if (!$this->isChief($target)||!$this->app->auth->eligible($target)) { return; }
        $count=$this->ready()?$this->app->db->one("SELECT COUNT(*) n FROM users u JOIN access_groups g ON g.id=u.access_group_id WHERE g.is_super_admin=1 AND u.is_active=1 AND u.approval_status='approved' AND u.email_verified_at IS NOT NULL"):$this->app->db->one("SELECT COUNT(*) n FROM users WHERE app_role='admin' AND is_active=1 AND approval_status='approved' AND email_verified_at IS NOT NULL");
        if ((int)$count['n']<=1) { throw new ApiError(409,'conflict','آخرین مدیر کل فعال باید حفظ شود.'); }
    }
    public function catalog(): array
    {
        $items=[]; foreach (self::CATALOG as $code=>$definition) { $items[]=['code'=>$code]+$definition; } return $items;
    }
    public function view(int $id): array
    {
        $row=$this->app->db->one('SELECT g.*,(SELECT COUNT(*) FROM users u WHERE u.access_group_id=g.id) user_count FROM access_groups g WHERE g.id=?',[$id]);
        if (!$row) { throw new ApiError(404,'not_found','گروه پیدا نشد.'); }
        return ['id'=>(int)$row['id'],'name'=>$row['name'],'system_key'=>$row['system_key'],'is_super_admin'=>(bool)$row['is_super_admin'],'protected'=>(bool)$row['is_super_admin']||$row['system_key']==='user','revision'=>(int)$row['revision'],'capabilities'=>$row['is_super_admin']?array_keys(self::CATALOG):($row['system_key']==='user'?[]:array_column($this->app->db->all('SELECT capability FROM access_group_capabilities WHERE group_id=? ORDER BY capability',[$id]),'capability')),'user_count'=>(int)$row['user_count']];
    }
    private function validateCapabilities(array $data): array
    {
        $capabilities=$data['capabilities']??null;
        if (!is_array($capabilities)||!array_is_list($capabilities)||count($capabilities)>count(self::CATALOG)) { Input::invalid('capabilities'); }
        $seen=[];
        foreach ($capabilities as $capability) { if (!is_string($capability)||!isset(self::CATALOG[$capability])||isset($seen[$capability])) { Input::invalid('capabilities','قابلیت انتخاب‌شده معتبر نیست.'); } $seen[$capability]=true; }
        foreach ($capabilities as $capability) { foreach (self::CATALOG[$capability]['requires'] as $required) { if (!isset($seen[$required])) { Input::invalid('capabilities','پیش‌نیاز «'.self::CATALOG[$capability]['label'].'»، «'.self::CATALOG[$required]['label'].'» است.'); } } }
        sort($capabilities); return $capabilities;
    }
    public function list(array $actor): array
    {
        $this->requireReady(); $this->app->auth->requirePermission($actor,'access_groups.manage');
        return $this->app->db->transaction(function () use ($actor) {
            $fresh=$this->app->auth->user((int)$actor['id']);
            if (!$fresh||!$this->app->auth->eligible($fresh)||(int)$fresh['auth_version']!==(int)$actor['auth_version']) { throw new ApiError(401,'unauthenticated','دوباره وارد شوید.'); }
            $this->app->auth->requirePermission($fresh,'access_groups.manage');
            return ['items'=>array_map(fn($r)=>$this->view((int)$r['id']),$this->app->db->all('SELECT id FROM access_groups ORDER BY id')),'capability_catalog'=>$this->catalog()];
        });
    }
    public function save(array $data,array $actor,bool $update=false): array
    {
        $this->requireReady(); Input::only($data,$update?['group_id','name','capabilities','expected_revision','idempotency_key']:['name','capabilities','idempotency_key']);
        $name=Input::text($data,'name',120); $capabilities=$this->validateCapabilities($data);
        return (new Domain($this->app))->tx($actor,'access_groups.manage',$update?'access_group.update':'access_group.create',$data,function ($fresh) use ($data,$name,$capabilities,$update) {
            $before=null;
            if ($update) {
                $id=Input::id($data,'group_id'); $before=$this->view($id);
                if ($before['protected']) { throw new ApiError(409,'protected_group','این گروه پایه محافظت‌شده است.'); }
                if (!isset($data['expected_revision'])||!is_int($data['expected_revision'])) { Input::invalid('expected_revision'); }
                if ($before['revision']!==$data['expected_revision']) { throw new ApiError(409,'conflict','گروه تغییر کرده است؛ دوباره بخوانید.'); }
                $old=$before['capabilities']; sort($old);
                if ($name===$before['name']&&$old===$capabilities) { return ['group'=>$before]; }
                $this->app->db->run('UPDATE access_groups SET name=?,revision=revision+1 WHERE id=?',[$name,$id]);
                $this->app->db->run('DELETE FROM access_group_capabilities WHERE group_id=?',[$id]);
            } else {
                $this->app->db->run('INSERT INTO access_groups(name) VALUES(?)',[$name]); $id=(int)$this->app->db->pdo->lastInsertId();
            }
            foreach ($capabilities as $capability) { $this->app->db->run('INSERT INTO access_group_capabilities(group_id,capability) VALUES(?,?)',[$id,$capability]); }
            if ($update) { $this->app->db->run('UPDATE users SET auth_version=auth_version+1 WHERE access_group_id=?',[$id]); }
            $after=$this->view($id); $this->app->audit($update?'access_group.updated':'access_group.created',(int)$fresh['id'],null,['before'=>$before,'after'=>$after]);
            return ['group'=>$after];
        });
    }
    public function assign(array $data,array $actor): array
    {
        $this->requireReady(); Input::only($data,['user_id','access_group_id','idempotency_key']);
        return (new Domain($this->app))->tx($actor,'access_groups.manage','user.access_group',$data,function ($fresh) use ($data) {
            return $this->assignInTransaction(Input::id($data),Input::id($data,'access_group_id'),$fresh);
        });
    }
    public function assignInTransaction(int $userId,int $groupId,array $actor): array
    {
        $this->app->auth->requirePermission($actor,'access_groups.manage');
        $target=$this->app->db->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$userId]); if (!$target) { throw new ApiError(404,'not_found','کاربر پیدا نشد.'); }
        if ($target['approval_status']!=='approved') { throw new ApiError(409,'conflict','ابتدا ثبت‌نام باید تأیید شود.'); }
        $group=$this->view($groupId);
        if ((int)$target['access_group_id']!==$groupId) {
            if (!$group['is_super_admin']) { $this->protectLastChief($target); }
            $role=in_array($group['system_key'],['user','hr','operator','admin'],true)?$group['system_key']:'user';
            $this->app->db->run('UPDATE users SET access_group_id=?,app_role=?,auth_version=auth_version+1 WHERE id=?',[$groupId,$role,$userId]);
            $this->app->audit('user.access_group_changed',(int)$actor['id'],$userId,['before'=>['access_group_id'=>(int)$target['access_group_id'],'app_role'=>$target['app_role']],'after'=>['access_group_id'=>$groupId,'access_group_name'=>$group['name'],'app_role'=>$role]]);
        }
        return ['user'=>$this->app->auth->publicUser($this->app->auth->user($userId))];
    }
}
