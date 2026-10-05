import { lazy, Suspense, useCallback, useRef, useState } from "react";
import { Bell, BookOpen, Inbox, Search, Settings2 } from "lucide-react";
import { Icon, IconButton, ListRow, Pill } from "@basmilius/desktop-ui";
import { SettingsDialog } from "@basmilius/desktop-ui/settings";
import { request } from "./api";
import { useNotifications } from "./use-notifications";
const DocsPanel = lazy(() =>
    import("./DocsPanel").then((module) => ({ default: module.DocsPanel })),
);
import { MessageDetail } from "./MessageDetail";
import { MessageList } from "./MessageList";
import { MessagesToolbar } from "./MessagesToolbar";
import { SetupPanel } from "./SetupPanel";
import { AppearancePane } from "./theme";
import { useInbox, useMessage } from "./use-inbox";

const SETTINGS_GROUPS = [
    {
        label: null,
        sections: [
            {
                id: "general",
                icon: Settings2,
                label: "General",
                description: "Choose how msgpit looks.",
                pane: AppearancePane,
            },
        ],
    },
];

export function App() {
    const [docsOpen, setDocsOpen] = useState(false);
    const [channel, setChannel] = useState("");
    const [search, setSearch] = useState("");
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [setupOpen, setSetupOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const selectNotification = useCallback((id: string) => {
        setSelectedId(id);
        setSetupOpen(false);
        setDocsOpen(false);
    }, []);
    const notifications = useNotifications(selectNotification);
    const searchRef = useRef<HTMLInputElement>(null);
    const importRef = useRef<HTMLInputElement>(null);
    const inbox = useInbox(channel, search);
    const message = useMessage(selectedId, inbox.revision);

    async function run(work: () => Promise<unknown>) {
        setBusy(true);
        try {
            await work();
            await inbox.refresh();
        } catch (error) {
            inbox.setError((error as Error).message);
            throw error;
        } finally {
            setBusy(false);
        }
    }

    function perform(work: () => Promise<unknown>) {
        void run(work).catch(() => {});
    }

    async function importMail(files: File[]) {
        await run(async () => {
            for (const file of files)
                await request("/api/messages/import", {
                    method: "POST",
                    headers: {
                        "Content-Type": "message/rfc822",
                        "X-Msgpit-Filename": encodeURIComponent(file.name),
                    },
                    body: file,
                });
        });
    }

    return (
        <div
            className="flex h-full min-h-0"
            onDragOver={(event) => event.preventDefault()}
            onDrop={(event) => {
                event.preventDefault();
                const files = Array.from(event.dataTransfer.files);
                if (files.length) void importMail(files).catch(() => {});
                else
                    inbox.setError(
                        "Mail supplied a message pointer rather than a file. Drag the message to Finder first, then drop the .eml here, or use Import .eml.",
                    );
            }}
        >
            <nav
                aria-label="Screens"
                className="app-sidebar flex h-full w-62 shrink-0 flex-col border-r border-border bg-surface"
            >
                <header className="flex h-12 shrink-0 items-center pl-4 text-xs font-semibold text-text">
                    msgpit
                </header>
                <div className="mt-2 overflow-y-auto px-2">
                    <p className="sidebar-label px-2 py-1 text-xs font-medium text-text-faint">
                        Local
                    </p>
                    <ListRow
                        variant="inset"
                        render={<button type="button" />}
                        className="w-full gap-2 bg-surface-active text-left text-sm text-text"
                        onClick={() => {
                            setDocsOpen(false);
                            setSetupOpen(false);
                            setSelectedId(null);
                        }}
                    >
                        <Icon icon={Inbox} size={16} />
                        <span className="sidebar-label grow">Messages</span>
                        {inbox.unread > 0 && <Pill>{inbox.unread}</Pill>}
                    </ListRow>
                    <ListRow
                        variant="inset"
                        render={<button type="button" />}
                        className={`w-full gap-2 text-left text-sm ${docsOpen ? "bg-surface-active text-text" : "text-text-muted"}`}
                        onClick={() => setDocsOpen(true)}
                    >
                        <Icon icon={BookOpen} size={16} />
                        <span className="sidebar-label">Documentation</span>
                    </ListRow>
                </div>
                <span className="grow" />
                <footer className="flex shrink-0 items-center border-t border-border p-2">
                    <IconButton
                        icon={Bell}
                        label={notifications.label}
                        disabled={notifications.disabled}
                        aria-pressed={notifications.active}
                        onClick={() => void notifications.toggle()}
                    />
                    <IconButton
                        icon={Settings2}
                        label="Settings"
                        onClick={() => setSettingsOpen(true)}
                    />
                </footer>
            </nav>
            <main className="flex min-h-0 min-w-0 grow flex-col">
                <header className="flex h-12 shrink-0 items-center border-b border-border bg-surface px-2">
                    <h1 className="grow px-2 text-sm font-medium text-text">
                        {docsOpen ? "Documentation" : "Messages"}
                    </h1>
                    <IconButton
                        icon={Search}
                        label="Search messages"
                        onClick={() => searchRef.current?.focus()}
                    />
                </header>
                {docsOpen ? (
                    <Suspense
                        fallback={
                            <p className="p-6 text-xs text-text-muted">Loading documentation…</p>
                        }
                    >
                        <DocsPanel close={() => setDocsOpen(false)} />
                    </Suspense>
                ) : (
                    <section className="flex min-h-0 grow flex-col bg-bg">
                        <MessagesToolbar
                            channel={channel}
                            search={search}
                            unread={inbox.unread}
                            count={inbox.messages.length}
                            scenario={inbox.scenario}
                            live={inbox.status === "live"}
                            busy={busy}
                            setupOpen={setupOpen}
                            searchRef={searchRef}
                            onChannel={(value) => {
                                setChannel(value);
                                setSelectedId(null);
                            }}
                            onSearch={(value) => {
                                setSearch(value);
                                setSelectedId(null);
                            }}
                            onSetup={() => setSetupOpen((value) => !value)}
                            onImport={() => importRef.current?.click()}
                            onReadAll={() =>
                                perform(() => request("/api/messages/read", { method: "POST" }))
                            }
                            onClear={async () => {
                                await run(() => request("/api/messages", { method: "DELETE" }));
                                setSelectedId(null);
                            }}
                        />
                        {inbox.error && (
                            <p
                                role="alert"
                                className="border-b border-border bg-status-error/10 px-4 py-2 text-xs text-status-error"
                            >
                                {inbox.error}
                            </p>
                        )}
                        <div
                            className="message-columns flex min-h-0 grow"
                            data-detail-open={selectedId !== null || setupOpen}
                        >
                            <MessageList
                                messages={inbox.messages}
                                selectedId={selectedId}
                                loading={inbox.loading}
                                filtered={Boolean(channel || search)}
                                onSelect={(id) => {
                                    setSelectedId(id);
                                    setSetupOpen(false);
                                }}
                                onSetup={() => setSetupOpen(true)}
                            />
                            <div className="message-detail min-h-0 min-w-0 grow">
                                {setupOpen ? (
                                    <SetupPanel scenario={inbox.scenario} refresh={inbox.refresh} />
                                ) : (
                                    <MessageDetail
                                        detail={message.detail}
                                        selectedId={selectedId}
                                        error={message.error}
                                        close={() => setSelectedId(null)}
                                    />
                                )}
                            </div>
                        </div>
                    </section>
                )}
            </main>
            <input
                ref={importRef}
                type="file"
                accept=".eml,message/rfc822"
                hidden
                multiple
                onChange={(event) => {
                    const files = Array.from(event.target.files ?? []);
                    if (files.length) void importMail(files).catch(() => {});
                    event.target.value = "";
                }}
            />
            <SettingsDialog
                open={settingsOpen}
                onOpenChange={setSettingsOpen}
                section="general"
                onNavigate={() => {}}
                groups={SETTINGS_GROUPS}
            />
        </div>
    );
}
