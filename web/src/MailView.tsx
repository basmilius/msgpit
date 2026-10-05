import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Download, Paperclip } from "lucide-react";
import { Button, EmptyState, Icon, Pill, Segmented } from "@basmilius/desktop-ui";
import { formatBytes } from "@basmilius/desktop-ui/format";
import type { Detail } from "./api";
import { CodeBlock } from "./components/CodeBlock";
import { KeyValueList } from "./components/KeyValueList";
import { Tabs, type TabItem } from "./components/Tabs";
import { foldBase64 } from "./model";

type MailTab = "preview" | "text" | "headers" | "source" | "attachments";
const MAX_PLAIN_SOURCE = 1024 * 1024;

function Preview({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    if (!message.html) return <EmptyState>{t("mail.noHtml")}</EmptyState>;
    // Captured mail runs without scripts or forms; only local inline images may load automatically.
    const html = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src ${location.origin} data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'"><style>body{font:14px system-ui;padding:16px;background:white;color:black}img{max-width:100%}</style>${message.html}`;
    return (
        <iframe
            title={t("mail.previewLabel")}
            sandbox=""
            referrerPolicy="no-referrer"
            srcDoc={html}
            className="h-full min-h-96 w-full border-0 bg-[#fff]"
        />
    );
}

function Source({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    const [view, setView] = useState<"message" | "html">("message");
    const [folded, setFolded] = useState(true);
    const raw = message.rawRequest;
    const source =
        view === "html"
            ? (message.html ?? "")
            : folded
              ? foldBase64(raw, (bytes) => `[base64 content, ${formatBytes(bytes)}]`)
              : raw.slice(0, MAX_PLAIN_SOURCE);
    return (
        <div className="flex min-h-0 grow flex-col gap-3 p-4">
            <div className="flex items-center gap-2">
                <Segmented<"message" | "html">
                    label={t("mail.tabs.source")}
                    value={view}
                    onValueChange={setView}
                    options={[
                        { id: "message", label: t("mail.sourceMessage") },
                        ...(message.html
                            ? [{ id: "html" as const, label: t("mail.sourceHtml") }]
                            : []),
                    ]}
                />
                {view === "message" && (
                    <Button variant="ghost" onClick={() => setFolded(!folded)}>
                        {folded ? t("mail.showFull") : t("mail.fold")}
                    </Button>
                )}
            </div>
            <CodeBlock
                label={t("mail.tabs.source")}
                copyLabel={t("detail.copy")}
                className="min-h-0 grow"
            >
                {source}
            </CodeBlock>
        </div>
    );
}

function Attachments({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    if (message.parts.length === 0) return <EmptyState>{t("mail.attachmentNone")}</EmptyState>;
    return (
        <div className="flex flex-col p-4">
            <ul className="flex flex-col divide-y divide-border-soft">
                {message.parts.map((part) => (
                    <li key={part.id} className="flex items-center gap-3 py-2 text-sm">
                        <Icon icon={Paperclip} size={16} className="shrink-0 text-text-faint" />
                        <span className="flex min-w-0 grow flex-col">
                            <span className="truncate text-text">
                                {part.filename ?? part.contentType}
                            </span>
                            <span className="text-xs text-text-faint">
                                {part.contentType} · {formatBytes(part.size)}
                            </span>
                        </span>
                        {part.contentId && <Pill>{t("mail.inline")}</Pill>}
                        <a
                            className="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border border-border bg-surface-raised px-2.5 text-xs font-medium text-text hover:bg-surface-hover"
                            href={`/api/messages/${message.id}/parts/${part.id}`}
                            download={part.filename ?? "attachment"}
                        >
                            <Download size={14} />
                            {t("mail.download")}
                        </a>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function MailView({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    const [picked, setPicked] = useState<MailTab | null>(null);
    const tab = picked ?? (message.html ? "preview" : "text");
    const tabs: TabItem<MailTab>[] = [
        { id: "preview", label: t("mail.tabs.preview") },
        { id: "text", label: t("mail.tabs.text") },
        { id: "headers", label: t("mail.tabs.headers") },
        { id: "source", label: t("mail.tabs.source") },
        { id: "attachments", label: t("mail.tabs.attachments"), count: message.parts.length },
    ];
    return (
        <div className="flex min-h-0 grow flex-col">
            <Tabs<MailTab>
                tabs={tabs}
                value={tab}
                onValueChange={setPicked}
                label={t("mail.tabsLabel")}
            />
            <div className="flex min-h-0 grow flex-col overflow-auto">
                {tab === "preview" && <Preview message={message} />}
                {tab === "text" &&
                    (message.body ? (
                        <div className="p-4">
                            <CodeBlock
                                label={t("mail.tabs.text")}
                                copyLabel={t("detail.copy")}
                                wrap
                            >
                                {message.body}
                            </CodeBlock>
                        </div>
                    ) : (
                        <EmptyState>{t("mail.noText")}</EmptyState>
                    ))}
                {tab === "headers" && (
                    <div className="p-4">
                        <KeyValueList
                            rows={message.headerList.map((header) => ({
                                key: header.name,
                                value: header.value,
                                mono: true,
                            }))}
                            keyWidth="w-44"
                        />
                    </div>
                )}
                {tab === "source" && <Source message={message} />}
                {tab === "attachments" && <Attachments message={message} />}
            </div>
        </div>
    );
}
