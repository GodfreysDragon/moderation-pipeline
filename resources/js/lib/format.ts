export const dateTime = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

export const shortDate = (isoDate: string) =>
    new Date(`${isoDate}T00:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });

const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

export const timeAgo = (iso: string) => {
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return rtf.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
};

export const percent = (ratio: number | null, digits = 0) =>
    ratio === null ? '—' : `${(ratio * 100).toFixed(digits)}%`;

export const minutes = (value: number | null) => {
    if (value === null) {
        return '—';
    }

    if (value < 1) {
        return '< 1 min';
    }

    return value < 90 ? `${value} min` : `${(value / 60).toFixed(1)} h`;
};

export const sourceLabel: Record<string, string> = {
    blocklist: 'Known-content match',
    policy: 'Automated policy',
    moderator: 'Moderator',
    appeal: 'Appeal review',
    audit: 'QA audit',
};
