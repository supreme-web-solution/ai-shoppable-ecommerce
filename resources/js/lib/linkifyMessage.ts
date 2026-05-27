export type MessagePart =
    | { type: 'text'; text: string }
    | { type: 'link'; text: string; href: string };

const URL_PATTERN =
    /(?:https?:\/\/|www\.)[^\s<]+[^\s<.,;:!?'")\]}>]/gi;

export function normalizeLinkHref(url: string): string | null {
    const trimmed = url.trim();

    if (trimmed === '') {
        return null;
    }

    try {
        const withProtocol = /^https?:\/\//i.test(trimmed)
            ? trimmed
            : `https://${trimmed}`;
        const parsed = new URL(withProtocol);

        if (!['http:', 'https:'].includes(parsed.protocol)) {
            return null;
        }

        return parsed.toString();
    } catch {
        return null;
    }
}

export function parseMessageParts(message: string): MessagePart[] {
    if (message === '') {
        return [{ type: 'text', text: '' }];
    }

    const parts: MessagePart[] = [];
    let lastIndex = 0;

    for (const match of message.matchAll(URL_PATTERN)) {
        const index = match.index ?? 0;

        if (index > lastIndex) {
            parts.push({ type: 'text', text: message.slice(lastIndex, index) });
        }

        const raw = match[0];
        const href = normalizeLinkHref(raw);

        if (href) {
            parts.push({ type: 'link', text: raw, href });
        } else {
            parts.push({ type: 'text', text: raw });
        }

        lastIndex = index + raw.length;
    }

    if (lastIndex < message.length) {
        parts.push({ type: 'text', text: message.slice(lastIndex) });
    }

    if (parts.length === 0) {
        return [{ type: 'text', text: message }];
    }

    return parts;
}
