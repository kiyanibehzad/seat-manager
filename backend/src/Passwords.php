<?php
declare(strict_types=1);
namespace AccManager;

final class Passwords
{
    // Keep creation, migration and the absent-user check independent of PHP's changing default.
    public const ALGORITHM = PASSWORD_BCRYPT;
    public const COST = 12;

    // Precomputed non-account hashes. These values are not credentials and are never generated per request.
    private const DUMMY_HASHES = [
        4 => '$2y$04$6AU5ysbR/qHTJgcE0xuJjO8XPgMKXxpxYZXLkRWQGis9USmaigkl2',
        5 => '$2y$05$Dz0guRBhIuWbJcdUW/LvPOpkZOtZMfMT38fC9frT0xvvcy8juL1HC',
        6 => '$2y$06$Q3NWvlDDZZRtm16wNbhkr.jkhc9xMWFSleEMvqoB6BN8COFKmi2sK',
        7 => '$2y$07$r2OuECZSkslFagaOINx78eNAAzKKJxsH1acsFx3Tj9pTCNT84hWZa',
        8 => '$2y$08$zSn0kstAK186K5h9aDyl.OuFfxtYIgdxAr4RUSWyAaeDoaCaHjdVO',
        9 => '$2y$09$wnTtSEH0bpO.vauejj4JS.5/XxLtMnglyJVLkJ9Xpv.sWnMsbXgky',
        10 => '$2y$10$jV1xNGIPYN5tjgHK2/aI6u8L3dp4QKgwJPANM.Pq4gCUEjmx4Jdnq',
        11 => '$2y$11$uB4/kax7MduVo6GMJrB2f.fkSQM3dBf0/1D5GI94BPW8ppjoODxDS',
        12 => '$2y$12$M4IBCFBjhDNQT42RRRh.pOTsaNPOCCE2VgjV8reHIQs3tS.ddiAo.',
    ];

    public static function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, self::ALGORITHM, ['cost'=>self::COST]);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHM, ['cost'=>self::COST]);
    }

    public static function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        $candidate = $hash ?? self::DUMMY_HASHES[self::COST];
        $info = password_get_info($candidate);
        $verified = password_verify($password, $candidate);
        if (($info['algoName'] ?? '') === 'bcrypt') {
            $cost = (int)($info['options']['cost'] ?? 0);
            // Old PHP defaults created cost-10 hashes. Add only the missing bcrypt work:
            // 2^c (actual) + sum(2^c .. 2^11) = 2^12. No database lock or fresh hash is needed.
            for ($padding=$cost; $padding>=4 && $padding<self::COST; $padding++) {
                password_verify($password, self::DUMMY_HASHES[$padding]);
            }
        } elseif (!isset($info['algo']) || $info['algo'] === null) {
            // A malformed stored value must not create an instant rejected-account path.
            password_verify($password, self::DUMMY_HASHES[self::COST]);
        }
        return $hash !== null && $verified;
    }
}
