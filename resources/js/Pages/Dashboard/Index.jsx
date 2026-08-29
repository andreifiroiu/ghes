import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import EventList from '@/Components/Events/EventList';

/**
 * @param {Object} props
 * @param {Array<Object>} props.recommendations
 * @param {Array<Object>} props.discoveryEvents
 */
export default function Index({ recommendations = [], discoveryEvents = [] }) {
    return (
        <AppLayout>
            <Head title="Acasă" />

            {/* Recommended section */}
            <section className="mb-10">
                <h2 className="text-xl font-semibold text-gray-900 mb-4">
                    Recomandate pentru tine
                </h2>
                <EventList
                    events={recommendations}
                    emptyMessage="Nicio recomandare momentan. Finalizează onboarding-ul pentru sugestii personalizate."
                />
            </section>

            {/* Discovery section */}
            <section>
                <div className="mb-4">
                    <h2 className="text-xl font-semibold text-gray-900">
                        Descoperă ceva nou
                    </h2>
                    <p className="text-sm text-gray-500 mt-1">
                        Evenimente în afara intereselor tale obișnuite care ți-ar putea plăcea
                    </p>
                </div>
                <EventList
                    events={discoveryEvents}
                    emptyMessage="Niciun eveniment de descoperit momentan."
                />
            </section>
        </AppLayout>
    );
}
