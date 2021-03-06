<?php

declare(strict_types=1);

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014-2018 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace Jose\Component\Signature\Util;

use Jose\Component\Core\Util\BigInteger;
use Jose\Component\Core\Util\Hash;
use Jose\Component\Core\Util\RSAKey;

/**
 * @internal
 */
class RSA
{
    /**
     * Probabilistic Signature Scheme.
     */
    public const SIGNATURE_PSS = 1;

    /**
     * Use the PKCS#1.
     */
    public const SIGNATURE_PKCS1 = 2;

    /**
     * @param BigInteger $x
     * @param int        $xLen
     *
     * @return string
     */
    private static function convertIntegerToOctetString(BigInteger $x, int $xLen): string
    {
        $x = $x->toBytes();
        if (mb_strlen($x, '8bit') > $xLen) {
            throw new \RuntimeException();
        }

        return str_pad($x, $xLen, chr(0), STR_PAD_LEFT);
    }

    /**
     * MGF1.
     *
     * @param string $mgfSeed
     * @param int    $maskLen
     * @param Hash   $mgfHash
     *
     * @return string
     */
    private static function getMGF1(string $mgfSeed, int $maskLen, Hash $mgfHash): string
    {
        $t = '';
        $count = ceil($maskLen / $mgfHash->getLength());
        for ($i = 0; $i < $count; $i++) {
            $c = pack('N', $i);
            $t .= $mgfHash->hash($mgfSeed.$c);
        }

        return mb_substr($t, 0, $maskLen, '8bit');
    }

    /**
     * EMSA-PSS-ENCODE.
     *
     * @param string $message
     * @param int    $modulusLength
     * @param Hash   $hash
     *
     * @return string
     */
    private static function encodeEMSAPSS(string $message, int $modulusLength, Hash $hash): string
    {
        $emLen = ($modulusLength + 1) >> 3;
        $sLen = $hash->getLength();
        $mHash = $hash->hash($message);
        if ($emLen <= $hash->getLength() + $sLen + 2) {
            throw new \RuntimeException();
        }
        $salt = random_bytes($sLen);
        $m2 = "\0\0\0\0\0\0\0\0".$mHash.$salt;
        $h = $hash->hash($m2);
        $ps = str_repeat(chr(0), $emLen - $sLen - $hash->getLength() - 2);
        $db = $ps.chr(1).$salt;
        $dbMask = self::getMGF1($h, $emLen - $hash->getLength() - 1, $hash);
        $maskedDB = $db ^ $dbMask;
        $maskedDB[0] = ~chr(0xFF << ($modulusLength & 7)) & $maskedDB[0];
        $em = $maskedDB.$h.chr(0xBC);

        return $em;
    }

    /**
     * EMSA-PSS-VERIFY.
     *
     * @param string $m
     * @param string $em
     * @param int    $emBits
     * @param Hash   $hash
     *
     * @return bool
     */
    private static function verifyEMSAPSS(string $m, string $em, int $emBits, Hash $hash): bool
    {
        $emLen = ($emBits + 1) >> 3;
        $sLen = $hash->getLength();
        $mHash = $hash->hash($m);
        if ($emLen < $hash->getLength() + $sLen + 2) {
            throw new \InvalidArgumentException();
        }
        if ($em[mb_strlen($em, '8bit') - 1] !== chr(0xBC)) {
            throw new \InvalidArgumentException();
        }
        $maskedDB = mb_substr($em, 0, -$hash->getLength() - 1, '8bit');
        $h = mb_substr($em, -$hash->getLength() - 1, $hash->getLength(), '8bit');
        $temp = chr(0xFF << ($emBits & 7));
        if ((~$maskedDB[0] & $temp) !== $temp) {
            throw new \InvalidArgumentException();
        }
        $dbMask = self::getMGF1($h, $emLen - $hash->getLength() - 1, $hash/*MGF*/);
        $db = $maskedDB ^ $dbMask;
        $db[0] = ~chr(0xFF << ($emBits & 7)) & $db[0];
        $temp = $emLen - $hash->getLength() - $sLen - 2;
        if (mb_substr($db, 0, $temp, '8bit') !== str_repeat(chr(0), $temp)) {
            throw new \InvalidArgumentException();
        }
        if (1 !== ord($db[$temp])) {
            throw new \InvalidArgumentException();
        }
        $salt = mb_substr($db, $temp + 1, null, '8bit'); // should be $sLen long
        $m2 = "\0\0\0\0\0\0\0\0".$mHash.$salt;
        $h2 = $hash->hash($m2);

        return hash_equals($h, $h2);
    }

    /**
     * @param string $m
     * @param int    $emBits
     * @param Hash   $hash
     *
     * @return string
     */
    private static function encodeEMSA15(string $m, int $emBits, Hash $hash): string
    {
        $h = $hash->hash($m);
        switch ($hash->name()) {
            case 'sha256':
                $t = "\x30\x31\x30\x0d\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01\x05\x00\x04\x20";

                break;
            case 'sha384':
                $t = "\x30\x41\x30\x0d\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x02\x05\x00\x04\x30";

                break;
            case 'sha512':
                $t = "\x30\x51\x30\x0d\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x03\x05\x00\x04\x40";

                break;
            default:
                throw new \InvalidArgumentException();
        }
        $t .= $h;
        $tLen = mb_strlen($t, '8bit');
        if ($emBits < $tLen + 11) {
            throw new \RuntimeException();
        }
        $ps = str_repeat(chr(0xFF), $emBits - $tLen - 3);
        $em2 = "\0\1$ps\0$t";

        return $em2;
    }

    /**
     * @param RSAKey $key
     * @param string $message
     * @param string $hash
     * @param int    $mode
     *
     * @return string
     */
    public static function sign(RSAKey $key, string $message, string $hash, int $mode): string
    {
        switch ($mode) {
            case self::SIGNATURE_PSS:
                return self::signWithPSS($key, $message, $hash);
            case self::SIGNATURE_PKCS1:
                return self::signWithPKCS15($key, $message, $hash);
            default:
                throw new \InvalidArgumentException('Unsupported mode.');
        }
    }

    /**
     * Create a signature.
     *
     * @param RSAKey $key
     * @param string $message
     * @param string $hash
     *
     * @return string
     */
    public static function signWithPSS(RSAKey $key, string $message, string $hash): string
    {
        $em = self::encodeEMSAPSS($message, 8 * $key->getModulusLength() - 1, Hash::$hash());
        $message = BigInteger::createFromBinaryString($em);
        $signature = RSAKey::exponentiate($key, $message);

        return self::convertIntegerToOctetString($signature, $key->getModulusLength());
    }

    /**
     * Create a signature.
     *
     * @param RSAKey $key
     * @param string $message
     * @param string $hash
     *
     * @return string
     */
    public static function signWithPKCS15(RSAKey $key, string $message, string $hash): string
    {
        $em = self::encodeEMSA15($message, $key->getModulusLength(), Hash::$hash());
        $message = BigInteger::createFromBinaryString($em);
        $signature = RSAKey::exponentiate($key, $message);

        return self::convertIntegerToOctetString($signature, $key->getModulusLength());
    }

    /**
     * @param RSAKey $key
     * @param string $message
     * @param string $signature
     * @param string $hash
     * @param int    $mode
     *
     * @return bool
     */
    public static function verify(RSAKey $key, string $message, string $signature, string $hash, int $mode): bool
    {
        switch ($mode) {
            case self::SIGNATURE_PSS:
                return self::verifyWithPSS($key, $message, $signature, $hash);
            case self::SIGNATURE_PKCS1:
                return self::verifyWithPKCS15($key, $message, $signature, $hash);
            default:
                throw new \InvalidArgumentException('Unsupported mode.');
        }
    }

    /**
     * Verifies a signature.
     *
     * @param RSAKey $key
     * @param string $message
     * @param string $signature
     * @param string $hash
     *
     * @return bool
     */
    public static function verifyWithPSS(RSAKey $key, string $message, string $signature, string $hash): bool
    {
        if (mb_strlen($signature, '8bit') !== $key->getModulusLength()) {
            throw new \InvalidArgumentException();
        }
        $s2 = BigInteger::createFromBinaryString($signature);
        $m2 = RSAKey::exponentiate($key, $s2);
        $em = self::convertIntegerToOctetString($m2, $key->getModulusLength());
        $modBits = 8 * $key->getModulusLength();

        return self::verifyEMSAPSS($message, $em, $modBits - 1, Hash::$hash());
    }

    /**
     * Verifies a signature.
     *
     * @param RSAKey $key
     * @param string $message
     * @param string $signature
     * @param string $hash
     *
     * @return bool
     */
    public static function verifyWithPKCS15(RSAKey $key, string $message, string $signature, string $hash): bool
    {
        if (mb_strlen($signature, '8bit') !== $key->getModulusLength()) {
            throw new \InvalidArgumentException();
        }
        $signature = BigInteger::createFromBinaryString($signature);
        $m2 = RSAKey::exponentiate($key, $signature);
        $em = self::convertIntegerToOctetString($m2, $key->getModulusLength());

        return hash_equals($em, self::encodeEMSA15($message, $key->getModulusLength(), Hash::$hash()));
    }
}
