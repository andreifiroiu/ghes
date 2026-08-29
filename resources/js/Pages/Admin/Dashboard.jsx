import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Stat from '@/Components/Admin/StatCard';

/**
 * @param {Object} props
 * @param {Object} props.stats
 */
export default function Dashboard({ stats }) {
    const { events, users, scraper_runs: runs } = stats;

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
            </div>
        </AdminLayout>
    );
}
