<?php
declare(strict_types=1);
namespace AccManager;
final class Crypto
{
    private string $key;
    public function __construct(string $encodedKey)
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== 32) { throw new \RuntimeException('Invalid encryption key'); }
        $this->key = $key;
    }
    public function encrypt(array $payload): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'acc-mail-v1');
        if ($cipher === false) { throw new \RuntimeException('Encryption failure'); }
        return base64_encode($iv . $tag . $cipher);
    }
    public function decrypt(string $payload): array
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) { throw new \RuntimeException('Invalid ciphertext'); }
        $plain = openssl_decrypt(substr($raw,28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16), 'acc-mail-v1');
        if ($plain === false) { throw new \RuntimeException('Decryption failure'); }
        return json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
    }
}
