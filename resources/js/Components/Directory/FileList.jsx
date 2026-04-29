import Modal from '@/Components/Modal';
import { router, useForm } from '@inertiajs/react';
import { Download, Eye, File as FileIcon, FileImage, FileSpreadsheet, FileText, Pencil, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { createPortal } from 'react-dom';

function formatBytes(bytes) {
    const size = Number(bytes || 0);

    if (size < 1024) return `${size} o`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} Ko`;
    return `${(size / (1024 * 1024)).toFixed(1)} Mo`;
}

export default function FileList({ files = [], canDelete = false, canRename = false }) {
    const [fileToDelete, setFileToDelete] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [fileToPreview, setFileToPreview] = useState(null);
    const [fileToRename, setFileToRename] = useState(null);
    const [isRenaming, setIsRenaming] = useState(false);
    const renameForm = useForm({
        display_name: '',
    });

    const isPreviewable = (file) => {
        const mime = String(file?.mime_type || '').toLowerCase();
        const ext = String(file?.extension || '').toLowerCase();

        return (
            mime.startsWith('image/')
            || mime === 'application/pdf'
            || ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf'].includes(ext)
        );
    };

    const previewKind = (file) => {
        const mime = String(file?.mime_type || '').toLowerCase();
        const ext = String(file?.extension || '').toLowerCase();

        if (mime.startsWith('image/') || ['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext)) {
            return 'image';
        }

        return 'document';
    };

    const fileCategory = (file) => {
        const mime = String(file?.mime_type || '').toLowerCase();
        const ext = String(file?.extension || '').toLowerCase();

        if (mime.startsWith('image/') || ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'].includes(ext)) {
            return 'image';
        }

        if (mime === 'application/pdf' || ext === 'pdf') {
            return 'pdf';
        }

        if (
            mime.includes('spreadsheet')
            || ['xls', 'xlsx', 'csv', 'ods'].includes(ext)
        ) {
            return 'spreadsheet';
        }

        if (
            mime.includes('msword')
            || mime.includes('wordprocessingml')
            || ['doc', 'docx', 'odt', 'rtf', 'txt'].includes(ext)
        ) {
            return 'document';
        }

        return 'other';
    };

    const fileIcon = (file) => {
        const category = fileCategory(file);

        if (category === 'image') {
            return FileImage;
        }

        if (category === 'pdf' || category === 'document') {
            return FileText;
        }

        if (category === 'spreadsheet') {
            return FileSpreadsheet;
        }

        return FileIcon;
    };

    const handleDelete = (file) => {
        if (!canDelete) return;
        setFileToDelete(file);
    };

    const closeDeleteModal = () => {
        if (isDeleting) return;
        setFileToDelete(null);
    };

    const confirmDelete = () => {
        if (!fileToDelete || isDeleting) return;

        router.delete(fileToDelete.delete_url, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setIsDeleting(true),
            onFinish: () => setIsDeleting(false),
            onSuccess: () => setFileToDelete(null),
        });
    };

    const openRenameModal = (file) => {
        if (!canRename || !file?.rename_url) return;
        setFileToRename(file);
        renameForm.setData('display_name', file.display_name || file.name || '');
        renameForm.clearErrors();
    };

    const closeRenameModal = () => {
        if (isRenaming) return;
        setFileToRename(null);
        renameForm.reset();
        renameForm.clearErrors();
    };

    const confirmRename = (event) => {
        event.preventDefault();

        if (!fileToRename?.rename_url || isRenaming) return;

        const trimmedDisplayName = String(renameForm.data.display_name || '').trim();
        renameForm.clearErrors();

        if (!trimmedDisplayName) {
            renameForm.setError('display_name', 'Le nom du fichier est requis.');
            return;
        }

        router.put(
            fileToRename.rename_url,
            { display_name: trimmedDisplayName },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setIsRenaming(true),
                onFinish: () => setIsRenaming(false),
                onError: (errors) => {
                    renameForm.setError(errors || {});
                },
                onSuccess: () => {
                    closeRenameModal();
                },
            }
        );
    };

    return (
        <>
            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                <div className="flex items-center justify-between gap-2">
                    <h2 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        Pièces jointes
                    </h2>
                    <span className="text-xs text-[var(--app-muted)]">{files.length} fichier(s)</span>
                </div>

                {files.length === 0 ? (
                    <p className="mt-4 rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-4 text-sm text-[var(--app-muted)]">
                        Aucun fichier attaché.
                    </p>
                ) : (
                    <div className="mt-4 space-y-2">
                        {files.map((file) => (
                            <div
                                key={file.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex min-w-0 items-start gap-2">
                                        {(() => {
                                            const Icon = fileIcon(file);

                                            return (
                                                <span className="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)]">
                                                    <Icon className="h-3.5 w-3.5" strokeWidth={2.1} />
                                                </span>
                                            );
                                        })()}
                                        {file.preview_url && isPreviewable(file) ? (
                                            <button
                                                type="button"
                                                onClick={() => setFileToPreview(file)}
                                                className="truncate text-left text-sm font-semibold text-[var(--app-text)] hover:underline"
                                            >
                                                {file.name}
                                            </button>
                                        ) : (
                                            <a
                                                href={file.download_url}
                                                className="truncate text-sm font-semibold text-[var(--app-text)] hover:underline"
                                            >
                                                {file.name}
                                            </a>
                                        )}
                                    </div>
                                    {file.original_name && file.original_name !== file.name ? (
                                        <p className="mt-1 truncate text-xs text-[var(--app-muted)]">
                                            Fichier: {file.original_name}
                                        </p>
                                    ) : null}
                                    <p className="mt-1 text-xs text-[var(--app-muted)]">
                                        {formatBytes(file.size_bytes)} • {file.created_at_label || 'Date inconnue'} •
                                        {' '}Ajouté par {file.uploader}
                                    </p>
                                </div>

                                <div className="flex w-full flex-wrap items-center justify-start gap-2 sm:w-auto sm:flex-nowrap sm:justify-end">
                                    {file.preview_url && isPreviewable(file) ? (
                                        <button
                                            type="button"
                                            onClick={() => setFileToPreview(file)}
                                            className="shrink-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)]"
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                <Eye className="h-3 w-3" strokeWidth={2.2} />
                                                <span>Voir</span>
                                            </span>
                                        </button>
                                    ) : null}
                                    <a
                                        href={file.download_url}
                                        className="shrink-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)]"
                                    >
                                        <span className="inline-flex items-center gap-1">
                                            <Download className="h-3 w-3" strokeWidth={2.2} />
                                            <span>Télécharger</span>
                                        </span>
                                    </a>

                                    {canRename && file.rename_url ? (
                                        <button
                                            type="button"
                                            onClick={() => openRenameModal(file)}
                                            className="shrink-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)]"
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                <Pencil className="h-3 w-3" strokeWidth={2.2} />
                                                <span>Renommer</span>
                                            </span>
                                        </button>
                                    ) : null}

                                    {canDelete ? (
                                        <button
                                            type="button"
                                            onClick={() => handleDelete(file)}
                                            className="shrink-0 rounded-lg border border-red-600 bg-red-600 px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-white transition hover:bg-red-500"
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                <Trash2 className="h-3 w-3" strokeWidth={2.2} />
                                                <span>Supprimer</span>
                                            </span>
                                        </button>
                                    ) : null}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            <Modal show={Boolean(fileToDelete)} onClose={closeDeleteModal} maxWidth="md">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <p className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        Confirmer la suppression
                    </p>
                </div>

                <div className="bg-[var(--app-surface)] px-5 py-4">
                    <p className="text-sm text-[var(--app-text)]">
                        Voulez-vous supprimer ce fichier ?
                    </p>
                    <p className="mt-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm font-semibold text-[var(--app-text)]">
                        {fileToDelete?.name}
                    </p>
                    <p className="mt-3 text-xs text-[var(--app-muted)]">
                        Cette action est définitive.
                    </p>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={closeDeleteModal}
                        disabled={isDeleting}
                        className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)] disabled:opacity-60"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        onClick={confirmDelete}
                        disabled={isDeleting}
                        className="rounded-lg border border-red-600 bg-red-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-red-500 disabled:opacity-60"
                    >
                        {isDeleting ? (
                            'Suppression...'
                        ) : (
                            <span className="inline-flex items-center gap-1">
                                <Trash2 className="h-3 w-3" strokeWidth={2.2} />
                                <span>Supprimer</span>
                            </span>
                        )}
                    </button>
                </div>
            </Modal>

            <Modal show={Boolean(fileToRename)} onClose={closeRenameModal} maxWidth="md">
                <form onSubmit={confirmRename}>
                    <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                        <p className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                            Renommer le fichier
                        </p>
                    </div>

                    <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4">
                        <p className="text-xs text-[var(--app-muted)]">
                            Nom actuel: <span className="font-semibold text-[var(--app-text)]">{fileToRename?.name}</span>
                        </p>
                        <div className="space-y-1">
                            <label className="block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Nouveau nom
                            </label>
                            <input
                                type="text"
                                value={renameForm.data.display_name}
                                onChange={(event) => renameForm.setData('display_name', event.target.value)}
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm text-[var(--app-text)] placeholder:text-[var(--app-muted)]"
                                placeholder="Ex: Carte grise tracteur"
                                autoFocus
                            />
                            {renameForm.errors.display_name ? (
                                <p className="text-xs text-red-600">{renameForm.errors.display_name}</p>
                            ) : null}
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                        <button
                            type="button"
                            onClick={closeRenameModal}
                            disabled={isRenaming}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)] disabled:opacity-60"
                        >
                            Annuler
                        </button>
                        <button
                            type="submit"
                            disabled={isRenaming}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] transition hover:brightness-95 disabled:opacity-60"
                        >
                            {isRenaming ? 'Enregistrement...' : 'Valider'}
                        </button>
                    </div>
                </form>
            </Modal>

            {typeof document !== 'undefined' && fileToPreview
                ? createPortal(
                    <div className="fixed inset-0 z-[70] flex items-center justify-center p-2 sm:p-4">
                    <button
                        type="button"
                        aria-label="Fermer l'aperçu"
                        onClick={() => setFileToPreview(null)}
                        className="absolute inset-0 bg-black/70"
                    />

                    <div className="relative flex h-[calc(100vh-1rem)] w-full max-w-[calc(100vw-1rem)] flex-col overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-2xl sm:h-[calc(100vh-2rem)] sm:max-w-[calc(100vw-2rem)]">
                        <div className="flex items-start justify-between gap-3 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-3">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                                    Aperçu - {fileToPreview.name}
                                </p>
                                {fileToPreview.original_name && fileToPreview.original_name !== fileToPreview.name ? (
                                    <p className="mt-1 truncate text-xs text-[var(--app-muted)]">
                                        Fichier: {fileToPreview.original_name}
                                    </p>
                                ) : null}
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
                                {fileToPreview.download_url ? (
                                    <a
                                        href={fileToPreview.download_url}
                                        className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)]"
                                    >
                                        <span className="inline-flex items-center gap-1">
                                            <Download className="h-3 w-3" strokeWidth={2.2} />
                                            <span>Télécharger</span>
                                        </span>
                                    </a>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() => setFileToPreview(null)}
                                    className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)]"
                                >
                                    <span className="inline-flex items-center gap-1">
                                        <X className="h-3 w-3" strokeWidth={2.2} />
                                        <span>Fermer</span>
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div className="min-h-0 flex-1 bg-[var(--app-surface)] p-3">
                            <div className="h-full overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]">
                                {previewKind(fileToPreview) === 'image' ? (
                                    <div className="flex h-full items-center justify-center p-2">
                                        <img
                                            src={fileToPreview.preview_url}
                                            alt={fileToPreview.name}
                                            className="max-h-full w-auto max-w-full rounded-lg object-contain"
                                        />
                                    </div>
                                ) : (
                                    <iframe
                                        src={fileToPreview.preview_url}
                                        title={`Aperçu ${fileToPreview.name}`}
                                        className="h-full w-full"
                                    />
                                )}
                            </div>
                        </div>

                    </div>
                    </div>,
                    document.body
                )
                : null}
        </>
    );
}
