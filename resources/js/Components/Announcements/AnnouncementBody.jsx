/**
 * Rendu unique du corps HTML d'une annonce (gras/italique/souligné/barré +
 * retours à la ligne), partagé par toutes les vues affichant une annonce.
 * Le HTML est déjà aplati côté serveur (SimpleHtmlSanitizer) : une ligne =
 * un <br>, jamais de <p>/<div> par ligne, donc aucune marge de paragraphe
 * ne doit être appliquée ici.
 */
export default function AnnouncementBody({ html, emptyText = '(message vide)', className = '' }) {
    const hasContent = typeof html === 'string' && html.trim() !== '';

    return (
        <div
            className={`whitespace-pre-wrap break-words text-sm leading-relaxed text-[var(--app-text)] [&_p]:m-0 [&_div]:contents ${className}`}
            dangerouslySetInnerHTML={{
                __html: hasContent ? html : `<span class="italic text-[var(--app-muted)]">${emptyText}</span>`,
            }}
        />
    );
}
