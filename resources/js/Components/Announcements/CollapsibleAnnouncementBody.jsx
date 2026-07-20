import AnnouncementBody from '@/Components/Announcements/AnnouncementBody';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';

const COLLAPSED_HEIGHT = 320; // ~14-15 lignes en text-sm leading-relaxed
const OVERFLOW_TOLERANCE = 16;

/**
 * Affiche le texte d'une annonce, replié automatiquement au-delà d'une
 * hauteur fixe une fois que l'utilisateur l'a déjà lue une première fois.
 * Détecte la première lecture réelle (carte visible à l'écran, pas juste
 * "la page a chargé") via IntersectionObserver et l'enregistre côté
 * serveur pour cet utilisateur. Le repli se base sur la hauteur réellement
 * rendue du contenu (ResizeObserver), jamais sur un comptage de caractères.
 */
export default function CollapsibleAnnouncementBody({ html, announcementId, hasBeenViewed, className = '' }) {
    const [wasAlreadyViewed] = useState(Boolean(hasBeenViewed));
    const [expanded, setExpanded] = useState(!wasAlreadyViewed);
    const [contentHeight, setContentHeight] = useState(0);
    const [isMeasured, setIsMeasured] = useState(false);

    const rootRef = useRef(null);
    const innerRef = useRef(null);
    const hasMarkedViewed = useRef(wasAlreadyViewed);

    useLayoutEffect(() => {
        const el = innerRef.current;
        if (!el) return;

        const measure = () => {
            setContentHeight(el.scrollHeight);
            setIsMeasured(true);
        };
        measure();

        if (typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        return () => observer.disconnect();
    }, [html]);

    useLayoutEffect(() => {
        if (hasMarkedViewed.current || !rootRef.current || !announcementId || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (!entries[0]?.isIntersecting || hasMarkedViewed.current) return;
                hasMarkedViewed.current = true;
                observer.disconnect();

                const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                const csrfToken = match ? decodeURIComponent(match[1]) : '';
                fetch(route('annonces.mark-viewed', announcementId), {
                    method: 'POST',
                    headers: { 'X-XSRF-TOKEN': csrfToken, Accept: 'application/json' },
                    credentials: 'same-origin',
                }).catch(() => null);
            },
            { threshold: 0.5 },
        );

        observer.observe(rootRef.current);
        return () => observer.disconnect();
    }, [announcementId]);

    const isOverflowing = contentHeight > COLLAPSED_HEIGHT + OVERFLOW_TOLERANCE;
    const canCollapse = wasAlreadyViewed && isOverflowing;
    const isClamped = canCollapse && !expanded;

    return (
        <div ref={rootRef}>
            <div
                style={{
                    overflow: 'hidden',
                    maxHeight: isClamped ? `${COLLAPSED_HEIGHT}px` : (contentHeight ? `${contentHeight}px` : undefined),
                    transition: isMeasured ? 'max-height 300ms ease' : 'none',
                }}
            >
                <div ref={innerRef}>
                    <AnnouncementBody html={html} className={className} />
                </div>
            </div>
            {canCollapse ? (
                <button
                    type="button"
                    onClick={() => setExpanded((value) => !value)}
                    className="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-[#0F6930] transition hover:opacity-80"
                >
                    {expanded ? (
                        <>
                            <ChevronUp className="h-3.5 w-3.5" />
                            Réduire
                        </>
                    ) : (
                        <>
                            <ChevronDown className="h-3.5 w-3.5" />
                            Afficher plus
                        </>
                    )}
                </button>
            ) : null}
        </div>
    );
}
