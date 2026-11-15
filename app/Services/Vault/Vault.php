<?php

namespace App\Services\Vault;

use App\Models\Workspace;
use RuntimeException;

/**
 * Envelope encryption: every workspace has its own random data key, stored
 * wrapped by the master key. Secrets are AES-256-GCM encrypted with the data key.
 * Swap wrap()/unwrap() for a KMS call when moving to production.
 */
class Vault
{
    private const CIPHER = 'aes-256-gcm';

    public function newWrappedDataKey(): string
    {
        return $this->seal($this->masterKey(), random_bytes(32));
    }

    public function encrypt(Workspace $workspace, string $plaintext): string
    {
        return $this->seal($this->dataKey($workspace), $plaintext);
    }

    public function decrypt(Workspace $workspace, string $ciphertext): string
    {
        return $this->open($this->dataKey($workspace), $ciphertext);
    }

    private function dataKey(Workspace $workspace): string
    {
        return $this->open($this->masterKey(), $workspace->encrypted_data_key);
    }

    private function masterKey(): string
    {
        $configured = (string) config('vault.master_key');
        $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('VAULT_MASTER_KEY must be a base64:-prefixed 32-byte key.');
        }

        return $key;
    }

    private function seal(string $key, string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return 'v1:'.base64_encode($iv.$tag.$ciphertext);
    }

    private function open(string $key, string $sealed): string
    {
        $raw = base64_decode(substr($sealed, 3), true);
        if (! str_starts_with($sealed, 'v1:') || $raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Malformed vault payload.');
        }

        $plaintext = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plaintext === false) {
            throw new RuntimeException('Vault payload could not be decrypted.');
        }

        return $plaintext;
    }
}
