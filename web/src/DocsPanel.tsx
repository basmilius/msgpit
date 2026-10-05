import { useEffect, useState } from "react";
import Markdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { Button, FormError, ListRow, Spinner } from "@basmilius/desktop-ui";
import { request } from "./api";

export function DocsPanel({ close }: { close(): void }) {
    const [pages, setPages] = useState<{ slug: string; title: string }[]>([]);
    const [slug, setSlug] = useState("02-getting-started");
    const [markdown, setMarkdown] = useState<string | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    useEffect(() => {
        const controller = new AbortController();
        void request<{ pages: { slug: string; title: string }[] }>("/api/docs", {
            signal: controller.signal,
        })
            .then((result) => setPages(result.pages))
            .catch((error) => {
                if (!controller.signal.aborted) setFailure(error.message);
            });
        return () => controller.abort();
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        setMarkdown(null);
        setFailure(null);
        void request<{ markdown: string }>(`/api/docs/${slug}`, { signal: controller.signal })
            .then((result) => setMarkdown(result.markdown))
            .catch((error) => {
                if (!controller.signal.aborted) setFailure(error.message);
            });
        return () => controller.abort();
    }, [slug]);
    return (
        <section className="flex min-h-0 grow flex-col bg-bg">
            <div className="flex h-12 shrink-0 items-center gap-3 border-b border-border bg-surface px-4">
                <h2 className="grow text-sm font-medium text-text">Documentation</h2>
                <Button variant="ghost" onClick={close}>
                    Back to messages
                </Button>
            </div>
            <div className="flex min-h-0 grow flex-col overflow-auto md:flex-row">
                <nav
                    aria-label="Documentation chapters"
                    className="shrink-0 border-b border-border p-2 md:w-60 md:overflow-y-auto md:border-r md:border-b-0"
                >
                    {pages.map((page) => (
                        <ListRow
                            key={page.slug}
                            variant="inset"
                            render={<button type="button" />}
                            onClick={() => setSlug(page.slug)}
                            className={`w-full text-left text-xs ${slug === page.slug ? "bg-surface-active text-text" : "text-text-muted"}`}
                        >
                            {page.title}
                        </ListRow>
                    ))}
                </nav>
                <article className="docs-content min-w-0 grow overflow-y-auto p-6 md:p-8">
                    {failure ? (
                        <FormError>{failure}</FormError>
                    ) : markdown === null ? (
                        <Spinner size={20} />
                    ) : (
                        <Markdown
                            remarkPlugins={[remarkGfm]}
                            components={{
                                a: ({ href, children }) =>
                                    href?.endsWith(".md") && !href.includes("://") ? (
                                        <button
                                            className="text-left underline"
                                            onClick={() =>
                                                setSlug(
                                                    href.replace(/.*\//, "").replace(/\.md$/, ""),
                                                )
                                            }
                                        >
                                            {children}
                                        </button>
                                    ) : (
                                        <a href={href} target="_blank" rel="noreferrer">
                                            {children}
                                        </a>
                                    ),
                                img: ({ alt }) => <span>{alt}</span>,
                            }}
                        >
                            {markdown}
                        </Markdown>
                    )}
                </article>
            </div>
        </section>
    );
}
