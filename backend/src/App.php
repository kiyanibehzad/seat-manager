<?php
declare(strict_types=1);
namespace AccManager;
final class App
{
    public Db $db;
    public Crypto $crypto;
    public MailQueue $mail;
    public Events $events;
    public Auth $auth;
    public AccessGroups $accessGroups;
    public People $people;
    public string $requestId;
    public function __construct(public string $root, public array $config)
    {
        $this->requestId = bin2hex(random_bytes(8));
        $this->db = new Db($config['db']);
        $this->crypto = new Crypto($config['app_key']);
        $this->mail = new MailQueue($this);
        $this->events = new Events($this);
        $this->accessGroups = new AccessGroups($this);
        $this->auth = new Auth($this);
        $this->people = new People($this);
    }
    public static function boot(string $root): self
    {
        if (PHP_VERSION_ID < 80300) { throw new \RuntimeException('PHP 8.3 required'); }
        date_default_timezone_set('UTC');
        umask(0077);
        $configPath = $root . '/config/local.php';
        // Alternate config is a CLI/test-only environment setting, never request input.
        if (getenv('ACC_CONFIG')) { $configPath = (string)getenv('ACC_CONFIG'); }
        if (!is_file($configPath)) { throw new \RuntimeException('Configuration missing'); }
        $config = require $configPath;
        $url = parse_url($config['app_url']);
        if (!$url || !isset($url['scheme'], $url['host']) || !in_array($url['scheme'],['https','http'],true) || isset($url['query']) || isset($url['fragment']) || isset($url['user']) || isset($url['pass'])) { throw new \RuntimeException('Invalid app URL'); }
        $config['app_url'] = rtrim($config['app_url'], '/');
        if ($config['environment'] === 'production' && ($url['scheme'] !== 'https' || !$config['session']['secure'])) { throw new \RuntimeException('HTTPS required'); }
        foreach (['logs', 'sessions', 'run'] as $directory) {
            if (!is_dir($root . '/storage/' . $directory)) { mkdir($root . '/storage/' . $directory, 0700, true); }
        }
        return new self($root, $config);
    }
    public function audit(string $action, ?int $actor, ?int $subject, array $details = []): void
    {
        // Only allow structured non-secret fields, never arbitrary request bodies.
        $safe = array_intersect_key($details, array_flip(['before','after','reason','department_id','app_role','is_active','fields']));
        $this->db->run('INSERT INTO audit_log(actor_id,subject_id,action,details_json,request_id) VALUES(?,?,?,?,?)', [$actor,$subject,$action,json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),$this->requestId]);
    }
    public function log(string $code): void
    {
        file_put_contents($this->root . '/storage/logs/app.log', gmdate('c') . ' ' . $this->requestId . ' ' . $code . "\n", FILE_APPEND | LOCK_EX);
    }
    public function url(string $fragment = ''): string { return $this->config['app_url'] . '/' . ($fragment ? '#' . $fragment : ''); }
}
