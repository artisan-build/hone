<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Support;

final class PublicIp
{
    public static function isValid(?string $ipAddress): bool
    {
        if ($ipAddress === null || filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        $packed = inet_pton($ipAddress);
        $sharedAddressSpace = inet_pton('100.64.0.0');

        if ($packed === false || $sharedAddressSpace === false || strlen($packed) !== strlen($sharedAddressSpace)) {
            return true;
        }

        return (ord($packed[0]) !== ord($sharedAddressSpace[0]))
            || ((ord($packed[1]) & 0xC0) !== (ord($sharedAddressSpace[1]) & 0xC0));
    }
}
