<?php

namespace App\Http\Controllers;

use App\Mail\CotationInfoMail;
use App\Models\CotationSetting;
use App\Services\AuditLogService;
use App\Support\Access\AccessManager;
use App\Support\Cotations\CerealInfoSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
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
    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function draft(Request $request): JsonResponse
    {
        $this->authorizeExport($request);

        $html = $this->infoHtml();

        return response()->json([
            'subject' => $this->defaultSubject(),
            'body_html' => $html,
            'is_empty' => $this->isEmpty($html),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeExport($request);

        $validated = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:20'],
            'to.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:100000'],
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

        try {
            Mail::to($recipients)->send(new CotationInfoMail($subject, $html, $user->email ?: null));
        } catch (Throwable $exception) {
            Log::warning('Cotations : envoi du message d\'information impossible', [
                'user_id' => $user->id,
                'recipients' => count($recipients),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => "L'e-mail n'a pas pu être envoyé. Réessayez dans un instant.",
            ], 502);
        }

        $this->auditLogService->log([
            'action' => 'send_cotation_info_mail',
            'module' => 'cotations',
            'description' => "Envoi du message d'information des cotations par e-mail",
            'payload' => ['subject' => $subject, 'recipients' => $recipients],
        ]);

        return response()->json(['ok' => true, 'sent' => count($recipients)]);
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
