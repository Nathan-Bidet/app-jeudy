import { FileText, Loader2, Paperclip, RefreshCw, Trash2, X, Download } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (value >= 1048576) return `${(value / 1048576).toFixed(1).replace('.', ',').replace(/,0$/, '')} Mo`;

    return `${Math.max(1, Math.round(value / 1024))} Ko`;
}

function extensionOf(name) {
    const match = String(name || '').toLowerCase().match(/\.([a-z0-9]+)$/);

    return match ? match[1] : '';
}

function typeLabel(name, mime) {
    const extension = extensionOf(name);

    return extension ? extension.toUpperCase() : (mime || 'Fichier');
}

function firstError(data, fallback) {
    if (data?.errors) {
        const first = Object.values(data.errors).flat()[0];
        if (first) return first;
    }

    return data?.message || fallback;
}

async function jsonRequest(url, method, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await response.json().catch(() => ({}));

    return { ok: response.ok, data };
}

// XMLHttpRequest : seul moyen d'obtenir la progression d'un téléversement.
function uploadFile(url, file, onProgress) {
    const xhr = new XMLHttpRequest();
    const promise = new Promise((resolve, reject) => {
        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
        };
        xhr.onload = () => {
            let data = {};
            try { data = JSON.parse(xhr.responseText || '{}'); } catch { data = {}; }
            if (xhr.status >= 200 && xhr.status < 300) resolve(data);
            else reject(new Error(firstError(data, 'Le fichier n\'a pas pu être téléversé.')));
        };
        xhr.onerror = () => reject(new Error('Échec du téléversement (connexion interrompue).'));
        xhr.onabort = () => reject(Object.assign(new Error('aborted'), { aborted: true }));
        const form = new FormData();
        form.append('file', file);
        xhr.send(form);
    });

    return { promise, abort: () => xhr.abort() };
}

/**
 * État des pièces jointes d'un brouillon : PDF généré par le serveur et fichiers
 * ajoutés. Tous les fichiers vivent côté serveur (stockage privé temporaire) ;
 * l'interface ne manipule que leurs identifiants.
 */
export function useMailAttachments({ baseUrl, draftId, limits }) {
    const [pdf, setPdf] = useState({ attachment: null, busy: false, error: '' });
    const [files, setFiles] = useState([]);
    const [notices, setNotices] = useState([]);
    const uploadsRef = useRef(new Map());
    const pdfBusyRef = useRef(false);
    const filesRef = useRef([]);
    filesRef.current = files;

    // Nouveau brouillon : repartir d'une liste vide.
    useEffect(() => {
        setPdf({ attachment: null, busy: false, error: '' });
        setFiles([]);
        setNotices([]);
        pdfBusyRef.current = false;
    }, [draftId]);

    const url = (suffix = '') => `${baseUrl}/${draftId}${suffix}`;

    const generatePdf = useCallback(async (replacesId = null) => {
        if (!draftId || pdfBusyRef.current) return;
        pdfBusyRef.current = true;
        setPdf((state) => ({ ...state, busy: true, error: '' }));

        try {
            const { ok, data } = await jsonRequest(`${baseUrl}/${draftId}/pdf`, 'POST', replacesId ? { replaces: replacesId } : {});
            if (!ok) throw new Error(firstError(data, 'Le PDF n\'a pas pu être généré.'));
            setPdf({ attachment: data.attachment, busy: false, error: '' });
        } catch (exception) {
            // L'ancien PDF (régénération) reste en place.
            setPdf((state) => ({ ...state, busy: false, error: exception?.message || 'Le PDF n\'a pas pu être généré.' }));
        } finally {
            pdfBusyRef.current = false;
        }
    }, [baseUrl, draftId]);

    const removePdf = async () => {
        const attachment = pdf.attachment;
        if (!attachment || pdf.busy) return;
        setPdf((state) => ({ ...state, busy: true, error: '' }));
        const { ok, data } = await jsonRequest(url(`/attachments/${attachment.id}`), 'DELETE').catch(() => ({ ok: false, data: {} }));
        setPdf(ok
            ? { attachment: null, busy: false, error: '' }
            : { attachment, busy: false, error: firstError(data, 'Le PDF n\'a pas pu être retiré.') });
    };

    const addFiles = (fileList) => {
        const incoming = Array.from(fileList || []);
        const errors = [];
        const accepted = [];
        let count = filesRef.current.filter((item) => item.status !== 'error').length;
        let total = (pdf.attachment?.size || 0)
            + filesRef.current.filter((item) => item.status !== 'error').reduce((sum, item) => sum + item.size, 0);

        incoming.forEach((file) => {
            const extension = extensionOf(file.name);
            if (!limits.extensions.includes(extension)) {
                errors.push(`« ${file.name} » : type non autorisé (.${extension || '?'}). Types acceptés : ${limits.extensions.join(', ')}.`);
            } else if (file.size <= 0) {
                errors.push(`« ${file.name} » : le fichier est vide.`);
            } else if (file.size > limits.max_file_bytes) {
                errors.push(`« ${file.name} » : dépasse la taille maximale de ${formatBytes(limits.max_file_bytes)} par fichier.`);
            } else if (count >= limits.max_files) {
                errors.push(`« ${file.name} » : nombre maximal de ${limits.max_files} fichiers atteint.`);
            } else if (total + file.size > limits.max_total_bytes) {
                errors.push(`« ${file.name} » : la taille totale dépasserait ${formatBytes(limits.max_total_bytes)}.`);
            } else {
                count += 1;
                total += file.size;
                accepted.push(file);
            }
        });

        setNotices(errors);

        accepted.forEach((file) => {
            const key = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
            const item = { key, name: file.name, size: file.size, type: file.type, status: 'uploading', progress: 0, attachment: null, error: '' };
            setFiles((current) => [...current, item]);

            const upload = uploadFile(url('/files'), file, (progress) => {
                setFiles((current) => current.map((entry) => (entry.key === key ? { ...entry, progress } : entry)));
            });
            uploadsRef.current.set(key, upload);
            upload.promise
                .then((data) => {
                    setFiles((current) => current.map((entry) => (entry.key === key
                        ? { ...entry, status: 'ready', progress: 100, attachment: data.attachment }
                        : entry)));
                })
                .catch((exception) => {
                    if (exception?.aborted) return;
                    setFiles((current) => current.map((entry) => (entry.key === key
                        ? { ...entry, status: 'error', error: exception?.message || 'Échec du téléversement.' }
                        : entry)));
                })
                .finally(() => uploadsRef.current.delete(key));
        });
    };

    const removeFile = async (key) => {
        const item = filesRef.current.find((entry) => entry.key === key);
        if (!item) return;

        if (item.status === 'uploading') {
            uploadsRef.current.get(key)?.abort();
            setFiles((current) => current.filter((entry) => entry.key !== key));
            return;
        }

        if (item.status === 'ready' && item.attachment) {
            const { ok, data } = await jsonRequest(url(`/attachments/${item.attachment.id}`), 'DELETE').catch(() => ({ ok: false, data: {} }));
            if (!ok) {
                setFiles((current) => current.map((entry) => (entry.key === key
                    ? { ...entry, error: firstError(data, 'La pièce jointe n\'a pas pu être retirée.') }
                    : entry)));
                return;
            }
        }

        setFiles((current) => current.filter((entry) => entry.key !== key));
    };

    // Annulation du brouillon : interrompt les envois en cours et supprime les fichiers temporaires.
    const discard = () => {
        uploadsRef.current.forEach((upload) => upload.abort());
        uploadsRef.current.clear();
        if (draftId) {
            fetch(`${baseUrl}/${draftId}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                keepalive: true,
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            }).catch(() => {});
        }
    };

    const uploading = files.some((item) => item.status === 'uploading');
    const failed = files.some((item) => item.status === 'error');
    const attachmentIds = [
        ...(pdf.attachment ? [pdf.attachment.id] : []),
        ...files.filter((item) => item.status === 'ready').map((item) => item.attachment.id),
    ];

    return {
        pdf, files, notices, uploading, failed, attachmentIds,
        blocked: pdf.busy || uploading || failed,
        generatePdf, removePdf, addFiles, removeFile, discard,
        downloadUrl: (id) => url(`/attachments/${id}`),
    };
}

function RowButton({ icon: Icon, label, onClick, disabled = false }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className="inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-xs font-bold hover:bg-[var(--app-surface-soft)] disabled:opacity-50"
        >
            <Icon className="h-3.5 w-3.5" strokeWidth={2.3} />
            <span className="hidden sm:inline">{label}</span>
        </button>
    );
}

function Row({ icon: Icon = FileText, name, meta, status, children, error }) {
    return (
        <li className="flex min-w-0 flex-col gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 sm:flex-row sm:items-center sm:gap-3">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <Icon className="h-4 w-4 shrink-0 text-[var(--app-muted)]" strokeWidth={2.2} />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold" title={name}>{name}</div>
                    <div className="text-xs text-[var(--app-muted)]">{meta}</div>
                    {status}
                    {error ? <div role="alert" className="text-xs font-semibold text-red-700">{error}</div> : null}
                </div>
            </div>
            <div className="flex shrink-0 flex-wrap items-center gap-1.5">{children}</div>
        </li>
    );
}

/**
 * Section « Pièces jointes » : PDF généré automatiquement + fichiers ajoutés.
 */
export default function CotationMailAttachments({ attachments, limits, disabled = false }) {
    const inputRef = useRef(null);
    const { pdf, files, notices } = attachments;

    return (
        <section aria-label="Pièces jointes">
            <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                <span className="block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Pièces jointes</span>
                <button
                    type="button"
                    onClick={() => inputRef.current?.click()}
                    disabled={disabled}
                    className="inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 text-xs font-black uppercase tracking-[0.08em] disabled:opacity-60"
                >
                    <Paperclip className="h-3.5 w-3.5" strokeWidth={2.3} />
                    Ajouter des pièces jointes
                </button>
                <input
                    ref={inputRef}
                    type="file"
                    multiple
                    hidden
                    aria-label="Sélectionner des pièces jointes"
                    accept={limits.extensions.map((extension) => `.${extension}`).join(',')}
                    onChange={(event) => {
                        attachments.addFiles(event.target.files);
                        event.target.value = '';
                    }}
                />
            </div>
            <p className="mb-2 text-xs text-[var(--app-muted)]">
                {limits.max_files} fichiers maximum · {formatBytes(limits.max_file_bytes)} par fichier · {formatBytes(limits.max_total_bytes)} au total (PDF inclus) · types : {limits.extensions.join(', ')}
            </p>

            <ul className="space-y-2">
                {pdf.attachment ? (
                    <Row
                        name={pdf.attachment.name}
                        meta={`PDF généré · ${typeLabel(pdf.attachment.name, pdf.attachment.type)} · ${formatBytes(pdf.attachment.size)}`}
                        status={pdf.busy ? (
                            <div className="inline-flex items-center gap-1 text-xs font-semibold"><Loader2 className="h-3 w-3 animate-spin" /> Génération du PDF…</div>
                        ) : null}
                        error={pdf.error}
                    >
                        <a
                            href={attachments.downloadUrl(pdf.attachment.id)}
                            download
                            className="inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-xs font-bold hover:bg-[var(--app-surface-soft)]"
                            aria-label="Télécharger le PDF"
                            title="Télécharger le PDF"
                        >
                            <Download className="h-3.5 w-3.5" strokeWidth={2.3} />
                            <span className="hidden sm:inline">Télécharger</span>
                        </a>
                        <RowButton icon={RefreshCw} label="Régénérer" disabled={pdf.busy} onClick={() => attachments.generatePdf(pdf.attachment.id)} />
                        <RowButton icon={Trash2} label="Supprimer le PDF" disabled={pdf.busy} onClick={attachments.removePdf} />
                    </Row>
                ) : (
                    <Row
                        name={pdf.busy ? 'Génération du PDF…' : 'Aucun PDF joint'}
                        meta="PDF des cotations"
                        status={pdf.busy ? <div className="inline-flex items-center gap-1 text-xs font-semibold"><Loader2 className="h-3 w-3 animate-spin" /> Génération du PDF…</div> : null}
                        error={pdf.error}
                    >
                        <RowButton icon={RefreshCw} label="Générer le PDF" disabled={pdf.busy} onClick={() => attachments.generatePdf(null)} />
                    </Row>
                )}

                {files.map((item) => (
                    <Row
                        key={item.key}
                        icon={Paperclip}
                        name={item.name}
                        meta={`${typeLabel(item.name, item.type)} · ${formatBytes(item.size)}`}
                        error={item.error}
                        status={item.status === 'uploading' ? (
                            <div className="mt-1 flex items-center gap-2 text-xs font-semibold">
                                <progress className="h-1.5 w-32 max-w-full" max="100" value={item.progress} aria-label={`Téléversement de ${item.name}`} />
                                {item.progress} %
                            </div>
                        ) : null}
                    >
                        <RowButton
                            icon={item.status === 'uploading' ? X : Trash2}
                            label={item.status === 'uploading' ? `Annuler ${item.name}` : `Supprimer ${item.name}`}
                            onClick={() => attachments.removeFile(item.key)}
                        />
                    </Row>
                ))}
            </ul>

            {notices.length > 0 ? (
                <ul role="alert" className="mt-2 space-y-1 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
                    {notices.map((notice) => <li key={notice}>{notice}</li>)}
                </ul>
            ) : null}
        </section>
    );
}
