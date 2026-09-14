<?php
// totp_lib.php
// Shared TOTP (RFC 6238) functions. No external libraries.
// Included by login.php, verify_2fa.php, setup_2fa.php.

function totp_base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binaryString = '';
    foreach (str_split($data) as $char) {
        $binaryString .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $encoded = '';
    foreach (str_split($binaryString, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $encoded .= $alphabet[bindec($chunk)];
    }
    return $encoded;
}

function totp_base32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim($b32, '='));
    $binaryString = '';
    foreach (str_split($b32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($binaryString, 8) as $byte) {
        if (strlen($byte) < 8) continue;
        $bytes .= chr(bindec($byte));
    }
    return $bytes;
}

function totp_generate_secret($length = 20) {
    return totp_base32_encode(random_bytes($length));
}

function totp_get_code($secret, $timeStep = 30, $digits = 6, $offset = 0) {
    $key = totp_base32_decode($secret);
    $time = floor(time() / $timeStep) + $offset;
    $timeBin = str_pad(pack('N', $time), 8, "\x00", STR_PAD_LEFT); // 8-byte big-endian counter

    $hash = hash_hmac('sha1', $timeBin, $key, true);
    $offsetByte = ord($hash[strlen($hash) - 1]) & 0x0F;

    $truncated = (ord($hash[$offsetByte]) & 0x7F) << 24
        | (ord($hash[$offsetByte + 1]) & 0xFF) << 16
        | (ord($hash[$offsetByte + 2]) & 0xFF) << 8
        | (ord($hash[$offsetByte + 3]) & 0xFF);

    return str_pad($truncated % (10 ** $digits), $digits, '0', STR_PAD_LEFT);
}

function totp_verify($secret, $code, $window = 1) {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_get_code($secret, 30, 6, $i), $code)) {
            return true;
        }
    }
    return false;
}

function totp_build_otpauth($secret, $issuer, $account) {
    return "otpauth://totp/" . rawurlencode($issuer . ':' . $account)
        . "?secret={$secret}&issuer=" . rawurlencode($issuer)
        . "&algorithm=SHA1&digits=6&period=30";
}
