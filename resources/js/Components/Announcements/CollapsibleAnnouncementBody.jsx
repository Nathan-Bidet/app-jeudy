import AnnouncementBody from '@/Components/Announcements/AnnouncementBody';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';

const OVERFLOW_LINES = 5; // le repli ne s'active que si le texte dépasse ce nombre de lignes
const COLLAPSED_LINES = 3; // hauteur affichée une fois repliée
const FALLBACK_LINE_HEIGHT = 20;
const OVERFLOW_TOLERANCE = 4;
const VISIBILITY_RATIO = 0.4; // part de la carte (ou de l'écran, si la carte est plus haute) devant être visible

/**
 * Part de `el` réellement visible à l'écran, rapportée à la plus petite des
 * deux dimensions (carte ou viewport) : une carte plus haute que l'écran
 * peut ainsi atteindre 100% dès qu'elle remplit tout le viewport, sans quoi
 * une longue annonce ne serait jamais "vue" au sens de ce calcul.
 */
function getVisibleRatio(el) {
    const rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return 0;

    const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;

    const visibleHeight = Math.min(rect.bottom, viewportHeight) - Math.max(rect.top, 0);
    const visibleWidth = Math.min(rect.right, viewportWidth) - Math.max(rect.left, 0);
    if (visibleHeight <= 0 || visibleWidth <= 0) return 0;

    const referenceHeight = Math.min(rect.height, viewportHeight);
    const referenceWidth = Math.min(rect.width, viewportWidth);
    if (referenceHeight <= 0 || referenceWidth <= 0) return 0;

    return (visibleHeight * visibleWidth) / (referenceHeight * referenceWidth);
}

/**
 * Affiche le texte (et, une fois déplié, le sondage) d'une annonce, replié
 * automatiquement au-delà de 5 lignes une fois que l'utilisateur l'a déjà
 * lue une première fois. Détecte la première lecture réelle (carte
 * réellement visible à l'écran, pas juste "la page a chargé") via
 * plusieurs signaux combinés (voir le second useLayoutEffect) et
 * l'enregistre côté serveur pour cet utilisateur, une seule fois.
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

    /**
     * Marque l'annonce comme vue dès qu'une part significative de la carte
     * est réellement visible. IntersectionObserver est un signal parmi
     * d'autres, pas l'unique juge : certaines versions de Safari/iOS ne
     * déclenchent pas de façon fiable son callback initial (carte déjà
     * visible au chargement, sans scroll) ni lors des retours d'arrière-plan
     * en PWA. On combine donc IntersectionObserver, un contrôle immédiat au
     * montage, et des vérifications sur scroll/resize/visibilitychange/
     * pageshow via getBoundingClientRect — la même fonction de décision
     * (getVisibleRatio) tranche dans tous les cas, pour un comportement
     * identique quel que soit le déclencheur.
     */
    useLayoutEffect(() => {
        if (hasMarkedViewed.current || !rootRef.current || !announcementId) {
            return;
        }

        const el = rootRef.current;
        let rafPending = false;
        let disposed = false;

        const markViewed = () => {
            if (hasMarkedViewed.current) return;
            hasMarkedViewed.current = true;
            cleanup();

            const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
            const csrfToken = match ? decodeURIComponent(match[1]) : '';
            fetch(route('annonces.mark-viewed', announcementId), {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': csrfToken, Accept: 'application/json' },
                credentials: 'same-origin',
            }).catch(() => null);
        };

        const checkVisibility = () => {
            if (disposed || hasMarkedViewed.current) return;
            if (getVisibleRatio(el) >= VISIBILITY_RATIO) {
                markViewed();
            }
        };

        const scheduleCheck = () => {
            if (rafPending) return;
            rafPending = true;
            requestAnimationFrame(() => {
                rafPending = false;
                checkVisibility();
            });
        };

        let observer = null;
        if (typeof IntersectionObserver !== 'undefined') {
            observer = new IntersectionObserver((entries) => {
                if (entries[0]?.isIntersecting) {
                    checkVisibility();
                }
            }, { threshold: VISIBILITY_RATIO });
            observer.observe(el);
        }

        function cleanup() {
            disposed = true;
            observer?.disconnect();
            window.removeEventListener('scroll', scheduleCheck);
            window.removeEventListener('resize', scheduleCheck);
            document.removeEventListener('visibilitychange', scheduleCheck);
            window.removeEventListener('pageshow', scheduleCheck);
        }

        window.addEventListener('scroll', scheduleCheck, { passive: true });
        window.addEventListener('resize', scheduleCheck, { passive: true });
        document.addEventListener('visibilitychange', scheduleCheck);
        window.addEventListener('pageshow', scheduleCheck);

        scheduleCheck(); // contrôle immédiat au montage (carte déjà visible sans scroll)

        return cleanup;
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
