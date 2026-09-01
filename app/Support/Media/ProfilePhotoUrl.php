<?php

namespace App\Support\Media;

/**
 * Résolution de l'URL publique d'une photo de profil.
 *
 * La règle existait à l'identique dans LdtController et HandleInertiaRequests ;
 * elle est ici en un seul endroit, pour que les modules qui affichent un avatar
 * partagent le même comportement plutôt que d'en recopier une variante.
 */
final class ProfilePhotoUrl
{
    public static function resolve(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_starts_with($path, '/')) {
            return $path;
        }

        return '/storage/'.$path;
    }
}
