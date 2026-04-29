function clampChannel(value) {
    return Math.max(0, Math.min(255, Math.round(value)));
}

function parseHexColor(value) {
    const input = String(value || '').trim().replace('#', '');
    if (input.length === 3) {
        const r = parseInt(input[0] + input[0], 16);
        const g = parseInt(input[1] + input[1], 16);
        const b = parseInt(input[2] + input[2], 16);
        return [r, g, b];
    }

    if (input.length === 6) {
        const r = parseInt(input.slice(0, 2), 16);
        const g = parseInt(input.slice(2, 4), 16);
        const b = parseInt(input.slice(4, 6), 16);
        return [r, g, b];
    }

    return null;
}

function parseRgbPart(part) {
    const value = String(part || '').trim();
    if (value.endsWith('%')) {
        const percent = Number.parseFloat(value.slice(0, -1));
        if (!Number.isFinite(percent)) return null;
        return clampChannel((percent / 100) * 255);
    }

    const number = Number.parseFloat(value);
    if (!Number.isFinite(number)) return null;
    return clampChannel(number);
}

function parseRgbColor(value) {
    const match = String(value || '').trim().match(/^rgba?\((.+)\)$/i);
    if (!match) return null;

    const parts = match[1].split(',').map((item) => item.trim());
    if (parts.length < 3) return null;

    const r = parseRgbPart(parts[0]);
    const g = parseRgbPart(parts[1]);
    const b = parseRgbPart(parts[2]);

    if (r === null || g === null || b === null) return null;
    return [r, g, b];
}

function hslToRgb(h, s, l) {
    const hue = ((h % 360) + 360) % 360;
    const saturation = Math.max(0, Math.min(1, s));
    const lightness = Math.max(0, Math.min(1, l));

    if (saturation === 0) {
        const gray = clampChannel(lightness * 255);
        return [gray, gray, gray];
    }

    const c = (1 - Math.abs((2 * lightness) - 1)) * saturation;
    const x = c * (1 - Math.abs(((hue / 60) % 2) - 1));
    const m = lightness - (c / 2);

    let r = 0;
    let g = 0;
    let b = 0;

    if (hue < 60) {
        r = c;
        g = x;
    } else if (hue < 120) {
        r = x;
        g = c;
    } else if (hue < 180) {
        g = c;
        b = x;
    } else if (hue < 240) {
        g = x;
        b = c;
    } else if (hue < 300) {
        r = x;
        b = c;
    } else {
        r = c;
        b = x;
    }

    return [
        clampChannel((r + m) * 255),
        clampChannel((g + m) * 255),
        clampChannel((b + m) * 255),
    ];
}

function parseHslColor(value) {
    const match = String(value || '').trim().match(/^hsla?\((.+)\)$/i);
    if (!match) return null;

    const parts = match[1].split(',').map((item) => item.trim());
    if (parts.length < 3) return null;

    const h = Number.parseFloat(parts[0]);
    const s = Number.parseFloat(parts[1].replace('%', '')) / 100;
    const l = Number.parseFloat(parts[2].replace('%', '')) / 100;

    if (!Number.isFinite(h) || !Number.isFinite(s) || !Number.isFinite(l)) return null;
    return hslToRgb(h, s, l);
}

function parseColor(value) {
    if (!value) return null;
    const input = String(value).trim();
    if (!input) return null;

    if (input.startsWith('#')) {
        return parseHexColor(input);
    }

    if (/^rgba?\(/i.test(input)) {
        return parseRgbColor(input);
    }

    if (/^hsla?\(/i.test(input)) {
        return parseHslColor(input);
    }

    return null;
}

function toLinear(channel) {
    const c = channel / 255;
    return c <= 0.03928 ? (c / 12.92) : (((c + 0.055) / 1.055) ** 2.4);
}

function luminance(rgb) {
    return (
        (0.2126 * toLinear(rgb[0])) +
        (0.7152 * toLinear(rgb[1])) +
        (0.0722 * toLinear(rgb[2]))
    );
}

function contrastRatio(foreground, background) {
    const l1 = luminance(foreground);
    const l2 = luminance(background);
    const lighter = Math.max(l1, l2);
    const darker = Math.min(l1, l2);
    return (lighter + 0.05) / (darker + 0.05);
}

function blendRgb(base, overlay, ratio) {
    const alpha = Math.max(0, Math.min(1, ratio));
    return [
        clampChannel((base[0] * (1 - alpha)) + (overlay[0] * alpha)),
        clampChannel((base[1] * (1 - alpha)) + (overlay[1] * alpha)),
        clampChannel((base[2] * (1 - alpha)) + (overlay[2] * alpha)),
    ];
}

function rgbToHex(rgb) {
    return `#${rgb.map((channel) => clampChannel(channel).toString(16).padStart(2, '0')).join('')}`;
}

function isDarkTheme() {
    if (typeof document === 'undefined') return false;
    return document.documentElement.classList.contains('theme-dark');
}

function themeSurfaceSoftRgb() {
    const dark = isDarkTheme();
    const fallback = dark ? [58, 47, 35] : [246, 239, 231];

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return fallback;
    }

    const styles = window.getComputedStyle(document.documentElement);
    const raw =
        styles.getPropertyValue('--app-surface-soft').trim()
        || styles.getPropertyValue('--app-surface').trim();

    return parseColor(raw) || fallback;
}

function ensureContrastViaBackground(textRgb, bgRgb, target = 4.5) {
    let current = [...bgRgb];
    if (contrastRatio(textRgb, current) >= target) return current;

    const textIsLight = luminance(textRgb) >= 0.5;
    const contrastTarget = textIsLight ? [0, 0, 0] : [255, 255, 255];

    for (let i = 0; i < 14; i += 1) {
        current = blendRgb(current, contrastTarget, 0.2);
        if (contrastRatio(textRgb, current) >= target) {
            return current;
        }
    }

    return current;
}

function autoTextColorForBackground(bgRgb) {
    const darkText = [17, 24, 39];
    const lightText = [248, 250, 252];

    return contrastRatio(darkText, bgRgb) >= contrastRatio(lightText, bgRgb)
        ? darkText
        : lightText;
}

export function adaptiveTaskStyle(rawStyle = {}) {
    const textRaw = String(rawStyle?.text_color || rawStyle?.textColor || '').trim();
    const bgRaw = String(rawStyle?.bg_color || rawStyle?.bgColor || '').trim();

    const darkTheme = isDarkTheme();
    const textRgb = parseColor(textRaw);
    const bgRgb = parseColor(bgRaw);
    const result = {};

    if (bgRaw) {
        result.backgroundColor = bgRaw;
    }

    if (textRaw) {
        result.color = textRaw;
    }

    // Only adapt colors in dark mode.
    if (!darkTheme) {
        return result;
    }

    if (!textRgb && !bgRgb) {
        return result;
    }

    if (textRgb && !bgRgb) {
        const base = themeSurfaceSoftRgb();
        const ratio = 0.3;
        const tinted = blendRgb(base, textRgb, ratio);
        const readableBg = ensureContrastViaBackground(textRgb, tinted, 4.5);
        result.backgroundColor = rgbToHex(readableBg);
        return result;
    }

    if (!textRgb && bgRgb) {
        result.color = rgbToHex(autoTextColorForBackground(bgRgb));
        return result;
    }

    return result;
}
