import { Head, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardHeader, CardTitle, CardContent, CardFooter } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select } from '@/Components/ui/Select';
import { Badge } from '@/Components/ui/Badge';

/**
 * @param {Object} props
 * @param {Object} props.user
 * @param {Object} props.insights
 */
export default function UserShow({ user, insights }) {
    const { data, setData, put, processing } = useForm({
        name: user.name || '',
        email: user.email || '',
        city: user.city || '',
        notification_channel: user.notification_channel || 'email',
        notification_frequency: user.notification_frequency || 'daily',
    });

    const submit = (e) => {
        e.preventDefault();
        put(`/admin/users/${user.id}`);
    };

    const profile = insights.interest_profile || {};
    const reactions = insights.reactions_by_type || {};
    const discovery = insights.discovery || {};

    return (
        <AdminLayout title={`User — ${user.name}`}>
            <Head title="Admin — User" />

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle className="text-lg">Account</CardTitle></CardHeader>
                    <form onSubmit={submit}>
                        <CardContent className="space-y-4">
                            <div className="space-y-1">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="email">Email</Label>
                                <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="city">City</Label>
                                <Input id="city" value={data.city} onChange={(e) => setData('city', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="channel">Channel</Label>
                                <Select id="channel" value={data.notification_channel} onChange={(e) => setData('notification_channel', e.target.value)}>
                                    <option value="email">email</option>
                                    <option value="push">push</option>
                                    <option value="both">both</option>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="frequency">Frequency</Label>
                                <Select id="frequency" value={data.notification_frequency} onChange={(e) => setData('notification_frequency', e.target.value)}>
                                    <option value="daily">daily</option>
                                    <option value="weekly">weekly</option>
                                    <option value="realtime">realtime</option>
                                </Select>
                            </div>
                        </CardContent>
                        <CardFooter>
                            <Button type="submit" disabled={processing}>Save</Button>
                        </CardFooter>
                    </form>
                </Card>

                <Card>
                    <CardHeader><CardTitle className="text-lg">Insights</CardTitle></CardHeader>
                    <CardContent className="space-y-4 text-sm">
                        <div>
                            <p className="font-medium mb-1">Discovery</p>
                            <p className="text-gray-600">
                                openness {discovery.openness} · hit-rate {discovery.hit_rate} ({discovery.hits}/{discovery.resolved})
                            </p>
                        </div>
                        <div>
                            <p className="font-medium mb-1">Reactions</p>
                            <div className="flex flex-wrap gap-1">
                                {Object.entries(reactions).map(([type, count]) => (
                                    <Badge key={type}>{type}: {count}</Badge>
                                ))}
                                {Object.keys(reactions).length === 0 && <span className="text-gray-400">None</span>}
                            </div>
                        </div>
                        <div>
                            <p className="font-medium mb-1">Interest profile</p>
                            <div className="flex flex-wrap gap-1">
                                {Object.entries(profile).map(([key, score]) => (
                                    <Badge key={key}>{key}: {Number(score).toFixed(2)}</Badge>
                                ))}
                                {Object.keys(profile).length === 0 && <span className="text-gray-400">Empty</span>}
                            </div>
                        </div>
                        <div>
                            <p className="font-medium mb-1">Recent reactions</p>
                            <ul className="text-gray-600 space-y-1">
                                {(insights.recent_reactions || []).map((r, i) => (
                                    <li key={i}>{r.reaction} · {r.event_title || r.event_id}</li>
                                ))}
                            </ul>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
