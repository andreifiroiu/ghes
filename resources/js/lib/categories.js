/**
 * Canonical event category list. Values match the lowercase `EventCategory`
 * enum (app/Enums/EventCategory.php); labels are the Romanian display names.
 * Single source of truth for filter chips and badges.
 *
 * @type {Array<{ value: string, label: string }>}
 */
export const CATEGORIES = [
    { value: 'music', label: 'Muzică' },
    { value: 'technology', label: 'Tech' },
    { value: 'sports', label: 'Sport' },
    { value: 'arts', label: 'Artă' },
    { value: 'food', label: 'Gastronomie' },
    { value: 'nightlife', label: 'Viața de noapte' },
    { value: 'business', label: 'Business' },
    { value: 'health', label: 'Sănătate' },
    { value: 'education', label: 'Educație' },
    { value: 'family', label: 'Familie' },
    { value: 'community', label: 'Comunitate' },
    { value: 'film', label: 'Film' },
    { value: 'literature', label: 'Literatură' },
    { value: 'other', label: 'Altele' },
];

const labels = Object.fromEntries(CATEGORIES.map(({ value, label }) => [value, label]));

const colors = {
    music: 'bg-purple-100 text-purple-800 border-purple-200',
    technology: 'bg-blue-100 text-blue-800 border-blue-200',
    sports: 'bg-green-100 text-green-800 border-green-200',
    arts: 'bg-pink-100 text-pink-800 border-pink-200',
    food: 'bg-orange-100 text-orange-800 border-orange-200',
    nightlife: 'bg-violet-100 text-violet-800 border-violet-200',
    business: 'bg-slate-100 text-slate-800 border-slate-200',
    health: 'bg-teal-100 text-teal-800 border-teal-200',
    education: 'bg-cyan-100 text-cyan-800 border-cyan-200',
    family: 'bg-lime-100 text-lime-800 border-lime-200',
    community: 'bg-amber-100 text-amber-800 border-amber-200',
    film: 'bg-rose-100 text-rose-800 border-rose-200',
    literature: 'bg-fuchsia-100 text-fuchsia-800 border-fuchsia-200',
    other: 'bg-gray-100 text-gray-800 border-gray-200',
};

/**
 * Romanian label for a category value, falling back to the raw value.
 *
 * @param {string} value
 * @returns {string}
 */
export function categoryLabel(value) {
    return labels[value] || value;
}

/**
 * Tailwind color classes for a category value, falling back to "other".
 *
 * @param {string} value
 * @returns {string}
 */
export function categoryColor(value) {
    return colors[value] || colors.other;
}
