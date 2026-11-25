# I would like a feature where when a custom data layer event tag is created with included properties, a user can click a button on that event tag to generate a JavaScript snippet to hand off to a dev

_Draft for JOIN-70, written by the team in Slack: each section names who wrote it. JOIN keeps it until an approver saves it to the repository._

## Product Owner

The anchor docs/features/event-snippet-handoff.md stands. The approver has decided the points below; they are settled and not reopened here.

**Decided**
- Architecture: the snippet is generated on the server from the PHP entities (src/Entities/TagManager, TagReferences, TagVariables, TagEvent, TagTrigger). The client-only plan and the JavaScript copy of the path rules are replaced.
- Scope: the button is added to the dashboard and a command-line version is in scope too, so SettingsParityTest applies to this feature.
- One snippet per tag. A combined push is not in the MVP.
- Placeholders are labelled with the customData key, not the Variables-tab alias. Format: `'<<REPLACE: key, type>>'` with a type comment.
- Error state: the button opens the panel with the reason shown. It is not disabled. (UX's current draft shows it disabled; see the conflict raised in the thread.)
- Shared link: an optional, read-only page reached by an unguessable GUID in the URL.
- Paths through `event` (for example event.detail.plan): reported as an attention item and left out of the push. The event key stays the event name.
- Conflicting paths (for example a.b and a.b.c): both variables are named, and the rest of the push is still built.
- Latest-value variables ({"$var"}): kept as a placeholder in the push, with the comment that they may already be on the page from an earlier push.

- No-variable tags (decided by the approver, 2026-11-25): the property-table fallback wins over the anchor's "no button" line. When a tag reads no variables at all, the snippet names each of the tag's own customData properties, with each property name (from the tag's property table) as the placeholder label and the type taken from its fixed value, plus one "Needs attention" line saying the tag still sends its fixed values until each property gets a variable. A tag with no properties pushes only the event. Fixed values beside at least one variable are still left out.
- Share-link storage (decided by the approver, 2026-11-25): YAML under config/, never the database. A link is kept as a SHA-256 hash of its token beside the tag's settings (`tag_snippet_shares` in the website's config/tag-manager/sites/<id>.yaml, or in config/aggregate.yaml for the shared configuration).
- Share-link expiry (decided by the approver, 2026-11-25): links expire. `tag_snippet_share_days` (1 to 365, default 7) sets the lifetime for the whole installation on General settings, in config/aggregate.yaml, or with `app:config set general.tag_snippet_share_days`. Making a new link replaces the tag's old one, and "Stop sharing" closes it at once.
- Parity (decided by the approver, 2026-11-25): the snippet routes are audited and mapped in SettingsParityTest to `app:tag-manager:snippet`, which has `--share` and `--stop-sharing`.

**Anchor "Done when" (updated here)**
docs/features/event-snippet-handoff.md is not in the repository, so its "Done when" is recorded here: done when a data-layer event tag shows "Get developer snippet"; a tag that reads no variables gets the property-table fallback described above instead of no button; the shared link is stored as a hash in YAML, expires after `tag_snippet_share_days` and can be stopped; and the dashboard and CLI give the same snippet and attention items.

**Acceptance criteria (checkable without asking)**
1. A data-layer event tag with included properties shows "Get developer snippet"; the snippet opens in a panel on the same row.
2. Pasting the snippet with real values into Test this tag sends the event with those values.
3. Every placeholder names its customData key and accepted type. No placeholder looks like a real value.
4. A variable that reads through `event` is listed under "Needs attention" and is absent from the push. `event` stays the event-name string.
5. Conflicting paths: both variables are named under "Needs attention"; the rest of the push is built.
6. Latest-value variables appear as placeholders with the earlier-push comment.
7. A tag edited but not saved produces a snippet that reflects the edited row.
8. The shared page is read-only, shows the saved tag only, and says so.
9. The CLI prints the same snippet and the same attention items as the dashboard.
10. A tag that reads no variables gets a snippet of its own properties named from the property table, with one attention item; a tag with no properties pushes only the event.
11. A shared link opens for `tag_snippet_share_days` days, then shows "This link is not open"; "Stop sharing" closes it at once; only a hash of its token is stored.
12. Every snippet route is audited and has a CLI equivalent in SettingsParityTest.

**Out of scope for the MVP:** combined pushes across tags, and any in-app report.

_By Product Owner (claude-haiku-5-5)._

## UX/UI Designer

Revised after the approver's decisions (error state: open with a reason; server-side build; CLI in scope; shared link as an option). The screen is the same inline panel as before. Test this tag is a `<details>` disclosure (per Frontend), so the snippet panel matches it, not a modal.

**Button**
- "Get developer snippet", a native secondary text button next to Test this tag, in tab order right after it. Shown on Send an Aggregate event tags with a data layer trigger. Whether it shows on tags with no variables waits on the anchor conflict the approver has not yet answered.
- Error state: the button stays enabled and opens the panel. The panel shows the reason at the top (for example "Add an event name to the trigger first.") and no snippet. The button is never disabled, and the reason is never tooltip-only.
- "Needs attention" items (below) are shown in the same panel whenever they apply, with or without a snippet.

**Panel** (`aria-expanded`/`aria-controls` on the button; focus moves to the heading; Escape or Close returns focus to the button)
- Heading "Snippet for your developer". Line: "Ask your developer to add this where the page makes the event. Replace each marked value with the real value. Making this snippet saves and sends nothing."
- Read-only code block, focusable, scrollable, with a maximum height.
- Buttons: "Copy snippet" (becomes "Copied" for about 3 seconds; polite live region says "Snippet copied"; if blocked: "Copy didn't work. Press Ctrl+C to copy the selected text."), "Share link", "Close".
- "Needs attention" list below, only when needed. Each item names the variable and the fix: a path through `event` ("Variable `plan` reads `event.detail.plan`. The event key must stay the event name, so this value is left out. Read it from another path."), and conflicting paths (names both variables, says the rest of the push is still built).
- Stale: if the row changes while the panel is open, a note "The tag changed." and a "Refresh snippet" button appear. Nothing rebuilds on its own.

**Placeholders**
- One marker everywhere: `'<<REPLACE: key, type>>'`, labelled by the customData key (decided). Each line has a comment with the accepted type. Latest-value variables also get a placeholder, with the comment "may already be on the page from an earlier push".
- Properties with a default in the property table: fixed values are not asked of the developer. For a tag with no variables, the snippet falls back to the property table (decided). The exact content still needs the Product Owner's confirmation.

**Share link (new, option; needs the Product Owner and Backend)**
- "Share link" makes a read-only page for the developer at an unguessable address (long random ID). The developer needs no dashboard sign-in, so this is the sensitive part. Off until the editor clicks it; shows the full address with "Copy link"; says in plain words "Anyone with this link can read this snippet"; has "Stop sharing" that kills the link; and shows what the page contains (event name and placeholders only, no tag secrets or website data). Expiry is an open call.
- The shared page shows the snippet saved with the tag, not the unsaved row, and says so.
- Open for the team: where the link record is stored. AGENTS.md says the database holds no configuration, so a share token needs a home that fits that rule.

**Server-side and command line**
- The button posts the row as edited and shows what comes back. Loading: button reads "Preparing…" and is disabled while waiting. Error: "We couldn't build the snippet. Check the tag's trigger and variables, then try again."
- The command line prints the same snippet and the same attention items as plain text, so the wording lives in one place.

**Accessibility**
- Theme tokens only, so white labelling keeps working. Placeholders are marked by their text, not colour. Works at 200% zoom. The code block scrolls without trapping focus. Reasons are text, never tooltip-only.

**Risks**
- Two similar buttons (Test this tag and Get developer snippet): headings and panel wording must separate previewing from handing off.
- The share link is a public door to tag information. It needs a clear warning and an off switch.
- Server-side building must still reflect the unsaved row; if it only reads saved tags, the criterion fails.

_By UX/UI Designer (claude-sonnet-5-5)._

_By UX Designer (claude-haiku-5-5)._

## Front-end Developer

Revised after the approver's decision to generate the snippet on the server from the PHP entities. My earlier client-only plan is withdrawn: no `event_snippet.js` built from `tag_row_controller.js`, no `event_paths.js` refactor, and no JavaScript copy of the path rules. The browser only posts the row as edited, shows what comes back, and handles the panel, copy, freshness and sharing UI.

**Approach**
- The button "Get developer snippet" sits in `TagRow.html.twig` next to Test this tag, with a sibling panel in the same style (disclosure, `aria-expanded`, `aria-controls`). Page CSS goes beside the Tag manager page, not inline.
- On click, the controller posts the row's current unsaved state (`syncEventFromKv()` first when `eventMode === 'kv'`) to the server route the Architect designs, and renders the response: code block, "Needs attention" list, or the reason. It does not parse paths or build placeholders itself.
- Error and attention wording comes from the server response, so the dashboard and CLI print the same text. The loading state reads "Preparing…" and disables the button only while waiting.
- Copy uses `navigator.clipboard.writeText` with a fallback that selects the code block. Plain-HTTP intranets make the fallback a main path, so it is tested too.
- Freshness: the panel is not rebuilt on its own. Edits and `tag-manager:variables-changed` mark it stale and show "Refresh snippet", which posts again.
- Display: read-only `<pre tabindex="0">` with `overflow:auto` and a max height. Placeholders are marked in text, not colour.
- Shared page: a read-only view reusing the same panel partial, fed by the saved tag only, with the "saved tag only" note and no edit controls.

**Gap for the Architect**
- The route's request and response shape (code, attention items, reason) must be set before I build the panel.
- The button's visibility rule lives in `update()` beside `actionFields`; it waits for the no-variable conflict to be answered.

**Risks**
- The server must build from the unsaved row, so the post carries the row, not only a saved id.
- Focus returns to the button when the panel closes or the row is rebuilt on save.

**Files I expect to touch**
- `TagRow.html.twig`, `assets/controllers/components/tag_manager/tag_row_controller.js` (wiring only), a panel partial and page CSS beside the Tag manager page, `docs/TAG-MANAGER.md`, and JS tests for the panel and the copy fallback.

_By Front-end Developer._

_By Frontend (claude-haiku-5-5)._

## To do

- [x] (done in JOIN-70) Architect: design the server route, DashboardPages entry (Tag manager level), SettingsParityTest handling and the CLI command, with the shared-message wording in one place.
- [ ] UX/UI Designer: mockup for the error state (open with reason) and the share-link states; align the disabled state with the approver's decision.
- [x] (done in JOIN-70) Product Owner: confirm the no-variable rule once the anchor conflict is answered; keep the acceptance criteria current.
- [x] (done in JOIN-70) Frontend Developer: rework the Test this tag reuse plan to the server-side design; drop the event_paths.js refactor unless the Architect keeps a client piece.
- [x] (done in JOIN-70) DBA: design where a share link is stored, consistent with the rule that the database holds no configuration (YAML beside the tag's settings, hash only).
- [x] Developer: server snippet builder, route `POST /dashboard/tag-manager/snippet`, command `app:tag-manager:snippet`, button and panel (done in JOIN-70; CI green on 8775e52)
- [x] Developer: share link, with expiry, stop and the General settings lifetime (done in JOIN-70)
- [x] Developer: no-variable fallback to the property table (done in JOIN-70)
- [x] Developer: tests for the route body and `SnippetCommand` (done in JOIN-70)
- [ ] Developer: tests for the panel JavaScript (snippet_controller.js), including the share and stop-sharing states

_By Product Owner (claude-haiku-5-5)._
