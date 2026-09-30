<?php

use App\Http\Controllers\CotationController;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Mail\CotationInfoMail;
use App\Models\CotationMailAttachment;
use App\Models\CotationSetting;
use App\Models\Sector;
use App\Models\User;
use App\Services\Cotations\CotationMailAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pièces jointes du courriel des cotations : PDF généré à l'ouverture,
 * fichiers ajoutés, stockage privé, limites, propriété, envoi et purge.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // Disque « local » (privé) pointé vers un dossier temporaire jetable.
    $this->attachmentsRoot = sys_get_temp_dir().'/cotation-mail-tests-'.Str::random(8);
    config(['filesystems.disks.local.root' => $this->attachmentsRoot]);
    Storage::forgetDisk('local');
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory($this->attachmentsRoot);
    Storage::forgetDisk('local');
});

function attachmentUser(string $ability = 'cotations.cereals.edit'): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);
    $role = Role::findOrCreate('cotation-att-'.fake()->unique()->word(), 'web');
    $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    $user = User::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function pdfFile(string $name = 'note.pdf', int $kb = 4): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', $kb * 1024)."\n%%EOF");
}

function attachmentUrl(string $draft, string $suffix = ''): string
{
    return '/cotations/mail/'.$draft.$suffix;
}

function makeDraftWithPdf($test, User $user): array
{
    $draft = (string) Str::uuid();
    $response = $test->actingAs($user)->postJson(route('cotations.mail.pdf', $draft))->assertOk();

    return [$draft, $response->json('attachment')];
}

const ATT_BODY = '<p><span style="color: #b91c1c">Marché</span></p>';

function sendPayload(string $draft, array $ids, array $extra = []): array
{
    return array_merge([
        'to' => ['a@example.fr'],
        'subject' => 'Cotation',
        'body_html' => ATT_BODY,
        'draft_id' => $draft,
        'attachment_ids' => $ids,
    ], $extra);
}

it('expose le brouillon, ses limites et n\'écrit rien à l\'ouverture', function (): void {
    $response = $this->actingAs(attachmentUser())->getJson(route('cotations.mail-draft'))->assertOk();

    expect(Str::isUuid($response->json('draft_id')))->toBeTrue()
        ->and($response->json('limits'))->toMatchArray([
            'max_files' => 5,
            'max_file_bytes' => 5120 * 1024,
            'max_total_bytes' => 15360 * 1024,
        ])
        ->and(CotationMailAttachment::query()->count())->toBe(0);
});

it('génère le PDF nommé selon la date, sans chemin exposé, sur le disque privé', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Europe/Paris'));
    $user = attachmentUser();

    [$draft, $attachment] = makeDraftWithPdf($this, $user);

    expect($attachment)->toMatchArray(['kind' => 'pdf', 'name' => 'Cotation_du_30-09-2026.pdf', 'type' => 'application/pdf'])
        ->and($attachment['size'])->toBeGreaterThan(100)
        ->and(array_keys($attachment))->toEqualCanonicalizing(['id', 'kind', 'name', 'type', 'size']);

    $model = CotationMailAttachment::query()->findOrFail($attachment['id']);
    expect(Storage::disk('local')->exists($model->storagePath()))->toBeTrue();
    expect(Storage::disk('local')->get($model->storagePath()))->toStartWith('%PDF')
        ->and($model->stored_name)->not->toContain('Cotation')
        ->and(strlen($model->stored_name))->toBe(40)
        ->and($model->user_id)->toBe($user->id)
        ->and($model->draft_id)->toBe($draft);
});

it('joint le même PDF que l\'export (même méthode de rendu)', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Europe/Paris'));
    $user = attachmentUser();

    $export = $this->actingAs($user)->get(route('cotations.export-pdf'));
    $export->assertOk()->assertHeader('content-type', 'application/pdf');
    [, $attachment] = makeDraftWithPdf($this, $user);

    $exported = $export->getContent();
    $attached = Storage::disk('local')->get(CotationMailAttachment::query()->findOrFail($attachment['id'])->storagePath());
    // Les métadonnées (date de création, identifiant) varient : on compare le contenu rendu.
    $normalize = fn (string $pdf): string => preg_replace('#/(CreationDate|ModDate|ID)\s*(\([^)]*\)|\[[^\]]*\])#', '', $pdf);

    expect(abs(strlen($exported) - strlen($attached)))->toBeLessThan(200)
        ->and(strlen($normalize($attached)))->toBe(strlen($normalize($exported)))
        ->and(app(CotationController::class)->renderExportPdf())->toStartWith('%PDF');
});

it('supprime le PDF puis permet de le recréer', function (): void {
    $user = attachmentUser();
    [$draft, $attachment] = makeDraftWithPdf($this, $user);
    $path = CotationMailAttachment::query()->findOrFail($attachment['id'])->storagePath();

    $this->actingAs($user)->deleteJson(attachmentUrl($draft, '/attachments/'.$attachment['id']))->assertOk();
    expect(Storage::disk('local')->exists($path))->toBeFalse();
    expect(CotationMailAttachment::query()->count())->toBe(0);

    $this->actingAs($user)->postJson(route('cotations.mail.pdf', $draft))->assertOk();
    expect(CotationMailAttachment::query()->count())->toBe(1);
});

it('régénère le PDF : le nouveau remplace l\'ancien, dont le fichier est supprimé', function (): void {
    $user = attachmentUser();
    [$draft, $old] = makeDraftWithPdf($this, $user);
    $oldPath = CotationMailAttachment::query()->findOrFail($old['id'])->storagePath();

    $new = $this->actingAs($user)->postJson(route('cotations.mail.pdf', $draft), ['replaces' => $old['id']])
        ->assertOk()->json('attachment');

    expect($new['id'])->not->toBe($old['id'])
        ->and(CotationMailAttachment::query()->where('draft_id', $draft)->pluck('id')->all())->toBe([$new['id']]);
    expect(Storage::disk('local')->exists($oldPath))->toBeFalse();
});

it('conserve l\'ancien PDF quand la régénération échoue', function (): void {
    $user = attachmentUser();
    [$draft, $old] = makeDraftWithPdf($this, $user);
    $oldPath = CotationMailAttachment::query()->findOrFail($old['id'])->storagePath();

    $this->mock(CotationController::class, function ($mock): void {
        $mock->shouldReceive('renderExportPdf')->andThrow(new RuntimeException('dompdf'));
    });

    $this->actingAs($user)->postJson(route('cotations.mail.pdf', $draft), ['replaces' => $old['id']])
        ->assertStatus(500)
        ->assertJsonPath('message', 'Le PDF n\'a pas pu être généré. Réessayez dans un instant.');

    expect(Storage::disk('local')->exists($oldPath))->toBeTrue();
    expect(CotationMailAttachment::query()->where('draft_id', $draft)->count())->toBe(1);
});

it('ajoute et supprime plusieurs fichiers', function (): void {
    $user = attachmentUser();
    $draft = (string) Str::uuid();

    $ids = collect(['a.pdf', 'b.png', 'c.txt'])->map(function (string $name) use ($user, $draft): int {
        $file = $name === 'b.png' ? UploadedFile::fake()->image($name, 20, 20) : ($name === 'c.txt' ? UploadedFile::fake()->createWithContent($name, 'bonjour') : pdfFile($name));

        return $this->actingAs($user)->post(attachmentUrl($draft, '/files'), ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->json('attachment.id');
    });

    expect(CotationMailAttachment::query()->where('draft_id', $draft)->count())->toBe(3);

    $this->actingAs($user)->deleteJson(attachmentUrl($draft, '/attachments/'.$ids[1]))->assertOk();
    expect(CotationMailAttachment::query()->where('draft_id', $draft)->pluck('original_name')->all())->toBe(['a.pdf', 'c.txt']);
});

it('refuse au-delà du nombre maximal de fichiers', function (): void {
    config(['cotations.mail.max_files' => 2]);
    $user = attachmentUser();
    $draft = (string) Str::uuid();

    foreach (['a.pdf', 'b.pdf'] as $name) {
        $this->actingAs($user)->post(attachmentUrl($draft, '/files'), ['file' => pdfFile($name)], ['Accept' => 'application/json'])->assertOk();
    }

    $this->actingAs($user)->post(attachmentUrl($draft, '/files'), ['file' => pdfFile('c.pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Nombre maximal de pièces jointes atteint (2 fichiers ajoutés).');
});

it('refuse un fichier trop volumineux ou dépassant la taille totale', function (): void {
    config(['cotations.mail.max_file_kb' => 10, 'cotations.mail.max_total_kb' => 15]);
    $user = attachmentUser();
    $draft = (string) Str::uuid();

    $this->actingAs($user)->post(attachmentUrl($draft, '/files'), ['file' => pdfFile('gros.pdf', 20)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Le fichier dépasse la taille maximale de 10 Ko par pièce jointe.');

    $this->actingAs($user)->post(attachmentUrl($draft, '/files'), ['file' => pdfFile('a.pdf', 8)], ['Accept' => 'application/json'])->assertOk();
    $this->actingAs($user)->post(attachmentUrl($draft, '/files'), ['file' => pdfFile('b.pdf', 8)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', fn ($m) => str_starts_with($m, 'La taille totale des pièces jointes dépasserait'));
});

it('refuse les types, contenus et noms dangereux ou invalides', function (string $name, string $content, string $expected): void {
    $file = UploadedFile::fake()->createWithContent($name, $content);

    $this->actingAs(attachmentUser())->post(attachmentUrl((string) Str::uuid(), '/files'), ['file' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, $expected));

    expect(CotationMailAttachment::query()->count())->toBe(0);
})->with([
    'exécutable' => ['virus.exe', 'MZ....', 'extension potentiellement dangereuse'],
    'script php' => ['shell.php', '<?php echo 1;', 'extension potentiellement dangereuse'],
    'html' => ['page.html', '<script>1</script>', 'extension potentiellement dangereuse'],
    'archive' => ['dossier.zip', 'PK', 'non autorisé'],
    'double extension' => ['facture.php.pdf', "%PDF-1.4\nx", 'extension potentiellement dangereuse'],
    'double extension exe' => ['photo.exe.png', 'x', 'extension potentiellement dangereuse'],
    'sans extension' => ['README', 'texte', 'extension manquante'],
    'contenu ≠ extension' => ['faux.pdf', 'ceci est du texte, pas un PDF', 'ne correspond pas'],
    'fichier vide' => ['vide.pdf', '', 'vide'],
]);

it('neutralise les chemins dans le nom et stocke sous un nom aléatoire', function (): void {
    $user = attachmentUser();
    $draft = (string) Str::uuid();

    $attachment = $this->actingAs($user)->post(attachmentUrl($draft, '/files'), [
        'file' => UploadedFile::fake()->createWithContent('../../etc/évidence rapport.txt', 'bonjour'),
    ], ['Accept' => 'application/json'])->assertOk()->json('attachment');

    $model = CotationMailAttachment::query()->findOrFail($attachment['id']);
    expect($attachment['name'])->toBe('évidence rapport.txt')
        ->and($model->storagePath())->toStartWith('cotation-mail/'.$draft.'/')
        ->and($model->stored_name)->not->toContain('rapport');
});

it('interdit l\'accès aux fichiers d\'un autre utilisateur ou d\'un autre brouillon', function (): void {
    $owner = attachmentUser();
    $intruder = attachmentUser();
    [$draft, $attachment] = makeDraftWithPdf($this, $owner);
    $url = attachmentUrl($draft, '/attachments/'.$attachment['id']);

    $this->actingAs($intruder)->get($url)->assertNotFound();
    $this->actingAs($intruder)->deleteJson($url)->assertNotFound();
    $this->actingAs($owner)->get(attachmentUrl((string) Str::uuid(), '/attachments/'.$attachment['id']))->assertNotFound();
    $this->actingAs($intruder)->postJson(route('cotations.mail.pdf', $draft), ['replaces' => $attachment['id']])->assertOk();
    // Le PDF de l'utilisateur d'origine n'a pas été remplacé ni supprimé par la requête de l'intrus.
    expect(CotationMailAttachment::query()->where('user_id', $owner->id)->count())->toBe(1);

    Mail::fake();
    $this->actingAs($intruder)->postJson(route('cotations.send-mail'), sendPayload($draft, [$attachment['id']]))
        ->assertUnprocessable();
    Mail::assertNothingSent();

    $this->actingAs($owner)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('exige la permission d\'export sur toutes les routes de pièces jointes', function (): void {
    $viewer = attachmentUser('cotations.cereals.view');
    $draft = (string) Str::uuid();

    $this->actingAs($viewer)->postJson(route('cotations.mail.pdf', $draft))->assertForbidden();
    $this->actingAs($viewer)->post(attachmentUrl($draft, '/files'), ['file' => pdfFile()], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($viewer)->getJson(attachmentUrl($draft, '/attachments/1'))->assertForbidden();
    $this->actingAs($viewer)->deleteJson(attachmentUrl($draft))->assertForbidden();
    expect(CotationMailAttachment::query()->count())->toBe(0);
});

it('envoie avec le PDF et les fichiers ajoutés, puis supprime les fichiers temporaires', function (): void {
    Mail::fake();
    $user = attachmentUser();
    [$draft, $pdf] = makeDraftWithPdf($this, $user);
    $extra = $this->actingAs($user)->post(attachmentUrl($draft, '/files'), ['file' => pdfFile('devis.pdf')], ['Accept' => 'application/json'])
        ->json('attachment');
    $paths = CotationMailAttachment::query()->get()->map->storagePath();

    $this->actingAs($user)->postJson(route('cotations.send-mail'), sendPayload($draft, [$pdf['id'], $extra['id']]))
        ->assertOk()->assertJson(['ok' => true]);

    Mail::assertSent(CotationInfoMail::class, function (CotationInfoMail $mail) use ($pdf, $extra): bool {
        $names = collect($mail->attachments())->map(fn ($a) => $a->as)->all();

        return $names === [$pdf['name'], $extra['name']];
    });
    expect(CotationMailAttachment::query()->count())->toBe(0);
    $paths->each(fn (string $path) => expect(Storage::disk('local')->exists($path))->toBeFalse());
});

it('envoie sans PDF après sa suppression volontaire', function (): void {
    Mail::fake();
    $user = attachmentUser();
    [$draft, $pdf] = makeDraftWithPdf($this, $user);
    $this->actingAs($user)->deleteJson(attachmentUrl($draft, '/attachments/'.$pdf['id']))->assertOk();

    $this->actingAs($user)->postJson(route('cotations.send-mail'), sendPayload($draft, []))->assertOk();

    Mail::assertSent(CotationInfoMail::class, fn (CotationInfoMail $mail): bool => $mail->attachments() === []);
});

it('conserve les fichiers après un échec d\'envoi et permet une nouvelle tentative', function (): void {
    $user = attachmentUser();
    [$draft, $pdf] = makeDraftWithPdf($this, $user);
    $path = CotationMailAttachment::query()->findOrFail($pdf['id'])->storagePath();

    Mail::shouldReceive('to')->once()->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));
    $this->actingAs($user)->postJson(route('cotations.send-mail'), sendPayload($draft, [$pdf['id']]))->assertStatus(502);

    expect(Storage::disk('local')->exists($path))->toBeTrue();
    expect(CotationMailAttachment::query()->where('draft_id', $draft)->count())->toBe(1);

    Mail::swap(new \Illuminate\Mail\MailManager(app()));
    Mail::fake();
    $this->actingAs($user)->postJson(route('cotations.send-mail'), sendPayload($draft, [$pdf['id']]))->assertOk();
    Mail::assertSentCount(1);
    expect(CotationMailAttachment::query()->count())->toBe(0);
});

it('empêche le double envoi d\'un même brouillon', function (): void {
    Mail::fake();
    $user = attachmentUser();
    $draft = (string) Str::uuid();

    $this->actingAs($user)->postJson(route('cotations.send-mail'), sendPayload($draft, []))->assertOk();
    $this->actingAs($user)->postJson(route('cotations.send-mail'), sendPayload($draft, []))
        ->assertStatus(409)->assertJsonPath('message', 'Ce message a déjà été envoyé.');

    Mail::assertSentCount(1);
});

it('refuse un envoi concurrent du même brouillon', function (): void {
    Mail::fake();
    $draft = (string) Str::uuid();
    $lock = \Illuminate\Support\Facades\Cache::lock('cotation-mail-send:'.$draft, 60);
    expect($lock->get())->toBeTrue();

    $this->actingAs(attachmentUser())->postJson(route('cotations.send-mail'), sendPayload($draft, []))->assertStatus(409);

    Mail::assertNothingSent();
    $lock->release();
});

it('refuse l\'envoi si une pièce jointe a disparu ou n\'existe pas', function (): void {
    Mail::fake();
    $user = attachmentUser();
    [$draft, $pdf] = makeDraftWithPdf($this, $user);
    Storage::disk('local')->delete(CotationMailAttachment::query()->findOrFail($pdf['id'])->storagePath());

    $this->actingAs($user)->postJson(route('cotations.send-mail'), sendPayload($draft, [$pdf['id']]))
        ->assertUnprocessable();
    $this->actingAs($user)->postJson(route('cotations.send-mail'), sendPayload($draft, [999999]))
        ->assertUnprocessable();

    Mail::assertNothingSent();
});

it('supprime tout le brouillon à l\'annulation', function (): void {
    $user = attachmentUser();
    [$draft] = makeDraftWithPdf($this, $user);
    $this->actingAs($user)->post(attachmentUrl($draft, '/files'), ['file' => pdfFile()], ['Accept' => 'application/json'])->assertOk();
    $paths = CotationMailAttachment::query()->get()->map->storagePath();

    $this->actingAs($user)->deleteJson(attachmentUrl($draft))->assertOk();

    expect(CotationMailAttachment::query()->count())->toBe(0);
    $paths->each(fn (string $path) => expect(Storage::disk('local')->exists($path))->toBeFalse());
    expect(Storage::disk('local')->exists('cotation-mail/'.$draft))->toBeFalse();
});

it('purge les brouillons expirés de façon idempotente, y compris les fichiers orphelins', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Europe/Paris'));
    $user = attachmentUser();
    [$oldDraft, $old] = makeDraftWithPdf($this, $user);
    Carbon::setTestNow(Carbon::now()->addHours(5));
    [, $recent] = makeDraftWithPdf($this, $user);
    // Fichier orphelin (ligne perdue) et ancien.
    Storage::disk('local')->put('cotation-mail/'.Str::uuid().'/orphelin', 'x');
    touch(Storage::disk('local')->path(collect(Storage::disk('local')->allFiles('cotation-mail'))->first(fn ($f) => str_ends_with($f, 'orphelin'))), now()->subHours(20)->getTimestamp());
    config(['cotations.mail.expire_hours' => 6]);
    Carbon::setTestNow(Carbon::now()->addHours(2)); // ancien : 7 h ; récent : 2 h

    $this->artisan('cotations:purge-mail-attachments')->assertSuccessful();

    expect(CotationMailAttachment::query()->pluck('id')->all())->toBe([$recent['id']]);
    expect(Storage::disk('local')->exists('cotation-mail/'.$oldDraft))->toBeFalse();
    expect(collect(Storage::disk('local')->allFiles('cotation-mail'))->contains(fn ($f) => str_ends_with($f, 'orphelin')))->toBeFalse();

    // Idempotent.
    expect(app(CotationMailAttachmentService::class)->purgeExpired())->toBe(0);
    $this->artisan('cotations:purge-mail-attachments')->assertSuccessful();
});

it('planifie la purge périodique', function (): void {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'cotations:purge-mail-attachments'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

it('la suppression d\'un fichier déjà absent ne provoque pas d\'erreur', function (): void {
    $user = attachmentUser();
    [$draft, $pdf] = makeDraftWithPdf($this, $user);
    Storage::disk('local')->deleteDirectory('cotation-mail');

    $this->actingAs($user)->deleteJson(attachmentUrl($draft, '/attachments/'.$pdf['id']))->assertOk();
    $this->actingAs($user)->deleteJson(attachmentUrl($draft))->assertOk();
});

it('n\'expose plus le stockage : disque privé, aucune route publique', function (): void {
    $disks = require config_path('filesystems.php');

    expect($disks['disks']['local']['root'])->toEndWith('storage/app/private')
        ->and(config('cotations.mail.disk'))->toBe('local')
        ->and($disks['disks']['local'])->not->toHaveKey('visibility');
});
