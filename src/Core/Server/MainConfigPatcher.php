<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Čisté funkce pro patchování DS `config/main.json` (D3) — agent
 * hosting-sync přes ně zapisuje `auth.providers` na nově založený DS.
 * Zápis na disk (atomicky tmp + rename, chmod 0600) dělá volající
 * (HostingSyncRunner) — tady jen transformace pole kvůli testovatelnosti.
 */
final class MainConfigPatcher
{
    /**
     * Merge položky do `auth.providers` podle `id`: existující položka se
     * stejným id se nahradí, jinak se přidá na konec. Ostatní klíče configu
     * (vč. ostatních klíčů sekce `auth`) zůstávají beze změny.
     *
     * @param array<string, mixed> $config celý dekódovaný main.json
     * @param array<string, mixed> $provider položka auth.providers
     *        (id, label, issuer, clientId, clientSecret, autoLinkEmail, …)
     * @return array<string, mixed>
     */
    public static function mergeAuthProvider(array $config, array $provider): array
    {
        $id = (string) ($provider['id'] ?? '');
        if ($id === '' || !preg_match('/^[a-z0-9-]+$/', $id)) {
            throw new \InvalidArgumentException("Auth provider 'id' must match [a-z0-9-]+, got '{$id}'");
        }

        $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
        $providers = is_array($auth['providers'] ?? null) ? array_values($auth['providers']) : [];

        $replaced = false;
        foreach ($providers as $i => $existing) {
            if (is_array($existing) && (string) ($existing['id'] ?? '') === $id) {
                $providers[$i] = $provider;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $providers[] = $provider;
        }

        $auth['providers'] = $providers;
        $config['auth'] = $auth;

        return $config;
    }
}
