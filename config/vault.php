<?php

return [
    // base64:-prefixed 32-byte key that wraps every workspace data key.
    'master_key' => env('VAULT_MASTER_KEY'),
];
