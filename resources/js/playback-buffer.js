export function playbackBuffer(media) {
    const position = Math.max(0, Number(media?.currentTime) || 0);
    const duration = Number.isFinite(media?.duration) ? media.duration : Infinity;
    let end = position;
    try {
        for (let index = 0; index < (media?.buffered?.length || 0); index++) {
            if (media.buffered.start(index) <= position + 0.1 && media.buffered.end(index) >= position) {
                end = Math.min(duration, media.buffered.end(index));
                break;
            }
        }
    } catch {}
    return {end: Math.max(position, end), ahead: Math.max(0, end - position)};
}

export function playbackPosition(media, fallback = 0) {
    const current = Number(media?.currentTime);
    return media?.readyState >= 1 && Number.isFinite(current) ? Math.max(0, current) : Math.max(0, Number(fallback) || 0);
}
