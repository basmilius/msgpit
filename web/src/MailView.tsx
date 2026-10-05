import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Download, Paperclip } from "lucide-react";
import { Button, EmptyState, Icon, Pill, Segmented } from "@basmilius/desktop-ui";
import { formatBytes } from "@basmilius/desktop-ui/format";
import type { Detail } from "./api";
import { CodeBlock } from "./components/CodeBlock";
import { KeyValueList } from "./components/KeyValueList";
import { Tabs, type TabItem } from "./components/Tabs";
import { HtmlCheck, Links, Spam } from "./MailChecks";
import { ReportView } from "./ReportView";
import { copyText } from "@basmilius/desktop-ui";
import { renderHtmlSource } from "./source/htmlsource.js";
import { renderRawMessage } from "./source/rawmessage.js";

type MailTab =
    | "preview"
    | "text"
    | "headers"
    | "source"
    | "check"
    | "links"
    | "spam"
    | "report"
    | "attachments";
const MAX_PLAIN_SOURCE = 1024 * 1024;

function Preview({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    if (!message.html) return <EmptyState>{t("mail.noHtml")}</EmptyState>;
    // Captured mail runs without scripts or forms; only local inline images may load automatically.
    let preview = message.html;
    for (const part of message.parts ?? []) {
        if (part.contentId)
            preview = preview.replaceAll(
                `cid:${part.contentId}`,
                `${location.origin}/api/messages/${message.id}/parts/${part.id}`,
            );
    }
    const html = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src ${location.origin} data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'"><style>body{font:14px system-ui;padding:16px;background:white;color:black}img{max-width:100%}</style>${preview}`;
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
    const formatted =
        view === "html"
            ? renderHtmlSource(message.html ?? "", { format: folded })
            : folded
              ? renderRawMessage(raw)
              : null;
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
                {
                    <Button variant="ghost" onClick={() => setFolded(!folded)}>
                        {view === "html"
                            ? folded
                                ? "Original HTML"
                                : "Format HTML"
                            : folded
                              ? t("mail.showFull")
                              : t("mail.fold")}
                    </Button>
                }
                {message.sourcePart && (
                    <a
                        className="ml-auto text-xs text-text-muted hover:text-text"
                        href={`/api/messages/${message.id}/parts/${message.sourcePart.id}`}
                        download={message.sourcePart.filename ?? "message.eml"}
                    >
                        Download .eml
                    </a>
                )}
            </div>
            <div className="flex gap-2">
                <Button
                    variant="ghost"
                    onClick={() => void copyText(view === "html" ? (message.html ?? "") : raw)}
                >
                    Copy original
                </Button>
            </div>
            {/* These upstream renderers escape captured content before inserting their own markup. */}
            {formatted !== null ? (
                <div
                    className="code min-h-0 grow overflow-auto rounded-lg border border-border bg-surface p-3 font-mono text-xs text-text"
                    style={view === "html" ? { whiteSpace: "pre" } : undefined}
                    dangerouslySetInnerHTML={{ __html: formatted }}
                />
            ) : (
                <CodeBlock label={t("mail.tabs.source")} className="min-h-0 grow">
                    {raw.slice(0, MAX_PLAIN_SOURCE)}
                </CodeBlock>
            )}
        </div>
    );
}

function Attachments({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    const parts = message.parts.filter((part) => part.disposition !== "body");
    if (parts.length === 0) return <EmptyState>{t("mail.attachmentNone")}</EmptyState>;
    return (
        <div className="flex flex-col p-4">
            <ul className="flex flex-col divide-y divide-border-soft">
                {parts.map((part) => (
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
        { id: "check", label: t("mail.tabs.check") },
        { id: "links", label: t("mail.tabs.links"), count: message.links?.length },
        { id: "spam", label: t("mail.tabs.spam") },
        { id: "report", label: "Deliverability" },
        {
            id: "attachments",
            label: t("mail.tabs.attachments"),
            count: message.parts.filter((part) => part.disposition !== "body").length,
        },
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
                    (message.text ? (
                        <div className="p-4">
                            <CodeBlock
                                label={t("mail.tabs.text")}
                                copyLabel={t("detail.copy")}
                                wrap
                            >
                                {message.text}
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
                {tab === "check" && <HtmlCheck message={message} />}
                {tab === "links" && <Links message={message} />}
                {tab === "spam" && <Spam message={message} configured={message.spamConfigured} />}
                {tab === "report" && <ReportView message={message} />}
                {tab === "attachments" && <Attachments message={message} />}
            </div>
        </div>
    );
}
