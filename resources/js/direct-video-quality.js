export function directVideoSource(renditions, fallbackUrl, preference, effectiveType) {
    const heights = Object.keys(renditions).map(Number).filter(height => Number.isInteger(height) && height > 0).sort((a, b) => a - b);
    const slow = ['slow-2g', '2g', '3g'].includes(effectiveType);
    const automatic = slow ? heights[0] : (heights.filter(height => height <= 720).at(-1) || heights.at(-1));
    const requested = preference === 'auto' ? automatic : Number(preference);
    const height = heights.includes(requested) ? requested : automatic;
    return {url: renditions[height] || fallbackUrl, height};
}
