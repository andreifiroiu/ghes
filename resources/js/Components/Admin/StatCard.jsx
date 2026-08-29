import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';

/**
 * A single headline number with its label.
 *
 * @param {Object} props
 * @param {string} props.label
 * @param {string|number} props.value
 * @param {string} [props.hint] Secondary line under the value.
 */
export default function StatCard({ label, value, hint }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-gray-500">{label}</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-bold text-[#0A1128]">{value}</p>
                {hint && <p className="mt-1 text-xs text-gray-500">{hint}</p>}
            </CardContent>
        </Card>
    );
}
