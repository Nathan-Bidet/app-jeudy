<?php

namespace App\Http\Controllers;

use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Models\UserFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserFileController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        $this->authorize('attachFile', $user);

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
        $path = $uploaded->store('directory/'.$user->id, $disk);

        $userFile = UserFile::query()->create([
            'user_id' => $user->id,
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

        $this->writeAudit($request, 'directory.file.uploaded', [
            'directory_user_id' => $user->id,
            'user_file_id' => $userFile->id,
            'original_name' => $userFile->original_name,
            'display_name' => $userFile->display_name,
        ]);

        return back()->with('status', 'Fichier ajouté.');
    }

    public function preview(Request $request, User $user, UserFile $userFile): StreamedResponse
    {
        $this->assertOwnership($user, $userFile);
        $this->authorize('view', $user);

        abort_unless(Storage::disk($userFile->disk)->exists($userFile->path), HttpResponse::HTTP_NOT_FOUND);

        $headers = [];

        if ($userFile->mime_type) {
            $headers['Content-Type'] = $userFile->mime_type;
        }

        return Storage::disk($userFile->disk)->response(
            $userFile->path,
            $userFile->display_name ?: $userFile->original_name,
            $headers,
            'inline'
        );
    }

    public function download(Request $request, User $user, UserFile $userFile): StreamedResponse
    {
        $this->assertOwnership($user, $userFile);
        $this->authorize('view', $user);

        return Storage::disk($userFile->disk)->download(
            $userFile->path,
            $userFile->display_name ?: $userFile->original_name
        );
    }

    public function rename(Request $request, User $user, UserFile $userFile): RedirectResponse
    {
        $this->assertOwnership($user, $userFile);
        $this->authorize('renameFile', $userFile);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
        ]);

        $newDisplayName = trim((string) $validated['display_name']);
        $oldDisplayName = $userFile->display_name ?: $userFile->original_name;

        $userFile->update([
            'display_name' => $newDisplayName,
        ]);

        $this->writeAudit($request, 'directory.file.renamed', [
            'directory_user_id' => $user->id,
            'user_file_id' => $userFile->id,
            'old_display_name' => $oldDisplayName,
            'new_display_name' => $newDisplayName,
            'original_name' => $userFile->original_name,
        ]);

        return back()->with('status', 'Nom du fichier mis à jour.');
    }

    public function destroy(Request $request, User $user, UserFile $userFile): RedirectResponse
    {
        $this->assertOwnership($user, $userFile);
        $this->authorize('deleteFile', $userFile);

        if (Storage::disk($userFile->disk)->exists($userFile->path)) {
            Storage::disk($userFile->disk)->delete($userFile->path);
        }

        $this->writeAudit($request, 'directory.file.deleted', [
            'directory_user_id' => $user->id,
            'user_file_id' => $userFile->id,
            'original_name' => $userFile->original_name,
        ]);

        $userFile->delete();

        return back()->with('status', 'Fichier supprimé.');
    }

    private function assertOwnership(User $user, UserFile $userFile): void
    {
        abort_if((int) $userFile->user_id !== (int) $user->id, HttpResponse::HTTP_NOT_FOUND);
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
