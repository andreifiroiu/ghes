import { router } from '@inertiajs/react';

/**
 * Numbered pagination for an API-Resource paginated payload.
 *
 * A resource collection over a paginator exposes `links` as an object
 * ({first, last, prev, next}) and the numbered link array as `meta.links`.
 *
 * @param {Object} props
 * @param {{meta?: {links?: Array<{url: string|null, label: string, active: boolean}>}}} props.paginator
 */
export default function Pagination({ paginator }) {
    const links = paginator?.meta?.links;

    if (!Array.isArray(links) || links.length <= 3) {
        return null;
    }

    return (
        <div className="mt-4 flex flex-wrap gap-2">
            {links.map((link, i) => (
                <button
                    key={i}
                    disabled={!link.url}
                    onClick={() => link.url && router.get(link.url)}
                    className={`inline-flex min-h-11 min-w-11 items-center justify-center rounded px-3 py-2 text-sm sm:min-h-0 sm:min-w-0 sm:py-1 ${link.active ? 'bg-[#0A1128] text-white' : 'bg-white border'} ${!link.url ? 'opacity-40' : ''}`}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    );
}
