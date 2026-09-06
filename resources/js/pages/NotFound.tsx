import { Link } from 'react-router-dom';
import { EmptyState } from '@/components/ui';

export function NotFoundPage() {
    return (
        <EmptyState
            title="That page does not exist"
            description="The link may be out of date, or the record may have been removed."
            action={
                <Link to="/" className="underline underline-offset-2">
                    Back to the dashboard
                </Link>
            }
        />
    );
}
