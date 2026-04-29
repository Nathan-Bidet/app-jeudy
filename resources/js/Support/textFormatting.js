function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

const EMAIL_OR_PHONE_REGEX =
    /([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})|((?:\+?\d[\d().\-\s]{7,}\d))/gi;

function normalizePhoneForHref(candidate) {
    let value = String(candidate ?? '').trim();
    if (!value) return null;

    value = value
        .replace(/^[^\d+]+/, '')
        .replace(/[^\d]+$/, '');

    if (!value) return null;

    const hasPlusPrefix = value.startsWith('+');
    const digitsOnly = value.replace(/[^\d]/g, '');

    if (digitsOnly.length < 8) {
        return null;
    }

    return hasPlusPrefix ? `+${digitsOnly}` : digitsOnly;
}

function linkifyPlainSegment(segment) {
    const source = String(segment ?? '');
    if (!source) return '';

    let output = '';
    let cursor = 0;
    let match = null;

    while ((match = EMAIL_OR_PHONE_REGEX.exec(source)) !== null) {
        const token = match[0];
        const index = match.index;
        const email = match[1];
        const phone = match[2];

        output += source.slice(cursor, index);

        if (email) {
            const href = `mailto:${encodeURIComponent(email)}`;
            output += `<a class="app-autolink" href="${href}">${token}</a>`;
        } else if (phone) {
            const normalizedPhone = normalizePhoneForHref(phone);

            if (normalizedPhone) {
                output += `<a class="app-autolink" data-phone="${normalizedPhone}" href="tel:${normalizedPhone}">${token}</a>`;
            } else {
                output += token;
            }
        } else {
            output += token;
        }

        cursor = index + token.length;
    }

    output += source.slice(cursor);
    return output;
}

function linkifyHtmlText(html) {
    const tokens = String(html ?? '').split(/(<[^>]+>)/g);

    return tokens
        .map((token) => {
            if (token.startsWith('<') && token.endsWith('>')) {
                return token;
            }

            return linkifyPlainSegment(token);
        })
        .join('');
}

export function stripTextMarkers(value) {
    return String(value ?? '').replaceAll('**', '').replaceAll('~~', '');
}

export function renderFormattedHtml(value, { multiline = false, linkify = true } = {}) {
    let html = escapeHtml(value ?? '');

    html = html.replace(/\*\*([\s\S]+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/~~([\s\S]+?)~~/g, '<s>$1</s>');

    if (multiline) {
        html = html.replace(/\r\n|\n|\r/g, '<br />');
    }

    if (linkify) {
        html = linkifyHtmlText(html);
    }

    return html;
}

export function applyMarkerToSelection(text, selectionStart, selectionEnd, marker) {
    const source = String(text ?? '');
    const start = Math.max(0, Math.min(Number(selectionStart || 0), source.length));
    const end = Math.max(start, Math.min(Number(selectionEnd || 0), source.length));

    if (start === end) {
        const value = `${source.slice(0, start)}${marker}${marker}${source.slice(end)}`;
        const cursor = start + marker.length;

        return {
            value,
            selectionStart: cursor,
            selectionEnd: cursor,
        };
    }

    const selected = source.slice(start, end);
    const value = `${source.slice(0, start)}${marker}${selected}${marker}${source.slice(end)}`;

    return {
        value,
        selectionStart: start + marker.length,
        selectionEnd: end + marker.length,
    };
}

function isBlockNode(node) {
    return (
        node?.nodeType === Node.ELEMENT_NODE
        && ['DIV', 'P', 'LI'].includes(node.tagName)
    );
}

export function htmlToMarkedText(html) {
    if (typeof document === 'undefined') {
        return String(html ?? '');
    }

    const root = document.createElement('div');
    root.innerHTML = String(html ?? '');

    const walk = (node, context = { bold: false, strike: false }) => {
        if (!node) return '';

        if (node.nodeType === Node.TEXT_NODE) {
            let text = node.textContent?.replace(/\u00a0/g, ' ') ?? '';
            if (!text) return '';
            if (context.strike) text = `~~${text}~~`;
            if (context.bold) text = `**${text}**`;
            return text;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
            return '';
        }

        if (node.tagName === 'BR') {
            return '\n';
        }

        const nextContext = {
            bold: context.bold || ['STRONG', 'B'].includes(node.tagName),
            strike: context.strike || ['S', 'STRIKE', 'DEL'].includes(node.tagName),
        };

        const chunks = [];
        node.childNodes.forEach((child, index) => {
            const childText = walk(child, nextContext);
            if (!childText) return;

            const childIsBlock = isBlockNode(child);
            const lastChunk = chunks.length ? chunks[chunks.length - 1] : '';
            const hasContentBefore = chunks.length > 0 && String(lastChunk).replace(/\n/g, '').trim() !== '';

            // If a block element follows inline text, preserve the visual line break.
            if (childIsBlock && hasContentBefore && !String(lastChunk).endsWith('\n')) {
                chunks.push('\n');
            }

            chunks.push(childText);

            if (isBlockNode(child) && index < node.childNodes.length - 1) {
                const latest = chunks[chunks.length - 1];
                if (!String(latest).endsWith('\n')) {
                    chunks.push('\n');
                }
            }
        });

        return chunks.join('');
    };

    return walk(root)
        .replace(/\u200b/g, '')
        .replace(/\n{3,}/g, '\n\n');
}
