import clsx from "clsx";

export interface TabItem<T extends string> {
    id: T;
    label: string;
    /* A count after the label; 0 and undefined draw none. */
    count?: number;
}

export interface TabsProps<T extends string> {
    tabs: readonly TabItem<T>[];
    value: T;
    onValueChange(id: T): void;
    /* The accessible name of the strip. */
    label: string;
    className?: string;
}

// todo(desktop-ui#30): replace with the library component once it ships.
export function Tabs<T extends string>({
    tabs,
    value,
    onValueChange,
    label,
    className,
}: TabsProps<T>) {
    return (
        <div
            role="tablist"
            aria-label={label}
            className={clsx(
                "flex shrink-0 items-end gap-4 overflow-x-auto border-b border-border px-4",
                className,
            )}
        >
            {tabs.map((tab) => {
                const selected = tab.id === value;
                return (
                    <button
                        key={tab.id}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        className={clsx(
                            "-mb-px flex h-9 shrink-0 items-center gap-1.5 border-b-2 text-xs font-medium",
                            selected
                                ? "border-text text-text"
                                : "border-transparent text-text-muted hover:text-text",
                        )}
                        onClick={() => onValueChange(tab.id)}
                    >
                        {tab.label}
                        {tab.count !== undefined && tab.count > 0 && (
                            <span className="rounded-full bg-surface-sunken px-1.5 text-text-muted tabular-nums">
                                {tab.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
