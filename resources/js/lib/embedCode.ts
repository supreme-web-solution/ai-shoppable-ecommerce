export function buildEmbedScriptCode(
    slug: string,
    type = 'vertical_feed',
    origin = typeof window !== 'undefined' ? window.location.origin : '',
): string {
    const scriptUrl = `${origin}/embed/embed.js`;
    const endTag = '</scr' + 'ipt>';

    return `<script async src="${scriptUrl}" data-embed="${slug}" data-type="${type}" data-height="700">${endTag}`;
}
