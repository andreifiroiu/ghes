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
 * @param {Object} props
 * @param {Object} props.settings
 * @param {string} props.settings.channel
 * @param {string} props.settings.frequency
 */
export default function Notifications({ settings = {}, vapidPublicKey = null }) {
    const { data, setData, put, processing, recentlySuccessful } = useForm({
        channel: settings.channel || 'email',
        frequency: settings.frequency || 'daily',
    });

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

            await fetch('/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(subscription.toJSON()),
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
                        <CardFooter className="flex items-center gap-4">
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
