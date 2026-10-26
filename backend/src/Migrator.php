<?php
declare(strict_types=1);
namespace AccManager;
final class Migrator
{
    public function __construct(private App $app) {}
    public function run(): array
    {
        $db=$this->app->db; $lock='acc-migration-'.substr(hash('sha256',$this->app->config['db']['dsn']),0,32);
        if (!(int)$db->one('SELECT GET_LOCK(?,5) acquired',[$lock])['acquired']) { throw new \RuntimeException('Migration busy'); }
        $applied=[];
        try {
            $db->run('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY,checksum CHAR(64) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
            $files=glob($this->app->root.'/database/migrations/*.sql'); sort($files);
            foreach ($files as $file) {
                $version=basename($file); $checksum=hash_file('sha256',$file); $existing=$db->one('SELECT checksum FROM schema_migrations WHERE version=?',[$version]);
                if ($existing) { if (!hash_equals($existing['checksum'],$checksum)) { throw new \RuntimeException('Migration checksum mismatch'); } continue; }
                // Migration files are trusted release assets. DDL commits implicitly on MySQL/MariaDB.
                $db->pdo->exec(file_get_contents($file));
                $db->run('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)',[$version,$checksum]); $applied[]=$version;
            }
        } finally { $db->one('SELECT RELEASE_LOCK(?) released',[$lock]); }
        $this->app->accessGroups->refreshSchema();
        return $applied;
    }
}
