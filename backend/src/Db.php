<?php
declare(strict_types=1);
namespace AccManager;
use PDO;
final class Db
{
    public PDO $pdo;
    public function __construct(array $config)
    {
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec("SET SESSION innodb_lock_wait_timeout = 5");
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
    public function one(string $sql, array $params = []): ?array { return $this->run($sql, $params)->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { return $this->run($sql, $params)->fetchAll(); }
    public function transaction(callable $callback): mixed
    {
        // Callbacks contain database work only; mail/network delivery happens after commit.
        for ($attempt=0;;$attempt++) {
            $this->pdo->beginTransaction();
            try { $result=$callback(); $this->pdo->commit(); return $result; }
            catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                $transient=$e instanceof \PDOException && in_array((int)($e->errorInfo[1]??0),[1020,1205,1213],true);
                if (!$transient || $attempt>=2) { throw $e; }
                usleep(random_int(10000,40000));
            }
        }
    }
}
