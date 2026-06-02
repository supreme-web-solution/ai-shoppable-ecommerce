export type OverlayKind = 'product' | 'flash' | 'coupon';

export type TagPosition = {
    x?: number;
    y?: number;
    anchor?: string;
};

export type TimedTagLike = {
    id: number;
    starts_at_ms?: number | null;
    ends_at_ms?: number | null;
    is_pinned?: boolean;
    overlay_kind?: OverlayKind | string | null;
    coupon_code?: string | null;
    discount_percent?: string | number | null;
    position?: TagPosition | null;
};

/** x/y = inset % from the anchored edges (not raw coordinates). */
const DEFAULT_POSITION: Record<OverlayKind, TagPosition> = {
    product: { x: 4, y: 10, anchor: 'bottom-left' },
    flash: { x: 4, y: 10, anchor: 'top-right' },
    coupon: { x: 50, y: 42, anchor: 'center' },
};

export type OverlaySlot = 'top' | 'middle' | 'bottom';

export function overlaySlotForAnchor(anchor?: string | null): OverlaySlot {
    switch (anchor) {
        case 'center':
            return 'middle';
        case 'bottom-left':
        case 'bottom-right':
            return 'bottom';
        default:
            return 'top';
    }
}

export function resolveOverlayKind(tag: TimedTagLike): OverlayKind {
    const kind = tag.overlay_kind;
    if (kind === 'flash' || kind === 'coupon' || kind === 'product') {
        return kind;
    }

    if (tag.coupon_code?.trim()) {
        return 'coupon';
    }

    const discount = Number(tag.discount_percent ?? 0);
    if (discount >= 15) {
        return 'flash';
    }

    return 'product';
}

export function isTagActiveAt(tag: TimedTagLike, atMs: number): boolean {
    if (tag.is_pinned) {
        return false;
    }

    const start = tag.starts_at_ms ?? 0;
    const end = tag.ends_at_ms ?? Number.MAX_SAFE_INTEGER;

    return atMs >= start && atMs <= end;
}

export function tagPosition(tag: TimedTagLike): TagPosition {
    const kind = resolveOverlayKind(tag);
    const pos = tag.position ?? {};

    return {
        ...DEFAULT_POSITION[kind],
        ...pos,
        anchor: pos.anchor ?? DEFAULT_POSITION[kind].anchor,
    };
}

export function tagOverlayStyle(
    tag: TimedTagLike,
    mode: 'absolute' | 'docked' = 'absolute',
): Record<string, string> {
    if (mode === 'docked') {
        return {};
    }

    const pos = tagPosition(tag);
    const anchor = pos.anchor ?? 'bottom-left';
    const insetX = Math.min(20, Math.max(2, Number(pos.x ?? 4)));
    const insetY = Math.min(36, Math.max(6, Number(pos.y ?? 10)));

    const style: Record<string, string> = {
        position: 'absolute',
        maxWidth: 'min(calc(100% - 76px), 260px)',
        width: 'max-content',
        minWidth: 'min(200px, 100%)',
        zIndex: '25',
    };

    if (anchor === 'center') {
        style.left = '50%';
        style.top = `${insetY}%`;
        style.transform = 'translate(-50%, -50%)';
    } else if (anchor === 'top-right' || anchor === 'bottom-right') {
        style.right = `${insetX}%`;
        if (anchor === 'bottom-right') {
            style.bottom = `${insetY}%`;
        } else {
            style.top = `${insetY}%`;
        }
    } else if (anchor === 'top-left') {
        style.left = `${insetX}%`;
        style.top = `${insetY}%`;
    } else {
        style.left = `${insetX}%`;
        style.bottom = `${insetY}%`;
    }

    return style;
}

export function msRemaining(
    endsAtMs: number | null | undefined,
    currentMs: number,
): number {
    if (endsAtMs == null || endsAtMs <= 0) {
        return 0;
    }

    return Math.max(0, endsAtMs - currentMs);
}

export function formatCountdown(ms: number): string {
    const totalSec = Math.ceil(ms / 1000);
    const m = Math.floor(totalSec / 60);
    const s = totalSec % 60;

    return `${m}:${String(s).padStart(2, '0')}`;
}
