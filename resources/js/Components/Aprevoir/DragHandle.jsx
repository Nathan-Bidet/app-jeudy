import { GripVertical } from 'lucide-react';

export default function DragHandle({ className = '' }) {
    return (
        <span
            className={`inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)] ${className}`}
            title="Glisser-déposer"
        >
            <GripVertical className="h-4 w-4" strokeWidth={2} />
        </span>
    );
}

