import { FileDown, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const BUTTON_CLASS = 'inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] disabled:cursor-wait';

/**
 * Nom de fichier fourni par le serveur (Content-Disposition), encodé RFC 5987
 * (filename*) ou entre guillemets ; null si absent.
 */
export function filenameFromDisposition(header) {
    if (!header) return null;

    const encoded = header.match(/filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/i);
    if (encoded) {
        try { return decodeURIComponent(encoded[1].trim()); } catch { /* repli ci-dessous */ }
    }

    const quoted = header.match(/filename\s*=\s*"([^"]+)"/i) || header.match(/filename\s*=\s*([^;]+)/i);

    return quoted ? quoted[1].trim() : null;
}

/**
 * Bouton d'export PDF avec état de chargement. Récupère le fichier par une
 * requête asynchrone (même route, même session : permissions et
 * authentification inchangées), vérifie le statut et le type de la réponse,
 * puis déclenche le téléchargement sous le nom fourni par le serveur.
 * L'état de chargement est propre à chaque instance.
 */
export default function PdfExportButton({ url, onError, label = 'Export PDF', fallbackName = 'cotations.pdf' }) {
    const [busy, setBusy] = useState(false);
    const busyRef = useRef(false);
    const controllerRef = useRef(null);
    const mountedRef = useRef(true);

    useEffect(() => {
        mountedRef.current = true;

        return () => {
            mountedRef.current = false;
            controllerRef.current?.abort();
        };
    }, []);

    const download = async () => {
        // busyRef bloque aussi les clics rapprochés avant le re-rendu.
        if (busyRef.current) return;
        busyRef.current = true;
        setBusy(true);

        const controller = new AbortController();
        controllerRef.current = controller;

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/pdf' },
                signal: controller.signal,
            });

            const type = response.headers.get('Content-Type') || '';
            if (!response.ok || !type.includes('application/pdf')) {
                // Erreur serveur (texte) ou redirection vers une autre page (accès refusé) : rien à télécharger.
                const detail = response.ok ? '' : await response.text().catch(() => '');
                throw new Error(detail && detail.length < 300 ? detail : "Le PDF n'a pas pu être généré. Réessayez dans un instant.");
            }

            const blob = await response.blob();
            if (blob.size === 0) throw new Error('Le PDF reçu est vide. Réessayez dans un instant.');

            const objectUrl = URL.createObjectURL(blob);
            try {
                const link = document.createElement('a');
                link.href = objectUrl;
                link.download = filenameFromDisposition(response.headers.get('Content-Disposition')) || fallbackName;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                link.remove();
            } finally {
                // Libère l'URL temporaire une fois le téléchargement lancé.
                window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
            }
        } catch (exception) {
            if (exception?.name === 'AbortError') return;
            onError?.(exception?.message || "Le PDF n'a pas pu être généré. Réessayez dans un instant.");
        } finally {
            busyRef.current = false;
            controllerRef.current = null;
            if (mountedRef.current) setBusy(false);
        }
    };

    // Les deux libellés occupent la même cellule : la largeur du bouton ne varie pas.
    return (
        <button
            type="button"
            onClick={download}
            disabled={busy}
            aria-busy={busy ? 'true' : 'false'}
            data-state={busy ? 'busy' : 'idle'}
            className={BUTTON_CLASS}
        >
            <span className="inline-grid">
                <span className={`col-start-1 row-start-1 inline-flex items-center justify-center gap-1.5 ${busy ? 'invisible' : ''}`}>
                    <FileDown className="h-3.5 w-3.5" strokeWidth={2.3} />
                    {label}
                </span>
                <span className={`col-start-1 row-start-1 inline-flex items-center justify-center gap-1.5 ${busy ? '' : 'invisible'}`} aria-hidden={busy ? undefined : 'true'}>
                    <Loader2 className="h-3.5 w-3.5 animate-spin motion-reduce:animate-none" strokeWidth={2.3} />
                    Génération…
                </span>
            </span>
            <span role="status" className="sr-only">{busy ? 'Génération du PDF en cours…' : ''}</span>
        </button>
    );
}
