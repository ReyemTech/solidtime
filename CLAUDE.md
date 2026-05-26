# solidtime — ReyemTech fork

This is a **fork** of [solidtime-io/solidtime](https://github.com/solidtime-io/solidtime). This file lives **only on the `reyem` branch** and must never be merged back upstream.

## Repository structure

| Remote     | URL                                                | Purpose                                              |
| ---------- | -------------------------------------------------- | ---------------------------------------------------- |
| `origin`   | `git@github.com:ReyemTech/solidtime.git`           | Our fork                                             |
| `upstream` | `https://github.com/solidtime-io/solidtime.git`    | Original solidtime repo                              |

## Branches

| Branch    | Where it lives                  | What it contains                                                                 |
| --------- | ------------------------------- | -------------------------------------------------------------------------------- |
| `main`    | `origin/main` + `upstream/main` | Clean mirror of upstream. **Never commit local-only changes here.** PR base.     |
| `reyem`   | `origin/reyem` (default)        | Working branch. Upstream code + local additions (Helm, this CLAUDE.md, etc.).    |
| `pr/<x>`  | feature branches off `main`     | Short-lived branches for PRs back to upstream.                                   |

## Local-only files (only exist on `reyem`)

- `CLAUDE.md` — this file
- `deploy/helm/` — Helm charts for our self-hosted deployment
- Anything else we add for ReyemTech-only use

These files are **not** in `.gitignore`. They simply don't exist on `main`. When you `git checkout main`, they vanish from the working tree. That's the whole mechanism — keep it that way.

## Workflows

### Pull in upstream changes (do this regularly)

```bash
git checkout main
git pull upstream main
git push origin main         # keep our fork's main in sync

git checkout reyem
git merge main               # pulls upstream changes into reyem
# resolve conflicts if any, then push
git push origin reyem
```

### Send a change back upstream

**Branch from `main`, not `reyem`** — otherwise local-only files leak into the PR.

```bash
git checkout main
git pull upstream main
git checkout -b pr/short-description

# make the change, commit
git push -u origin pr/short-description

gh pr create --repo solidtime-io/solidtime --base main \
  --title "..." --body "..."
```

If the change already exists on `reyem`, cherry-pick the relevant commit(s):

```bash
git checkout -b pr/short-description main
git cherry-pick <sha>
```

### Add a local-only feature

Just commit to `reyem`. Done.

```bash
git checkout reyem
# edit files
git commit -am "local: ..."
git push origin reyem
```

Prefix local-only commits with `local:` (convention) so they're easy to filter when looking at history.

## Hard rules

1. **Never** commit `CLAUDE.md`, `deploy/helm/`, or other local-only paths to `main` or any `pr/*` branch.
2. **Never** push `reyem` to `upstream`. (You don't have permission, but be aware.)
3. **Never** rebase `main` onto `reyem`. Always merge `main` → `reyem`, not the other way around.
4. When pulling upstream, do it through `main` first, then merge `main` into `reyem`. Don't `git pull upstream main` while checked out on `reyem`.

## Conflict strategy

Local changes should be **additive** (new files in new directories). If you find yourself patching upstream's own files heavily, the merge conflicts will compound — at that point switch to a `patches/` directory + `git am` workflow. Don't do that preemptively.

## Stack reminder

Upstream solidtime is Laravel + Inertia + Vue 3 + Tailwind. See upstream's `README.md` and `CONTRIBUTING.md` for build/test commands.
