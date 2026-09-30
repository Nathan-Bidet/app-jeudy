<?php

namespace App\Services\Cotations;

use App\Models\CotationMailAttachment;
use App\Models\User;
use App\Support\Cotations\CotationPdfFormatter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pièces jointes temporaires du courriel des cotations : validation, stockage
 * privé sous un nom aléatoire, suppression idempotente et purge des brouillons
 * abandonnés. Chaque fichier est rattaché à un utilisateur ET à un brouillon ;
 * toute lecture passe par forDraft(), qui applique ce double filtre.
 */
class CotationMailAttachmentService
{
    /** Extensions qui ne doivent jamais apparaître dans un nom, même en « double extension ». */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'com', 'bat', 'cmd', 'scr', 'msi', 'dll',
        'js', 'mjs', 'jar', 'vbs', 'vbe', 'wsf', 'ps1', 'sh', 'bash', 'html', 'htm', 'xhtml', 'svg', 'hta', 'lnk', 'reg',
    ];

    public function disk(): string
    {
        return (string) config('cotations.mail.disk', 'local');
    }

    /**
     * Limites exposées à l'interface (les mêmes que celles appliquées ici).
     *
     * @return array<string, mixed>
     */
    public function limits(): array
    {
        return [
            'max_files' => (int) config('cotations.mail.max_files'),
            'max_file_bytes' => (int) config('cotations.mail.max_file_kb') * 1024,
            'max_total_bytes' => (int) config('cotations.mail.max_total_kb') * 1024,
            'extensions' => array_keys((array) config('cotations.mail.allowed')),
        ];
    }

    public function pdfFilename(): string
    {
        return CotationPdfFormatter::exportFilename();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, CotationMailAttachment>
     */
    public function forDraft(User $user, string $draftId)
    {
        return CotationMailAttachment::query()
            ->where('user_id', $user->id)
            ->where('draft_id', $draftId)
            ->orderBy('id')
            ->get();
    }

    public function find(User $user, string $draftId, int $id): ?CotationMailAttachment
    {
        return CotationMailAttachment::query()
            ->where('user_id', $user->id)
            ->where('draft_id', $draftId)
            ->whereKey($id)
            ->first();
    }

    /**
     * Enregistre le PDF généré. L'ancien PDF (régénération) n'est supprimé
     * qu'une fois le nouveau écrit : en cas d'échec, il reste en place.
     */
    public function storePdf(User $user, string $draftId, string $binary, ?int $replacesId = null): CotationMailAttachment
    {
        $replaced = $replacesId !== null ? $this->find($user, $draftId, $replacesId) : null;
        if ($replaced && $replaced->kind !== CotationMailAttachment::KIND_PDF) {
            $replaced = null;
        }

        $this->assertTotalWithin($user, $draftId, strlen($binary), $replaced?->id, 'file');

        $attachment = $this->persist($user, $draftId, CotationMailAttachment::KIND_PDF, $this->pdfFilename(), 'application/pdf', $binary);

        if ($replaced) {
            $this->delete($replaced);
        }

        return $attachment;
    }

    public function storeUpload(User $user, string $draftId, UploadedFile $file): CotationMailAttachment
    {
        if (! $file->isValid()) {
            $this->fail('Le fichier n\'a pas pu être téléversé (transfert interrompu ou trop volumineux pour le serveur).');
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            $this->fail('Le fichier est vide.');
        }

        $maxBytes = (int) config('cotations.mail.max_file_kb') * 1024;
        if ($size > $maxBytes) {
            $this->fail(sprintf('Le fichier dépasse la taille maximale de %s par pièce jointe.', $this->formatBytes($maxBytes)));
        }

        $existingFiles = $this->forDraft($user, $draftId)->where('kind', CotationMailAttachment::KIND_FILE)->count();
        $maxFiles = (int) config('cotations.mail.max_files');
        if ($existingFiles >= $maxFiles) {
            $this->fail(sprintf('Nombre maximal de pièces jointes atteint (%d fichiers ajoutés).', $maxFiles));
        }

        $this->assertTotalWithin($user, $draftId, $size, null, 'file');

        $name = $this->safeName((string) $file->getClientOriginalName());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = (array) config('cotations.mail.allowed');

        if (! isset($allowed[$extension])) {
            $this->fail(sprintf('Type de fichier non autorisé (.%s). Types acceptés : %s.', $extension ?: '?', implode(', ', array_keys($allowed))));
        }

        // Type détecté sur le contenu (finfo), jamais le type annoncé par le navigateur.
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($detected, $allowed[$extension], true)) {
            $this->fail('Le contenu du fichier ne correspond pas à son extension.');
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || $contents === '') {
            $this->fail('Le fichier est vide ou illisible.');
        }

        return $this->persist($user, $draftId, CotationMailAttachment::KIND_FILE, $name, $detected, $contents);
    }

    /**
     * Suppression idempotente : un fichier déjà absent n'est pas une erreur.
     */
    public function delete(CotationMailAttachment $attachment): void
    {
        Storage::disk($this->disk())->delete($attachment->storagePath());
        $attachment->delete();
        $this->removeDirectoryIfEmpty($attachment->draft_id);
    }

    public function discardDraft(User $user, string $draftId): int
    {
        $attachments = $this->forDraft($user, $draftId);
        foreach ($attachments as $attachment) {
            $this->delete($attachment);
        }

        return $attachments->count();
    }

    /**
     * Purge les brouillons abandonnés : lignes expirées, puis dossiers orphelins
     * (sans ligne en base) plus anciens que la durée de conservation.
     * Idempotente : relancée, elle ne trouve plus rien à supprimer.
     */
    public function purgeExpired(): int
    {
        $cutoff = now()->subHours(max(1, (int) config('cotations.mail.expire_hours')));
        $removed = 0;

        CotationMailAttachment::query()->where('created_at', '<', $cutoff)->get()->each(function (CotationMailAttachment $attachment) use (&$removed): void {
            $this->delete($attachment);
            $removed++;
        });

        $disk = Storage::disk($this->disk());
        foreach ($disk->directories('cotation-mail') as $directory) {
            $draftId = basename($directory);
            if (CotationMailAttachment::query()->where('draft_id', $draftId)->exists()) {
                continue;
            }

            $files = $disk->files($directory);
            $isStale = collect($files)->every(fn (string $file): bool => $disk->lastModified($file) < $cutoff->getTimestamp());
            if ($isStale) {
                $disk->deleteDirectory($directory);
                $removed += count($files);
            }
        }

        return $removed;
    }

    /**
     * Nom d'affichage sûr : sans chemin, sans caractères de contrôle, sans
     * extension dangereuse (y compris en double extension), longueur bornée.
     */
    public function safeName(string $original): string
    {
        $name = trim(basename(str_replace('\\', '/', $original)));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name) ?? '';
        $name = trim($name, ". \t");

        if ($name === '' || ! str_contains($name, '.')) {
            $this->fail('Nom de fichier invalide (extension manquante).');
        }

        $segments = array_slice(explode('.', strtolower($name)), 1);
        foreach ($segments as $segment) {
            if (in_array($segment, self::DANGEROUS_EXTENSIONS, true)) {
                $this->fail('Nom de fichier refusé : il contient une extension potentiellement dangereuse.');
            }
        }

        return Str::limit($name, 120, '');
    }

    private function persist(User $user, string $draftId, string $kind, string $name, string $mime, string $contents): CotationMailAttachment
    {
        $storedName = Str::random(40);
        $attachment = new CotationMailAttachment([
            'user_id' => $user->id,
            'draft_id' => $draftId,
            'kind' => $kind,
            'original_name' => $name,
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'size_bytes' => strlen($contents),
        ]);

        Storage::disk($this->disk())->put($attachment->storagePath(), $contents);
        $attachment->save();

        return $attachment;
    }

    private function assertTotalWithin(User $user, string $draftId, int $addedBytes, ?int $ignoreId, string $field): void
    {
        $current = (int) $this->forDraft($user, $draftId)->where('id', '!=', $ignoreId)->sum('size_bytes');
        $max = (int) config('cotations.mail.max_total_kb') * 1024;

        if ($current + $addedBytes > $max) {
            $this->fail(sprintf(
                'La taille totale des pièces jointes dépasserait %s (actuellement %s).',
                $this->formatBytes($max),
                $this->formatBytes($current),
            ), $field);
        }
    }

    private function removeDirectoryIfEmpty(string $draftId): void
    {
        $disk = Storage::disk($this->disk());
        $directory = 'cotation-mail/'.$draftId;

        if ($disk->directoryExists($directory) && $disk->files($directory) === []) {
            $disk->deleteDirectory($directory);
        }
    }

    private function fail(string $message, string $field = 'file'): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',').' Mo'
            : max(1, (int) round($bytes / 1024)).' Ko';
    }
}
