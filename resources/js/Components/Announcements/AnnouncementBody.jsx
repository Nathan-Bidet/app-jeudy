import { forwardRef } from 'react';

/**
 * Rendu unique du corps HTML d'une annonce (gras/italique/souligné/barré,
 * paragraphes, listes), partagé par toutes les vues affichant une annonce.
 * Le HTML est déjà structuré côté serveur (SimpleHtmlSanitizer::render) :
 * une ligne vide = un nouveau <p>, un simple retour à la ligne = <br> dans
 * le paragraphe, les lignes "-"/"•"/numérotées = <ul>/<ol><li>. Le CSS ici
 * ne fait qu'espacer ces éléments déjà corrects, il ne compense rien.
 *
 * Le ref est transmis jusqu'à la racine rendue : les appelants qui mesurent
 * la hauteur/line-height réels (ex. CollapsibleAnnouncementBody) doivent
 * mesurer ce nœud précis, pas un wrapper englobant sans classes, sous
 * peine de lire un line-height hérité "normal" au lieu du leading-relaxed
 * explicite appliqué ici (comportement non fiable entre navigateurs).
 */
const AnnouncementBody = forwardRef(function AnnouncementBody({ html, emptyText = '(message vide)', className = '' }, ref) {
    const hasContent = typeof html === 'string' && html.trim() !== '';

    return (
        <div
            ref={ref}
            className={`break-words text-sm leading-relaxed text-[var(--app-text)] [&_p]:my-2 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0 [&_ul]:my-2 [&_ul:first-child]:mt-0 [&_ul:last-child]:mb-0 [&_ol]:my-2 [&_ol:first-child]:mt-0 [&_ol:last-child]:mb-0 [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-5 [&_ol]:pl-5 [&_li]:leading-snug [&_ul>li+li]:mt-0.5 [&_ol>li+li]:mt-0.5 ${className}`}
            dangerouslySetInnerHTML={{
                __html: hasContent ? html : `<p class="italic text-[var(--app-muted)]">${emptyText}</p>`,
            }}
        />
    );
});

export default AnnouncementBody;
