# Hone explainer pilot

A 54-second, silent-first Remotion composition. This is an illustrative product explainer, not a recorded session or a Hone dashboard. All telemetry shown is synthetic. No savings or diagnosed root cause is claimed.

## Reproduce

Node 25.6.0 and npm 11.8.0 were used on macOS ARM64. Dependencies are locked; Remotion is 4.0.527. FFmpeg/ffprobe must be on PATH for verification. The first render downloads Chrome Headless Shell if absent. No API key or Laravel environment is needed.

Use an absolute path for `VIDEO_DIR` (the directory containing this file):

```sh
VIDEO_DIR=/Users/edgrosvenor/Library/Caches/solo-worktrees/hone-remotion-pilot/video
npm --prefix "$VIDEO_DIR" ci --no-audit --no-fund
npm --prefix "$VIDEO_DIR" run typecheck
npm --prefix "$VIDEO_DIR" test
npm --prefix "$VIDEO_DIR" run render
npm --prefix "$VIDEO_DIR" run poster
npm --prefix "$VIDEO_DIR" run verify
```

The render script validates input then invokes:

```sh
remotion render src/index.tsx Hone out/hone-explainer.mp4 --codec=h264 --pixel-format=yuv420p --crf=18 --concurrency=2
```

It runs from `video/` regardless of the caller's directory, writes the log and elapsed time to `out/`, and exits nonzero on failure. Poster: `remotion still src/index.tsx Hone out/hone-poster.png --frame=1455`. The video is 1920×1080, 30 fps, 1,620 frames. There is no audio stream. Outputs and dependencies are ignored by Git. Preserve the local worktree for immediate artifact access; the branch can recreate them.

`verify` checks actual codec, frame count, duration, dimensions and silent stream count, fully decodes to null, and extracts scene middles, boundaries and ending. It does not certify visual quality. Inspect `out/frames/` and `out/embedded-960.png`; play the MP4 muted if possible. `npm run studio` is optional, not required by the render.

## Minimal second-product recipe

1. Copy this standalone directory without `node_modules/` or `out/` into an isolated product worktree. Keep the lockfile.
2. Supply an approved logo/art asset and palette; confirm font and artwork rights. Set name, series, asset and theme in `src/product.json`.
3. Write six beats: problem, mechanism, question, grounded finding, benefit, CTA. Every claim needs a shipped-source citation. Use only synthetic or explicitly approved demonstration data, with disclosure on every example scene.
4. Edit `product.json`: title, body, footer, timings, three flow nodes, conversation tool/arguments, query/example, metric labels, and CTA. Keep 45–60 seconds, each beat at least six seconds. Use short lines; current layouts target two-line headlines.
5. Reuse `Frame`, `Heading`, `Card`, `Reveal`, `Art` and `Note`. The conversation and query finding layouts are Hone-specific examples: adapt those two branches in `Explainer.tsx` for a product without an MCP/query story. This is a small scene kit, not a universal data-only video generator. If renaming the composition ID or output files, also update render/poster/verify scripts.
6. Run the commands above, inspect every scene at 960-pixel width and transition boundaries, then watch muted end to end. Revise copy/layout before any publishing decision.

Scene times: 0–8 problem; 8–17 mechanism; 17–27 question; 27–38 finding; 38–46 benefit; 46–54 CTA. The next-step finding is fully visible from 30 seconds, leaving roughly eight seconds to read it. The CTA holds for over six seconds. Short fades precede cuts; text enters with a small rise. No voiceover is needed to convey the story.

## Provenance and claims

Grounded in Hone `f1ff2c781ffa7d42dedd11f50ff9a3c718098ec7`:

- Laravel telemetry, self-hosted PostgreSQL, coding-agent audience, MCP and no dashboard: root `README.md:7–23`, architecture at `README.md:81–102`.
- Nightwatch transport: `README.md:36–39`.
- `slow_queries`: `packages/hone-server/src/Mcp/Tools/SlowQueriesTool.php:16–27`; accepted app/days/metric/limit and response envelope in `Concerns/HandlesSlowMetricTool.php:18–67`.
- Aggregate key, count and avg: `packages/hone-server/src/Mcp/Support/AggregateWindow.php:19–41`; milliseconds: `README.md:820` and `Maintenance/RawEventRollup.php:40–44`.
- `shop`, SQL shape, 420 ms and 2,400 are authored examples, not observed records. The index/query-plan step is a suggested investigation, not an MCP tool result or proof of a missing index.
- CTA source: root `README.md:43–51`. No idle-cost claims are included.

Copied assets are from Scalpels checkout `ef602efc61ec772bbf867c72a4b7d40af201ff27`: `public/img/products/transparent/hone.png`, and Latin 400/600 Instrument Sans WOFF2 files from `public/build/assets/`, as referenced in `resources/css/tailwind.css`. Font license retained in `public/InstrumentSans-OFL.txt`, sourced from Google Fonts' official repository. Artwork is existing first-party product material, not newly generated stock.

## Embedding and later replacement

Scalpels' `app/Marketing/ProductPage/Video.php` accepts only `youtube_url`, `title`, optional `caption`; watch/share/embed URLs are converted to `youtube-nocookie.com/embed/{id}`. The product template uses a lazy-loaded, full-size iframe in a 16:9 container with fullscreen. It does not accept a local MP4 or custom poster. No Scalpels file or persisted record was changed.

For later authorized publication, upload the approved explainer to the chosen YouTube account and update the persisted product's hero video URL/title/caption through the supported catalog process. When a real demo is ready, change those same fields. Editing the seeder alone is not proof that an existing persisted product changed. Direct MP4/poster support requires a separate model/template change and tests; it is outside this pilot.

## Official docs and license check (2026-09-23)

- https://www.remotion.dev/docs/the-fundamentals
- https://www.remotion.dev/docs/sequence
- https://www.remotion.dev/docs/render
- https://www.remotion.dev/docs/cli/render
- https://www.remotion.dev/license (redirects to official repository license)
- https://raw.githubusercontent.com/remotion-dev/remotion/main/LICENSE.md

The installed Remotion license also matches the reviewed terms: evaluation is eligible for free use while not yet used commercially. Individuals, nonprofits and for-profit organizations with up to three employees qualify for the free license; other commercial organizations need a company license. This pilot remains an unpublished evaluation. Organization eligibility for later commercial publication was not established. No license or service was purchased, and no paid API was called. Recheck terms before changing Remotion major versions or publishing.
