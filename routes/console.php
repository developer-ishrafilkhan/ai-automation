<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('vault:key {--show : Display the key instead of modifying files}', function () {
    $key = 'base64:'.base64_encode(random_bytes(32));
    if ($this->option('show')) {
        $this->line('<comment>'.$key.'</comment>');

        return;
    }

    $envPath = base_path('.env');
    if (! file_exists($envPath)) {
        $this->error('.env file not found. Copy .env.example to .env first.');

        return;
    }

    $env = file_get_contents($envPath);
    if (str_contains($env, 'VAULT_MASTER_KEY=')) {
        $env = preg_replace('/^VAULT_MASTER_KEY=.*$/m', 'VAULT_MASTER_KEY='.$key, $env);
    } else {
        $env .= "\nVAULT_MASTER_KEY=".$key."\n";
    }
    file_put_contents($envPath, $env);

    $this->info('Vault master key set successfully in .env');
})->purpose('Generate and set the VAULT_MASTER_KEY in the .env file');
