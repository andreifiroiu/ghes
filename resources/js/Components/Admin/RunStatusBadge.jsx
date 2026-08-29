import { Badge } from '@/Components/ui/Badge';

/** @type {Object<string, string>} */
const VARIANTS = {
    completed: 'default',
    failed: 'destructive',
    running: 'secondary',
};

/**
 * A scraper run's status, coloured so failures stand out in a dense table.
 *
 * @param {Object} props
 * @param {string|null|undefined} props.status
 */
export default function RunStatusBadge({ status }) {
    if (!status) {
        return <span className="text-gray-400">never run</span>;
    }

    return <Badge variant={VARIANTS[status] ?? 'outline'}>{status}</Badge>;
}
