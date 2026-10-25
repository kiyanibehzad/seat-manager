<?php
declare(strict_types=1);
namespace AccManager;
final class Input
{
    public static function text(array $data, string $key, int $max = 100): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value)) { self::invalid($key); }
        return trim($value);
    }
    public static function email(array $data): string
    {
        $email = strtolower(self::text($data, 'email', 254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[\x21-\x7e]+$/', $email)) { self::invalid('email'); }
        return $email;
    }
    public static function password(array $data, string $key = 'password'): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) { self::invalid($key,'رمز عبور معتبر نیست.'); }
        if (mb_strlen($value,'UTF-8')<12) { self::invalid($key,'رمز عبور باید حداقل ۱۲ نویسه داشته باشد.'); }
        if (strlen($value)>72) { self::invalid($key,'رمز عبور طولانی است؛ رمز کوتاه‌تری انتخاب کنید.'); }
        return $value;
    }
    public static function id(array $data, string $key = 'user_id'): int
    {
        $value = $data[$key] ?? null;
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int)$value < 1) { self::invalid($key); }
        return (int)$value;
    }
    public static function boolean(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) { self::invalid($key); }
        return $data[$key];
    }
    public static function position(array $data): string
    {
        $value = $data['organizational_position'] ?? '';
        if (!in_array($value,['employee','supervisor'],true)) { self::invalid('organizational_position'); }
        return $value;
    }
    public static function only(array $data, array $keys): void
    {
        if (array_diff(array_keys($data),$keys)) { throw new ApiError(422,'validation_failed','فیلد ناشناخته در درخواست وجود دارد.'); }
    }
    public static function invalid(string $key, string $message = 'مقدار واردشده معتبر نیست.'): never
    { throw new ApiError(422,'validation_failed',$message,[$key=>$message]); }
}
