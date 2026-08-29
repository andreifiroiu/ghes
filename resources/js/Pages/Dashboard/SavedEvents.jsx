import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import EventList from '@/Components/Events/EventList';

/**
 * @param {Object} props
 * @param {Array<Object>} props.events
 */
export default function SavedEvents({ events = [] }) {
    return (
        <AppLayout title="Evenimente salvate">
            <Head title="Evenimente salvate" />
            <EventList
                events={events}
                emptyMessage="Nu ai salvat niciun eveniment. Răsfoiește evenimentele și adaugă-le la favorite pe cele care îți plac."
            />
        </AppLayout>
    );
}
