import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';

/**
 * A single headline number with its label. Shared by the admin dashboard and
 * the user dashboard so both read the same.
 *
 * @param {Object} props
 * @param {string} props.label
 * @param {string|number} props.value
 * @param {string} [props.hint] Optional caption under the number.
 */
export default function StatTile({ label, value, hint }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-gray-500">
                    {label}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-bold text-[#0A1128]">{value}</p>
                {hint && <p className="mt-1 text-xs text-gray-400">{hint}</p>}
            </CardContent>
        </Card>
    );
}
