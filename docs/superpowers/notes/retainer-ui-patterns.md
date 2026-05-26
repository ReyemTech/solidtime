# Retainer UI patterns (notes from Task 19 inspection)

## Client detail page

- Path: No single-client detail page exists. Only a list page at `resources/js/Pages/Clients.vue` (and a lowercase duplicate at `resources/js/pages/Clients.vue`).
- Tab pattern: `resources/js/Pages/Clients.vue` uses `<TabBar>` / `<TabBarItem>` from `@/packages/ui/src` to toggle between "Active" and "Archived" tabs. Tab state is tracked with a `ref<'active' | 'archived'>`.
- Implication for Task 22: There is no drill-down client detail page. The Retainers tab should either:
  1. Be added to `resources/js/Pages/Clients.vue` as a new tab (alongside "Active" / "Archived"), showing retainers for a selected/clicked client; or
  2. Introduced via a new page (e.g. `resources/js/Pages/ClientDetail.vue`) with its own route; or
  3. Live in a modal triggered from the `ClientMoreOptionsDropdown`.
  Option 1 (new tab on Clients page showing all retainers, filterable by client) is simplest given the existing codebase pattern. Option 2 requires a new Inertia route.

## Form component pattern

- Reference component: `resources/js/Components/Common/Client/ClientCreateModal.vue` and `ClientEditModal.vue`
- Input library: Custom `TextInput` at `@/packages/ui/src/Input/TextInput.vue`; field wrappers via `Field` and `FieldLabel` from `@/packages/ui/src/field`
- Form library: **No Inertia `useForm`**. Pattern is plain `ref<BodyType>()` + async submit function calling a Pinia store action. All store actions wrap calls in `handleApiRequestNotifications()` from `useNotificationsStore` which shows toast notifications on success/failure.
- Dialog wrapper: `DialogModal` from `@/packages/ui/src/DialogModal.vue` with slots: `#title`, `#content`, `#footer`
- Buttons: `SecondaryButton` (cancel) and `PrimaryButton` (submit) from `@/packages/ui/src/Buttons/`
- Error display: No inline validation error rendering is visible in the client modals. Errors surface as toast notifications via `handleApiRequestNotifications`. If field-level errors are needed, they would need to be added (no established pattern for them in the client modals).
- Save state: `const saving = ref(false)` on the component; `:disabled="saving"` and `:class="{ 'opacity-25': saving }"` on PrimaryButton.

## API store pattern

- Pattern: Pinia stores (not composables) make API calls. Each store (`useClientsStore`, `useProjectsStore`, etc.) calls `api.<alias>(body, { params: { organization } })` using the Zodios-generated `api` object from `@/packages/api/src`.
- Query pattern: Read queries use TanStack Vue Query (`useQuery`) in separate `use*Query.ts` composables. The query key is `computed(() => [resource, getCurrentOrganizationId()])`.
- After mutations: `queryClient.invalidateQueries({ queryKey: ['clients'] })` pattern.
- Retainer store to create: `resources/js/utils/useRetainers.ts` (Pinia store) + `resources/js/utils/useRetainersQuery.ts` (TanStack query composable).

## Time entry form

- File: `resources/js/Components/TimeTracker.vue` (top-level orchestrator) and `resources/js/packages/ui/src/TimeTracker/TimeTrackerControls.vue` (renders the active tracker bar)
- Where the project picker emits project_id: `TimeTrackerProjectTaskDropdown.vue` (at `resources/js/packages/ui/src/TimeTracker/TimeTrackerProjectTaskDropdown.vue`) emits `@changed` with `(project.value, task.value)`. The parent `TimeTrackerControls.vue` listens at line 270 (`@changed="updateProject"`). The `project_id` is bound via `v-model:project="currentTimeEntry.project_id"`.
- Where to mount the retainer badge in Task 23: Inside `TimeTrackerControls.vue` in the template block at lines 255-271, immediately after the `<TimeTrackerProjectTaskDropdown>` closing tag (still within the `<div class="flex items-center w-[130px] @2xl:w-auto shrink min-w-0">`). The badge should be conditional on `currentTimeEntry.project_id` having an active retainer.

## API client

- Generation: Two steps:
  1. `./vendor/bin/sail artisan scramble:export` — exports the Scramble-generated OpenAPI spec to `api.json` at the project root (Scramble config at `config/scramble.php`, uses `dedoc/scramble`)
  2. `npm run zod:generate` — runs `npx openapi-zod-client <url> --output resources/js/packages/api/src/openapi.json.client.ts --base-url /api`. The default script targets `http://localhost:80/docs/api.json` (requires a running server). For offline use, run: `npx openapi-zod-client ./api.json --output resources/js/packages/api/src/openapi.json.client.ts --base-url /api`
- Output type file: `resources/js/packages/api/src/openapi.json.client.ts` (auto-generated Zodios client). Public types re-exported from `resources/js/packages/api/src/index.ts`.
- How endpoints are called: `api.<alias>(body, { params: { organization, ...pathParams }, queries: { ... } })`. The `api` instance is imported from `@/packages/api/src` (which creates it via `createApiClient`). All aliases follow the Laravel route names (e.g. `v1.retainers.index`, `v1.retainers.store`).
- Retainer aliases now available (as of this task): `v1.retainers.index`, `v1.retainers.store`, `v1.retainers.show`, `v1.retainers.update`, `v1.retainers.destroy`, `v1.retainers.status`, `v1.retainers.periods`, `v1.retainers.for-client`, `v1.retainers.project-caps.index`, `v1.retainers.project-caps.store`, `v1.retainers.project-caps.update`, `v1.retainers.project-caps.destroy`, `v1.retainers.periods.replace`.

## OpenAPI regeneration (this task)

- Command used: `./vendor/bin/sail artisan scramble:export` then `npx openapi-zod-client ./api.json --output resources/js/packages/api/src/openapi.json.client.ts --base-url /api`
- Result: SUCCESS. All retainer endpoints are present in the regenerated `openapi.json.client.ts`.
- The intermediate `api.json` (project root) is the raw OpenAPI spec; it can be committed for reference or gitignored. The generated `openapi.json.client.ts` is what the app imports.
