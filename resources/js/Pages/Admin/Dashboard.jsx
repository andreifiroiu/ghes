import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';

function Stat({ label, value }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-gray-500">
                    {label}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-bold text-[#0A1128]">{value}</p>
            </CardContent>
        </Card>
    );
}

/**
 * @param {Object} props
 * @param {Object} props.stats
 */
export default function Dashboard({ stats }) {
    const { events, users, scraper_runs: runs, activity } = stats;

    return (
        <AdminLayout title="Dashboard">
            <Head title="Admin — Dashboard" />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <Stat label="Events" value={events.total} />
                <Stat label="Upcoming" value={events.upcoming} />
                <Stat label="Classified" value={events.classified} />
                <Stat label="Hidden" value={events.hidden} />
                <Stat label="Users" value={users.total} />
                <Stat label="Onboarded" value={users.onboarded} />
                <Stat label="Scraper runs" value={runs.total} />
                <Stat label="Failed runs" value={runs.failed} />
                <Stat label="Clicks (7d)" value={activity.clicks_7d} />
                <Stat label="Views (7d)" value={activity.views_7d} />
            </div>
        </AdminLayout>
    );
}
