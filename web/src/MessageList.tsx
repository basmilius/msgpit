import clsx from "clsx";
import { useTranslation } from "react-i18next";
import { Icon, ListRow, PanelEmpty, Pill, Button } from "@basmilius/desktop-ui";
import { formatMoment } from "@basmilius/desktop-ui/format";
import { Inbox, Mail, MessageSquare } from "lucide-react";
import type { Message } from "./api";
import { previewOf, titleOf } from "./model";

const STATUS_TONE = { accepted: "muted", delivered: "idle", failed: "error" } as const;

function MessageRow({
    message,
    selected,
    onSelect,
}: {
    message: Message;
    selected: boolean;
    onSelect(id: string): void;
}) {
    const { t } = useTranslation("messages");
    return (
        <ListRow
            variant="inset"
            render={<button type="button" aria-current={selected ? "true" : undefined} />}
            className={clsx(
                "h-auto min-h-12 w-full gap-2 py-1.5 text-left text-sm",
                selected ? "bg-surface-active text-text" : "text-text-muted hover:bg-surface-hover",
            )}
            onClick={() => onSelect(message.id)}
        >
            <Icon
                icon={message.channel === "email" ? Mail : MessageSquare}
                size={16}
                className="shrink-0 text-text-faint"
            />
            <span className="flex min-w-0 grow flex-col">
                <span className={clsx("truncate", !message.read && "font-medium text-text")}>
                    {titleOf(message)}
                </span>
                <span className="truncate text-xs text-text-faint">{previewOf(message)}</span>
            </span>
            <span className="flex shrink-0 flex-col items-end gap-1 text-xs text-text-faint">
                <span className="tabular-nums">{formatMoment(new Date(message.createdAt))}</span>
                {message.status !== "accepted" && (
                    <Pill tone={STATUS_TONE[message.status as keyof typeof STATUS_TONE] ?? "muted"}>
                        {t(`status.${message.status}`)}
                    </Pill>
                )}
            </span>
            {!message.read && (
                <span
                    role="img"
                    aria-label={t("list.unread")}
                    className="size-1.5 shrink-0 rounded-full bg-text"
                />
            )}
        </ListRow>
    );
}

export function MessageList({
    messages,
    selectedId,
    loading,
    filtered,
    onSelect,
    onSetup,
}: {
    messages: Message[];
    selectedId: string | null;
    loading: boolean;
    filtered: boolean;
    onSelect(id: string): void;
    onSetup(): void;
}) {
    const { t } = useTranslation("messages");
    return (
        <nav
            aria-label={t("list.label")}
            className="message-list flex w-[340px] shrink-0 flex-col border-r border-border bg-surface"
        >
            {messages.length === 0 ? (
                <PanelEmpty
                    icon={Inbox}
                    busy={loading}
                    action={
                        filtered || loading ? undefined : (
                            <Button
                                title={t("list.emptyActionHint")}
                                variant="secondary"
                                onClick={onSetup}
                            >
                                {t("list.emptyAction")}
                            </Button>
                        )
                    }
                >
                    {loading
                        ? "Loading messages…"
                        : filtered
                          ? t("list.emptyFiltered")
                          : t("list.empty")}
                </PanelEmpty>
            ) : (
                <div className="flex min-h-0 grow flex-col gap-0.5 overflow-y-auto p-1.5">
                    {messages.map((message) => (
                        <MessageRow
                            key={message.id}
                            message={message}
                            selected={message.id === selectedId}
                            onSelect={onSelect}
                        />
                    ))}
                </div>
            )}
        </nav>
    );
}
