import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Label } from '@/Components/ui/Label';
import { Select } from '@/Components/ui/Select';
import { cn } from '@/lib/utils';

const channelOptions = [
    { value: 'email', label: 'Email' },
    { value: 'push', label: 'Push Notifications' },
    { value: 'both', label: 'Both Email & Push' },
];

const frequencyOptions = [
    { value: 'realtime', label: 'Real-time' },
    { value: 'daily', label: 'Daily Digest' },
    { value: 'weekly', label: 'Weekly Digest' },
];

/**
 * Romanian labels for the lead times the server offers, keyed by minutes.
 * Anything the server sends that is not listed here still renders, as a plain
 * minute count, so adding an option to config is never a blank checkbox.
 */
const leadLabels = {
    1440: 'Cu o zi înainte',
    360: 'Cu 6 ore înainte',
    180: 'Cu 3 ore înainte',
    60: 'Cu o oră înainte',
};

const leadLabel = (minutes) =>
    leadLabels[minutes] ?? `Cu ${minutes} de minute înainte`;

/**
 * @param {Object} props
 * @param {Object} props.user - The UserResource the controller renders; the
 *   stored preferences live on it as notification_channel / notification_frequency.
 * @param {number[]} props.reminderLeadOptions - Lead times the server accepts.
 * @param {string|null} props.vapidPublicKey
 */
export default function Notifications({
    user = {},
    reminderLeadOptions = [],
    vapidPublicKey = null,
}) {
    const { data, setData, put, processing, recentlySuccessful } = useForm({
        channel: user.notification_channel || 'email',
        frequency: user.notification_frequency || 'daily',
        event_reminders: user.event_reminders_enabled ?? true,
        reminder_lead_minutes: user.reminder_lead_minutes ?? [],
    });

    const toggleLead = (minutes) => {
        setData(
            'reminder_lead_minutes',
            data.reminder_lead_minutes.includes(minutes)
                ? data.reminder_lead_minutes.filter((m) => m !== minutes)
                : [...data.reminder_lead_minutes, minutes]
        );
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        put('/settings/notifications');
    };

    const handleEnablePush = async () => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            alert('Browserul tău nu suportă notificările push.');
            return;
        }
        if (!vapidPublicKey) {
            alert('Notificările push nu sunt configurate pe server.');
            return;
        }

        try {
            const registration = await navigator.serviceWorker.register('/sw.js');
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') return;

            const key = Uint8Array.from(
                atob(vapidPublicKey.replace(/-/g, '+').replace(/_/g, '/')),
                (c) => c.charCodeAt(0)
            );
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: key,
            });

            const csrfToken = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            // A stable id for this browser install, shared with a native app
            // on the same handset so the digest is not delivered twice to it.
            let installId = null;
            try {
                installId = localStorage.getItem('ghes.install_id');
                if (!installId) {
                    installId = crypto.randomUUID();
                    localStorage.setItem('ghes.install_id', installId);
                }
            } catch {
                installId = null;
            }

            await fetch('/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ ...subscription.toJSON(), install_id: installId }),
            });

            alert('Notificările push au fost activate.');
        } catch (e) {
            alert('Nu am putut activa notificările push.');
        }
    };

    return (
        <AppLayout title="Notification Settings">
            <Head title="Notification Settings" />

            <div className="max-w-2xl">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">
                            Notification Preferences
                        </CardTitle>
                        <CardDescription>
                            Choose how and when you want to receive event
                            recommendations.
                        </CardDescription>
                    </CardHeader>
                    <form onSubmit={handleSubmit}>
                        <CardContent className="space-y-6">
                            {/* Channel selection */}
                            <div className="space-y-3">
                                <Label className="text-base font-medium">
                                    Notification Channel
                                </Label>
                                <div className="space-y-2">
                                    {channelOptions.map((option) => (
                                        <label
                                            key={option.value}
                                            className={cn(
                                                'flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors',
                                                data.channel === option.value
                                                    ? 'border-indigo-500 bg-indigo-50'
                                                    : 'border-gray-200 hover:bg-gray-50'
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="channel"
                                                value={option.value}
                                                checked={
                                                    data.channel === option.value
                                                }
                                                onChange={(e) =>
                                                    setData('channel', e.target.value)
                                                }
                                                className="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <span className="text-sm font-medium text-gray-900">
                                                {option.label}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </div>

                            {/* Frequency selection */}
                            <div className="space-y-2">
                                <Label htmlFor="frequency" className="text-base font-medium">
                                    Frequency
                                </Label>
                                <Select
                                    id="frequency"
                                    value={data.frequency}
                                    onChange={(e) =>
                                        setData('frequency', e.target.value)
                                    }
                                >
                                    {frequencyOptions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </Select>
                            </div>

                            {/* Event reminders */}
                            <div className="space-y-3 border-t border-gray-100 pt-4">
                                <label className="flex items-start gap-3 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={data.event_reminders}
                                        onChange={(e) =>
                                            setData('event_reminders', e.target.checked)
                                        }
                                        className="mt-1 h-4 w-4 rounded text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span>
                                        <span className="block text-base font-medium text-gray-900">
                                            Memento pentru evenimentele salvate
                                        </span>
                                        <span className="block text-sm text-gray-500">
                                            Îți dăm ghes înainte să înceapă un eveniment
                                            pe care l-ai salvat sau ai spus că te
                                            interesează. Dacă îl salvezi mai târziu de
                                            atât, nu mai primești memento.
                                        </span>
                                    </span>
                                </label>

                                {data.event_reminders && (
                                    <div className="space-y-2 pl-7">
                                        {reminderLeadOptions.map((minutes) => (
                                            <label
                                                key={minutes}
                                                className="flex items-center gap-3 cursor-pointer"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={data.reminder_lead_minutes.includes(
                                                        minutes
                                                    )}
                                                    onChange={() => toggleLead(minutes)}
                                                    className="h-4 w-4 rounded text-indigo-600 focus:ring-indigo-500"
                                                />
                                                <span className="text-sm text-gray-900">
                                                    {leadLabel(minutes)}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Web push */}
                            <div className="space-y-2 border-t border-gray-100 pt-4">
                                <Label className="text-base font-medium">
                                    Notificări push în browser
                                </Label>
                                <p className="text-sm text-gray-500">
                                    Activează notificările push pentru a primi
                                    recomandări direct în browser (necesită canalul
                                    „push" sau „both").
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={handleEnablePush}
                                >
                                    Activează notificările push
                                </Button>
                            </div>
                        </CardContent>
                        <CardFooter className="flex flex-wrap items-center gap-4">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving...' : 'Save Settings'}
                            </Button>
                            {recentlySuccessful && (
                                <p className="text-sm text-green-600">
                                    Settings saved successfully.
                                </p>
                            )}
                        </CardFooter>
                    </form>
                </Card>
            </div>
        </AppLayout>
    );
}
