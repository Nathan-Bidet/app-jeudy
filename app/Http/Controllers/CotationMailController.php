<?php

namespace App\Http\Controllers;

use App\Mail\CotationInfoMail;
use App\Models\CotationMailAttachment;
use App\Models\CotationSetting;
use App\Services\AuditLogService;
use App\Services\Cotations\CotationMailAttachmentService;
use App\Support\Access\AccessManager;
use App\Support\Cotations\CerealInfoSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Composition et envoi du message d'information des cotations par e-mail.
 *
 * Même permission que l'export PDF (cotations.cereals.edit), vérifiée ici en
 * plus du middleware de route. Le corps de départ vient de la source unique du
 * bloc « Information » (cotation_settings.cereal_info_html) ; le texte envoyé
 * est celui rédigé dans la modale, toujours assaini ici avec les mêmes règles
 * que l'éditeur des cotations (CerealInfoSanitizer). Le message enregistré
 * n'est jamais modifié.
 */
class CotationMailController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly CotationMailAttachmentService $attachments,
    ) {
    }

    public function draft(Request $request): JsonResponse
    {
        $this->authorizeExport($request);

        $html = $this->infoHtml();

        return response()->json([
            'subject' => $this->defaultSubject(),
            'body_html' => $html,
            'is_empty' => $this->isEmpty($html),
            // Identifiant du brouillon : rattache les pièces jointes temporaires
            // à cette rédaction (et à l'utilisateur qui la mène).
            'draft_id' => (string) Str::uuid(),
            'limits' => $this->attachments->limits(),
            // Adresse préremplie dans « À » : un destinataire ordinaire, revalidé à l'envoi.
            'default_recipient' => (string) config('cotations.mail.default_recipient', ''),
        ]);
    }

    /**
     * Génère (ou régénère) le PDF des cotations, identique à l'export PDF.
     * `replaces` : PDF précédent du brouillon, supprimé seulement si la
     * nouvelle génération réussit.
     */
    public function generatePdf(Request $request, string $draft): JsonResponse
    {
        $this->authorizeExport($request);
        $validated = $request->validate(['replaces' => ['nullable', 'integer', 'min:1']]);

        try {
            $binary = app(CotationController::class)->renderExportPdf();
        } catch (Throwable $exception) {
            Log::error('cotations.mail pdf generation failed', [
                'message' => $exception->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => 'Le PDF n\'a pas pu être généré. Réessayez dans un instant.'], 500);
        }

        $attachment = $this->attachments->storePdf($request->user(), $draft, $binary, $validated['replaces'] ?? null);

        return response()->json(['attachment' => $attachment->toClient()]);
    }

    public function upload(Request $request, string $draft): JsonResponse
    {
        $this->authorizeExport($request);
        $request->validate(['file' => ['required', 'file']], ['file.required' => 'Aucun fichier reçu.', 'file.file' => 'Le fichier n\'a pas pu être téléversé.']);

        $attachment = $this->attachments->storeUpload($request->user(), $draft, $request->file('file'));

        return response()->json(['attachment' => $attachment->toClient()]);
    }

    public function download(Request $request, string $draft, int $attachment): StreamedResponse
    {
        $this->authorizeExport($request);
        $file = $this->attachments->find($request->user(), $draft, $attachment);
        abort_unless($file, 404);

        $disk = $this->attachments->disk();
        abort_unless(Storage::disk($disk)->exists($file->storagePath()), 404);

        return Storage::disk($disk)->download($file->storagePath(), $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyAttachment(Request $request, string $draft, int $attachment): JsonResponse
    {
        $this->authorizeExport($request);
        $file = $this->attachments->find($request->user(), $draft, $attachment);
        abort_unless($file, 404);

        $this->attachments->delete($file);

        return response()->json(['ok' => true]);
    }

    /**
     * Annulation du brouillon : supprime tous ses fichiers temporaires.
     */
    public function discard(Request $request, string $draft): JsonResponse
    {
        $this->authorizeExport($request);
        $this->attachments->discardDraft($request->user(), $draft);

        return response()->json(['ok' => true]);
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeExport($request);

        $validated = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:20'],
            'to.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:100000'],
            'draft_id' => ['required', 'uuid'],
            'attachment_ids' => ['nullable', 'array', 'max:'.((int) config('cotations.mail.max_files') + 1)],
            'attachment_ids.*' => ['integer', 'min:1', 'distinct'],
        ], [
            'body_html.required' => 'Le message est vide : rédigez un message avant d\'envoyer.',
            'to.required' => 'Renseignez au moins un destinataire.',
            'to.min' => 'Renseignez au moins un destinataire.',
            'to.max' => 'Vingt destinataires maximum.',
            'to.*.email' => 'Adresse e-mail invalide : :input.',
            'to.*.distinct' => 'Adresse e-mail en double : :input.',
        ]);

        $html = CerealInfoSanitizer::sanitize($validated['body_html']);
        if ($this->isEmpty($html)) {
            return response()->json([
                'message' => 'Le message est vide : rédigez un message avant d\'envoyer.',
            ], 422);
        }

        $subject = trim((string) preg_replace('/\s+/', ' ', (string) ($validated['subject'] ?? '')));
        if ($subject === '') {
            $subject = $this->defaultSubject();
        }

        $user = $request->user();
        $recipients = array_values($validated['to']);
        $draftId = $validated['draft_id'];

        // Anti double envoi : un brouillon ne part qu'une fois, et jamais deux
        // requêtes concurrentes pour le même brouillon.
        if (Cache::has('cotation-mail-sent:'.$draftId)) {
            return response()->json(['message' => 'Ce message a déjà été envoyé.'], 409);
        }

        $lock = Cache::lock('cotation-mail-send:'.$draftId, 120);
        if (! $lock->get()) {
            return response()->json(['message' => 'Un envoi est déjà en cours pour ce message.'], 409);
        }

        try {
            $files = $this->resolveAttachments($user, $draftId, $validated['attachment_ids'] ?? []);

            try {
                Mail::to($recipients)->send(new CotationInfoMail(
                    $subject,
                    $html,
                    $user->email ?: null,
                    $files,
                    $this->attachments->disk(),
                ));
            } catch (Throwable $exception) {
                // Échec : les fichiers restent en place pour une nouvelle tentative.
                Log::warning('Cotations : envoi du message d\'information impossible', [
                    'user_id' => $user->id,
                    'recipients' => count($recipients),
                    'attachments' => count($files),
                    'error' => $exception->getMessage(),
                ]);

                return response()->json([
                    'message' => "L'e-mail n'a pas pu être envoyé. Vos pièces jointes sont conservées : réessayez dans un instant.",
                ], 502);
            }

            Cache::put('cotation-mail-sent:'.$draftId, true, now()->addHour());
            // Succès confirmé : seulement maintenant, on supprime les fichiers temporaires.
            $this->attachments->discardDraft($user, $draftId);
        } finally {
            $lock->release();
        }

        $this->auditLogService->log([
            'action' => 'send_cotation_info_mail',
            'module' => 'cotations',
            'description' => "Envoi du message d'information des cotations par e-mail",
            'payload' => [
                'subject' => $subject,
                'recipients' => $recipients,
                'attachments' => array_map(fn (CotationMailAttachment $file): string => $file->original_name, $files),
            ],
        ]);

        return response()->json(['ok' => true, 'sent' => count($recipients)]);
    }

    /**
     * Revalide chaque pièce jointe au moment de l'envoi : appartenance à
     * l'utilisateur et au brouillon, présence du fichier, limites.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, CotationMailAttachment>
     */
    private function resolveAttachments($user, string $draftId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = $this->attachments->forDraft($user, $draftId)->whereIn('id', array_map('intval', $ids))->values();
        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages(['attachment_ids' => ['Une pièce jointe n\'est plus disponible : retirez-la ou ajoutez-la de nouveau.']]);
        }

        $disk = Storage::disk($this->attachments->disk());
        $limits = $this->attachments->limits();
        $total = 0;
        $extraFiles = 0;

        foreach ($found as $file) {
            if (! $disk->exists($file->storagePath()) || $disk->size($file->storagePath()) <= 0) {
                throw ValidationException::withMessages(['attachment_ids' => ['La pièce jointe « '.$file->original_name.' » n\'est plus disponible : retirez-la ou ajoutez-la de nouveau.']]);
            }
            $total += $file->size_bytes;
            $extraFiles += $file->kind === CotationMailAttachment::KIND_FILE ? 1 : 0;
            if ($file->size_bytes > $limits['max_file_bytes'] && $file->kind === CotationMailAttachment::KIND_FILE) {
                throw ValidationException::withMessages(['attachment_ids' => ['La pièce jointe « '.$file->original_name.' » dépasse la taille maximale.']]);
            }
        }

        if ($extraFiles > $limits['max_files'] || $total > $limits['max_total_bytes']) {
            throw ValidationException::withMessages(['attachment_ids' => ['Les pièces jointes dépassent les limites autorisées.']]);
        }

        return $found->all();
    }

    private function authorizeExport(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && app(AccessManager::class)->can($user, 'cotations.cereals.edit'), 403);
    }

    private function defaultSubject(): string
    {
        return 'Cotation du '.now(config('app.timezone'))->format('d/m/Y');
    }

    private function infoHtml(): string
    {
        if (! Schema::hasTable('cotation_settings')) {
            return '';
        }

        $note = CotationSetting::query()->where('key', 'cereal_info_html')->value('note');

        return CerealInfoSanitizer::sanitize($note);
    }

    private function isEmpty(string $html): bool
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '';
    }
}
