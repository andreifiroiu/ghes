import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Pagination from '@/Components/Pagination';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';

/**
 * @param {Object} props
 * @param {{data: Array<Object>, links?: Array}} props.users
 * @param {Object} props.filters
 */
export default function UsersIndex({ users, filters = {} }) {
    const [search, setSearch] = useState(filters.search || '');

    const submit = (e) => {
        e.preventDefault();
        router.get('/admin/users', { search }, { preserveState: true, replace: true });
    };

    const destroy = (id) => {
        if (confirm('Delete this user permanently?')) {
            router.delete(`/admin/users/${id}`, { preserveScroll: true });
        }
    };

    const rows = users.data || [];

    return (
        <AdminLayout title="Users">
            <Head title="Admin — Users" />

            <form onSubmit={submit} className="flex flex-wrap gap-2 mb-4">
                <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name or email…" className="max-w-xs" />
                <Button type="submit">Search</Button>
            </form>

            <Card>
                <CardContent className="p-0 overflow-x-auto">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Name</th>
                                <th className="px-4 py-2">Email</th>
                                <th className="px-4 py-2">City</th>
                                <th className="px-4 py-2">Reactions</th>
                                <th className="px-4 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={5} className="px-4 py-6 text-center text-gray-400">No users.</td></tr>
                            )}
                            {rows.map((user) => (
                                <tr key={user.id} className="border-t border-gray-100">
                                    <td className="px-4 py-2 font-medium">{user.name}</td>
                                    <td className="px-4 py-2">{user.email}</td>
                                    <td className="px-4 py-2">{user.city}</td>
                                    <td className="px-4 py-2">{user.reactions_count ?? 0}</td>
                                    <td className="px-4 py-2 text-right space-x-3 whitespace-nowrap">
                                        <Link href={`/admin/users/${user.id}`} className="text-[#FF5733] hover:underline">View</Link>
                                        <button onClick={() => destroy(user.id)} className="inline-flex min-h-11 items-center text-red-600 hover:underline sm:min-h-0">Delete</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Pagination paginator={users} />
        </AdminLayout>
    );
}
