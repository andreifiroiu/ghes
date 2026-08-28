import { Head, useForm, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardFooter } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select } from '@/Components/ui/Select';

/**
 * @param {Object} props
 * @param {Object} props.event
 * @param {Array<string>} props.categories
 */
export default function EventEdit({ event, categories = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        title: event.title || '',
        description: event.description || '',
        category: event.category || '',
        tags: event.tags || [],
        venue: event.venue || '',
        address: event.address || '',
        city: event.city || '',
        starts_at: event.starts_at ? event.starts_at.slice(0, 16) : '',
        ends_at: event.ends_at ? event.ends_at.slice(0, 16) : '',
        price_min: event.price_min ?? '',
        price_max: event.price_max ?? '',
        is_free: !!event.is_free,
        image_url: event.image_url || '',
    });

    const field = (label, key, type = 'text') => (
        <div className="space-y-1">
            <Label htmlFor={key}>{label}</Label>
            <Input id={key} type={type} value={data[key]} onChange={(e) => setData(key, e.target.value)} />
            {errors[key] && <p className="text-sm text-red-600">{errors[key]}</p>}
        </div>
    );

    const submit = (e) => {
        e.preventDefault();
        put(`/admin/events/${event.id}`);
    };

    return (
        <AdminLayout title="Edit event">
            <Head title="Admin — Edit event" />
            <Card className="max-w-3xl">
                <form onSubmit={submit}>
                    <CardContent className="space-y-4 pt-6">
                        {field('Title', 'title')}
                        <div className="space-y-1">
                            <Label htmlFor="description">Description</Label>
                            <textarea
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                className="w-full rounded-md border border-gray-300 p-2 text-sm"
                                rows={4}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="category">Category</Label>
                            <Select id="category" value={data.category} onChange={(e) => setData('category', e.target.value)}>
                                {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="tags">Tags (comma-separated)</Label>
                            <Input
                                id="tags"
                                value={(data.tags || []).join(', ')}
                                onChange={(e) => setData('tags', e.target.value.split(',').map((t) => t.trim()).filter(Boolean))}
                            />
                        </div>
                        {field('Venue', 'venue')}
                        {field('Address', 'address')}
                        {field('City', 'city')}
                        {field('Starts at', 'starts_at', 'datetime-local')}
                        {field('Ends at', 'ends_at', 'datetime-local')}
                        {field('Price min', 'price_min', 'number')}
                        {field('Price max', 'price_max', 'number')}
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={data.is_free} onChange={(e) => setData('is_free', e.target.checked)} />
                            <span className="text-sm">Free event</span>
                        </label>
                        {field('Image URL', 'image_url')}
                    </CardContent>
                    <CardFooter className="flex gap-3">
                        <Button type="submit" disabled={processing}>Save</Button>
                        <Link href="/admin/events" className="text-sm text-gray-500 self-center">Cancel</Link>
                    </CardFooter>
                </form>
            </Card>
        </AdminLayout>
    );
}
