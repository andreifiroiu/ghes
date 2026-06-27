import { Badge } from '@/Components/ui/Badge';
import { cn } from '@/lib/utils';
import { categoryColor, categoryLabel } from '@/lib/categories';

/**
 * @param {Object} props
 * @param {string} props.category
 */
export default function CategoryBadge({ category }) {
    return (
        <Badge variant="outline" className={cn(categoryColor(category))}>
            {categoryLabel(category)}
        </Badge>
    );
}
