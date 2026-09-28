<?php

namespace App\Http\Controllers;

use App\Models\Depot;
use App\Models\Sector;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class DirectoryController extends Controller
{
    /**
     * Dates de validité, dans l'ordre d'affichage. Les colonnes historiques
     * fimo_valid_until et fco_valid_until portent respectivement « CACES
     * GRUE » et « FIMO / FCO » ; le libellé suit l'usage, pas le nom de colonne.
     *
     * @var array<string, string>
     */
    private const VALIDITY_FIELDS = [
        'driving_license_valid_until' => 'Permis',
        'fco_valid_until' => 'FIMO / FCO',
        'adr_valid_until' => 'ADR',
        'eco_conduite_valid_until' => 'Éco-conduite',
        'certiphyto_valid_until' => 'Certiphyto',
        'caces_valid_until' => 'CACES',
        'fimo_valid_until' => 'CACES GRUE',
        'nacelle_valid_until' => 'Habilitation nacelle',
        'occupational_health_valid_until' => 'Médecine du travail',
        'sst_valid_until' => 'SST',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $viewer = $request->user();

        abort_unless($viewer !== null, HttpResponse::HTTP_UNAUTHORIZED);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'mobile_all' => ['nullable', 'boolean'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $isAdmin = $viewer->hasRole('admin');
        $mobileAll = $request->boolean('mobile_all');

        $query = User::query()
            ->with('sector:id,name')
            ->select([
                'id',
                'name',
                'first_name',
                'last_name',
                'email',
                'phone',
                'mobile_phone',
                'directory_phones',
                'internal_number',
                'photo_path',
                'sector_id',
            ])
            ->where('is_active', true);

        if ($isAdmin && ! empty($validated['sector_id'])) {
            $query->where('sector_id', (int) $validated['sector_id']);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $like = '%'.$search.'%';

                $builder
                    ->where('name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('mobile_phone', 'like', $like)
                    ->orWhere('directory_phones', 'like', $like)
                    ->orWhere('internal_number', 'like', $like);
            });
        }

        $mapUser = function (User $user): array {
            $displayName = trim((string) ($user->first_name.' '.$user->last_name));

            return [
                'id' => $user->id,
                'name' => $displayName !== '' ? $displayName : $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'mobile_phone' => $user->mobile_phone,
                'phones' => $this->directoryPhonesForList($user),
                'internal_number' => $user->internal_number,
                'sector_name' => $user->sector?->name,
                'photo_url' => $this->photoUrl($user->photo_path),
                'show_url' => route('directory.show', $user),
                'vcard_url' => route('directory.vcard', $user),
            ];
        };

        $orderedQuery = $query
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('name');

        if ($mobileAll) {
            $rows = $orderedQuery->get()->map($mapUser)->values();
            $count = $rows->count();

            $users = [
                'data' => $rows->all(),
                'links' => [],
                'total' => $count,
                'from' => $count > 0 ? 1 : 0,
                'to' => $count,
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $count > 0 ? $count : 1,
            ];
        } else {
            $users = $orderedQuery
                ->paginate(12)
                ->withQueryString()
                ->through($mapUser);
        }

        return Inertia::render('Directory/Index', [
            'directoryUsers' => $users,
            'filters' => [
                'search' => $search,
                'sector_id' => $isAdmin ? ($validated['sector_id'] ?? null) : null,
                'mobile_all' => $mobileAll,
            ],
            'sectors' => $isAdmin
                ? Sector::query()->orderBy('name')->get(['id', 'name'])
                : [],
            'viewer' => [
                'is_admin' => $isAdmin,
                'sector_id' => $viewer->sector_id,
                'sector_name' => $viewer->sector?->name,
            ],
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorize('view', $user);

        $user->load([
            'sector:id,name',
            'sectorManager:id,name,first_name,last_name,email',
            'depot:id,name,address_line1,address_line2,postal_code,city,country,phone,email,gps_lat,gps_lng',
            'directoryFiles' => fn ($query) => $query->latest()->with('uploader:id,name,first_name,last_name'),
        ]);

        $viewer = $request->user();
        $birthday = $user->birthday instanceof Carbon ? $user->birthday : null;
        $depotPayload = $this->depotPayload($user->depot);
        $depotAddress = $depotPayload['address_full'] ?? $this->normalizeText($user->depot_address);
        $depotGpsUrl = $depotPayload['gps_url'] ?? $this->gpsUrl($depotAddress);
        $canRenameFile = ($viewer?->hasRole('admin') ?? false)
            || ((int) ($viewer?->id ?? 0) === (int) $user->id);

        return Inertia::render('Directory/Show', [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'mobile_phone' => $user->mobile_phone,
                'phones' => $this->directoryPhonesForList($user),
                'internal_number' => $user->internal_number,
                'photo_url' => $this->photoUrl($user->photo_path),
                'sector' => [
                    'id' => $user->sector?->id,
                    'name' => $user->sector?->name,
                ],
                'job_title' => $user->job_title,
                'sector_manager' => $user->sectorManager ? [
                    'id' => $user->sectorManager->id,
                    'name' => $this->userDisplayName($user->sectorManager),
                    'url' => route('directory.show', $user->sectorManager),
                ] : null,
                'glpi_url' => $user->glpi_url,
                'glpi_href' => $this->safeExternalUrl($user->glpi_url),
                'depot' => $depotPayload,
                'depot_address' => $depotAddress,
                'gps_url' => $depotGpsUrl,
                'birthday' => [
                    'date' => $birthday?->toDateString(),
                    'formatted' => $birthday?->format('d/m/Y'),
                    'age' => $birthday?->age,
                ],
                'validities' => $this->validityRows($user),
                'equipment' => $this->equipmentRows($user),
                'actions' => [
                    'vcard_url' => route('directory.vcard', $user),
                    'tel_url' => $this->telUrl($user->mobile_phone ?: $user->phone),
                    'sms_url' => $this->smsUrl($user->mobile_phone ?: $user->phone),
                ],
            ],
            'files' => $user->directoryFiles->map(function ($file): array {
                $uploaderName = trim((string) (($file->uploader?->first_name ?? '').' '.($file->uploader?->last_name ?? '')));
                $displayName = $file->display_name ?: $file->original_name;

                return [
                    'id' => $file->id,
                    'name' => $displayName,
                    'display_name' => $file->display_name,
                    'original_name' => $file->original_name,
                    'size_bytes' => (int) $file->size_bytes,
                    'mime_type' => $file->mime_type,
                    'extension' => $file->extension,
                    'created_at' => $file->created_at?->toIso8601String(),
                    'created_at_label' => $file->created_at?->format('d/m/Y H:i'),
                    'uploader' => $uploaderName !== '' ? $uploaderName : ($file->uploader?->name ?? 'Inconnu'),
                    'preview_url' => route('directory.files.preview', ['user' => $file->user_id, 'userFile' => $file->id]),
                    'download_url' => route('directory.files.download', ['user' => $file->user_id, 'userFile' => $file->id]),
                    'rename_url' => route('directory.files.rename', ['user' => $file->user_id, 'userFile' => $file->id]),
                    'delete_url' => route('directory.files.destroy', ['user' => $file->user_id, 'userFile' => $file->id]),
                ];
            })->values()->all(),
            'permissions' => [
                'can_attach_file' => $viewer?->can('attachFile', $user) ?? false,
                'can_delete_file' => $viewer?->hasRole('admin') ?? false,
                'can_rename_file' => $canRenameFile,
                'can_update' => $viewer?->can('update', $user) ?? false,
            ],
            'routes' => [
                'index' => route('directory.index'),
                'upload' => route('directory.files.store', $user),
                'edit' => route('directory.edit', $user),
            ],
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        $viewer = $request->user();
        $isAdmin = (bool) $viewer?->hasRole('admin');
        $canManageFields = (bool) $viewer?->can('updateManagedFields', $user);

        $user->loadMissing('sector:id,name', 'depot:id,name', 'sectorManager:id,name,first_name,last_name');

        return Inertia::render('Directory/Edit', [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'mobile_phone' => $user->mobile_phone,
                'directory_phones' => $this->directoryPhones($user),
                'internal_number' => $user->internal_number,
                'photo_url' => $this->photoUrl($user->photo_path),
                'photo_path' => $user->photo_path,
                'sector_id' => $user->sector_id,
                'sector_name' => $user->sector?->name,
                'depot_id' => $user->depot_id,
                'depot_name' => $user->depot?->name,
                'glpi_url' => $user->glpi_url,
                'birthday' => $user->birthday?->toDateString(),
                'driving_license_valid_until' => $user->driving_license_valid_until?->toDateString(),
                'fimo_valid_until' => $user->fimo_valid_until?->toDateString(),
                'adr_valid_until' => $user->adr_valid_until?->toDateString(),
                'fco_valid_until' => $user->fco_valid_until?->toDateString(),
                'caces_valid_until' => $user->caces_valid_until?->toDateString(),
                'certiphyto_valid_until' => $user->certiphyto_valid_until?->toDateString(),
                'nacelle_valid_until' => $user->nacelle_valid_until?->toDateString(),
                'eco_conduite_valid_until' => $user->eco_conduite_valid_until?->toDateString(),
                'occupational_health_valid_until' => $user->occupational_health_valid_until?->toDateString(),
                'sst_valid_until' => $user->sst_valid_until?->toDateString(),
                'job_title' => $user->job_title,
                'sector_manager_id' => $user->sector_manager_id,
                'sector_manager' => $this->userDisplayName($user->sectorManager),
            ],
            'sectors' => Sector::query()->orderBy('name')->get(['id', 'name']),
            'depots' => Depot::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'managers' => $canManageFields ? $this->sectorManagerOptions($user) : [],
            'permissions' => [
                'can_manage_all_fields' => $isAdmin,
                'can_manage_directory_fields' => $canManageFields,
            ],
            'routes' => [
                'show' => route('directory.show', $user),
                'update' => route('directory.update', $user),
            ],
            'field_access' => [
                'identity_contact' => [
                    'first_name' => $isAdmin,
                    'last_name' => $isAdmin,
                    'email' => $isAdmin,
                    'phone' => true,
                    'mobile_phone' => true,
                    'internal_number' => true,
                ],
                'organization' => [
                    'sector_id' => $canManageFields,
                    'depot_id' => $canManageFields,
                    'job_title' => $canManageFields,
                    'sector_manager_id' => $canManageFields,
                ],
                'validities' => $canManageFields,
                'links' => [
                    'glpi_url' => $canManageFields,
                ],
                'photo' => true,
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $actor = $request->user();
        $isAdmin = (bool) $actor?->hasRole('admin');
        $canManageFields = (bool) $actor?->can('updateManagedFields', $user);

        // Coordonnées : toute personne autorisée à éditer la fiche.
        $rules = [
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile_phone' => ['nullable', 'string', 'max:50'],
            'internal_number' => ['nullable', 'string', 'max:50'],
            'directory_phones' => ['nullable', 'array', 'max:10'],
            'directory_phones.*.label' => ['nullable', 'string', 'max:40'],
            'directory_phones.*.number' => ['nullable', 'string', 'max:50'],
            'birthday' => ['nullable', 'date'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
        $messages = [];

        // Organisation, dates de validité, liens : permission directory.update.
        if ($canManageFields) {
            $rules = array_merge($rules, [
                'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
                'depot_id' => ['nullable', 'integer', 'exists:depots,id'],
                'job_title' => ['nullable', 'string', 'max:120'],
                'sector_manager_id' => [
                    'nullable',
                    'integer',
                    Rule::notIn([$user->id]),
                    // Un utilisateur actif, ou le responsable déjà enregistré même
                    // s'il a été désactivé depuis (la fiche reste enregistrable).
                    Rule::exists('users', 'id')->where(function ($query) use ($user): void {
                        $query->where('is_active', true);

                        if ($user->sector_manager_id) {
                            $query->orWhere('id', $user->sector_manager_id);
                        }
                    }),
                ],
                'glpi_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            ]);

            foreach (self::VALIDITY_FIELDS as $field => $label) {
                $rules[$field] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'];
                $messages[$field.'.date_format'] = "La date « {$label} » est invalide.";
                $messages[$field.'.after_or_equal'] = "La date « {$label} » est invalide.";
                $messages[$field.'.before_or_equal'] = "La date « {$label} » est invalide.";
            }

            $messages += [
                'sector_id.exists' => 'Le secteur sélectionné est introuvable.',
                'depot_id.exists' => 'Le dépôt sélectionné est introuvable.',
                'sector_manager_id.exists' => 'Le responsable sélectionné est introuvable ou inactif.',
                'sector_manager_id.not_in' => 'Une personne ne peut pas être son propre responsable.',
                'job_title.max' => 'Le poste ne peut pas dépasser 120 caractères.',
                'glpi_url.url' => 'Le lien GLPI doit être une adresse http:// ou https:// valide.',
                'glpi_url.max' => 'Le lien GLPI est trop long.',
            ];
        }

        // Identité : administrateurs uniquement.
        if ($isAdmin) {
            $rules = array_merge($rules, [
                'first_name' => ['nullable', 'string', 'max:120'],
                'last_name' => ['nullable', 'string', 'max:120'],
                'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user->id)],
            ]);
        }

        $validated = $request->validate($rules, $messages);

        // Seuls les champs validés ci-dessus peuvent être écrits ; les clés
        // absentes de la requête laissent la valeur existante intacte.
        $fillable = ['phone', 'mobile_phone', 'directory_phones', 'internal_number', 'birthday'];

        if ($canManageFields) {
            $fillable = array_merge(
                $fillable,
                ['sector_id', 'depot_id', 'job_title', 'sector_manager_id', 'glpi_url'],
                array_keys(self::VALIDITY_FIELDS)
            );
        }

        if ($isAdmin) {
            $fillable = array_merge($fillable, ['first_name', 'last_name', 'email']);
        }

        $payload = [];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('directory_phones', $payload)) {
            $payload['directory_phones'] = $this->normalizeDirectoryPhones($payload['directory_phones']);
        }

        if (array_key_exists('job_title', $payload)) {
            $payload['job_title'] = $this->normalizeText($payload['job_title']);
        }

        if (array_key_exists('depot_id', $payload)) {
            $selectedDepot = null;

            if (! empty($payload['depot_id'])) {
                $selectedDepot = Depot::query()
                    ->select(['id', 'address_line1', 'address_line2', 'postal_code', 'city', 'country'])
                    ->find((int) $payload['depot_id']);
            }

            // Keep legacy text field in sync for existing modules still using depot_address.
            $payload['depot_address'] = $this->depotAddressFromDepot($selectedDepot);
        }

        $oldManagedPhoto = null;

        if ($request->hasFile('photo')) {
            $oldManagedPhoto = $this->managedPhotoPath($user->photo_path);
            $payload['photo_path'] = $request->file('photo')->store('user-photos', 'public');
        }

        if ($isAdmin && (array_key_exists('first_name', $payload) || array_key_exists('last_name', $payload))) {
            $firstName = array_key_exists('first_name', $payload) ? (string) ($payload['first_name'] ?? '') : (string) ($user->first_name ?? '');
            $lastName = array_key_exists('last_name', $payload) ? (string) ($payload['last_name'] ?? '') : (string) ($user->last_name ?? '');
            $fullName = trim(preg_replace('/\s+/', ' ', $firstName.' '.$lastName) ?? '');
            $payload['name'] = $fullName !== '' ? $fullName : ($user->name ?: ($payload['email'] ?? $user->email));
        }

        $user->fill($payload);
        $changes = $this->auditableChanges($user);
        $user->save();

        if ($oldManagedPhoto && $user->photo_path !== $oldManagedPhoto) {
            Storage::disk('public')->delete($oldManagedPhoto);
        }

        if ($changes !== []) {
            app(AuditLogService::class)->log([
                'action' => 'update_directory_entry',
                'module' => 'directory',
                'description' => sprintf(
                    '%s a modifié la fiche annuaire de %s',
                    $this->userDisplayName($actor) ?? 'Utilisateur inconnu',
                    $this->userDisplayName($user) ?? '#'.$user->id
                ),
                'payload' => [
                    'target_user_id' => (int) $user->id,
                    'changes' => $changes,
                ],
            ]);
        }

        return redirect()
            ->route('directory.show', $user)
            ->with('status', 'Fiche enregistrée.');
    }

    public function vcard(Request $request, User $user): HttpResponse
    {
        $this->authorize('view', $user);
        $user->loadMissing('depot:id,address_line1,address_line2,postal_code,city,country');

        $fullName = trim((string) (($user->first_name ?? '').' '.($user->last_name ?? '')));
        $fullName = $fullName !== '' ? $fullName : $user->name;

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:'.$this->escapeVcard($fullName),
            'N:'.$this->escapeVcard((string) ($user->last_name ?? '')).';'.$this->escapeVcard((string) ($user->first_name ?? '')).';;;',
            'EMAIL;TYPE=INTERNET:'.$this->escapeVcard((string) $user->email),
        ];

        if (! empty($user->phone)) {
            $lines[] = 'TEL;TYPE=CELL:'.$this->escapeVcard((string) $user->phone);
        }

        if (! empty($user->mobile_phone)) {
            $lines[] = 'TEL;TYPE=CELL:'.$this->escapeVcard((string) $user->mobile_phone);
        }

        foreach ($this->directoryPhones($user) as $phone) {
            if (empty($phone['number'])) {
                continue;
            }

            $label = strtoupper((string) ($phone['label'] ?? 'OTHER'));
            $lines[] = 'TEL;TYPE='.$this->escapeVcard($label).':'.$this->escapeVcard((string) $phone['number']);
        }

        if (! empty($user->internal_number)) {
            $lines[] = 'TEL;TYPE=WORK,VOICE:'.$this->escapeVcard((string) $user->internal_number);
        }

        $depotAddress = $this->depotAddressFromDepot($user->depot) ?? $this->normalizeText($user->depot_address);

        if ($depotAddress !== null) {
            $lines[] = 'ADR;TYPE=WORK:;;'.$this->escapeVcard($depotAddress).';;;;';
        }

        if (! empty($user->glpi_url)) {
            $lines[] = 'URL:'.$this->escapeVcard((string) $user->glpi_url);
        }

        $lines[] = 'END:VCARD';

        $content = implode("\r\n", $lines)."\r\n";
        $filename = Str::slug($fullName !== '' ? $fullName : 'contact').'.vcf';

        return response($content, 200, [
            'Content-Type' => 'text/vcard; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function validityRows(User $user): array
    {
        $rows = [];

        foreach (self::VALIDITY_FIELDS as $field => $label) {
            $rows[] = $this->validityRow($label, $user->{$field});
        }

        return $rows;
    }

    private function userDisplayName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $fullName = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $fullName !== '' ? $fullName : ($this->normalizeText($user->name) ?? $user->email);
    }

    /**
     * Responsables possibles : utilisateurs actifs, hors la personne de la
     * fiche, plus le responsable actuel s'il a été désactivé depuis.
     *
     * @return array<int, array{id:int, name:string, is_active:bool}>
     */
    private function sectorManagerOptions(User $target): array
    {
        return User::query()
            ->select(['id', 'name', 'first_name', 'last_name', 'email', 'is_active'])
            ->where('id', '!=', $target->id)
            ->where(function ($query) use ($target): void {
                $query->where('is_active', true);

                if ($target->sector_manager_id) {
                    $query->orWhere('id', $target->sector_manager_id);
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $candidate): array => [
                'id' => (int) $candidate->id,
                'name' => (string) $this->userDisplayName($candidate),
                'is_active' => (bool) $candidate->is_active,
            ])
            ->all();
    }

    /**
     * Anciennes et nouvelles valeurs des attributs modifiés, pour le journal.
     *
     * @return array<string, array{old:mixed, new:mixed}>
     */
    private function auditableChanges(User $user): array
    {
        $changes = [];

        foreach (array_keys($user->getDirty()) as $field) {
            $changes[$field] = [
                'old' => $user->getRawOriginal($field),
                'new' => $user->getAttributes()[$field] ?? null,
            ];
        }

        return $changes;
    }

    /**
     * Seuls les liens http(s) sont rendus cliquables, pour neutraliser toute
     * valeur héritée de type javascript: ou data:.
     */
    private function safeExternalUrl(?string $url): ?string
    {
        $candidate = $this->normalizeText($url);

        if ($candidate === null || ! preg_match('#^https?://#i', $candidate)) {
            return null;
        }

        return filter_var($candidate, FILTER_VALIDATE_URL) !== false ? $candidate : null;
    }

    private function validityRow(string $label, mixed $value): array
    {
        $date = $value instanceof Carbon ? $value : null;

        return [
            'label' => $label,
            'date' => $date?->toDateString(),
            'formatted' => $date?->format('d/m/Y'),
        ];
    }

    /**
     * @return array<int, array{label:string,value:string}>
     */
    private function equipmentRows(User $user): array
    {
        $assignedVehicles = Vehicle::query()
            ->with([
                'type:id,label,code',
                'bennes:id,name,registration,vehicle_type_id',
                'bennes.type:id,label,code',
            ])
            ->where(function ($query) use ($user): void {
                $query
                    ->where('driver_user_id', $user->id)
                    ->orWhere('driver_carb_user_id', $user->id);
            })
            ->orderBy('name')
            ->orderBy('registration')
            ->get(['id', 'name', 'registration', 'vehicle_type_id', 'driver_user_id', 'driver_carb_user_id']);

        $vehicles = [];
        $bennes = [];

        foreach ($assignedVehicles as $vehicle) {
            $label = $this->vehicleLabel($vehicle);

            if ($this->isBenneType($vehicle)) {
                $bennes[$vehicle->id] = $label;
            } elseif ($this->isVehicleType($vehicle)) {
                $vehicles[$vehicle->id] = $label;
            }

            foreach ($vehicle->bennes as $benne) {
                if ($this->isBenneType($benne)) {
                    $bennes[$benne->id] = $this->vehicleLabel($benne);
                }
            }
        }

        return [
            [
                'label' => 'Véhicules',
                'value' => $this->listOrFallback(array_values($vehicles), 'Aucun véhicule attribué'),
            ],
            [
                'label' => 'Bennes',
                'value' => $this->listOrFallback(array_values($bennes), 'Aucune benne attribuée'),
            ],
        ];
    }

    private function vehicleLabel(Vehicle $vehicle): string
    {
        $name = trim((string) $vehicle->name);
        $registration = trim((string) $vehicle->registration);
        $typeLabel = trim((string) ($vehicle->type?->label ?? ''));
        $base = $name !== '' ? $name : ($registration !== '' ? $registration : 'Véhicule #'.$vehicle->id);

        if ($name !== '' && $registration !== '') {
            $base .= ' - '.$registration;
        }

        if ($typeLabel !== '') {
            $base .= ' ('.$typeLabel.')';
        }

        return $base;
    }

    private function isVehicleType(Vehicle $vehicle): bool
    {
        $code = $this->normalizeVehicleType((string) ($vehicle->type?->code ?? ''));
        $label = $this->normalizeVehicleType((string) ($vehicle->type?->label ?? ''));

        return in_array($code, ['porteur', 'tracteur', 'vl'], true)
            || in_array($label, ['porteur', 'tracteur', 'vl'], true);
    }

    private function isBenneType(Vehicle $vehicle): bool
    {
        $code = $this->normalizeVehicleType((string) ($vehicle->type?->code ?? ''));
        $label = $this->normalizeVehicleType((string) ($vehicle->type?->label ?? ''));

        return in_array($code, ['remorque', 'remorques', 'semi-remorque', 'semi-remorques', 'semiremorque', 'semiremorques'], true)
            || in_array($label, ['remorque', 'remorques', 'semi-remorque', 'semi-remorques', 'semiremorque', 'semiremorques'], true);
    }

    private function normalizeVehicleType(string $value): string
    {
        $normalized = trim(mb_strtolower($value));
        $normalized = str_replace(['é', 'è', 'ê', 'ë'], 'e', $normalized);
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * @param  array<int,string>  $items
     */
    private function listOrFallback(array $items, string $fallback): string
    {
        $clean = array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items), static fn ($item) => $item !== ''));

        return $clean === [] ? $fallback : implode(' • ', $clean);
    }

    /**
     * @return array{id:int,name:string,address_line1:?string,address_line2:?string,postal_code:?string,city:?string,country:?string,phone:?string,email:?string,address_full:?string,gps_url:?string,map_query:?string}|null
     */
    private function depotPayload(?Depot $depot): ?array
    {
        if (! $depot) {
            return null;
        }

        $address = $this->depotAddressFromDepot($depot);
        $gpsUrl = $this->depotGpsUrl($depot) ?? $this->gpsUrl($address);
        $mapQuery = $depot->gps_lat !== null && $depot->gps_lng !== null
            ? $depot->gps_lat.','.$depot->gps_lng
            : ($address ?? $this->normalizeText($depot->name));

        return [
            'id' => (int) $depot->id,
            'name' => (string) $depot->name,
            'address_line1' => $this->normalizeText($depot->address_line1),
            'address_line2' => $this->normalizeText($depot->address_line2),
            'postal_code' => $this->normalizeText($depot->postal_code),
            'city' => $this->normalizeText($depot->city),
            'country' => $this->normalizeText($depot->country),
            'phone' => $this->normalizeText($depot->phone),
            'email' => $this->normalizeText($depot->email),
            'address_full' => $address,
            'gps_url' => $gpsUrl,
            'map_query' => $mapQuery,
        ];
    }

    private function depotAddressFromDepot(?Depot $depot): ?string
    {
        if (! $depot) {
            return null;
        }

        $parts = array_filter([
            $this->normalizeText($depot->address_line1),
            $this->normalizeText($depot->address_line2),
            $this->normalizeText(trim((string) (($depot->postal_code ?? '').' '.($depot->city ?? '')))),
            $this->normalizeText($depot->country),
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function depotGpsUrl(Depot $depot): ?string
    {
        if ($depot->gps_lat !== null && $depot->gps_lng !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='.$depot->gps_lat.','.$depot->gps_lng;
        }

        return null;
    }

    private function normalizeText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function gpsUrl(?string $address): ?string
    {
        if (! $address) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.urlencode($address);
    }

    private function telUrl(?string $phone): ?string
    {
        $normalized = preg_replace('/[^0-9+]/', '', (string) $phone);

        return $normalized ? 'tel:'.$normalized : null;
    }

    private function smsUrl(?string $phone): ?string
    {
        $normalized = preg_replace('/[^0-9+]/', '', (string) $phone);

        return $normalized ? 'sms:'.$normalized : null;
    }

    private function escapeVcard(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\;', '\,', '\n', ''], $value);
    }

    private function photoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return '/storage/'.$path;
    }

    private function managedPhotoPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return null;
        }

        return $path;
    }

    /**
     * @return array<int, array{label:string,number:string}>
     */
    private function normalizeDirectoryPhones(mixed $phones): array
    {
        if (! is_array($phones)) {
            return [];
        }

        $rows = [];

        foreach ($phones as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $number = trim((string) ($row['number'] ?? ''));

            if ($label === '' && $number === '') {
                continue;
            }

            if ($number === '') {
                continue;
            }

            $rows[] = [
                'label' => $label !== '' ? $label : 'Téléphone',
                'number' => $number,
            ];
        }

        return array_values(array_slice($rows, 0, 10));
    }

    /**
     * @return array<int, array{label:string,number:string}>
     */
    private function directoryPhones(User $user): array
    {
        return $this->normalizeDirectoryPhones($user->directory_phones);
    }

    /**
     * @return array<int, array{label:string,number:string,href:?string}>
     */
    private function directoryPhonesForList(User $user): array
    {
        $phones = [];

        if (! empty($user->phone)) {
            $phones[] = [
                'label' => 'Téléphone',
                'number' => (string) $user->phone,
                'href' => $this->telUrl($user->phone),
            ];
        }

        if (! empty($user->mobile_phone)) {
            $phones[] = [
                'label' => 'Mobile',
                'number' => (string) $user->mobile_phone,
                'href' => $this->telUrl($user->mobile_phone),
            ];
        }

        foreach ($this->directoryPhones($user) as $phone) {
            if ($phone['number'] === '') {
                continue;
            }

            $phones[] = [
                'label' => $phone['label'],
                'number' => $phone['number'],
                'href' => $this->telUrl($phone['number']),
            ];
        }

        return $phones;
    }
}
