<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Depot;
use App\Models\EntityFile;
use App\Models\Garage;
use App\Models\SecurityAuditLog;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntityFileController extends Controller
{
    public function storeVehicle(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        return $this->storeFor($request, $vehicle, 'vehicle');
    }

    public function downloadVehicle(Request $request, Vehicle $vehicle, EntityFile $entityFile): StreamedResponse
    {
        $this->authorize('view', $vehicle);

        return $this->downloadFor($vehicle, $entityFile);
    }

    public function previewVehicle(Request $request, Vehicle $vehicle, EntityFile $entityFile): StreamedResponse
    {
        $this->authorize('view', $vehicle);

        return $this->previewFor($vehicle, $entityFile);
    }

    public function destroyVehicle(Request $request, Vehicle $vehicle, EntityFile $entityFile): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        return $this->destroyFor($request, $vehicle, $entityFile, 'vehicle');
    }

    public function storeDepot(Request $request, Depot $depot): RedirectResponse
    {
        $this->authorize('update', $depot);

        return $this->storeFor($request, $depot, 'depot');
    }

    public function downloadDepot(Request $request, Depot $depot, EntityFile $entityFile): StreamedResponse
    {
        $this->authorize('view', $depot);

        return $this->downloadFor($depot, $entityFile);
    }

    public function previewDepot(Request $request, Depot $depot, EntityFile $entityFile): StreamedResponse
    {
        $this->authorize('view', $depot);

        return $this->previewFor($depot, $entityFile);
    }

    public function destroyDepot(Request $request, Depot $depot, EntityFile $entityFile): RedirectResponse
    {
        $this->authorize('update', $depot);

        return $this->destroyFor($request, $depot, $entityFile, 'depot');
    }

    public function storeGarage(Request $request, Garage $garage): RedirectResponse
    {
        $this->authorize('update', $garage);

        return $this->storeFor($request, $garage, 'garage');
    }

    public function downloadGarage(Request $request, Garage $garage, EntityFile $entityFile): StreamedResponse
    {
        $this->authorize('view', $garage);

        return $this->downloadFor($garage, $entityFile);
    }

    public function previewGarage(Request $request, Garage $garage, EntityFile $entityFile): StreamedResponse
    {
        $this->authorize('view', $garage);

        return $this->previewFor($garage, $entityFile);
    }

    public function destroyGarage(Request $request, Garage $garage, EntityFile $entityFile): RedirectResponse
    {
        $this->authorize('update', $garage);

        return $this->destroyFor($request, $garage, $entityFile, 'garage');
    }

    private function storeFor(Request $request, Model $entity, string $entityKey): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'file' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,csv',
            ],
        ]);

        $uploaded = $validated['file'];
        $disk = 'local';
        $path = $uploaded->store(sprintf('entities/%s/%s', $entityKey, $entity->getKey()), $disk);

        $file = $entity->entityFiles()->create([
            'uploaded_by_user_id' => $request->user()?->id,
            'original_name' => (string) $uploaded->getClientOriginalName(),
            'display_name' => $this->nullableTrimmedString($validated['label'] ?? null),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $uploaded->getClientMimeType(),
            'extension' => $uploaded->getClientOriginalExtension(),
            'size_bytes' => (int) $uploaded->getSize(),
            'checksum_sha256' => @hash_file('sha256', $uploaded->getRealPath()) ?: null,
            'version_group' => (string) Str::uuid(),
            'version_number' => 1,
        ]);

        $this->writeAudit($request, 'entity.file.uploaded', [
            'entity_type' => $entityKey,
            'entity_id' => $entity->getKey(),
            'entity_file_id' => $file->id,
            'original_name' => $file->original_name,
            'display_name' => $file->display_name,
        ]);

        return back()->with('status', 'Fichier ajouté.');
    }

    private function downloadFor(Model $entity, EntityFile $entityFile): StreamedResponse
    {
        $this->assertOwnership($entity, $entityFile);

        return Storage::disk($entityFile->disk)->download($entityFile->path, $entityFile->original_name);
    }

    private function previewFor(Model $entity, EntityFile $entityFile): StreamedResponse
    {
        $this->assertOwnership($entity, $entityFile);

        abort_unless(Storage::disk($entityFile->disk)->exists($entityFile->path), HttpResponse::HTTP_NOT_FOUND);

        $headers = [];

        if ($entityFile->mime_type) {
            $headers['Content-Type'] = $entityFile->mime_type;
        }

        return Storage::disk($entityFile->disk)->response(
            $entityFile->path,
            $entityFile->original_name,
            $headers,
            'inline'
        );
    }

    private function destroyFor(Request $request, Model $entity, EntityFile $entityFile, string $entityKey): RedirectResponse
    {
        $this->assertOwnership($entity, $entityFile);

        if (Storage::disk($entityFile->disk)->exists($entityFile->path)) {
            Storage::disk($entityFile->disk)->delete($entityFile->path);
        }

        $this->writeAudit($request, 'entity.file.deleted', [
            'entity_type' => $entityKey,
            'entity_id' => $entity->getKey(),
            'entity_file_id' => $entityFile->id,
            'original_name' => $entityFile->original_name,
        ]);

        $entityFile->delete();

        return back()->with('status', 'Fichier supprimé.');
    }

    private function assertOwnership(Model $entity, EntityFile $entityFile): void
    {
        abort_if(
            $entityFile->attachable_type !== $entity->getMorphClass()
            || (int) $entityFile->attachable_id !== (int) $entity->getKey(),
            HttpResponse::HTTP_NOT_FOUND
        );
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function writeAudit(Request $request, string $action, array $metadata = []): void
    {
        SecurityAuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 65535),
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
