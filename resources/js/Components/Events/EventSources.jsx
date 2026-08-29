import { Card, CardContent } from '@/Components/ui/Card';
import { sourceLabel } from '@/lib/sources';

/**
 * Credits every provider that reported an event, linking back to the listing
 * we scraped it from.
 *
 * Deduplicated by adapter key rather than by URL: one provider can legitimately
 * contribute several rows (a recurring event reuses one URL across occurrences),
 * and naming the same site twice reads as a bug on a credits list. The first URL
 * for a given provider wins.
 *
 * @param {Object} props
 * @param {Array<{source: string, source_url: string}>} [props.sources]
 * @param {(link: {source: string, source_url: string}) => string} [props.hrefFor]
 *   Resolves the outbound URL, so the caller can route credit clicks through
 *   the tracked redirect. Defaults to linking the provider directly.
 */
export default function EventSources({
    sources = [],
    hrefFor = (link) => link.source_url,
}) {
    const credits = [];
    const seen = new Set();

    for (const entry of sources) {
        if (!entry?.source_url || seen.has(entry.source)) {
            continue;
        }

        seen.add(entry.source);
        credits.push(entry);
    }

    if (credits.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardContent className="p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-3 flex items-center gap-2">
                    <svg
                        className="w-5 h-5 text-gray-400 flex-shrink-0"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"
                        />
                    </svg>
                    Surse
                </h2>

                <p className="text-sm text-gray-500">
                    Informațiile despre acest eveniment provin de la:
                </p>

                <ul className="mt-3 space-y-2">
                    {credits.map((credit) => (
                        <li key={credit.source_url}>
                            <a
                                href={hrefFor(credit)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 underline-offset-4 hover:underline"
                            >
                                {sourceLabel(credit.source) ?? 'Sursa originală'}
                                <svg
                                    className="w-4 h-4 flex-shrink-0"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth={2}
                                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                                    />
                                </svg>
                            </a>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
