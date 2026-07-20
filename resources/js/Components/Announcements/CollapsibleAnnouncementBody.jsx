import AnnouncementBody from '@/Components/Announcements/AnnouncementBody';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';

const OVERFLOW_LINES = 5; // le repli ne s'active que si le texte dépasse ce nombre de lignes
const COLLAPSED_LINES = 3; // hauteur affichée une fois repliée
const FALLBACK_LINE_HEIGHT = 20;
const OVERFLOW_TOLERANCE = 4;

/**
 * Affiche le texte (et, une fois déplié, le sondage) d'une annonce, replié
 * automatiquement au-delà de 5 lignes une fois que l'utilisateur l'a déjà
 * lue une première fois. Détecte la première lecture réelle (carte visible
 * à l'écran, pas juste "la page a chargé") via IntersectionObserver et
 * l'enregistre côté serveur pour cet utilisateur.
 *
 * La hauteur d'une "ligne" est lue depuis le line-height CSS réellement
 * rendu (jamais un comptage de caractères), afin de rester correct quels
 * que soient la police, la taille d'écran ou le contenu.
 *
 * Le sondage (passé en `children`) est placé à l'intérieur du même
 * conteneur animé que le texte : replié, il est donc masqué avec le texte
 * au-delà de la 3e ligne ; déplié, il apparaît juste en dessous.
 */
export default function CollapsibleAnnouncementBody({ html, announcementId, hasBeenViewed, className = '', children = null }) {
    const [wasAlreadyViewed] = useState(Boolean(hasBeenViewed));
    const [expanded, setExpanded] = useState(!wasAlreadyViewed);
    const [textHeight, setTextHeight] = useState(0);
    const [fullHeight, setFullHeight] = useState(0);
    const [lineHeight, setLineHeight] = useState(FALLBACK_LINE_HEIGHT);
    const [isMeasured, setIsMeasured] = useState(false);

    const rootRef = useRef(null);
    const textRef = useRef(null);
    const fullRef = useRef(null);
    const hasMarkedViewed = useRef(wasAlreadyViewed);

    useLayoutEffect(() => {
        const textEl = textRef.current;
        const fullEl = fullRef.current;
        if (!textEl || !fullEl) return;

        const measure = () => {
            setTextHeight(textEl.scrollHeight);
            setFullHeight(fullEl.scrollHeight);
            const computedLineHeight = parseFloat(getComputedStyle(textEl).lineHeight);
            setLineHeight(Number.isFinite(computedLineHeight) && computedLineHeight > 0 ? computedLineHeight : FALLBACK_LINE_HEIGHT);
            setIsMeasured(true);
        };
        measure();

        if (typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(measure);
        observer.observe(textEl);
        observer.observe(fullEl);
        return () => observer.disconnect();
    }, [html, children]);

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

    const overflowThreshold = lineHeight * OVERFLOW_LINES;
    const collapsedHeight = lineHeight * COLLAPSED_LINES;
    const isOverflowing = textHeight > overflowThreshold + OVERFLOW_TOLERANCE;
    const canCollapse = wasAlreadyViewed && isOverflowing;
    const isClamped = canCollapse && !expanded;

    return (
        <div ref={rootRef}>
            <div
                style={{
                    overflow: 'hidden',
                    maxHeight: isClamped ? `${collapsedHeight}px` : (fullHeight ? `${fullHeight}px` : undefined),
                    transition: isMeasured ? 'max-height 300ms ease' : 'none',
                }}
            >
                <div ref={fullRef}>
                    <AnnouncementBody ref={textRef} html={html} className={className} />
                    {children}
                </div>
            </div>
            {canCollapse ? (
                <button
                    type="button"
                    onClick={() => setExpanded((value) => !value)}
                    className="-ml-1 mt-1 inline-flex items-center gap-1 rounded-lg px-1 py-1.5 text-xs font-semibold text-[#0F6930] transition hover:opacity-80 active:opacity-70"
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
