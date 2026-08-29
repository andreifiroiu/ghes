import { cn } from '@/lib/utils';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';

const CATEGORY_COLORS = {
    music: 'bg-purple-100 text-purple-800',
    arts: 'bg-pink-100 text-pink-800',
    sports: 'bg-green-100 text-green-800',
    technology: 'bg-blue-100 text-blue-800',
    food: 'bg-orange-100 text-orange-800',
    nightlife: 'bg-indigo-100 text-indigo-800',
    business: 'bg-gray-100 text-gray-800',
    health: 'bg-teal-100 text-teal-800',
    education: 'bg-yellow-100 text-yellow-800',
    family: 'bg-rose-100 text-rose-800',
    community: 'bg-amber-100 text-amber-800',
    film: 'bg-red-100 text-red-800',
    literature: 'bg-emerald-100 text-emerald-800',
};

/**
 * @param {Object} props
 * @param {Object<string, number>} props.profile
 */
export default function ProfilePreviewCard({ profile }) {
    const categories = Object.entries(profile)
        .filter(([key]) => !key.startsWith('tag:') && !key.startsWith('source:'))
        .filter(([, val]) => typeof val === 'number')
        .sort(([, a], [, b]) => b - a);

    const tags = Object.entries(profile)
        .filter(([key]) => key.startsWith('tag:'))
        .filter(([, val]) => typeof val === 'number' && val >= 0.3)
        .sort(([, a], [, b]) => b - a);

    return (
        <Card className="border-green-200 bg-green-50">
            <CardHeader className="pb-3">
                <CardTitle className="text-green-800 text-base">
                    Profilul tău de interese
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Category bars */}
                {categories.length > 0 && (
                    <div className="space-y-2">
                        {categories.map(([name, score]) => (
                            <div key={name} className="flex items-center gap-2">
                                <span className="text-sm font-medium text-gray-700 w-24 capitalize">
                                    {name}
                                </span>
                                <div className="flex-1 bg-gray-200 rounded-full h-2.5">
                                    <div
                                        className={cn(
                                            'h-2.5 rounded-full',
                                            CATEGORY_COLORS[name]
                                                ? 'bg-indigo-500'
                                                : 'bg-gray-400',
                                        )}
                                        style={{
                                            width: `${Math.round(score * 100)}%`,
                                        }}
                                    />
                                </div>
                                <span className="text-xs text-gray-500 w-8 text-right">
                                    {Math.round(score * 100)}%
                                </span>
                            </div>
                        ))}
                    </div>
                )}

                {/* Tags */}
                {tags.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {tags.map(([key, score]) => (
                            <Badge
                                key={key}
                                variant="secondary"
                                className={cn(
                                    'text-xs',
                                    score >= 0.7
                                        ? 'bg-indigo-100 text-indigo-700'
                                        : 'bg-gray-100 text-gray-600',
                                )}
                            >
                                {key.replace('tag:', '')}
                            </Badge>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
