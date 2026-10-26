<?php
declare(strict_types=1);
namespace AccManager;
final class RateLimiter
{
    public function __construct(private App $app) {}
    private function key(string $scope,string $identity): string { return hash_hmac('sha256',$scope.':'.$identity,$this->app->config['app_key']); }
    public function check(string $scope,string $identity,int $limit,int $seconds=900): void
    {
        $row=$this->app->db->one('SELECT attempts,window_started FROM rate_limits WHERE bucket=?',[$this->key($scope,$identity)]);
        if ($row && strtotime($row['window_started'].' UTC')+$seconds>time() && (int)$row['attempts']>=$limit) { $this->denied(); }
    }
    public function clear(string $scope,string $identity): void { $this->app->db->run('DELETE FROM rate_limits WHERE bucket=?',[$this->key($scope,$identity)]); }
    private function denied(): never { throw new ApiError(429,'rate_limited','تعداد تلاش‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.'); }
    public function consume(string $scope, string $identity, int $limit, int $seconds = 900): void
    {
        $key=$this->key($scope,$identity);
        $consume=function () use ($key,$limit,$seconds) {
            $db=$this->app->db;
            $db->run('INSERT IGNORE INTO rate_limits(bucket,window_started) VALUES(?,UTC_TIMESTAMP())',[$key]);
            $row=$db->one('SELECT * FROM rate_limits WHERE bucket=? FOR UPDATE',[$key]);
            if (strtotime($row['window_started'].' UTC')+$seconds<=time()) {
                $db->run('UPDATE rate_limits SET attempts=1,window_started=UTC_TIMESTAMP(),blocked_until=NULL WHERE bucket=?',[$key]); return true;
            }
            if ((int)$row['attempts']>=$limit) { return false; }
            $db->run('UPDATE rate_limits SET attempts=attempts+1 WHERE bucket=?',[$key]); return true;
        };
        // Administrative commands may already hold the identity transaction.
        $allowed=$this->app->db->pdo->inTransaction()?$consume():$this->app->db->transaction($consume);
        if (!$allowed) { $this->denied(); }
    }
}
