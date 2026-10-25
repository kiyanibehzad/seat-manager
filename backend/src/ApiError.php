<?php
declare(strict_types=1);
namespace AccManager;
final class ApiError extends \RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public array $fields = [])
    { parent::__construct($message); }
}
