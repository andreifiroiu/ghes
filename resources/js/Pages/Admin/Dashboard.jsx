import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatTile from '@/Components/StatTile';

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
                <StatTile label="Events" value={events.total} />
                <StatTile label="Upcoming" value={events.upcoming} />
                <StatTile label="Classified" value={events.classified} />
                <StatTile label="Hidden" value={events.hidden} />
                <StatTile label="Users" value={users.total} />
                <StatTile label="Onboarded" value={users.onboarded} />
                <StatTile label="Scraper runs" value={runs.total} />
                <StatTile label="Failed runs" value={runs.failed} />
                <StatTile label="Clicks (7d)" value={activity.clicks_7d} />
                <StatTile label="Views (7d)" value={activity.views_7d} />
            </div>
        </AdminLayout>
    );
}
