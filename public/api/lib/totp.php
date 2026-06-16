<?php
declare(strict_types=1);
// TOTP (RFC 6238) compatible con Google Authenticator / Authy. Sin dependencias externas.

function totp_base32_decode(string $b32): string {
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
  $bits = '';
  foreach (str_split($b32) as $ch) {
    $bits .= str_pad(decbin(strpos($alphabet, $ch)), 5, '0', STR_PAD_LEFT);
  }
  $bytes = '';
  foreach (str_split($bits, 8) as $byte) {
    if (strlen($byte) === 8) $bytes .= chr(bindec($byte));
  }
  return $bytes;
}

function totp_base32_encode(string $bin): string {
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits = '';
  foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
  $out = '';
  foreach (str_split($bits, 5) as $chunk) {
    $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
  }
  return $out;
}

function totp_secret(int $bytes = 20): string {
  return totp_base32_encode(random_bytes($bytes));
}

function totp_code(string $secretB32, ?int $ts = null, int $period = 30, int $digits = 6): string {
  $ts = $ts ?? time();
  $counter = intdiv($ts, $period);
  $bin = "\0\0\0\0" . pack('N', $counter);            // contador de 8 bytes big-endian
  $hash = hash_hmac('sha1', $bin, totp_base32_decode($secretB32), true);
  $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
  $part = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        |  (ord($hash[$offset + 3]) & 0xff);
  return str_pad((string)($part % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function totp_verify(string $secretB32, string $code, int $window = 1): bool {
  $code = preg_replace('/\D/', '', $code) ?? '';
  if (strlen($code) < 6) return false;
  $now = time();
  for ($i = -$window; $i <= $window; $i++) {
    if (hash_equals(totp_code($secretB32, $now + $i * 30), $code)) return true;
  }
  return false;
}

function totp_uri(string $label, string $secretB32, string $issuer): string {
  return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
    . '?secret=' . $secretB32
    . '&issuer=' . rawurlencode($issuer)
    . '&period=30&digits=6&algorithm=SHA1';
}
